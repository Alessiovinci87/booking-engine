<?php

namespace App\Domain\Reservations\Metrics;

use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Payments\Enums\PaymentPurpose;
use App\Domain\Payments\Models\Payment;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;

/**
 * Metriche del pannello (01 "Metriche del pannello", 07 GET /admin/metrics, 6.2, ADR-0014):
 *
 *     fatturato_diretto         = Σ importi contrattuali delle prenotazioni dirette confermate
 *     commissioni_ota_evitate   = Σ fatturato × ota_commission_bps della struttura
 *     beneficio_diretto_stimato = commissioni_ota_evitate − commissioni_pagamento − sconti_diretti
 *
 * - Periodo: prenotazioni `CONFIRMED` con check-in tra `from` e `to` inclusi (data della struttura).
 *   Tutte le prenotazioni del sistema sono dirette (pagina ospite, preventivo, manuale).
 * - Commissioni di pagamento: `payments.fee_cents` quando è registrato; altrimenti stima con la tariffa
 *   di config/payments.php (`estimated_fee`), segnalata con `payment_fees_estimated`.
 * - Sconti diretti: `discount_cents` dei preventivi da cui nasce la prenotazione.
 *
 * Esegue nel contesto del tenant (RLS). Importi in centesimi interi.
 */
final class MetricsService
{
    public const MAX_DAYS = 366;

    public function forPeriod(CarbonImmutable $from, CarbonImmutable $to): MetricsReport
    {
        $reservations = Reservation::query()
            ->where('status', ReservationStatus::CONFIRMED->value)
            ->whereRaw('lower(stay) BETWEEN ?::date AND ?::date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'unit_id', 'quote_id', 'stay', 'contract_total_cents']);

        $units = Unit::query()->whereIn('id', $reservations->pluck('unit_id')->unique())->pluck('property_id', 'id');
        $properties = Property::query()->orderBy('name')->get(['id', 'name', 'ota_commission_bps'])->keyBy('id');
        $discounts = Quote::query()->whereIn('id', $reservations->pluck('quote_id')->filter()->unique())->pluck('discount_cents', 'id');
        $payments = Payment::query()
            ->whereIn('reservation_id', $reservations->pluck('id'))
            ->where('purpose', '!=', PaymentPurpose::OFFLINE->value)
            ->where('captured_cents', '>', 0)
            ->get(['reservation_id', 'captured_cents', 'fee_cents']);

        $feeBps = (int) config('payments.estimated_fee.bps', 150);
        $feeFixed = (int) config('payments.estimated_fee.fixed_cents', 25);

        $revenue = $avoided = $nights = $discountTotal = $fees = 0;
        $estimated = false;
        $byProperty = [];

        foreach ($reservations as $reservation) {
            $property = $properties->get($units->get($reservation->unit_id));
            $bps = $property->ota_commission_bps ?? 0;
            $commission = intdiv($reservation->contract_total_cents * $bps + 5000, 10000);

            $revenue += $reservation->contract_total_cents;
            $avoided += $commission;
            $nights += $reservation->stay->nights();
            $discountTotal += $reservation->quote_id === null ? 0 : (int) $discounts->get($reservation->quote_id, 0);

            if ($property !== null) {
                $byProperty[$property->id] ??= ['property_id' => $property->id, 'name' => $property->name, 'ota_commission_bps' => $bps, 'reservations' => 0, 'direct_revenue_cents' => 0, 'ota_commission_avoided_cents' => 0];
                $byProperty[$property->id]['reservations']++;
                $byProperty[$property->id]['direct_revenue_cents'] += $reservation->contract_total_cents;
                $byProperty[$property->id]['ota_commission_avoided_cents'] += $commission;
            }
        }

        foreach ($payments as $payment) {
            if ($payment->fee_cents > 0) {
                $fees += $payment->fee_cents;
            } else {
                $fees += intdiv($payment->captured_cents * $feeBps + 5000, 10000) + $feeFixed;
                $estimated = true;
            }
        }

        // Le strutture senza prenotazioni nel periodo mostrano comunque la percentuale usata (01).
        foreach ($properties as $property) {
            $byProperty[$property->id] ??= ['property_id' => $property->id, 'name' => $property->name, 'ota_commission_bps' => $property->ota_commission_bps, 'reservations' => 0, 'direct_revenue_cents' => 0, 'ota_commission_avoided_cents' => 0];
        }

        return new MetricsReport(
            from: $from,
            to: $to,
            reservations: $reservations->count(),
            nights: $nights,
            directRevenueCents: $revenue,
            otaCommissionAvoidedCents: $avoided,
            paymentFeesCents: $fees,
            paymentFeesEstimated: $estimated,
            directDiscountsCents: $discountTotal,
            properties: array_values($byProperty),
        );
    }
}
