<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Models\TenantExport;
use App\Domain\Tenancy\Offboarding\OffboardingService;
use App\Http\Api\ApiError;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uscita del cliente nel pannello (7.1; 07 "Privacy": /admin/tenant/export, /admin/tenant/offboarding/*).
 * Avvio, export e conferme con la ri-autenticazione; il download dell'export usa un link firmato valido
 * fino alla scadenza dell'export (30 giorni) e chiede comunque la password confermata.
 */
final class TenantOffboardingController extends Controller
{
    public function __construct(private readonly OffboardingService $offboarding) {}

    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => [
            ...$this->offboarding->overview(CarbonImmutable::now()),
            'exports' => $this->offboarding->exports()->map(fn (TenantExport $export): array => self::exportData($export))->values()->all(),
        ]]);
    }

    public function start(Request $request): JsonResponse
    {
        $userId = self::userId($request);
        $this->offboarding->start($userId, $userId === null ? AuditActor::system() : AuditActor::user($userId));

        return new JsonResponse(['data' => $this->offboarding->overview(CarbonImmutable::now())]);
    }

    public function export(Request $request): JsonResponse
    {
        $export = $this->offboarding->requestExport(self::userId($request));

        return new JsonResponse(['data' => self::exportData($export)], 202);
    }

    public function exports(): JsonResponse
    {
        return new JsonResponse(['data' => $this->offboarding->exports()->map(fn (TenantExport $export): array => self::exportData($export))->values()->all()]);
    }

    public function download(string $export, FilesystemFactory $files, AuditLogger $audit): Response
    {
        $model = TenantExport::query()->findOrFail($export);

        if (! $model->isDownloadable() || $model->file_path === null) {
            return ApiError::response(410, 'EXPORT_EXPIRED');
        }

        $audit->record('tenant.export_downloaded', 'tenant_export', $model->id);

        return $files->disk((string) config('offboarding.disk'))->download(
            $model->file_path,
            'export-'.$model->completed_at?->format('Y-m-d').'.zip',
            ['Cache-Control' => 'no-store, private'],
        );
    }

    public function confirmRemoved(string $feed): JsonResponse
    {
        $this->offboarding->confirmFeedRemoved($feed);

        return new JsonResponse(['data' => $this->offboarding->overview(CarbonImmutable::now())]);
    }

    /** @return array<string, mixed> */
    private static function exportData(TenantExport $export): array
    {
        return [
            'id' => $export->id,
            'status' => $export->status->value,
            'size_bytes' => $export->size_bytes,
            'future_reservations' => $export->future_reservations,
            'requested_at' => $export->created_at?->toIso8601ZuluString(),
            'completed_at' => $export->completed_at?->toIso8601ZuluString(),
            'expires_at' => $export->expires_at?->toIso8601ZuluString(),
            // Percorso relativo all'API admin, firmato fino alla scadenza dell'export.
            'download_path' => $export->isDownloadable() && $export->expires_at !== null
                ? substr(URL::temporarySignedRoute('admin.tenant.exports.download', $export->expires_at, ['export' => $export->id], absolute: false), strlen('/api/v1/admin'))
                : null,
        ];
    }

    private static function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
