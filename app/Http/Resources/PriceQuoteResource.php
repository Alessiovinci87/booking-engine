<?php

namespace App\Http\Resources;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Pricing\Calculator\PriceLine;
use App\Domain\Pricing\Calculator\PriceQuote;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Pricing\Money;
use App\Domain\Reservations\Models\CancellationPolicy;
use App\Domain\Tenancy\Enums\Locale;

/**
 * Risposta del calcolo prezzi (07), uguale per anteprima del pannello e pagina ospite: righe, totale,
 * caparra, saldo come restituiti dal PriceCalculator, modalità di prenotazione e politica di
 * cancellazione nella lingua richiesta. Il frontend mostra questi valori e non ricalcola nulla.
 */
final class PriceQuoteResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Unit $unit, PriceRequest $request, PriceQuote $quote, ?CancellationPolicy $policy, Locale $locale): array
    {
        $money = fn (int $cents): array => Money::json($cents, $quote->currency);

        return [
            'unit_id' => $unit->id,
            'check_in' => $request->stay->checkIn->toDateString(),
            'check_out' => $request->stay->checkOut->toDateString(),
            'nights' => $quote->nights,
            'adults' => $request->adults,
            'children' => $request->children,
            'guests' => $quote->guests,
            'required_min_nights' => $quote->requiredMinNights,
            'lines' => array_map(fn (PriceLine $line): array => [
                'kind' => $line->kind->value,
                'date' => $line->date,
                'quantity' => $line->quantity,
                'unit_amount' => $money($line->unitAmountCents),
                'amount' => $money($line->amountCents),
            ], $quote->lines),
            'subtotal' => $money($quote->subtotalCents),
            'discount' => $money($quote->discountCents),
            'total' => $money($quote->totalCents),
            'deposit' => $money($quote->depositCents),
            'balance' => $money($quote->balanceCents),
            'payment_mode' => $quote->paymentMode->value,
            'booking_mode' => $unit->booking_mode->value,
            'cancellation_policy' => $policy === null ? null : [
                'code' => $policy->code->value,
                'title' => $policy->title($locale),
                'text' => $policy->text($locale),
            ],
        ];
    }
}
