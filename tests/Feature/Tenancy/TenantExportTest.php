<?php

use App\Domain\Privacy\GuestPrivacyService;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Enums\TenantExportStatus;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\Models\TenantExport;
use App\Domain\Tenancy\Offboarding\OffboardingService;
use Illuminate\Support\Facades\Storage;

/*
 * Export completo del tenant (7.1; 09 "Uscita del cliente" punto 2, 08 "export completo"): asincrono,
 * CSV e JSON con le prenotazioni future evidenziate, download firmato valido 30 giorni e con la password
 * confermata, cancellato alla scadenza e all'anonimizzazione di un ospite che contiene (I22).
 */

beforeEach(function () {
    Storage::fake('local');
    config(['offboarding.disk' => 'local']);
});

/** Contenuto dello ZIP salvato per l'export: nome file → testo. */
function exportFiles(TenantExport $export): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'export-test-');
    file_put_contents($tmp, Storage::disk('local')->get((string) $export->file_path));
    $zip = new ZipArchive;
    $zip->open($tmp);
    $files = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    unlink($tmp);

    return $files;
}

function latestExport(string $tenant): TenantExport
{
    return inTenant($tenant, fn () => TenantExport::query()->latest('created_at')->firstOrFail());
}

it('export asincrono: ZIP con un CSV per tabella, JSON e prenotazioni future evidenziate', function () {
    $fx = bookableTenant();
    $past = reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    $future = reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-20,2027-07-24)');
    $this->travelTo('2027-07-16 10:00:00');
    signedInOwner($this, $fx['tenant'], passwordConfirmed: true);

    panelPost($this, '/api/v1/admin/tenant/export')->assertStatus(202)->assertJsonPath('data.status', 'PENDING');

    $export = latestExport($fx['tenant']);
    expect($export->status)->toBe(TenantExportStatus::READY)
        ->and($export->future_reservations)->toBe(1)
        ->and($export->expires_at?->toDateString())->toBe('2027-08-15');

    $files = exportFiles($export);
    expect(array_keys($files))->toContain('LEGGIMI.txt', 'reservations.csv', 'guests.csv', 'payments.csv', 'quotes.csv', 'prenotazioni_future.csv', 'export.json')
        ->and($files['prenotazioni_future.csv'])->toContain($future->code)->not->toContain($past->code)
        ->and($files['reservations.csv'])->toStartWith("\xEF\xBB\xBF")->toContain('futura')->toContain($past->code);

    $json = json_decode($files['export.json'], true);
    expect($json['future_reservations'])->toHaveCount(1)
        ->and($json['future_reservations'][0]['codice'])->toBe($future->code)
        ->and($json['tables']['reservations'])->toHaveCount(2)
        ->and($files['export.json'])->not->toContain('token_hash')->not->toContain('tenant_id');

    $message = inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('type', 'tenant.export_ready')->firstOrFail());
    expect($message->payload)->toBe(['tenant_export_id' => $export->id]);
});

it('la richiesta dell\'export chiede la password confermata', function () {
    $fx = bookableTenant();
    signedInOwner($this, $fx['tenant']);

    panelPost($this, '/api/v1/admin/tenant/export')->assertStatus(403)->assertJsonPath('error.code', 'REAUTH_REQUIRED');
    expect(inTenant($fx['tenant'], fn () => TenantExport::query()->count()))->toBe(0);
});

it('download firmato: password confermata, firma valida, file con nome neutro e senza cache', function () {
    $fx = bookableTenant();
    reservationIn($fx, ReservationStatus::CONFIRMED);
    inTenant($fx['tenant'], fn () => app(OffboardingService::class)->requestExport(null));
    $owner = signedInOwner($this, $fx['tenant']);

    $path = panelGet($this, '/api/v1/admin/tenant/exports')->assertOk()->json('data.0.download_path');
    expect($path)->toBeString()->toContain('signature=');

    // Senza password confermata negli ultimi 15 minuti: 403 REAUTH_REQUIRED.
    panelGet($this, '/api/v1/admin'.$path)->assertStatus(403)->assertJsonPath('error.code', 'REAUTH_REQUIRED');

    actingAsPanelUser($this, $owner['user'], passwordConfirmed: true);
    $response = $this->withHeader('Referer', TEST_PANEL_ORIGIN.'/app')->get('/api/v1/admin'.$path);
    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('export-')->toContain('.zip')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    // Firma alterata: rifiutata.
    panelGet($this, '/api/v1/admin'.str_replace('signature=', 'signature=0', $path))->assertForbidden();
});

it('dopo 30 giorni l\'export scade: file cancellato, link non più valido', function () {
    $fx = bookableTenant();
    inTenant($fx['tenant'], fn () => app(OffboardingService::class)->requestExport(null));
    $export = latestExport($fx['tenant']);
    Storage::disk('local')->assertExists((string) $export->file_path);
    $owner = signedInOwner($this, $fx['tenant'], passwordConfirmed: true);
    $path = panelGet($this, '/api/v1/admin/tenant/exports')->json('data.0.download_path');

    $this->travel(31)->days();
    expect(inTenant($fx['tenant'], fn () => app(OffboardingService::class)->expireExports(now()->toImmutable())))->toBe(1);

    $expired = latestExport($fx['tenant']);
    expect($expired->status)->toBe(TenantExportStatus::EXPIRED)->and($expired->file_path)->toBeNull();
    Storage::disk('local')->assertMissing((string) $export->file_path);

    actingAsPanelUser($this, $owner['user'], passwordConfirmed: true);
    panelGet($this, '/api/v1/admin'.$path)->assertForbidden();
    panelGet($this, '/api/v1/admin/tenant/exports')->assertJsonPath('data.0.status', 'EXPIRED')->assertJsonPath('data.0.download_path', null);
});

it('export scaduto con firma ancora valida → 410 EXPORT_EXPIRED', function () {
    $fx = bookableTenant();
    inTenant($fx['tenant'], fn () => app(OffboardingService::class)->requestExport(null));
    signedInOwner($this, $fx['tenant'], passwordConfirmed: true);
    $path = panelGet($this, '/api/v1/admin/tenant/exports')->json('data.0.download_path');

    inTenant($fx['tenant'], fn () => app(OffboardingService::class)->expireExportsContaining(now()->subDay()->toImmutable()));

    panelGet($this, '/api/v1/admin'.$path)->assertStatus(410)->assertJsonPath('error.code', 'EXPORT_EXPIRED');
});

it('I22: l\'anonimizzazione di un ospite cancella gli export completi che lo contengono', function () {
    $fx = bookableTenant();
    $reservation = reservationIn($fx, ReservationStatus::CANCELLED);
    inTenant($fx['tenant'], fn () => app(OffboardingService::class)->requestExport(null));
    $export = latestExport($fx['tenant']);
    expect(exportFiles($export)['guests.csv'])->toContain('Lovelace');

    inTenant($fx['tenant'], fn () => app(GuestPrivacyService::class)->erase($reservation->guest_id));

    expect(latestExport($fx['tenant'])->status)->toBe(TenantExportStatus::EXPIRED);
    Storage::disk('local')->assertMissing((string) $export->file_path);
});
