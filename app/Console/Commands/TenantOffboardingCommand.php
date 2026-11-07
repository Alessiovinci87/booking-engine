<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Auth\MfaReset;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Offboarding\OffboardingService;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comandi di piattaforma per l'uscita del cliente (7.1; 09 "Uscita del cliente", guida
 * docs/guide/uscita-cliente.md):
 *
 * - status: stato dell'uscita e dei feed (ultima lettura, conferme);
 * - start: avvio su richiesta del cliente (stesso effetto del pannello);
 * - export: export completo asincrono;
 * - retire-check: ritiro automatico dei feed non più letti (lo fa anche il job giornaliero);
 * - purge: cancellazione dei dati secondo il DPA e stato CLOSED; senza 30 giorni dall'export serve
 *   --instruction con il riassunto dell'istruzione scritta del cliente (senza dati personali).
 */
final class TenantOffboardingCommand extends Command
{
    protected $signature = 'tenant:offboarding
        {slug : Slug del tenant}
        {action : status | start | export | retire-check | purge}
        {--instruction= : Istruzione scritta del cliente per cancellare prima dei 30 giorni (10-200 caratteri, senza dati personali)}';

    protected $description = 'Uscita del cliente: stato, avvio, export, ritiro dei feed, cancellazione dei dati';

    public function handle(TenantContext $tenants, OffboardingService $offboarding): int
    {
        $tenantId = DB::selectOne('select app_tenant_by_slug(?) as id', [(string) $this->argument('slug')])->id;

        if ($tenantId === null) {
            $this->error(__('tenancy.offboarding.unknown_tenant'));

            return self::FAILURE;
        }

        $tenantId = (string) $tenantId;
        $now = CarbonImmutable::now();

        try {
            return match ((string) $this->argument('action')) {
                'status' => $this->status($tenants->run($tenantId, fn (): array => $offboarding->overview($now))),
                'start' => $this->start($tenants, $offboarding, $tenantId),
                'export' => $this->export($tenants, $offboarding, $tenantId),
                'retire-check' => $this->retireCheck($tenants->run($tenantId, fn (): array => $offboarding->retireUnreadFeeds($now))),
                'purge' => $this->purge($offboarding, $tenantId, $now),
                default => $this->invalidAction(),
            };
        } catch (BusinessRuleViolation $e) {
            $this->error(__('tenancy.offboarding.refused', ['code' => $e->errorCode, 'details' => json_encode($e->details, JSON_UNESCAPED_UNICODE)]));

            return self::FAILURE;
        }
    }

    /** @param  array<string, mixed>  $overview */
    private function status(array $overview): int
    {
        $this->line(__('tenancy.offboarding.status_line', [
            'status' => $overview['status'],
            'started' => $overview['offboarding_started_at'] ?? '-',
            'auto' => $overview['auto_retire_from'] ?? '-',
            'disabled' => $overview['feeds_disabled_at'] ?? '-',
        ]));

        /** @var list<array<string, mixed>> $feeds */
        $feeds = $overview['feeds'];
        $this->table(
            ['id', 'unit', 'destination', 'status', 'last_fetched_at', 'removal_confirmed_at'],
            array_map(fn (array $feed): array => [
                $feed['id'], $feed['unit_name'], $feed['destination'], $feed['status'].($feed['rotated'] ? ' (rotated)' : ''),
                $feed['last_fetched_at'] ?? '-', $feed['removal_confirmed_at'] ?? '-',
            ], $feeds),
        );

        return self::SUCCESS;
    }

    private function start(TenantContext $tenants, OffboardingService $offboarding, string $tenantId): int
    {
        if (! $this->confirm(__('tenancy.offboarding.confirm_start'))) {
            $this->line(__('tenancy.offboarding.aborted'));

            return self::FAILURE;
        }

        $tenants->run($tenantId, fn () => $offboarding->start(null, AuditActor::system()));
        $this->info(__('tenancy.offboarding.started'));

        return self::SUCCESS;
    }

    private function export(TenantContext $tenants, OffboardingService $offboarding, string $tenantId): int
    {
        $export = $tenants->run($tenantId, fn () => $offboarding->requestExport(null));
        $this->info(__('tenancy.offboarding.export_requested', ['id' => $export->id]));

        return self::SUCCESS;
    }

    /** @param  array{retired: int, still_read: int}  $result */
    private function retireCheck(array $result): int
    {
        $this->info(__('tenancy.offboarding.retire_check', $result));

        return self::SUCCESS;
    }

    private function purge(OffboardingService $offboarding, string $tenantId, CarbonImmutable $now): int
    {
        $instruction = $this->option('instruction');
        $instruction = is_string($instruction) && trim($instruction) !== '' ? trim($instruction) : null;

        if ($instruction !== null && ! MfaReset::reasonIsValid($instruction)) {
            $this->error(__('tenancy.offboarding.invalid_instruction'));

            return self::FAILURE;
        }

        $this->warn(__('tenancy.offboarding.purge_warning'));

        if (! $this->confirm(__('tenancy.offboarding.confirm_purge'))) {
            $this->line(__('tenancy.offboarding.aborted'));

            return self::FAILURE;
        }

        $counts = $offboarding->purge($tenantId, $instruction, $now);
        $this->info(__('tenancy.offboarding.purged', ['rows' => array_sum(array_diff_key($counts, ['files' => 0])), 'files' => $counts['files'] ?? 0]));

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error(__('tenancy.offboarding.invalid_action'));

        return self::FAILURE;
    }
}
