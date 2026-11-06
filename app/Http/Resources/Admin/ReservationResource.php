<?php

namespace App\Http\Resources\Admin;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Models\Refund;
use App\Domain\Pricing\Money;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Models\ReservationChange;
use App\Domain\Reservations\Models\ReservationPriceLine;
use App\Domain\Reservations\ReservationEconomics;
use App\Domain\Tenancy\Models\AuditLog;

/**
 * Prenotazione nel pannello (07). `summary()` per l'elenco, `detail()` per il dettaglio con righe di
 * prezzo, pagamenti, rimborsi, storico delle modifiche e audit. Stato economico e stato del rimborso
 * sono derivati (02). Il pannello vede i dati dell'ospite: è il titolare del trattamento (09).
 */
final class ReservationResource
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Reservation $r): array
    {
        $economics = ReservationEconomics::of($r);
        $money = fn (int $cents): array => Money::json($cents, $r->currency);

        return [
            'id' => $r->id,
            'code' => $r->code,
            'status' => $r->status->value,
            'channel' => $r->channel->value,
            'confirmation_kind' => $r->confirmation_kind?->value,
            'unit' => ['id' => $r->unit_id, 'name' => $r->unit->name],
            'guest' => ['first_name' => $r->guest->first_name, 'last_name' => $r->guest->last_name],
            'check_in' => $r->stay->checkIn->toDateString(),
            'check_out' => $r->stay->checkOut->toDateString(),
            'nights' => $r->stay->nights(),
            'adults' => $r->adults,
            'children' => $r->children,
            'booking_mode' => $r->booking_mode_snapshot->value,
            'contract_total' => $money($r->contract_total_cents),
            'paid' => $money($economics->netPaidCents()),
            'economic_status' => $economics->status()->value,
            'refund_status' => $economics->refundStatus()->value,
            'approval_deadline' => $r->approval_deadline?->toIso8601ZuluString(),
            'decision_deadline' => $r->decision_deadline?->toIso8601ZuluString(),
            'recovery_deadline' => $r->recovery_deadline?->toIso8601ZuluString(),
            'created_at' => $r->created_at?->toIso8601ZuluString(),
            'version' => $r->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Reservation $r): array
    {
        $economics = ReservationEconomics::of($r);
        $money = fn (int $cents): array => Money::json($cents, $r->currency);
        $paymentIds = $economics->payments->map(fn (Payment $p): string => $p->id)->all();
        $events = PaymentEvent::query()->whereIn('payment_id', $paymentIds)->orderBy('occurred_at')->orderBy('id')->get()->groupBy('payment_id');

        return [
            ...self::summary($r),
            'guest' => [
                'first_name' => $r->guest->first_name,
                'last_name' => $r->guest->last_name,
                'email' => $r->guest->email,
                'phone' => $r->guest->phone,
                'country_code' => $r->guest->country_code,
                'locale' => $r->guest->locale->value,
                'notes' => $r->guest->notes,
                'anonymized' => $r->guest->anonymized_at !== null,
            ],
            'payment_mode' => $r->payment_mode_snapshot->value,
            'deposit_due' => $money($r->deposit_due_cents),
            'balance_due' => $money($r->balance_due_cents),
            'outstanding' => $money($economics->outstandingCents()),
            'collected' => $money($economics->collectedCents),
            'refunded' => $money($economics->refundedCents),
            'cancellation_policy' => $r->cancellation_policy_snapshot,
            'confirmed_at' => $r->confirmed_at?->toIso8601ZuluString(),
            'cancelled_at' => $r->cancelled_at?->toIso8601ZuluString(),
            'cancel_reason' => $r->cancel_reason,
            'price_lines' => $r->priceLines()->orderBy('position')->get()->map(fn (ReservationPriceLine $l): array => [
                'kind' => $l->kind->value,
                'date' => $l->night_date?->toDateString(),
                'quantity' => $l->quantity,
                'unit_amount' => $money($l->unit_amount_cents),
                'amount' => $money($l->amount_cents),
            ])->all(),
            'payments' => $economics->payments->map(fn (Payment $p): array => [
                'id' => $p->id,
                'purpose' => $p->purpose->value,
                'status' => $p->status->value,
                'amount' => $money($p->amount_cents),
                'captured' => $money($p->captured_cents),
                'capture_method' => $p->capture_method->value,
                'method_offline' => $p->method_offline?->value,
                'failure_code' => $p->failure_code,
                'created_at' => $p->created_at?->toIso8601ZuluString(),
                'events' => ($events->get($p->id) ?? collect())->map(fn (PaymentEvent $e): array => [
                    'type' => $e->type,
                    'amount' => $e->amount_cents === null ? null : $money($e->amount_cents),
                    'occurred_at' => $e->occurred_at->toIso8601ZuluString(),
                ])->values()->all(),
            ])->values()->all(),
            'refunds' => $economics->refunds->map(fn (Refund $f): array => [
                'id' => $f->id,
                'payment_id' => $f->payment_id,
                'status' => $f->status->value,
                'method' => $f->method->value,
                'amount' => $money($f->amount_cents),
                'attempts' => $f->attempts,
                'next_attempt_at' => $f->next_attempt_at?->toIso8601ZuluString(),
                'completed_at' => $f->completed_at?->toIso8601ZuluString(),
                'created_at' => $f->created_at?->toIso8601ZuluString(),
            ])->values()->all(),
            'changes' => $r->changes()->orderBy('occurred_at')->orderBy('id')->get()->map(fn (ReservationChange $c): array => [
                'previous' => ['check_in' => $c->previous_stay->checkIn->toDateString(), 'check_out' => $c->previous_stay->checkOut->toDateString(),
                    'adults' => $c->previous_adults, 'children' => $c->previous_children, 'contract_total' => $money($c->previous_contract_total_cents)],
                'new' => ['check_in' => $c->new_stay->checkIn->toDateString(), 'check_out' => $c->new_stay->checkOut->toDateString(),
                    'adults' => $c->new_adults, 'children' => $c->new_children, 'contract_total' => $money($c->new_contract_total_cents)],
                'note' => $c->note,
                'changed_by_user_id' => $c->changed_by_user_id,
                'occurred_at' => $c->occurred_at->toIso8601ZuluString(),
            ])->all(),
            'history' => AuditLog::query()
                ->where(function ($q) use ($r, $paymentIds): void {
                    $q->where(fn ($s) => $s->where('subject_type', 'reservation')->where('subject_id', $r->id))
                        ->orWhere(fn ($s) => $s->where('subject_type', 'payment')->whereIn('subject_id', $paymentIds));
                })
                ->orderBy('occurred_at')->orderBy('id')->get()
                ->map(fn (AuditLog $a): array => [
                    'action' => $a->action,
                    'actor_type' => $a->actor_type->value,
                    'actor_id' => $a->actor_id,
                    'changes' => $a->changes,
                    'occurred_at' => $a->occurred_at->toIso8601ZuluString(),
                ])->all(),
        ];
    }
}
