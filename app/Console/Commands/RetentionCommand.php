<?php

namespace App\Console\Commands;

use App\Domain\Privacy\Enums\RetentionCategory;
use App\Domain\Privacy\RetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Conservazione dei dati (7.2; 09 "Conservazione", ADR-0018), comandi di piattaforma:
 *
 * - status: categorie con durata, azione e validazione (le non validate girano in simulazione);
 * - run [--tenant=slug]: esegue subito il job (globale e per i tenant, o solo per uno) e mostra cosa ha
 *   trattato o cosa tratterebbe;
 * - validate {categoria} --by="Nome Cognome" --date=AAAA-MM-GG [--days=N]: registra la validazione del
 *   professionista; da quel momento il job agisce davvero sulla categoria.
 */
final class RetentionCommand extends Command
{
    protected $signature = 'retention
        {action : status | run | validate}
        {category? : Categoria per validate (es. H_PASSWORD_RESET_LINKS oppure H)}
        {--tenant= : Slug del tenant per run}
        {--by= : Chi ha validato (nome del professionista, 3-100 caratteri)}
        {--date= : Data della validazione, AAAA-MM-GG, non nel futuro}
        {--days= : Durata validata in giorni, se diversa da quella attuale}';

    protected $description = 'Conservazione dei dati: stato delle categorie, esecuzione del job, validazione';

    public function handle(RetentionService $retention): int
    {
        return match ((string) $this->argument('action')) {
            'status' => $this->status($retention),
            'run' => $this->runNow($retention),
            'validate' => $this->validateCategory($retention),
            default => $this->refuse(__('privacy.retention.invalid_action')),
        };
    }

    private function status(RetentionService $retention): int
    {
        $rows = [];

        foreach (RetentionCategory::cases() as $category) {
            $policy = $retention->policies()[$category->value] ?? null;
            $rows[] = [
                $category->value,
                $policy->retention_days ?? '-',
                $policy?->action->value ?? '-',
                $policy?->validated_at === null ? __('privacy.retention.simulation') : __('privacy.retention.validated', ['by' => $policy->validated_by, 'date' => $policy->validated_at->toDateString()]),
            ];
        }

        $this->table(['categoria', 'giorni', 'azione', 'stato'], $rows);

        return self::SUCCESS;
    }

    private function runNow(RetentionService $retention): int
    {
        $now = CarbonImmutable::now();
        $slug = $this->option('tenant');

        if (is_string($slug) && $slug !== '') {
            $tenantId = DB::selectOne('select app_tenant_by_slug(?) as id', [$slug])->id;

            if ($tenantId === null) {
                return $this->refuse(__('privacy.retention.unknown_tenant'));
            }

            $this->report((string) $slug, $retention->runTenant((string) $tenantId, $now));

            return self::SUCCESS;
        }

        $this->report('piattaforma', $retention->runGlobal($now));

        foreach (DB::connection((string) config('payments.release_dispatcher_connection'))->select('select id::text as id, slug from tenants order by slug') as $tenant) {
            $this->report((string) $tenant->slug, $retention->runTenant((string) $tenant->id, $now));
        }

        return self::SUCCESS;
    }

    private function validateCategory(RetentionService $retention): int
    {
        $category = self::category((string) $this->argument('category'));
        $by = trim((string) $this->option('by'));
        $date = (string) $this->option('date');
        $days = $this->option('days');

        if ($category === null) {
            return $this->refuse(__('privacy.retention.unknown_category'));
        }

        if (mb_strlen($by) < 3 || mb_strlen($by) > 100 || str_contains($by, '@')) {
            return $this->refuse(__('privacy.retention.invalid_by'));
        }

        $validatedAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC') : null;

        if ($validatedAt === null || $validatedAt->format('Y-m-d') !== $date || $validatedAt->isAfter(CarbonImmutable::now())) {
            return $this->refuse(__('privacy.retention.invalid_date'));
        }

        if ($days !== null && (! ctype_digit((string) $days) || (int) $days < 1 || (int) $days > 36500)) {
            return $this->refuse(__('privacy.retention.invalid_days'));
        }

        $this->warn(__('privacy.retention.validate_warning', ['category' => $category->value]));

        if (! $this->confirm(__('privacy.retention.confirm_validate'))) {
            $this->line(__('privacy.retention.aborted'));

            return self::FAILURE;
        }

        $policy = $retention->validate($category, $by, $validatedAt, $days === null ? null : (int) $days);
        $this->info(__('privacy.retention.validated_done', ['category' => $category->value, 'days' => $policy->retention_days]));

        return self::SUCCESS;
    }

    /** @param  array<string, array{mode: string, days: int, rows: int}>  $report */
    private function report(string $scope, array $report): void
    {
        $this->line($scope);
        $this->table(['categoria', 'modalità', 'giorni', 'righe'], array_map(
            fn (string $category, array $row): array => [$category, $row['mode'], $row['days'], $row['rows']],
            array_keys($report),
            $report,
        ));
    }

    private static function category(string $value): ?RetentionCategory
    {
        foreach (RetentionCategory::cases() as $category) {
            if ($category->value === $value || str_starts_with($category->value, strtoupper($value).'_')) {
                return $category;
            }
        }

        return null;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
