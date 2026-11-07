<?php

namespace App\Domain\Quotes;

use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Inventory\AvailabilityService;
use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Enums\PaymentPurpose;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\StripeAccount;
use App\Domain\Pricing\Calculator\PriceCalculator;
use App\Domain\Pricing\Calculator\PriceLine;
use App\Domain\Pricing\Calculator\PriceQuote;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Pricing\Enums\PriceLineKind;
use App\Domain\Pricing\PriceQuoteService;
use App\Domain\Quotes\Enums\QuoteStatus;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\StateMachines\QuoteStateMachine;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\GuestDetails;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\NewReservation;
use App\Domain\Reservations\StateMachines\ReservationStateMachine;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Outbox\Outbox;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Preventivi (01 AC8 e AC16, 03 sezione 4, 04 "Preventivi", 07; 5.7).
 *
 * - creazione: prezzo calcolato una volta dal PriceCalculator con lo sconto esplicito e congelato in
 *   price_lines e final_total_cents (I14); modalità di prenotazione e pagamento copiate dall'unità;
 *   scadenza predefinita 48 h;
 * - invio: email in outbox o semplice copia del link; in entrambi i casi il pannello riceve link e
 *   testo per WhatsApp;
 * - accettazione dell'ospite: riga del preventivo bloccata, scadenza, tentativi e disponibilità
 *   verificati, nessun ricalcolo del prezzo; al massimo un tentativo attivo (I21): un secondo accept
 *   durante il checkout restituisce la stessa prenotazione e la stessa sessione di pagamento.
 */
final class QuoteService
{
    public const DEFAULT_VALIDITY_HOURS = 48;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly QuoteStateMachine $machine,
        private readonly ReservationStateMachine $reservations,
        private readonly PriceQuoteService $prices,
        private readonly AvailabilityService $availability,
        private readonly CheckoutService $checkout,
        private readonly Outbox $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /** Nel contesto tenant della richiesta del pannello. */
    public function create(Unit $unit, StayRange $stay, int $adults, int $children, int $discountCents, ?string $guestName, ?string $guestEmail, Locale $locale, ?CarbonImmutable $expiresAt): Quote
    {
        $this->availability->assertAvailable($unit, $stay);
        $price = $this->prices->quote($unit, new PriceRequest($stay, $adults, $children, $discountCents, true));
        $id = (string) Str::uuid7();

        return $this->machine->create([
            'id' => $id,
            'unit_id' => $unit->id,
            'stay' => $stay,
            'adults' => $adults,
            'children' => $children,
            'guest_name' => $guestName,
            'guest_email' => $guestEmail,
            'guest_locale' => $locale,
            'computed_total_cents' => $price->subtotalCents,
            'discount_cents' => $price->discountCents,
            'final_total_cents' => $price->totalCents,
            'currency' => $price->currency,
            'price_lines' => array_map(fn (PriceLine $line): array => $line->toArray(), $price->lines),
            'booking_mode_snapshot' => $unit->booking_mode,
            'payment_mode_snapshot' => $unit->payment_mode,
            'expires_at' => $expiresAt ?? CarbonImmutable::now()->addHours(self::DEFAULT_VALIDITY_HOURS),
            'public_token_hash' => QuoteLinks::hash(QuoteLinks::token($id)),
        ]);
    }

    /**
     * Invio (`email`) o copia del link (`link`, anche per WhatsApp): DRAFT → SENT.
     *
     * @return array{quote: Quote, link: string, whatsapp_text: string, whatsapp_url: string}
     */
    public function send(string $quoteId, string $channel): array
    {
        $quote = Quote::query()->with('unit.property')->lockForUpdate()->findOrFail($quoteId);

        if (in_array($quote->status, [QuoteStatus::DRAFT, QuoteStatus::SENT], true) && self::isPastExpiry($quote)) {
            throw BusinessRuleViolation::make(410, 'QUOTE_EXPIRED');
        }

        self::assertUsable($quote);

        if ($channel === 'email' && ($quote->guest_email === null || $quote->guest_email === '')) {
            throw BusinessRuleViolation::make(422, 'QUOTE_EMAIL_MISSING');
        }

        $this->machine->send($quote, $channel);

        if ($channel === 'email') {
            // Ogni invio è un'email: la chiave di deduplica distingue gli invii.
            $this->outbox->enqueue('quote.sent', 'quote.sent:'.$quote->id.':'.Str::uuid7(), ['quote_id' => $quote->id]);
        }

        $link = QuoteLinks::url($quote->id);
        $text = self::whatsappText($quote, $link);

        return ['quote' => $quote, 'link' => $link, 'whatsapp_text' => $text, 'whatsapp_url' => 'https://wa.me/?text='.rawurlencode($text)];
    }

    public function revoke(string $quoteId, ?string $userId): Quote
    {
        $quote = Quote::query()->lockForUpdate()->findOrFail($quoteId);
        $this->machine->revoke($quote, $userId);

        return $quote;
    }

    /** Preventivi DRAFT e SENT scaduti del tenant corrente → EXPIRED (job orario). */
    public function expireDue(CarbonImmutable $now): int
    {
        $quotes = Quote::query()->whereIn('status', [QuoteStatus::DRAFT->value, QuoteStatus::SENT->value])
            ->where('expires_at', '<=', $now)->lockForUpdate()->get();

        foreach ($quotes as $quote) {
            $this->machine->expire($quote);
        }

        return $quotes->count();
    }

    /**
     * Preventivo per la pagina ospite: 404 se il link non è ancora stato inviato, 410 se scaduto,
     * ritirato o esaurito. ACCEPTED e IN_CHECKOUT restano leggibili (la pagina mostra lo stato).
     */
    public function forGuest(string $tokenHash): Quote
    {
        $quote = Quote::query()->with(['unit.translations', 'unit.property.translations'])->where('public_token_hash', $tokenHash)->first();

        if ($quote === null || $quote->status === QuoteStatus::DRAFT) {
            throw BusinessRuleViolation::make(404, 'NOT_FOUND');
        }

        if ($quote->status === QuoteStatus::SENT && self::isPastExpiry($quote)) {
            throw BusinessRuleViolation::make(410, 'QUOTE_EXPIRED');
        }

        self::assertUsable($quote, allowFinal: true);

        return $quote;
    }

    /**
     * POST /public/quotes/{token}/accept con la sequenza in tre passi di 05: T1 nella transazione con
     * la riga del preventivo bloccata, sessione di pagamento fuori, allineamento delle scadenze.
     *
     * @param  array{terms: string|null, privacy: string|null}  $accepted
     * @return array{repeat: bool, reservation: Reservation, access_token: string|null, checkout_url: string, checkout_expires_at: CarbonImmutable}
     */
    public function accept(string $tenantId, string $tokenHash, GuestDetails $guest, array $accepted): array
    {
        /** @var array{kind: string, reservation?: Reservation, payment?: string, token?: string|null} $step */
        $step = $this->tenants->run($tenantId, function () use ($tokenHash, $guest): array {
            $quote = Quote::query()->where('public_token_hash', $tokenHash)->lockForUpdate()->first();

            if ($quote === null || $quote->status === QuoteStatus::DRAFT) {
                throw BusinessRuleViolation::make(404, 'NOT_FOUND');
            }

            if ($quote->status === QuoteStatus::IN_CHECKOUT) {
                return $this->activeAttempt($quote);
            }

            self::assertUsable($quote);

            // I8: la scadenza si verifica all'avvio del checkout; lo stato EXPIRED si conferma subito.
            if (self::isPastExpiry($quote)) {
                $this->machine->expire($quote);

                return ['kind' => 'expired'];
            }

            $unit = Unit::query()->with('property')->findOrFail($quote->unit_id);

            if ($quote->adults + $quote->children > $unit->max_occupancy) {
                throw BusinessRuleViolation::make(409, 'OCCUPANCY_EXCEEDED', ['max_occupancy' => $unit->max_occupancy]);
            }

            $this->availability->assertBookable($unit);
            $this->availability->assertAvailable($unit, $quote->stay);
            $account = StripeAccount::query()->first();

            if ($account === null || ! $account->charges_enabled) {
                throw BusinessRuleViolation::make(422, 'PAYMENTS_NOT_ENABLED');
            }

            $created = $this->reservations->startCheckout(new NewReservation(
                $unit, $quote->stay, $quote->adults, $quote->children, $guest, ReservationChannel::QUOTE, self::frozenPrice($quote),
                $quote->id, $this->bookingMode($quote), $quote->payment_mode_snapshot,
            ), $account->stripe_account_id);

            $this->machine->startCheckout($quote, $created->reservation);
            $payment = $created->payment ?? throw new \LogicException('Checkout senza pagamento.');

            return ['kind' => 'new', 'reservation' => $created->reservation, 'payment' => $payment->id, 'token' => $created->accessToken];
        });

        if ($step['kind'] === 'expired') {
            throw BusinessRuleViolation::make(410, 'QUOTE_EXPIRED');
        }

        $reservation = $step['reservation'] ?? throw new \LogicException('Tentativo senza prenotazione.');
        $session = $this->checkout->openSession($tenantId, (string) ($step['payment'] ?? ''));
        $repeat = $step['kind'] === 'repeat';

        if (! $repeat) {
            $this->tenants->run($tenantId, fn () => $this->audit->record('reservation.terms_accepted', 'reservation', $reservation->id, [
                'terms_version' => $accepted['terms'], 'privacy_version' => $accepted['privacy'], 'quote_id' => $reservation->quote_id,
            ]));
        }

        return [
            'repeat' => $repeat,
            'reservation' => $reservation,
            'access_token' => $repeat ? null : ($step['token'] ?? null),
            'checkout_url' => $session->url,
            'checkout_expires_at' => $session->expiresAt,
        ];
    }

    /**
     * Prezzo congelato del preventivo come PriceQuote (04 "Preventivi"): righe e totale del preventivo,
     * caparra e saldo dal totale congelato con la modalità di pagamento congelata.
     */
    public static function frozenPrice(Quote $quote): PriceQuote
    {
        $lines = array_map(fn (array $line): PriceLine => new PriceLine(
            PriceLineKind::from((string) $line['kind']),
            isset($line['date']) ? (string) $line['date'] : null,
            (int) $line['quantity'],
            (int) $line['unit_amount_cents'],
            (int) $line['amount_cents'],
            isset($line['season_id']) ? (string) $line['season_id'] : null,
        ), $quote->price_lines);

        [$deposit, $balance] = PriceCalculator::split($quote->final_total_cents, $quote->payment_mode_snapshot);

        return new PriceQuote(
            lines: $lines,
            nights: $quote->stay->nights(),
            guests: $quote->adults + $quote->children,
            requiredMinNights: 0,
            subtotalCents: $quote->computed_total_cents,
            discountCents: $quote->discount_cents,
            totalCents: $quote->final_total_cents,
            depositCents: $deposit,
            balanceCents: $balance,
            paymentMode: $quote->payment_mode_snapshot,
            currency: $quote->currency,
        );
    }

    /** Modalità congelata; se l'unità ha nel frattempo un calendario esterno attivo prevale REQUEST (I12). */
    public function bookingMode(Quote $quote): BookingMode
    {
        $external = ExternalCalendar::query()->where('unit_id', $quote->unit_id)
            ->where('status', '<>', ExternalCalendarStatus::DISABLED->value)->exists();

        return $external ? BookingMode::REQUEST : $quote->booking_mode_snapshot;
    }

    public static function isPastExpiry(Quote $quote): bool
    {
        return CarbonImmutable::now()->greaterThanOrEqualTo($quote->expires_at);
    }

    public static function whatsappText(Quote $quote, string $link): string
    {
        $locale = $quote->guest_locale->value;

        return (string) __('quotes.whatsapp_text', [
            'property' => $quote->unit->property->name,
            'check_in' => $quote->stay->checkIn->toDateString(),
            'check_out' => $quote->stay->checkOut->toDateString(),
            'link' => $link,
        ], $locale);
    }

    /**
     * I21: con un tentativo attivo ancora valido si restituiscono la stessa prenotazione e lo stesso
     * pagamento; se il tentativo è scaduto ma non ancora rilasciato dal job, 409.
     *
     * @return array{kind: string, reservation: Reservation, payment: string, token: null}
     */
    private function activeAttempt(Quote $quote): array
    {
        $reservation = Reservation::query()->find($quote->active_reservation_id);
        $payment = $reservation === null ? null : Payment::query()->where('reservation_id', $reservation->id)
            ->where('purpose', '!=', PaymentPurpose::OFFLINE->value)
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        $valid = $reservation !== null && $payment !== null
            && $reservation->status === ReservationStatus::AWAITING_PAYMENT
            && $payment->status === PaymentStatus::PENDING
            && ($payment->checkout_expires_at === null || $payment->checkout_expires_at->isFuture());

        if (! $valid) {
            throw BusinessRuleViolation::make(409, 'QUOTE_CHECKOUT_IN_PROGRESS');
        }

        return ['kind' => 'repeat', 'reservation' => $reservation, 'payment' => $payment->id, 'token' => null];
    }

    private static function assertUsable(Quote $quote, bool $allowFinal = false): void
    {
        match ($quote->status) {
            QuoteStatus::EXPIRED => throw BusinessRuleViolation::make(410, 'QUOTE_EXPIRED'),
            QuoteStatus::REVOKED => throw BusinessRuleViolation::make(410, 'QUOTE_REVOKED'),
            QuoteStatus::EXHAUSTED => throw BusinessRuleViolation::make(410, 'QUOTE_EXHAUSTED'),
            QuoteStatus::ACCEPTED, QuoteStatus::IN_CHECKOUT => $allowFinal ? null : throw BusinessRuleViolation::make(409, 'QUOTE_ALREADY_ACCEPTED'),
            default => null,
        };
    }
}
