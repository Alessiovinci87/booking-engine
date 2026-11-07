<?php

namespace App\Domain\Reservations;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Reservations\Enums\EconomicStatus;
use App\Domain\Reservations\Enums\RefundSummaryStatus;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Support\Collection;

/**
 * Stato economico e stato del rimborso di una prenotazione (02), derivati da Payment e Refund e mai
 * memorizzati:
 *
 * - incassato = somma di captured_cents dei pagamenti riusciti (online e OFFLINE, anche se poi
 *   rimborsati); rimborsato = somma dei Refund SUCCEEDED;
 * - REFUNDED se tutto l'incassato è stato rimborsato, PARTIALLY_REFUNDED se una parte; altrimenti PAID
 *   se il netto copre il totale contrattuale, DEPOSIT_PAID se ne copre una parte, AUTHORIZED con
 *   un'autorizzazione in corso, UNPAID altrimenti;
 * - refund_status dai Refund non annullati: NONE, PENDING (almeno uno in corso), SUCCEEDED (tutti
 *   riusciti), FAILED (tutti falliti), PARTIALLY_FAILED (riusciti e falliti).
 */
final readonly class ReservationEconomics
{
    /**
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, Refund>  $refunds
     */
    private function __construct(
        public int $contractTotalCents,
        public int $collectedCents,
        public int $refundedCents,
        public int $authorizedCents,
        public Collection $payments,
        public Collection $refunds,
    ) {}

    /**
     * Legge pagamenti e rimborsi della prenotazione (sotto il contesto tenant); usa la relazione
     * `payments.refunds` se già caricata (elenchi senza N+1).
     */
    public static function of(Reservation $reservation): self
    {
        $payments = $reservation->relationLoaded('payments')
            ? $reservation->payments->sortBy(fn (Payment $p): string => $p->created_at?->toIso8601String().$p->id)->values()
            : Payment::query()->where('reservation_id', $reservation->id)->with('refunds')->orderBy('created_at')->orderBy('id')->get();
        $refunds = $payments->flatMap(fn (Payment $p) => $p->refunds)->sortBy(fn (Refund $r): string => $r->created_at?->toIso8601String().$r->id)->values();

        $collected = (int) $payments->filter(fn (Payment $p): bool => in_array($p->status, [PaymentStatus::SUCCEEDED, PaymentStatus::PARTIALLY_REFUNDED, PaymentStatus::REFUNDED], true))
            ->sum(fn (Payment $p): int => $p->captured_cents);
        $refunded = (int) $refunds->filter(fn (Refund $r): bool => $r->status === RefundStatus::SUCCEEDED)->sum(fn (Refund $r): int => $r->amount_cents);
        $authorized = (int) $payments->filter(fn (Payment $p): bool => in_array($p->status, [PaymentStatus::AUTHORIZED, PaymentStatus::CAPTURE_PENDING], true))
            ->sum(fn (Payment $p): int => $p->amount_cents);

        return new self($reservation->contract_total_cents, $collected, $refunded, $authorized, $payments, $refunds);
    }

    public function netPaidCents(): int
    {
        return $this->collectedCents - $this->refundedCents;
    }

    /** Quanto resta da incassare rispetto al totale contrattuale (mai negativo). */
    public function outstandingCents(): int
    {
        return max(0, $this->contractTotalCents - $this->netPaidCents());
    }

    public function status(): EconomicStatus
    {
        return match (true) {
            $this->collectedCents > 0 && $this->refundedCents >= $this->collectedCents => EconomicStatus::REFUNDED,
            $this->refundedCents > 0 => EconomicStatus::PARTIALLY_REFUNDED,
            $this->collectedCents > 0 && $this->netPaidCents() >= $this->contractTotalCents => EconomicStatus::PAID,
            $this->collectedCents > 0 => EconomicStatus::DEPOSIT_PAID,
            $this->authorizedCents > 0 => EconomicStatus::AUTHORIZED,
            default => EconomicStatus::UNPAID,
        };
    }

    public function refundStatus(): RefundSummaryStatus
    {
        $statuses = $this->refunds->reject(fn (Refund $r): bool => $r->status === RefundStatus::CANCELLED)
            ->map(fn (Refund $r): RefundStatus => $r->status);

        return match (true) {
            $statuses->isEmpty() => RefundSummaryStatus::NONE,
            $statuses->contains(RefundStatus::PENDING) => RefundSummaryStatus::PENDING,
            $statuses->every(fn (RefundStatus $s): bool => $s === RefundStatus::SUCCEEDED) => RefundSummaryStatus::SUCCEEDED,
            $statuses->every(fn (RefundStatus $s): bool => $s === RefundStatus::FAILED) => RefundSummaryStatus::FAILED,
            default => RefundSummaryStatus::PARTIALLY_FAILED,
        };
    }
}
