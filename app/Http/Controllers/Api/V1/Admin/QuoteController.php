<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Pricing\Money;
use App\Domain\Quotes\Enums\QuoteStatus;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\QuoteLinks;
use App\Domain\Quotes\QuoteService;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Enums\Locale;
use App\Http\Api\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Requests\PriceQuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Preventivi del pannello (07 "Preventivi"): elenco, creazione (Idempotency-Key), dettaglio, invio
 * (email all'ospite o copia del link, con link e testo per WhatsApp) e ritiro. Ogni cambio di stato
 * passa dalla QuoteStateMachine. Preventivi di altri tenant: 404 (RLS).
 */
final class QuoteController extends Controller
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(QuoteStatus::class)],
            'unit_id' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ]);

        $query = Quote::query()->with(['unit', 'activeReservation'])->orderByDesc('created_at')->orderByDesc('id');

        if (isset($validated['status'])) {
            $query->whereIn('status', $validated['status']);
        }

        if (isset($validated['unit_id'])) {
            $query->where('unit_id', $validated['unit_id']);
        }

        $page = $query->cursorPaginate((int) ($validated['limit'] ?? 50));

        return new JsonResponse([
            'data' => collect($page->items())->map(fn (Quote $q): array => self::present($q))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit_id' => ['required', 'uuid'],
            'check_in' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before:2100-01-01'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in', 'before:2100-01-01'],
            'adults' => ['required', 'integer', 'between:1,50'],
            'children' => ['sometimes', 'integer', 'between:0,50'],
            'discount_cents' => ['sometimes', 'integer', 'between:0,100000000'],
            'guest_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'guest_email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:254'],
            'guest_locale' => ['sometimes', Rule::enum(Locale::class)],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now', 'before:+90 days'],
        ]);

        $stay = StayRange::of($validated['check_in'], $validated['check_out']);

        if ($stay->nights() > PriceQuoteRequest::MAX_NIGHTS) {
            return $this->tooLong();
        }

        $unit = Unit::query()->findOrFail((string) $validated['unit_id']);
        $email = isset($validated['guest_email']) ? mb_strtolower(trim((string) $validated['guest_email'])) : null;
        $name = isset($validated['guest_name']) && trim((string) $validated['guest_name']) !== '' ? trim((string) $validated['guest_name']) : null;

        $quote = $this->quotes->create(
            $unit, $stay, (int) $validated['adults'], (int) ($validated['children'] ?? 0), (int) ($validated['discount_cents'] ?? 0),
            $name, $email === '' ? null : $email, Locale::from((string) ($validated['guest_locale'] ?? Locale::IT->value)),
            isset($validated['expires_at']) ? CarbonImmutable::parse((string) $validated['expires_at'])->utc() : null,
        );

        return new JsonResponse(['data' => self::present($quote->load(['unit', 'activeReservation']))], 201);
    }

    public function show(string $quote): JsonResponse
    {
        return new JsonResponse(['data' => self::present(Quote::query()->with(['unit', 'activeReservation'])->findOrFail($quote))]);
    }

    public function send(Request $request, string $quote): JsonResponse
    {
        $validated = $request->validate(['channel' => ['required', Rule::in(['email', 'link'])]]);
        $result = $this->quotes->send($quote, (string) $validated['channel']);

        return new JsonResponse(['data' => [
            ...self::present($result['quote']->load(['unit', 'activeReservation'])),
            'link' => $result['link'],
            'whatsapp_text' => $result['whatsapp_text'],
            'whatsapp_url' => $result['whatsapp_url'],
        ]]);
    }

    public function revoke(Request $request, string $quote): JsonResponse
    {
        $revoked = $this->quotes->revoke($quote, $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => self::present($revoked->load(['unit', 'activeReservation']))]);
    }

    /** @return array<string, mixed> */
    public static function present(Quote $quote): array
    {
        $money = fn (int $cents): array => Money::json($cents, $quote->currency);
        $price = QuoteService::frozenPrice($quote);
        $reservation = $quote->activeReservation;
        $linkActive = in_array($quote->status, [QuoteStatus::SENT, QuoteStatus::IN_CHECKOUT], true);

        return [
            'id' => $quote->id,
            'status' => $quote->status->value,
            'unit' => ['id' => $quote->unit_id, 'name' => $quote->unit->name],
            'check_in' => $quote->stay->checkIn->toDateString(),
            'check_out' => $quote->stay->checkOut->toDateString(),
            'nights' => $quote->stay->nights(),
            'adults' => $quote->adults,
            'children' => $quote->children,
            'guest_name' => $quote->guest_name,
            'guest_email' => $quote->guest_email,
            'guest_locale' => $quote->guest_locale->value,
            'lines' => array_map(fn ($line): array => [
                'kind' => $line->kind->value, 'date' => $line->date, 'quantity' => $line->quantity,
                'unit_amount' => $money($line->unitAmountCents), 'amount' => $money($line->amountCents),
            ], $price->lines),
            'subtotal' => $money($quote->computed_total_cents),
            'discount' => $money($quote->discount_cents),
            'total' => $money($quote->final_total_cents),
            'deposit' => $money($price->depositCents),
            'balance' => $money($price->balanceCents),
            'booking_mode' => $quote->booking_mode_snapshot->value,
            'payment_mode' => $quote->payment_mode_snapshot->value,
            'expires_at' => $quote->expires_at->toIso8601ZuluString(),
            'expired' => in_array($quote->status, [QuoteStatus::DRAFT, QuoteStatus::SENT], true) && QuoteService::isPastExpiry($quote),
            'checkout_attempts' => $quote->checkout_attempts,
            'max_checkout_attempts' => $quote->max_checkout_attempts,
            'reservation' => $reservation instanceof Reservation ? ['id' => $reservation->id, 'code' => $reservation->code, 'status' => $reservation->status->value] : null,
            'link' => $linkActive ? QuoteLinks::url($quote) : null,
            'created_at' => $quote->created_at?->toIso8601ZuluString(),
        ];
    }

    private function tooLong(): JsonResponse
    {
        return ApiError::response(400, 'VALIDATION_FAILED', ['fields' => ['check_out' => [__('pricing.validation.stay_too_long', ['max' => PriceQuoteRequest::MAX_NIGHTS])]]]);
    }
}
