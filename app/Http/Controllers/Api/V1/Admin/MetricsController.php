<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reservations\Metrics\MetricsService;
use App\Domain\Reservations\Metrics\ReservationCsvExport;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Metriche ed export CSV (07 "Pagamenti e account", 6.2):
 * - GET /admin/metrics?from=&to= → fatturato diretto, commissioni OTA evitate, beneficio diretto
 *   stimato, con la percentuale usata per struttura (01);
 * - GET /admin/exports/reservations.csv?from=&to= → prenotazioni e anagrafica, registrato in audit
 *   senza dati personali (08 "export di dati").
 * Periodo predefinito: mese corrente; al massimo 366 giorni; date di check-in incluse.
 */
final class MetricsController extends Controller
{
    public function metrics(Request $request, MetricsService $metrics): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return new JsonResponse(['data' => $metrics->forPeriod($from, $to)->toArray()]);
    }

    public function reservationsCsv(Request $request, ReservationCsvExport $export, AuditLogger $audit): Response
    {
        [$from, $to] = $this->period($request);
        $result = $export->build($from, $to);

        $audit->record('export.reservations_csv', 'tenant', null, [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $result['rows'],
        ]);

        return new Response($result['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="prenotazioni-%s-%s.csv"', $from->toDateString(), $to->toDateString()),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'before:2100-01-01'],
        ]);

        $from = isset($validated['from']) ? CarbonImmutable::parse((string) $validated['from']) : CarbonImmutable::now()->startOfMonth();
        $to = isset($validated['to']) ? CarbonImmutable::parse((string) $validated['to']) : $from->endOfMonth()->startOfDay();

        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['to' => __('validation.after_or_equal', ['attribute' => 'to', 'date' => 'from'])]);
        }

        if ($from->diffInDays($to) >= MetricsService::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('validation.max.numeric', ['attribute' => 'to', 'max' => MetricsService::MAX_DAYS])]);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }
}
