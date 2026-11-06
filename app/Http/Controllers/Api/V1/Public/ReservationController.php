<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Inventory\AvailabilityService;
use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\PublicCatalog;
use App\Domain\Inventory\Translations;
use App\Domain\Payments\CheckoutService;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\PriceQuoteService;
use App\Domain\Reservations\EffectiveCancellationPolicy;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\LegalAcceptance;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\NewReservation;
use App\Domain\Reservations\ReservationEconomics;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Http\WithTenantContext;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetGuestLocale;
use App\Http\Requests\PublicReservationRequest;
use App\Http\Resources\PriceQuoteResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Prenotazione dalla pagina ospite (07):
 *
 * - POST /public/{t}/{p}/reservations: ricalcola il prezzo e lo confronta con expected_total_cents
 *   (409 PRICE_CHANGED con il nuovo prezzo), verifica le versioni di condizioni e informativa, poi T1
 *   e la sessione di pagamento con la sequenza in tre passi di 05. La richiesta non è avvolta in una
 *   transazione (`tenant:public,steps`): ogni passo apre il proprio contesto tenant e la chiamata al
 *   servizio di pagamento sta fuori. Il token di accesso torna nella risposta e non va mai in un URL
 *   di ritorno (05, ADR-0011);
 * - GET /public/reservations/{code}?token=: stato per la pagina di ritorno, senza dati personali.
 */
final class ReservationController extends Controller
{
    public function store(
        PublicReservationRequest $request,
        string $tenantSlug,
        string $propertySlug,
        TenantContext $tenants,
        PublicCatalog $catalog,
        AvailabilityService $availability,
        PriceQuoteService $prices,
        EffectiveCancellationPolicy $policies,
        CheckoutService $checkout,
    ): JsonResponse {
        $tenantId = (string) $request->attributes->get(WithTenantContext::TENANT_ATTRIBUTE);
        $locale = SetGuestLocale::of($request);

        $data = $tenants->run($tenantId, function () use ($request, $propertySlug, $catalog, $availability, $prices, $policies, $locale): NewReservation {
            $property = $catalog->property($propertySlug);
            $unit = $catalog->unit($property, $request->string('unit_id')->toString());
            $priceRequest = new PriceRequest($request->stay(), $request->integer('adults'), $request->integer('children'));

            $this->assertAccepted($request, $property);
            $availability->assertBookable($unit);
            $availability->assertAvailable($unit, $priceRequest->stay);
            $quote = $prices->quote($unit, $priceRequest);

            if ($quote->totalCents !== $request->integer('expected_total_cents')) {
                throw BusinessRuleViolation::make(409, 'PRICE_CHANGED', [
                    'price' => PriceQuoteResource::make($unit, $priceRequest, $quote, $policies->for($unit), $locale),
                ]);
            }

            return new NewReservation($unit, $priceRequest->stay, $priceRequest->adults, $priceRequest->children,
                $request->guest(), ReservationChannel::DIRECT_WEB, $quote);
        });

        $result = $checkout->start($tenantId, $data);
        $reservation = $result['created']->reservation;

        $tenants->run($tenantId, fn () => app(AuditLogger::class)->record('reservation.terms_accepted', 'reservation', $reservation->id, [
            'terms_version' => $request->input('accepted_terms_version'),
            'privacy_version' => $request->input('accepted_privacy_version'),
        ]));

        return new JsonResponse([
            'reservation_code' => $reservation->code,
            'status' => $reservation->status->value,
            'checkout_url' => $result['session']->url,
            'checkout_expires_at' => $result['session']->expiresAt->toIso8601ZuluString(),
            'access_token' => $result['created']->accessToken,
        ], 201);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $reservation = Reservation::query()->with(['unit.translations', 'unit.property.translations'])->where('code', $code)->firstOrFail();
        $locale = SetGuestLocale::of($request);
        $economics = ReservationEconomics::of($reservation);
        $money = fn (int $cents): array => Money::json($cents, $reservation->currency);
        /** @var array<string, array{title?: string, text?: string}> $texts */
        $texts = $reservation->cancellation_policy_snapshot['texts'] ?? [];
        $policy = $texts[$locale->value] ?? $texts[Locale::IT->value] ?? null;
        $unit = $reservation->unit;
        $property = $unit->property;

        return new JsonResponse([
            'reservation_code' => $reservation->code,
            'status' => $reservation->status->value,
            'booking_mode' => $reservation->booking_mode_snapshot->value,
            'property' => ['slug' => $property->slug, 'name' => self::name($property->translations, $property->name, $locale),
                'contact_email' => $property->contact_email, 'contact_phone' => $property->contact_phone],
            'unit' => ['id' => $unit->id, 'name' => self::name($unit->translations, $unit->name, $locale)],
            'check_in' => $reservation->stay->checkIn->toDateString(),
            'check_out' => $reservation->stay->checkOut->toDateString(),
            'nights' => $reservation->stay->nights(),
            'adults' => $reservation->adults,
            'children' => $reservation->children,
            'contract_total' => $money($reservation->contract_total_cents),
            'deposit_due' => $money($reservation->deposit_due_cents),
            'balance_due' => $money($reservation->balance_due_cents),
            'paid' => $money($economics->netPaidCents()),
            'outstanding' => $money($economics->outstandingCents()),
            'economic_status' => $economics->status()->value,
            'refund_status' => $economics->refundStatus()->value,
            'approval_deadline' => $reservation->approval_deadline?->toIso8601ZuluString(),
            'confirmed_at' => $reservation->confirmed_at?->toIso8601ZuluString(),
            'cancelled_at' => $reservation->cancelled_at?->toIso8601ZuluString(),
            'cancellation_policy' => $policy === null ? null : [
                'code' => $reservation->cancellation_policy_snapshot['code'] ?? null,
                'title' => $policy['title'] ?? null,
                'text' => $policy['text'] ?? null,
            ],
        ]);
    }

    /**
     * Versioni accettate uguali a quelle dei documenti attuali della struttura; le condizioni contano
     * solo se la struttura ne pubblica il link.
     */
    private function assertAccepted(PublicReservationRequest $request, Property $property): void
    {
        $errors = [];

        if ($request->input('accepted_privacy_version') !== LegalAcceptance::privacyVersion($property)) {
            $errors['accepted_privacy_version'] = __('reservations.validation.terms_required');
        }

        $terms = LegalAcceptance::termsVersion($property);

        if ($terms !== null && $request->input('accepted_terms_version') !== $terms) {
            $errors['accepted_terms_version'] = __('reservations.validation.terms_required');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  Collection<int, covariant Model>  $translations
     */
    private static function name(Collection $translations, string $fallback, Locale $locale): string
    {
        $all = Translations::toArray($translations);
        $name = $all[$locale->value]['name'] ?? $all[Locale::IT->value]['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $fallback;
    }
}
