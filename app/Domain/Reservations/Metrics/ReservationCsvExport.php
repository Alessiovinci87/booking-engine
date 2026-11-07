<?php

namespace App\Domain\Reservations\Metrics;

use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Guest;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\ReservationEconomics;
use Carbon\CarbonImmutable;

/**
 * Export CSV delle prenotazioni con l'anagrafica dell'ospite, per uso gestionale (01 "Export", 07
 * GET /admin/exports/reservations.csv, 6.2). Non è un file ufficiale Alloggiati Web.
 *
 * - Prenotazioni con check-in tra `from` e `to` inclusi, esclusi i tentativi non conclusi (categoria D
 *   di 09: `AWAITING_PAYMENT`, `EXPIRED`, `PAYMENT_FAILED`, `REJECTED`).
 * - Separatore `;` e BOM UTF-8 (Excel in italiano); importi in euro con la virgola.
 * - Celle che iniziano con `=`, `+`, `-`, `@`, tab o a capo precedute da un apostrofo (formula
 *   injection, 08).
 * - Generato in memoria e restituito subito: nessun file salvato, quindi nessun export da cancellare
 *   all'anonimizzazione (09). Un ospite anonimizzato compare con i valori neutri.
 */
final class ReservationCsvExport
{
    private const EXCLUDED = [
        ReservationStatus::AWAITING_PAYMENT, ReservationStatus::EXPIRED, ReservationStatus::PAYMENT_FAILED, ReservationStatus::REJECTED,
    ];

    /** @var list<string> */
    public const COLUMNS = [
        'codice', 'stato', 'canale', 'struttura', 'unita', 'check_in', 'check_out', 'notti', 'adulti', 'bambini',
        'totale_eur', 'incassato_eur', 'rimborsato_eur', 'nome', 'cognome', 'email', 'telefono', 'paese', 'lingua',
        'note', 'confermata_il', 'cancellata_il',
    ];

    /**
     * @return array{csv: string, rows: int}
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $reservations = Reservation::query()
            ->whereNotIn('status', array_map(fn (ReservationStatus $s): string => $s->value, self::EXCLUDED))
            ->whereRaw('lower(stay) BETWEEN ?::date AND ?::date', [$from->toDateString(), $to->toDateString()])
            ->orderByRaw('lower(stay)')->orderBy('code')
            ->get();

        $guests = Guest::query()->whereIn('id', $reservations->pluck('guest_id'))->get()->keyBy('id');
        $units = Unit::query()->whereIn('id', $reservations->pluck('unit_id'))->get(['id', 'name', 'property_id'])->keyBy('id');
        $properties = Property::query()->whereIn('id', $units->pluck('property_id'))->pluck('name', 'id');

        // In memoria, senza stream (le funzioni di file e rete sono riservate, Arch/ExternalHttpTest).
        $csv = "\xEF\xBB\xBF".self::line(self::COLUMNS);

        foreach ($reservations as $reservation) {
            $guest = $guests->get($reservation->guest_id);
            $unit = $units->get($reservation->unit_id);
            $economics = ReservationEconomics::of($reservation);

            $csv .= self::line(array_map(self::cell(...), [
                $reservation->code,
                $reservation->status->value,
                $reservation->channel->value,
                $unit === null ? '' : (string) $properties->get($unit->property_id, ''),
                $unit->name ?? '',
                $reservation->stay->checkIn->toDateString(),
                $reservation->stay->checkOut->toDateString(),
                (string) $reservation->stay->nights(),
                (string) $reservation->adults,
                (string) $reservation->children,
                self::euros($reservation->contract_total_cents),
                self::euros($economics->collectedCents),
                self::euros($economics->refundedCents),
                $guest->first_name ?? '',
                $guest->last_name ?? '',
                $guest->email ?? '',
                $guest->phone ?? '',
                $guest->country_code ?? '',
                $reservation->guest_locale->value,
                $guest->notes ?? '',
                $reservation->confirmed_at?->toIso8601ZuluString() ?? '',
                $reservation->cancelled_at?->toIso8601ZuluString() ?? '',
            ]));
        }

        return ['csv' => $csv, 'rows' => $reservations->count()];
    }

    /**
     * Riga CSV con separatore `;`: campi tra virgolette se contengono separatore, virgolette o a capo.
     *
     * @param  list<string>  $fields
     */
    private static function line(array $fields): string
    {
        return implode(';', array_map(
            fn (string $field): string => strpbrk($field, ";\"\r\n") === false ? $field : '"'.str_replace('"', '""', $field).'"',
            $fields,
        ))."\n";
    }

    public static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true) ? "'".$value : $value;
    }

    private static function euros(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
