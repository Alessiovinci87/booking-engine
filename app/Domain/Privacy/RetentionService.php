<?php

namespace App\Domain\Privacy;

use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Inventory\InventoryService;
use App\Domain\Privacy\Enums\RetentionCategory;
use App\Domain\Privacy\Models\RetentionPolicy;
use App\Domain\Quotes\Enums\QuoteStatus;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Enums\OutboxStatus;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Job di conservazione (7.2; 09 "Conservazione", ADR-0018). Per ogni categoria di RetentionPolicy:
 *
 * - **simulazione** finché la categoria non è validata dal professionista (`validated_at` vuoto): il job
 *   seleziona le righe che tratterebbe e registra solo il conteggio nell'audit, senza modificare nulla;
 * - **esecuzione** per le categorie validate: stessa selezione, poi anonimizzazione (B, D) con
 *   GuestPrivacyService su tutte le copie (I22) o cancellazione con il ruolo app_retention.
 *
 * Selezione per categoria (durata = `retention_days`, mai scritta nel codice, CLAUDE.md regola 17):
 *
 * | Cat. | Righe | Azione |
 * |---|---|---|
 * | A | prenotazioni concluse con check-out oltre la durata, con righe di prezzo, modifiche, pagamenti, occupazioni, conflitti, preventivo e ospite | cancellazione |
 * | B | ospiti non anonimizzati con l'ultimo check-out oltre la durata | anonimizzazione |
 * | C | pagamenti, eventi di pagamento e rimborsi di prenotazioni con check-out oltre la durata | cancellazione |
 * | D | ospiti con soli tentativi non conclusi (EXPIRED, PAYMENT_FAILED, REJECTED) fermi oltre la durata; dati ospite dei preventivi non accettati | anonimizzazione |
 * | E | eventi iCal importati con check-out oltre la durata, con i loro conflitti chiusi | cancellazione |
 * | F | righe di audit oltre la durata (del tenant; di piattaforma una volta per esecuzione) | cancellazione |
 * | G | messaggi in uscita inviati o falliti oltre la durata | cancellazione |
 * | H | link di reimpostazione della password usati o scaduti oltre la durata (tabella globale) | cancellazione |
 */
final class RetentionService
{
    public const SIMULATION = 'simulation';

    public const APPLIED = 'applied';

    /** Ordine per tenant: prima le anonimizzazioni, poi le cancellazioni dai figli ai genitori. */
    public const TENANT_CATEGORIES = [
        RetentionCategory::B_GUEST_IDENTITY, RetentionCategory::D_UNCONCLUDED_ATTEMPTS, RetentionCategory::G_COMMUNICATIONS,
        RetentionCategory::E_IMPORTED_EVENTS, RetentionCategory::F_SECURITY_AUDIT, RetentionCategory::C_PAYMENT_REFERENCES,
        RetentionCategory::A_ADMINISTRATIVE,
    ];

    /** Categorie trattate una volta per esecuzione, fuori dal contesto dei tenant. */
    public const GLOBAL_CATEGORIES = [RetentionCategory::H_PASSWORD_RESET_LINKS, RetentionCategory::F_SECURITY_AUDIT];

    /** Stati conclusi per la categoria A (nessuna occupazione in corso). */
    private const CONCLUDED = [ReservationStatus::CONFIRMED, ReservationStatus::CANCELLED, ReservationStatus::REJECTED, ReservationStatus::EXPIRED, ReservationStatus::PAYMENT_FAILED];

    /** Tentativi non conclusi (categoria D). */
    private const UNCONCLUDED = [ReservationStatus::EXPIRED, ReservationStatus::PAYMENT_FAILED, ReservationStatus::REJECTED];

    /** Preventivi non accettati (categoria D). */
    private const QUOTES_NOT_ACCEPTED = [QuoteStatus::EXPIRED, QuoteStatus::REVOKED, QuoteStatus::EXHAUSTED];

    /** Vincoli DEFERRABLE dei cicli preventivo ↔ prenotazione (migration 2027_04_01_000100). */
    private const DEFERRED = ['quotes_active_reservation_id_fk', 'reservations_quote_id_fk'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly RetentionDatabase $retention,
        private readonly GuestPrivacyService $guests,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Esecuzione per un tenant; registra il resoconto nell'audit del tenant (`retention.run`).
     *
     * @return array<string, array{mode: string, days: int, rows: int}>
     */
    public function runTenant(string $tenantId, CarbonImmutable $now): array
    {
        $policies = $this->policies();
        $report = [];

        foreach (self::TENANT_CATEGORIES as $category) {
            $policy = $policies[$category->value] ?? null;

            if ($policy === null) {
                continue;
            }

            $apply = $policy->validated_at !== null;
            $cutoff = $now->subDays($policy->retention_days);
            $rows = $this->tenants->run($tenantId, fn (): int => $this->tenantCategory($category, $tenantId, $cutoff, $apply));
            $report[$category->value] = ['mode' => $apply ? self::APPLIED : self::SIMULATION, 'days' => $policy->retention_days, 'rows' => $rows];
        }

        $this->tenants->run($tenantId, fn () => $this->audit->record('retention.run', 'tenant', $tenantId, $report, $tenantId, AuditActor::system()));

        return $report;
    }

    /**
     * Categorie globali (H e righe di piattaforma di F), una volta per esecuzione; audit di piattaforma.
     *
     * @return array<string, array{mode: string, days: int, rows: int}>
     */
    public function runGlobal(CarbonImmutable $now): array
    {
        $policies = $this->policies();
        $report = [];

        foreach (self::GLOBAL_CATEGORIES as $category) {
            $policy = $policies[$category->value] ?? null;

            if ($policy === null) {
                continue;
            }

            $apply = $policy->validated_at !== null;
            $cutoff = $now->subDays($policy->retention_days);
            $rows = $category === RetentionCategory::H_PASSWORD_RESET_LINKS
                ? $this->passwordResetLinks($cutoff, $apply)
                : $this->platformAudit($cutoff, $apply);
            $report[$category->value] = ['mode' => $apply ? self::APPLIED : self::SIMULATION, 'days' => $policy->retention_days, 'rows' => $rows];
        }

        $this->audit->record('retention.run', 'platform', null, $report, null, AuditActor::system());

        return $report;
    }

    /**
     * Segna una categoria come validata dal professionista (09): da quel momento il job agisce davvero.
     * Il nome resta in retention_policies, non nell'audit (nessun valore personale nell'audit log).
     */
    public function validate(RetentionCategory $category, string $validatedBy, CarbonImmutable $validatedAt, ?int $days = null): RetentionPolicy
    {
        $policy = RetentionPolicy::query()->where('category', $category->value)->firstOrFail();
        $policy->forceFill(array_filter([
            'validated_by' => $validatedBy,
            'validated_at' => $validatedAt,
            'retention_days' => $days,
        ], fn ($value): bool => $value !== null))->save();

        $this->audit->record('retention.validated', 'retention_policy', $policy->id, [
            'category' => $category->value,
            'retention_days' => $policy->retention_days,
            'validated_at' => $validatedAt->toDateString(),
        ], null, AuditActor::system());

        return $policy;
    }

    /** @return array<string, RetentionPolicy> */
    public function policies(): array
    {
        $policies = [];

        foreach (RetentionPolicy::query()->get() as $policy) {
            $policies[$policy->category->value] = $policy;
        }

        return $policies;
    }

    private function tenantCategory(RetentionCategory $category, string $tenantId, CarbonImmutable $cutoff, bool $apply): int
    {
        return match ($category) {
            RetentionCategory::B_GUEST_IDENTITY => $this->anonymizeGuests($this->guestsPastCheckOut($cutoff), [], $apply),
            RetentionCategory::D_UNCONCLUDED_ATTEMPTS => $this->anonymizeGuests($this->guestsWithOnlyAttempts($cutoff), $this->quotesNotAccepted($cutoff), $apply),
            RetentionCategory::G_COMMUNICATIONS => $this->deleteIds('outbox_messages', $tenantId, $this->sentMessages($cutoff), $apply),
            RetentionCategory::E_IMPORTED_EVENTS => $this->importedEvents($tenantId, $cutoff, $apply),
            RetentionCategory::F_SECURITY_AUDIT => $this->tenantAudit($tenantId, $cutoff, $apply),
            RetentionCategory::C_PAYMENT_REFERENCES => $this->paymentReferences($tenantId, $cutoff, $apply),
            RetentionCategory::A_ADMINISTRATIVE => $this->administrative($tenantId, $cutoff, $apply),
            RetentionCategory::H_PASSWORD_RESET_LINKS => 0,
        };
    }

    // ---- B e D: anonimizzazione (app_runtime, GuestPrivacyService su tutte le copie) ----

    /** @return list<string> */
    private function guestsPastCheckOut(CarbonImmutable $cutoff): array
    {
        return self::ids(DB::table('guests as g')
            ->whereNull('g.anonymized_at')
            ->whereExists(fn ($q) => $q->from('reservations as r')->whereColumn('r.guest_id', 'g.id'))
            ->whereNotExists(fn ($q) => $q->from('reservations as r')->whereColumn('r.guest_id', 'g.id')->whereRaw('upper(r.stay) > ?::date', [$cutoff->toDateString()]))
            ->orderBy('g.id')
            ->pluck('g.id'));
    }

    /** @return list<string> */
    private function guestsWithOnlyAttempts(CarbonImmutable $cutoff): array
    {
        $unconcluded = array_map(fn (ReservationStatus $s): string => $s->value, self::UNCONCLUDED);

        return self::ids(DB::table('guests as g')
            ->whereNull('g.anonymized_at')
            ->whereExists(fn ($q) => $q->from('reservations as r')->whereColumn('r.guest_id', 'g.id'))
            ->whereNotExists(fn ($q) => $q->from('reservations as r')->whereColumn('r.guest_id', 'g.id')
                ->where(fn ($w) => $w->whereNotIn('r.status', $unconcluded)->orWhere('r.updated_at', '>', $cutoff)))
            ->orderBy('g.id')
            ->pluck('g.id'));
    }

    /** @return list<string> */
    private function quotesNotAccepted(CarbonImmutable $cutoff): array
    {
        return self::ids(DB::table('quotes')
            ->whereIn('status', array_map(fn (QuoteStatus $s): string => $s->value, self::QUOTES_NOT_ACCEPTED))
            ->where('updated_at', '<=', $cutoff)
            ->where(fn ($q) => $q->where('guest_name', '<>', GuestPrivacyService::REMOVED)->orWhereNotNull('guest_email'))
            ->orderBy('id')
            ->pluck('id'));
    }

    /**
     * @param  list<string>  $guestIds
     * @param  list<string>  $quoteIds
     */
    private function anonymizeGuests(array $guestIds, array $quoteIds, bool $apply): int
    {
        if ($apply) {
            foreach ($guestIds as $guestId) {
                $this->guests->erase($guestId);
            }

            $this->guests->anonymizeQuotes($quoteIds);
        }

        return count($guestIds) + count($quoteIds);
    }

    // ---- G, E, F, C, A: cancellazione con app_retention ----

    /** @return list<string> */
    private function sentMessages(CarbonImmutable $cutoff): array
    {
        return self::ids(DB::table('outbox_messages')
            ->whereIn('status', [OutboxStatus::SENT->value, OutboxStatus::FAILED->value])
            ->whereRaw('coalesce(sent_at, failed_at, updated_at) <= ?', [$cutoff])
            ->orderBy('id')
            ->pluck('id'));
    }

    private function importedEvents(string $tenantId, CarbonImmutable $cutoff, bool $apply): int
    {
        $open = [ConflictStatus::OPEN->value, ConflictStatus::INVESTIGATING->value];
        $events = self::ids(DB::table('external_calendar_events as e')
            ->whereRaw('upper(e.stay) <= ?::date', [$cutoff->toDateString()])
            ->whereNotExists(fn ($q) => $q->from('inventory_conflicts as c')->whereIn('c.status', $open)
                ->where(fn ($w) => $w->whereColumn('c.external_event_id', 'e.id')->orWhereColumn('c.other_external_event_id', 'e.id')))
            ->orderBy('e.id')
            ->pluck('e.id'));

        if ($apply && $events !== []) {
            $this->retention->run($tenantId, function (ConnectionInterface $db) use ($tenantId, $events): void {
                foreach (array_chunk($events, 500) as $chunk) {
                    $db->table('inventory_conflicts')->where('tenant_id', $tenantId)
                        ->where(fn ($w) => $w->whereIn('external_event_id', $chunk)->orWhereIn('other_external_event_id', $chunk))->delete();
                    $db->table('external_calendar_events')->where('tenant_id', $tenantId)->whereIn('id', $chunk)->delete();
                }
            });
        }

        return count($events);
    }

    private function tenantAudit(string $tenantId, CarbonImmutable $cutoff, bool $apply): int
    {
        $rows = DB::table('audit_log')->where('tenant_id', $tenantId)->where('occurred_at', '<=', $cutoff)->count();

        if ($apply && $rows > 0) {
            $this->retention->run($tenantId, fn (ConnectionInterface $db): int => $db->table('audit_log')->where('tenant_id', $tenantId)->where('occurred_at', '<=', $cutoff)->delete());
        }

        return $rows;
    }

    private function paymentReferences(string $tenantId, CarbonImmutable $cutoff, bool $apply): int
    {
        $payments = self::ids(DB::table('payments as p')
            ->join('reservations as r', fn ($j) => $j->on('r.id', '=', 'p.reservation_id')->on('r.tenant_id', '=', 'p.tenant_id'))
            ->whereRaw('upper(r.stay) <= ?::date', [$cutoff->toDateString()])
            ->whereIn('r.status', self::concluded())
            ->orderBy('p.id')
            ->pluck('p.id'));

        if ($apply && $payments !== []) {
            $this->retention->run($tenantId, fn (ConnectionInterface $db) => $this->deletePayments($db, $tenantId, $payments));
        }

        return count($payments);
    }

    private function administrative(string $tenantId, CarbonImmutable $cutoff, bool $apply): int
    {
        $candidates = self::ids(DB::table('reservations')
            ->whereRaw('upper(stay) <= ?::date', [$cutoff->toDateString()])
            ->whereIn('status', self::concluded())
            ->orderBy('id')
            ->pluck('id'));

        if ($candidates === []) {
            return 0;
        }

        // Un preventivo si cancella con tutti i suoi tentativi: se uno resta, restano tutti (prossima notte).
        $set = array_flip($candidates);
        $quotes = [];

        foreach (DB::table('reservations')->whereNotNull('quote_id')->whereIn('quote_id', DB::table('reservations')->whereIn('id', $candidates)->whereNotNull('quote_id')->select('quote_id'))->get(['id', 'quote_id']) as $row) {
            $quotes[(string) $row->quote_id][] = (string) $row->id;
        }

        foreach ($quotes as $quoteId => $reservationIds) {
            if (array_diff($reservationIds, $candidates) !== []) {
                foreach ($reservationIds as $id) {
                    unset($set[$id]);
                }

                unset($quotes[$quoteId]);
            }
        }

        $reservations = array_keys($set);

        if ($apply && $reservations !== []) {
            $occupancies = $this->inventory->occupancyIdsFor($reservations);
            $payments = self::ids(DB::table('payments')->whereIn('reservation_id', $reservations)->pluck('id'));
            $guests = self::ids(DB::table('guests as g')
                ->whereIn('g.id', DB::table('reservations')->whereIn('id', $reservations)->select('guest_id'))
                ->whereNotExists(fn ($q) => $q->from('reservations as r')->whereColumn('r.guest_id', 'g.id')->whereNotIn('r.id', $reservations))
                ->pluck('g.id'));
            $quoteIds = array_map('strval', array_keys($quotes));

            $this->retention->run($tenantId, function (ConnectionInterface $db) use ($tenantId, $reservations, $occupancies, $payments, $guests, $quoteIds): void {
                $db->statement('SET CONSTRAINTS '.implode(', ', self::DEFERRED).' DEFERRED');
                $this->deletePayments($db, $tenantId, $payments);

                foreach (array_chunk($reservations, 500) as $chunk) {
                    $db->table('reservation_price_lines')->where('tenant_id', $tenantId)->whereIn('reservation_id', $chunk)->delete();
                    $db->table('reservation_changes')->where('tenant_id', $tenantId)->whereIn('reservation_id', $chunk)->delete();
                }

                foreach (array_chunk($occupancies, 500) as $chunk) {
                    $db->table('inventory_conflicts')->where('tenant_id', $tenantId)->whereIn('occupancy_id', $chunk)->delete();
                }

                $this->inventory->purgeReservations($db, $tenantId, $reservations);

                foreach (array_chunk($quoteIds, 500) as $chunk) {
                    $db->table('quotes')->where('tenant_id', $tenantId)->whereIn('id', $chunk)->delete();
                }

                foreach (array_chunk($reservations, 500) as $chunk) {
                    $db->table('reservations')->where('tenant_id', $tenantId)->whereIn('id', $chunk)->delete();
                }

                foreach (array_chunk($guests, 500) as $chunk) {
                    $db->table('guests')->where('tenant_id', $tenantId)->whereIn('id', $chunk)->delete();
                }
            });
        }

        return count($reservations);
    }

    /** @param  list<string>  $payments */
    private function deletePayments(ConnectionInterface $db, string $tenantId, array $payments): void
    {
        foreach (array_chunk($payments, 500) as $chunk) {
            $db->table('payment_events')->where('tenant_id', $tenantId)->whereIn('payment_id', $chunk)->delete();
            $db->table('refunds')->where('tenant_id', $tenantId)->whereIn('payment_id', $chunk)->delete();
            $db->table('payments')->where('tenant_id', $tenantId)->whereIn('id', $chunk)->delete();
        }
    }

    /** @param  list<string>  $ids */
    private function deleteIds(string $table, string $tenantId, array $ids, bool $apply): int
    {
        if ($apply && $ids !== []) {
            $this->retention->run($tenantId, function (ConnectionInterface $db) use ($table, $tenantId, $ids): void {
                foreach (array_chunk($ids, 500) as $chunk) {
                    $db->table($table)->where('tenant_id', $tenantId)->whereIn('id', $chunk)->delete();
                }
            });
        }

        return count($ids);
    }

    // ---- Globali ----

    private function passwordResetLinks(CarbonImmutable $cutoff, bool $apply): int
    {
        $query = fn (ConnectionInterface $db) => $db->table('user_password_resets')->whereRaw('coalesce(used_at, expires_at) <= ?', [$cutoff]);

        return $this->retention->run(null, fn (ConnectionInterface $db): int => $apply ? $query($db)->delete() : $query($db)->count());
    }

    private function platformAudit(CarbonImmutable $cutoff, bool $apply): int
    {
        $query = fn (ConnectionInterface $db) => $db->table('audit_log')->whereNull('tenant_id')->where('occurred_at', '<=', $cutoff);

        return $this->retention->run(null, fn (ConnectionInterface $db): int => $apply ? $query($db)->delete() : $query($db)->count());
    }

    /** @return list<string> */
    private static function concluded(): array
    {
        return array_map(fn (ReservationStatus $s): string => $s->value, self::CONCLUDED);
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private static function ids(iterable $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            $ids[] = (string) $value;
        }

        return $ids;
    }
}
