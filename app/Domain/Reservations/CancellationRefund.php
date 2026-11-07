<?php

namespace App\Domain\Reservations;

use App\Domain\Payments\Enums\PaymentPurpose;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Reservations\Enums\CancellationPolicyCode;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Rimborso di una cancellazione (05, "Cancellazioni e rimborsi"), calcolato con lo snapshot della
 * politica: FLEXIBLE_7D 100% dell'incassato online fino a 7 giorni prima delle 00:00 del giorno di
 * arrivo (fuso della struttura), poi 0%; NON_REFUNDABLE 0%. Il gestore può aumentarlo fino al 100%
 * dell'incassato online, mai oltre. I pagamenti OFFLINE non si rimborsano dal fornitore.
 */
final readonly class CancellationRefund
{
    private function __construct(public int $amountCents) {}

    /** Importo scelto dal gestore (null = quello proposto dalla politica). */
    public static function for(Reservation $reservation, ?int $requestedCents, ?CarbonImmutable $now = null): self
    {
        $refundable = self::refundableOnline($reservation);
        $amount = $requestedCents ?? self::proposed($reservation, $refundable, $now ?? CarbonImmutable::now());

        if ($amount < 0 || $amount > $refundable) {
            throw ValidationException::withMessages(['refund_cents' => [__('reservations.validation.refund_too_high', ['max' => $refundable])]]);
        }

        return new self($amount);
    }

    public static function proposed(Reservation $reservation, int $refundable, CarbonImmutable $now): int
    {
        $snapshot = $reservation->cancellation_policy_snapshot;
        $code = CancellationPolicyCode::tryFrom((string) ($snapshot['code'] ?? ''));

        if ($code !== CancellationPolicyCode::FLEXIBLE_7D) {
            return 0;
        }

        $timezone = is_string($snapshot['timezone'] ?? null) ? $snapshot['timezone'] : 'Europe/Rome';
        $limit = CarbonImmutable::parse($reservation->stay->checkIn->toDateString(), $timezone)->subDays(7);

        return $now->lessThanOrEqualTo($limit) ? $refundable : 0;
    }

    /** Incassato online e non ancora rimborsato (né in rimborso). */
    public static function refundableOnline(Reservation $reservation): int
    {
        return array_sum(self::remainingByPayment($reservation));
    }

    /**
     * Ripartizione sui pagamenti online, dal più recente.
     *
     * @return array<string, int> payment_id → importo
     */
    public function allocate(Reservation $reservation): array
    {
        $left = $this->amountCents;
        $allocation = [];

        foreach (self::remainingByPayment($reservation) as $paymentId => $remaining) {
            if ($left === 0) {
                break;
            }

            $amount = min($left, $remaining);

            if ($amount > 0) {
                $allocation[$paymentId] = $amount;
                $left -= $amount;
            }
        }

        if ($left !== 0) {
            throw BusinessRuleViolation::make(422, 'VALIDATION_FAILED', ['fields' => ['refund_cents' => ['too_high']]]);
        }

        return $allocation;
    }

    /** @return array<string, int> */
    private static function remainingByPayment(Reservation $reservation): array
    {
        $payments = Payment::query()
            ->where('reservation_id', $reservation->id)
            ->where('purpose', '!=', PaymentPurpose::OFFLINE->value)
            ->whereIn('status', [PaymentStatus::SUCCEEDED->value, PaymentStatus::PARTIALLY_REFUNDED->value])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();
        $result = [];

        foreach ($payments as $payment) {
            $refunded = (int) Refund::query()
                ->where('payment_id', $payment->id)
                ->whereIn('status', [RefundStatus::PENDING->value, RefundStatus::SUCCEEDED->value])
                ->sum('amount_cents');
            $result[$payment->id] = max(0, $payment->captured_cents - $refunded);
        }

        return $result;
    }
}
