<?php

namespace App\Domain\Tenancy\Offboarding;

use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\Enums\IcsFeedStatus;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Calendar\StateMachines\ExternalCalendarStateMachine;
use App\Domain\Calendar\StateMachines\IcsFeedStateMachine;
use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Enums\TenantExportStatus;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Jobs\GenerateTenantExportJob;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantExport;
use App\Domain\Tenancy\Outbox\Outbox;
use App\Domain\Tenancy\StateMachines\TenantExportStateMachine;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Collection;

/**
 * Uscita del cliente (7.1; 06 "Ciclo di vita dei feed e uscita del cliente", 09 "Uscita del cliente",
 * AC18, I19, ADR-0018):
 *
 * 1. start: tenant OFFBOARDING, pagina ospite e nuove prenotazioni disattivate, feed ACTIVE → FROZEN
 *    (continuano a servire le occupazioni esistenti, mai un calendario vuoto);
 * 2. requestExport: export completo asincrono, scaricabile per 30 giorni;
 * 3. confirmFeedRemoved: il gestore conferma, per ogni feed, di averlo tolto dall'OTA → RETIRED (410);
 * 4. retireUnreadFeeds (job giornaliero): dopo 30 giorni dall'avvio, i feed non letti da almeno 7 giorni
 *    → RETIRED; quelli ancora letti restano FROZEN e il gestore riceve un'email (mai un ritiro silenzioso);
 * 5. quando tutti i feed sono RETIRED: calendari importati disattivati (`feeds_disabled_at`);
 * 6. purge (comando di piattaforma): cancellazione dei dati secondo il DPA, poi CLOSED.
 *
 * Tutti i metodi girano nel contesto del tenant, salvo purge che lo apre da sé.
 */
final class OffboardingService
{
    public function __construct(
        private readonly TenantLifecycleService $lifecycle,
        private readonly IcsFeedStateMachine $feeds,
        private readonly ExternalCalendarStateMachine $calendars,
        private readonly TenantExportStateMachine $exports,
        private readonly AuditLogger $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenantContext,
        private readonly TenantDataPurger $purger,
        private readonly FilesystemFactory $files,
    ) {}

    public function start(?string $userId, AuditActor $actor): Tenant
    {
        $tenant = Tenant::query()->lockForUpdate()->firstOrFail();
        $this->lifecycle->startOffboarding($tenant);

        $frozen = 0;

        foreach (IcsFeed::query()->where('status', IcsFeedStatus::ACTIVE->value)->lockForUpdate()->get() as $feed) {
            $this->feeds->freeze($feed, 'offboarding');
            $frozen++;
        }

        $this->audit->record('tenant.offboarding_started', 'tenant', $tenant->id, ['feeds_frozen' => $frozen], $tenant->id, $actor);
        $this->finishIfAllRetired($tenant);

        return $tenant;
    }

    public function requestExport(?string $userId): TenantExport
    {
        $tenant = Tenant::query()->firstOrFail();

        if ($tenant->status === TenantStatus::CLOSED) {
            throw BusinessRuleViolation::make(409, 'TENANT_CLOSED');
        }

        $export = $this->exports->request($userId);
        GenerateTenantExportJob::dispatch($tenant->id, $export->id)->afterCommit();

        return $export;
    }

    public function confirmFeedRemoved(string $feedId): IcsFeed
    {
        $tenant = Tenant::query()->lockForUpdate()->firstOrFail();
        $this->assertOffboarding($tenant);

        $feed = IcsFeed::query()->lockForUpdate()->findOrFail($feedId);

        if ($feed->status === IcsFeedStatus::RETIRED) {
            return $feed;
        }

        $feed->removal_confirmed_at = CarbonImmutable::now();
        $feed->save();
        $this->feeds->retire($feed, 'removal_confirmed');
        $this->finishIfAllRetired($tenant);

        return $feed;
    }

    /**
     * Ritiro automatico (06 punto 4–5). Restituisce i conteggi: ritirati, ancora letti.
     *
     * @return array{retired: int, still_read: int}
     */
    public function retireUnreadFeeds(CarbonImmutable $now): array
    {
        $tenant = Tenant::query()->lockForUpdate()->firstOrFail();
        $result = ['retired' => 0, 'still_read' => 0];

        if ($tenant->status !== TenantStatus::OFFBOARDING || $tenant->offboarding_started_at === null
            || $tenant->offboarding_started_at->greaterThan($now->subDays((int) config('offboarding.auto_retire_after_days')))) {
            return $result;
        }

        $unreadSince = $now->subDays((int) config('offboarding.unread_days'));
        $feeds = IcsFeed::query()->where('status', IcsFeedStatus::FROZEN->value)->whereNull('rotated_at')->lockForUpdate()->get();

        foreach ($feeds as $feed) {
            if ($feed->last_fetched_at === null || $feed->last_fetched_at->lessThanOrEqualTo($unreadSince)) {
                $this->feeds->retire($feed, 'offboarding_unread');
                $result['retired']++;

                continue;
            }

            // L'OTA legge ancora il feed: resta FROZEN e si avvisa il gestore, una volta per feed.
            $this->outbox->enqueue('offboarding.feed_still_read', 'offboarding.feed_still_read:'.$feed->id, ['ics_feed_id' => $feed->id]);
            $result['still_read']++;
        }

        $this->finishIfAllRetired($tenant);

        return $result;
    }

    /**
     * Stato per il pannello e per il comando di piattaforma (07 GET /admin/tenant/offboarding).
     *
     * @return array<string, mixed>
     */
    public function overview(CarbonImmutable $now): array
    {
        $tenant = Tenant::query()->firstOrFail();
        $feeds = IcsFeed::query()->with('unit:id,name')->orderBy('created_at')->get();
        $autoRetireAt = $tenant->offboarding_started_at?->addDays((int) config('offboarding.auto_retire_after_days'));

        return [
            'status' => $tenant->status->value,
            'offboarding_started_at' => $tenant->offboarding_started_at?->toIso8601ZuluString(),
            'auto_retire_from' => $autoRetireAt?->toIso8601ZuluString(),
            'channels_disconnected_confirmed_at' => $tenant->channels_disconnected_confirmed_at?->toIso8601ZuluString(),
            'feeds_disabled_at' => $tenant->feeds_disabled_at?->toIso8601ZuluString(),
            'feeds' => $feeds->map(fn (IcsFeed $feed): array => [
                'id' => $feed->id,
                'unit_name' => $feed->unit->name ?? null,
                'destination' => $feed->destination->value,
                'status' => $feed->status->value,
                'rotated' => $feed->rotated_at !== null,
                'last_fetched_at' => $feed->last_fetched_at?->toIso8601ZuluString(),
                'read_recently' => $feed->last_fetched_at !== null && $feed->last_fetched_at->greaterThan($now->subDays((int) config('offboarding.unread_days'))),
                'removal_confirmed_at' => $feed->removal_confirmed_at?->toIso8601ZuluString(),
            ])->all(),
        ];
    }

    /** @return Collection<int, TenantExport> */
    public function exports(): Collection
    {
        return TenantExport::query()->orderByDesc('created_at')->limit(20)->get();
    }

    /** Export scaduti dopo 30 giorni: file cancellato, stato EXPIRED (09). */
    public function expireExports(CarbonImmutable $now): int
    {
        $expired = TenantExport::query()->where('status', TenantExportStatus::READY->value)->where('expires_at', '<=', $now)->lockForUpdate()->get();

        foreach ($expired as $export) {
            $this->deleteExportFile($export);
            $this->exports->expire($export, 'expired');
        }

        return $expired->count();
    }

    /**
     * Export attivi creati dopo il dato indicato (contengono quindi l'ospite): cancellati all'anonimizzazione
     * dell'ospite (09 "Anonimizzazione effettiva", I22). Restituisce il numero di export cancellati.
     */
    public function expireExportsContaining(CarbonImmutable $since): int
    {
        $exports = TenantExport::query()->where('status', TenantExportStatus::READY->value)->where('completed_at', '>=', $since)->lockForUpdate()->get();

        foreach ($exports as $export) {
            $this->deleteExportFile($export);
            $this->exports->expire($export, 'guest_erased');
        }

        return $exports->count();
    }

    /**
     * Cancellazione dei dati secondo il DPA (09 punto 6), poi CLOSED. Condizioni: tenant OFFBOARDING, tutti
     * i feed ritirati e almeno 30 giorni da un export completato, oppure un'istruzione scritta del cliente
     * (riassunta in `instruction`, senza dati personali). I feed ritirati sono obbligatori anche con
     * l'istruzione: un feed ancora letto non può sparire (I19).
     *
     * @return array<string, int>
     */
    public function purge(string $tenantId, ?string $instruction, CarbonImmutable $now): array
    {
        $plan = $this->tenantContext->run($tenantId, function () use ($instruction, $now): array {
            $tenant = Tenant::query()->lockForUpdate()->firstOrFail();
            $this->assertOffboarding($tenant);

            if ($tenant->feeds_disabled_at === null) {
                throw BusinessRuleViolation::make(409, 'OFFBOARDING_NOT_READY', ['missing' => 'feeds_retired']);
            }

            if ($instruction === null) {
                $lastExport = TenantExport::query()->whereNotNull('completed_at')->max('completed_at');
                $ready = $lastExport !== null && CarbonImmutable::parse((string) $lastExport)->lessThanOrEqualTo($now->subDays((int) config('offboarding.purge_after_export_days')));

                if (! $ready) {
                    throw BusinessRuleViolation::make(409, 'OFFBOARDING_NOT_READY', ['missing' => 'export_wait']);
                }
            }

            return $this->purger->plan($tenant->id);
        });

        $counts = $this->purger->purge($tenantId, $plan);

        $this->tenantContext->run($tenantId, function () use ($counts, $instruction): void {
            $tenant = Tenant::query()->lockForUpdate()->firstOrFail();
            $this->lifecycle->close($tenant);
            $this->audit->record('tenant.data_purged', 'tenant', $tenant->id, [
                'rows' => array_sum(array_diff_key($counts, ['files' => 0])),
                'files' => $counts['files'],
                'users' => $counts['users'],
                'written_instruction' => $instruction,
            ], $tenant->id, AuditActor::system());
        });

        return $counts;
    }

    private function finishIfAllRetired(Tenant $tenant): void
    {
        if ($tenant->status !== TenantStatus::OFFBOARDING || $tenant->feeds_disabled_at !== null) {
            return;
        }

        $open = IcsFeed::query()->where('status', '<>', IcsFeedStatus::RETIRED->value)->exists();

        if ($open) {
            return;
        }

        $now = CarbonImmutable::now();
        $allConfirmed = ! IcsFeed::query()->whereNull('rotated_at')->whereNull('removal_confirmed_at')->exists();

        // 09 punto 5: calendari importati disattivati quando nessun feed è più servito.
        foreach (ExternalCalendar::query()->where('status', '<>', ExternalCalendarStatus::DISABLED->value)->lockForUpdate()->get() as $calendar) {
            $this->calendars->disable($calendar);
        }

        $tenant->forceFill([
            'feeds_disabled_at' => $now,
            'channels_disconnected_confirmed_at' => $allConfirmed ? $now : null,
        ])->save();
        $this->audit->record('tenant.feeds_disabled', 'tenant', $tenant->id, ['all_confirmed' => $allConfirmed], $tenant->id);
    }

    private function assertOffboarding(Tenant $tenant): void
    {
        if ($tenant->status !== TenantStatus::OFFBOARDING) {
            throw BusinessRuleViolation::make(409, 'TENANT_NOT_OFFBOARDING', ['status' => $tenant->status->value]);
        }
    }

    private function deleteExportFile(TenantExport $export): void
    {
        if ($export->file_path !== null) {
            $this->files->disk((string) config('offboarding.disk'))->delete($export->file_path);
        }
    }
}
