<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Translations;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Quotes\Enums\QuoteStatus;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\QuoteLinks;
use App\Domain\Quotes\QuoteService;
use App\Domain\Reservations\EffectiveCancellationPolicy;
use App\Domain\Reservations\LegalAcceptance;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Http\WithTenantContext;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetGuestLocale;
use App\Http\Requests\AcceptQuoteRequest;
use App\Http\Resources\PriceQuoteResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Preventivo dalla pagina ospite `/q/{token}` (07):
 *
 * - GET /public/quotes/{token}: unità, date, ospiti, righe, sconto, totale, scadenza, booking_mode;
 *   scaduto, ritirato o esaurito → 410;
 * - POST /public/quotes/{token}/accept: prezzo congelato, tentativo unico (I21), stessa risposta di
 *   POST /reservations (201); con un tentativo attivo 200 con lo stesso reservation_code e
 *   checkout_url (il token di accesso si mostra una sola volta, al primo accept).
 */
final class QuoteController extends Controller
{
    public function show(Request $request, string $token, QuoteService $quotes, EffectiveCancellationPolicy $policies): JsonResponse
    {
        $quote = $quotes->forGuest(QuoteLinks::hash($token));
        $locale = SetGuestLocale::of($request);
        $unit = $quote->unit;
        $property = $unit->property;
        $price = PriceQuoteResource::make($unit, new PriceRequest($quote->stay, $quote->adults, $quote->children, $quote->discount_cents),
            QuoteService::frozenPrice($quote), $policies->for($unit), $locale);

        return new JsonResponse([
            ...$price,
            'status' => $quote->status->value,
            'booking_mode' => $quotes->bookingMode($quote)->value,
            'expires_at' => $quote->expires_at->toIso8601ZuluString(),
            'accepted' => $quote->status === QuoteStatus::ACCEPTED,
            'in_checkout' => $quote->status === QuoteStatus::IN_CHECKOUT,
            'guest' => ['name' => $quote->guest_name, 'email' => $quote->guest_email, 'locale' => $quote->guest_locale->value],
            'property' => [
                'tenant_slug' => Tenant::query()->whereKey($quote->tenant_id)->value('slug'),
                'slug' => $property->slug,
                'name' => self::name($property->translations, $property->name, $locale),
                'contact_email' => $property->contact_email,
                'contact_phone' => $property->contact_phone,
                'privacy_policy_url' => $property->privacy_policy_url,
                'terms_url' => $property->terms_url,
                'privacy_version' => LegalAcceptance::privacyVersion($property),
                'terms_version' => LegalAcceptance::termsVersion($property),
            ],
            'unit' => ['id' => $unit->id, 'name' => self::name($unit->translations, $unit->name, $locale)],
        ]);
    }

    public function accept(AcceptQuoteRequest $request, string $token, TenantContext $tenants, QuoteService $quotes): JsonResponse
    {
        $tenantId = (string) $request->attributes->get(WithTenantContext::TENANT_ATTRIBUTE);
        $hash = QuoteLinks::hash($token);

        $tenants->run($tenantId, function () use ($request, $hash): void {
            $quote = Quote::query()->with('unit.property')->where('public_token_hash', $hash)->first()
                ?? throw BusinessRuleViolation::make(404, 'NOT_FOUND');
            $this->assertAccepted($request, $quote->unit->property);
        });

        $result = $quotes->accept($tenantId, $hash, $request->guest(), [
            'terms' => $request->input('accepted_terms_version'),
            'privacy' => $request->input('accepted_privacy_version'),
        ]);

        return new JsonResponse([
            'reservation_code' => $result['reservation']->code,
            'status' => $result['reservation']->status->value,
            'checkout_url' => $result['checkout_url'],
            'checkout_expires_at' => $result['checkout_expires_at']->toIso8601ZuluString(),
            'access_token' => $result['access_token'],
        ], $result['repeat'] ? 200 : 201);
    }

    private function assertAccepted(AcceptQuoteRequest $request, Property $property): void
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
