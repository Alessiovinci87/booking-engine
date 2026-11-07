<?php

namespace App\Domain\Tenancy\Offboarding;

use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Metrics\ReservationCsvExport;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Contenuto dell'export completo del tenant (7.1; 09 "Uscita del cliente" punto 2): prenotazioni, ospiti,
 * pagamenti, rimborsi, preventivi, strutture, unità e tariffe, in CSV (separatore `;`, BOM, protezione
 * dalle formule come l'export di 6.2) e in un unico `export.json`. Le **prenotazioni future** da riportare
 * nel nuovo sistema sono evidenziate: colonna `futura` nel CSV delle prenotazioni, campo `future` nel
 * JSON e un file `prenotazioni_future.csv` a parte.
 *
 * Si legge nel contesto del tenant (RLS). Mai token, hash o valori cifrati: le colonne sono esplicite per
 * le tabelle che ne hanno (SENSITIVE).
 */
final class TenantExportBuilder
{
    /** Tabelle esportate, nell'ordine dei file. */
    public const TABLES = [
        'properties', 'property_translations', 'units', 'unit_translations', 'cancellation_policies',
        'rate_plans', 'season_rules', 'guests', 'reservations', 'reservation_price_lines', 'reservation_changes',
        'payments', 'refunds', 'quotes',
    ];

    /** Colonne mai esportate: token, hash e valori cifrati (08). */
    private const SENSITIVE = ['public_access_token_hash', 'public_token_hash', 'public_token_encrypted', 'token_hash', 'token_encrypted', 'tenant_id'];

    /**
     * Crea lo ZIP in un file temporaneo e ne restituisce percorso e numero di prenotazioni future.
     *
     * @return array{path: string, future_reservations: int}
     */
    public function buildZip(Tenant $tenant, CarbonImmutable $now): array
    {
        $data = $this->collect($now);
        $path = tempnam(sys_get_temp_dir(), 'tenant-export-');

        if ($path === false) {
            throw new RuntimeException('File temporaneo non disponibile per l\'export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare l\'archivio dell\'export.');
        }

        $zip->addFromString('LEGGIMI.txt', implode("\n", array_values((array) __('tenancy.export.readme', [
            'tenant' => $tenant->name,
            'date' => $now->toIso8601ZuluString(),
            'future' => count($data['future']),
        ], 'it')))."\n");

        foreach ($data['tables'] as $table => $rows) {
            $zip->addFromString($table.'.csv', self::csv($rows));
        }

        $zip->addFromString('prenotazioni_future.csv', self::csv($data['future']));
        $zip->addFromString('export.json', (string) json_encode([
            'tenant' => ['name' => $tenant->name, 'slug' => $tenant->slug],
            'generated_at' => $now->toIso8601ZuluString(),
            'future_reservations' => $data['future'],
            'tables' => $data['tables'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $zip->close();

        return ['path' => $path, 'future_reservations' => count($data['future'])];
    }

    /**
     * @return array{tables: array<string, list<array<string, mixed>>>, future: list<array<string, mixed>>}
     */
    public function collect(CarbonImmutable $now): array
    {
        $tables = [];

        foreach (self::TABLES as $table) {
            $tables[$table] = array_values(array_map(
                fn (object $row): array => self::clean((array) $row),
                DB::table($table)->orderBy('created_at')->orderBy('id')->get()->all(),
            ));
        }

        $today = $now->startOfDay();
        $occupying = array_map(
            static fn (ReservationStatus $s): string => $s->value,
            array_values(array_filter(ReservationStatus::cases(), static fn (ReservationStatus $s): bool => $s->occupies())),
        );
        $guests = [];

        foreach ($tables['guests'] as $guest) {
            $guests[(string) $guest['id']] = $guest;
        }

        $units = [];

        foreach ($tables['units'] as $unit) {
            $units[(string) $unit['id']] = (string) ($unit['name'] ?? '');
        }

        $future = [];

        foreach ($tables['reservations'] as $index => $reservation) {
            $checkOut = self::stayBound((string) ($reservation['stay'] ?? ''), 1);
            $isFuture = in_array($reservation['status'] ?? null, $occupying, true) && $checkOut !== null && $checkOut->greaterThan($today);
            $tables['reservations'][$index] = ['futura' => $isFuture ? 'si' : 'no', ...$reservation];

            if (! $isFuture) {
                continue;
            }

            $guest = $guests[(string) ($reservation['guest_id'] ?? '')] ?? [];
            $future[] = [
                'codice' => $reservation['code'] ?? null,
                'stato' => $reservation['status'] ?? null,
                'canale' => $reservation['channel'] ?? null,
                'unita' => $units[(string) ($reservation['unit_id'] ?? '')] ?? null,
                'check_in' => self::stayBound((string) ($reservation['stay'] ?? ''), 0)?->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'adulti' => $reservation['adults'] ?? null,
                'bambini' => $reservation['children'] ?? null,
                'totale_centesimi' => $reservation['contract_total_cents'] ?? null,
                'nome' => $guest['first_name'] ?? null,
                'cognome' => $guest['last_name'] ?? null,
                'email' => $guest['email'] ?? null,
                'telefono' => $guest['phone'] ?? null,
                'future' => true,
            ];
        }

        return ['tables' => $tables, 'future' => $future];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function clean(array $row): array
    {
        foreach (self::SENSITIVE as $column) {
            unset($row[$column]);
        }

        foreach ($row as $key => $value) {
            if (is_string($value) && ($value === '' || in_array($value[0], ['{', '['], true)) && ! str_starts_with($value, '[2')) {
                $decoded = json_decode($value, true);
                $row[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            }
        }

        return $row;
    }

    /** Estremo di un intervallo `[AAAA-MM-GG,AAAA-MM-GG)` (0 = check-in, 1 = check-out). */
    private static function stayBound(string $stay, int $index): ?CarbonImmutable
    {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2}),(\d{4}-\d{2}-\d{2})\)$/', $stay, $m) !== 1) {
            return null;
        }

        return CarbonImmutable::parse($m[$index + 1])->startOfDay();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function csv(array $rows): string
    {
        $columns = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $columns[$key] = true;
            }
        }

        $columns = array_keys($columns);
        $csv = "\xEF\xBB\xBF".self::line($columns);

        foreach ($rows as $row) {
            $csv .= self::line(array_map(static function (string $column) use ($row): string {
                $value = $row[$column] ?? null;
                $text = match (true) {
                    $value === null => '',
                    is_bool($value) => $value ? 'true' : 'false',
                    is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    is_scalar($value) => (string) $value,
                    default => '',
                };

                return ReservationCsvExport::cell($text);
            }, $columns));
        }

        return $csv;
    }

    /** @param  list<string>  $fields */
    private static function line(array $fields): string
    {
        return implode(';', array_map(
            static fn (string $field): string => strpbrk($field, ";\"\r\n") === false ? $field : '"'.str_replace('"', '""', $field).'"',
            $fields,
        ))."\n";
    }
}
