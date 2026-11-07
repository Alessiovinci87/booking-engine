<?php

namespace App\Domain\Inventory;

use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Inventory\Enums\OccupancyKind;
use App\Domain\Inventory\Enums\OccupancyPhase;
use App\Domain\Inventory\Enums\OccupancyStatus;
use App\Domain\Inventory\Enums\OccupancyTempReason;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\InventoryOccupancy;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Unico scrittore di inventory_occupancies (02 "Funzione unica di scrittura dell'inventario"; I1,
 * I15; CLAUDE.md regola 13). È anche l'unico punto che legge il modello delle occupazioni.
 *
 * Ogni scrittura gira in una transazione (savepoint dentro quella della richiesta o del job) con il
 * contesto del tenant già impostato e blocca la riga dell'unità con FOR UPDATE: le acquisizioni sulla
 * stessa unità sono serializzate. Il vincolo GiST occupancies_no_overlap resta la garanzia finale:
 * una sua violazione (SQLSTATE 23P01) diventa 409 DATES_UNAVAILABLE.
 *
 * Le verifiche su external_calendar_events e inventory_conflicts sono attive anche se le tabelle
 * si riempiono solo da M5 (06).
 *
 * Coerenza con lo stato della prenotazione (I15): la macchina a stati cambia lo stato e poi chiama
 * questo servizio nella stessa transazione. promoteToFirm, setTemporaryReason e move richiedono una
 * prenotazione in stato occupante; release richiede uno stato terminale.
 */
final class InventoryService
{
    private const GIST_VIOLATION = '23P01';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Occupa le date per una prenotazione. $createReservation crea e salva la prenotazione dopo il
     * lock e le verifiche (02: "INSERT reservation", poi "INSERT inventory_occupancy").
     *
     * @param  Closure(): Reservation  $createReservation
     */
    public function acquireForReservation(
        Unit $unit,
        StayRange $stay,
        OccupancyPhase $phase,
        ?OccupancyTempReason $reason,
        ?CarbonImmutable $expiresAt,
        Closure $createReservation,
    ): InventoryOccupancy {
        return $this->transaction($unit, $stay, function (Unit $locked) use ($stay, $phase, $reason, $expiresAt, $createReservation): InventoryOccupancy {
            $this->assertSellable($locked);
            $this->assertNoExternalBlocks($locked, $stay);

            $reservation = $createReservation();

            if ($reservation->unit_id !== $locked->id || ! $reservation->stay->equals($stay) || ! $reservation->status->occupies()) {
                throw new LogicException('La prenotazione non corrisponde all\'occupazione richiesta.');
            }

            $occupancy = $this->insert($locked, $stay, OccupancyKind::RESERVATION, $phase, $reason, $expiresAt, $reservation->id, null);
            $this->audit->record('occupancy.acquired', 'inventory_occupancy', $occupancy->id, [
                'kind' => OccupancyKind::RESERVATION->value, 'phase' => $phase->value, 'temp_reason' => $reason?->value,
                'reservation_id' => $reservation->id, 'unit_id' => $locked->id, 'stay' => (string) $stay,
            ]);

            return $occupancy;
        });
    }

    /** Blocco manuale del gestore: occupazione FIRM senza prenotazione. Le note sono personali (09). */
    public function acquireBlock(Unit $unit, StayRange $stay, ?string $note): InventoryOccupancy
    {
        return $this->transaction($unit, $stay, function (Unit $locked) use ($stay, $note): InventoryOccupancy {
            $this->assertNoExternalBlocks($locked, $stay);

            $occupancy = $this->insert($locked, $stay, OccupancyKind::MANUAL_BLOCK, OccupancyPhase::FIRM, null, null, null, $note);
            $this->audit->record('occupancy.block_created', 'inventory_occupancy', $occupancy->id, [
                'unit_id' => $locked->id, 'stay' => (string) $stay, 'note' => $note === null ? null : '[personal]',
            ]);

            return $occupancy;
        });
    }

    /** Rimozione di un blocco manuale attivo (dell'unità indicata, se data); altrimenti 404. */
    public function releaseBlock(string $occupancyId, string $reason, ?string $unitId = null): InventoryOccupancy
    {
        $occupancy = InventoryOccupancy::query()
            ->where('kind', OccupancyKind::MANUAL_BLOCK->value)
            ->where('status', OccupancyStatus::ACTIVE->value)
            ->when($unitId !== null, fn ($q) => $q->where('unit_id', $unitId))
            ->findOrFail($occupancyId);

        return DB::transaction(function () use ($occupancy, $reason): InventoryOccupancy {
            $this->lockUnit($occupancy->unit_id);
            $this->markReleased($occupancy, $reason);

            return $occupancy;
        });
    }

    /** Da temporanea a definitiva sulla stessa riga: l'UID esportato non cambia (02). */
    public function promoteToFirm(Reservation $reservation): InventoryOccupancy
    {
        return $this->updateActive($reservation, 'occupancy.promoted', function (InventoryOccupancy $occupancy): void {
            $occupancy->phase = OccupancyPhase::FIRM;
            $occupancy->temp_reason = null;
            $occupancy->expires_at = null;
        });
    }

    public function setTemporaryReason(Reservation $reservation, OccupancyTempReason $reason, ?CarbonImmutable $expiresAt): InventoryOccupancy
    {
        return $this->updateActive($reservation, 'occupancy.temp_reason_changed', function (InventoryOccupancy $occupancy) use ($reason, $expiresAt): void {
            $occupancy->phase = OccupancyPhase::TEMPORARY;
            $occupancy->temp_reason = $reason;
            $occupancy->expires_at = $expiresAt;
        });
    }

    /** Solo la scadenza (allineamento alla sessione di pagamento, 05 passo 3). */
    public function setExpiry(Reservation $reservation, ?CarbonImmutable $expiresAt): InventoryOccupancy
    {
        return $this->updateActive($reservation, 'occupancy.expiry_changed', function (InventoryOccupancy $occupancy) use ($expiresAt): void {
            if ($occupancy->phase !== OccupancyPhase::TEMPORARY) {
                throw new LogicException('Solo un\'occupazione temporanea ha una scadenza.');
            }

            $occupancy->expires_at = $expiresAt;
        });
    }

    /**
     * Modifica delle date (T18) sulla stessa riga. Le nuove date devono essere vendibili escludendo
     * la prenotazione stessa: il vincolo GiST ignora la riga che si aggiorna.
     */
    public function move(Reservation $reservation, StayRange $newStay): InventoryOccupancy
    {
        $occupancy = $this->activeFor($reservation);

        return $this->transaction($this->unitOf($occupancy), $newStay, function (Unit $locked) use ($occupancy, $reservation, $newStay): InventoryOccupancy {
            $this->assertOccupying($reservation);
            $this->assertNoExternalBlocks($locked, $newStay, $occupancy->id);

            $previous = (string) $occupancy->stay;
            $occupancy->stay = $newStay;
            // SEQUENCE dell'evento nei feed iCal: stesso UID, date nuove (06).
            $occupancy->ics_sequence++;
            $occupancy->save();

            $this->audit->record('occupancy.moved', 'inventory_occupancy', $occupancy->id, [
                'reservation_id' => $reservation->id, 'stay' => ['from' => $previous, 'to' => (string) $newStay],
            ]);

            return $occupancy;
        }, $occupancy->id);
    }

    /** Rilascio dell'occupazione di una prenotazione già passata a uno stato terminale (I15). */
    public function release(Reservation $reservation, string $reason): InventoryOccupancy
    {
        if ($reservation->status->occupies()) {
            throw new LogicException('Si rilascia solo l\'occupazione di una prenotazione in stato terminale (I15).');
        }

        $occupancy = $this->activeFor($reservation);

        return DB::transaction(function () use ($occupancy, $reason): InventoryOccupancy {
            $this->lockUnit($occupancy->unit_id);
            $this->markReleased($occupancy, $reason);

            return $occupancy;
        });
    }

    // ---------------------------------------------------------------- letture

    public function activeFor(Reservation $reservation): InventoryOccupancy
    {
        $occupancy = InventoryOccupancy::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', OccupancyStatus::ACTIVE->value)
            ->first();

        if ($occupancy === null) {
            throw new LogicException('Nessuna occupazione attiva per la prenotazione (I15).');
        }

        return $occupancy;
    }

    public function findFor(Reservation $reservation): ?InventoryOccupancy
    {
        return InventoryOccupancy::query()->where('reservation_id', $reservation->id)->first();
    }

    /**
     * Occupazioni attive di un'unità che toccano l'intervallo (anche scadute ma non rilasciate, 02).
     *
     * @return Collection<int, InventoryOccupancy>
     */
    public function activeInRange(string $unitId, StayRange $range): Collection
    {
        return InventoryOccupancy::query()
            ->where('unit_id', $unitId)
            ->where('status', OccupancyStatus::ACTIVE->value)
            ->whereRaw('stay && ?::daterange', [$range->toPostgres()])
            ->orderByRaw('lower(stay)')
            ->get();
    }

    /**
     * Intervalli che rendono non vendibili le notti di un'unità secondo le tre fonti di 02:
     * occupazioni attive, eventi esterni ACTIVE/PENDING_REMOVAL, conflitti OPEN/INVESTIGATING.
     *
     * @return list<StayRange>
     */
    public function blockedRanges(string $unitId, StayRange $range, ?string $excludeOccupancyId = null): array
    {
        $ranges = $this->activeInRange($unitId, $range)
            ->reject(fn (InventoryOccupancy $o): bool => $o->id === $excludeOccupancyId)
            ->map(fn (InventoryOccupancy $o): StayRange => $o->stay)
            ->values()->all();

        foreach (self::externalBlocksQuery($unitId, $range)->get() as $row) {
            $ranges[] = StayRange::fromPostgres((string) $row->stay);
        }

        return $ranges;
    }

    /**
     * Occupazioni di checkout o di recupero scadute (05 "Rilascio delle occupazioni con sessione").
     *
     * @return Collection<int, InventoryOccupancy>
     */
    public function expiredPaymentOccupancies(CarbonImmutable $now, int $limit = 100): Collection
    {
        return $this->expiredTemporary($now, [OccupancyTempReason::CHECKOUT, OccupancyTempReason::PAYMENT_RECOVERY], $limit);
    }

    /**
     * Occupazioni temporanee scadute con i motivi indicati.
     *
     * @param  list<OccupancyTempReason>  $reasons
     * @return Collection<int, InventoryOccupancy>
     */
    public function expiredTemporary(CarbonImmutable $now, array $reasons, int $limit = 100): Collection
    {
        return InventoryOccupancy::query()
            ->where('status', OccupancyStatus::ACTIVE->value)
            ->where('phase', OccupancyPhase::TEMPORARY->value)
            ->whereIn('temp_reason', array_map(fn (OccupancyTempReason $r): string => $r->value, $reasons))
            ->where('expires_at', '<', $now)
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Prenotazioni con occupazione temporanea scaduta per i motivi indicati, nel tenant corrente
     * (job di rilascio, 05): solo ID e scadenza, il modello non esce da questo servizio.
     *
     * @param  list<OccupancyTempReason>  $reasons
     * @return list<array{reservation_id: string, expires_at: CarbonImmutable}>
     */
    public function expiredReservations(CarbonImmutable $now, array $reasons, int $limit = 100): array
    {
        return $this->expiredTemporary($now, $reasons, $limit)
            ->filter(fn (InventoryOccupancy $o): bool => $o->reservation_id !== null && $o->expires_at !== null)
            ->map(fn (InventoryOccupancy $o): array => ['reservation_id' => (string) $o->reservation_id, 'expires_at' => $o->expires_at ?? $now])
            ->values()->all();
    }

    /**
     * Tenant con occupazioni temporanee scadute (dispatcher del rilascio, 08 "Job cross-tenant"): la
     * connessione è quella di app_admin_ops e si leggono solo gli ID dei tenant.
     *
     * @param  list<OccupancyTempReason>  $reasons
     * @return list<string>
     */
    public static function tenantsWithExpiredTemporary(ConnectionInterface $connection, CarbonImmutable $now, array $reasons, int $limit = 500): array
    {
        $placeholders = implode(',', array_fill(0, count($reasons), '?'));
        $rows = $connection->select(
            "select distinct tenant_id::text as tenant_id from inventory_occupancies
             where status = 'ACTIVE' and phase = 'TEMPORARY' and temp_reason in ({$placeholders}) and expires_at < ?
             limit ?",
            [...array_map(fn (OccupancyTempReason $r): string => $r->value, $reasons), $now->toIso8601ZuluString('microsecond'), $limit],
        );

        return array_map(fn (object $row): string => (string) $row->tenant_id, $rows);
    }

    /**
     * Violazioni di I15 in tutti i tenant (05 "Riconciliazione", allerta P1): occupazione RESERVATION
     * attiva con prenotazione terminale, o prenotazione occupante senza occupazione attiva. Da usare
     * con la connessione app_admin_ops (sola lettura, 08); restituisce solo ID.
     *
     * @return list<array{tenant_id: string, reservation_id: string, problem: string}>
     */
    public static function i15Violations(ConnectionInterface $connection, int $limit = 100): array
    {
        $occupying = array_values(array_filter(ReservationStatus::cases(), fn (ReservationStatus $s): bool => $s->occupies()));
        $terminal = array_values(array_filter(ReservationStatus::cases(), fn (ReservationStatus $s): bool => ! $s->occupies()));
        $list = fn (array $cases): string => implode(',', array_map(fn (ReservationStatus $s): string => "'".$s->value."'", $cases));

        $rows = $connection->select(
            "(select o.tenant_id::text as tenant_id, o.reservation_id::text as reservation_id, 'active_occupancy_on_terminal' as problem
              from inventory_occupancies o
              join reservations r on r.tenant_id = o.tenant_id and r.id = o.reservation_id
              where o.status = 'ACTIVE' and o.kind = 'RESERVATION' and r.status in ({$list($terminal)}) limit ?)
             union all
             (select r.tenant_id::text, r.id::text, 'occupying_without_occupancy'
              from reservations r
              where r.status in ({$list($occupying)})
                and not exists (select 1 from inventory_occupancies o where o.tenant_id = r.tenant_id and o.reservation_id = r.id and o.status = 'ACTIVE')
              limit ?)",
            [$limit, $limit],
        );

        return array_map(fn (object $row): array => ['tenant_id' => (string) $row->tenant_id, 'reservation_id' => (string) $row->reservation_id, 'problem' => (string) $row->problem], $rows);
    }

    /**
     * Occupazioni attive di un'unità che toccano l'intervallo, come fatti in sola lettura per i feed
     * iCal, la classificazione degli echi e i conflitti (06). Nessun dato personale (la nota resta fuori).
     *
     * @return list<array{id: string, kind: OccupancyKind, reservation_id: string|null, stay: StayRange, ics_uid: string, ics_sequence: int, created_at: CarbonImmutable, updated_at: CarbonImmutable}>
     */
    public function occupancyFacts(string $unitId, StayRange $range): array
    {
        return $this->activeInRange($unitId, $range)->map(fn (InventoryOccupancy $o): array => [
            'id' => $o->id,
            'kind' => $o->kind,
            'reservation_id' => $o->reservation_id,
            'stay' => $o->stay,
            'ics_uid' => $o->ics_uid,
            'ics_sequence' => $o->ics_sequence,
            'created_at' => CarbonImmutable::instance($o->created_at ?? now()),
            'updated_at' => CarbonImmutable::instance($o->updated_at ?? now()),
        ])->values()->all();
    }

    /**
     * Prenotazione di ciascuna occupazione (null per i blocchi manuali), per le viste del pannello.
     *
     * @param  list<string>  $occupancyIds
     * @return array<string, string|null>
     */
    public function reservationIdsFor(array $occupancyIds): array
    {
        if ($occupancyIds === []) {
            return [];
        }

        return InventoryOccupancy::query()->whereIn('id', $occupancyIds)->pluck('reservation_id', 'id')
            ->map(fn ($id) => $id === null ? null : (string) $id)->all();
    }

    /**
     * Occupazioni attive di un'unità nell'intervallo per il calendario del pannello (07), come array:
     * fuori da questo servizio il modello delle occupazioni non si usa (CLAUDE.md regola 13).
     *
     * @return list<array<string, mixed>>
     */
    public function calendarOccupancies(string $unitId, StayRange $range): array
    {
        return $this->activeInRange($unitId, $range)->map(fn (InventoryOccupancy $o): array => self::view($o))->values()->all();
    }

    /**
     * Vista di un'occupazione per le risposte del pannello. Le note dei blocchi sono del gestore.
     *
     * @return array<string, mixed>
     */
    public static function view(InventoryOccupancy $o): array
    {
        return [
            'id' => $o->id,
            'unit_id' => $o->unit_id,
            'kind' => $o->kind->value,
            'phase' => $o->phase->value,
            'temp_reason' => $o->temp_reason?->value,
            'status' => $o->status->value,
            'check_in' => $o->stay->checkIn->toDateString(),
            'check_out' => $o->stay->checkOut->toDateString(),
            'expires_at' => $o->expires_at?->toIso8601ZuluString(),
            'reservation_id' => $o->reservation_id,
            'note' => $o->note,
        ];
    }

    // ---------------------------------------------------------------- interni

    /**
     * @template T
     *
     * @param  Closure(Unit): T  $fn
     * @return T
     */
    private function transaction(Unit $unit, StayRange $stay, Closure $fn, ?string $excludeOccupancyId = null): mixed
    {
        try {
            return DB::transaction(fn () => $fn($this->lockUnit($unit->id)));
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? $e->getCode()) === self::GIST_VIOLATION) {
                throw $this->unavailable($unit->id, $stay, $excludeOccupancyId);
            }

            throw $e;
        }
    }

    private function lockUnit(string $unitId): Unit
    {
        return Unit::query()->lockForUpdate()->findOrFail($unitId);
    }

    private function unitOf(InventoryOccupancy $occupancy): Unit
    {
        return Unit::query()->findOrFail($occupancy->unit_id);
    }

    /** 02: unità ACTIVE e vendite non sospese; altrimenti 409 UNIT_NOT_BOOKABLE (ADR-0010). */
    private function assertSellable(Unit $unit): void
    {
        if ($unit->status !== UnitStatus::ACTIVE || $unit->sales_suspended) {
            throw BusinessRuleViolation::make(409, 'UNIT_NOT_BOOKABLE', ['unit_id' => $unit->id]);
        }
    }

    private function assertOccupying(Reservation $reservation): void
    {
        if (! $reservation->status->occupies()) {
            throw new LogicException('La prenotazione non è in uno stato che occupa le date (I15).');
        }
    }

    /** Eventi esterni ACTIVE o PENDING_REMOVAL (qualsiasi echo_kind) e conflitti aperti (02). */
    private function assertNoExternalBlocks(Unit $unit, StayRange $stay, ?string $excludeOccupancyId = null): void
    {
        $blocked = self::externalBlocksQuery($unit->id, $stay)->exists();

        if ($blocked) {
            throw $this->unavailable($unit->id, $stay, $excludeOccupancyId);
        }
    }

    /**
     * Intervalli che bloccano le date di un'unità senza essere occupazioni interne (02, fonti 2 e 3).
     *
     * @return Builder
     */
    public static function externalBlocksQuery(string $unitId, StayRange $range)
    {
        $events = DB::table('external_calendar_events as e')
            ->join('external_calendars as c', function ($join): void {
                $join->on('c.id', '=', 'e.external_calendar_id')->on('c.tenant_id', '=', 'e.tenant_id');
            })
            ->where('c.unit_id', $unitId)
            ->whereIn('e.status', [ExternalEventStatus::ACTIVE->value, ExternalEventStatus::PENDING_REMOVAL->value])
            ->whereRaw('e.stay && ?::daterange', [$range->toPostgres()])
            ->select('e.stay');

        $conflicts = DB::table('inventory_conflicts')
            ->where('unit_id', $unitId)
            ->whereIn('status', [ConflictStatus::OPEN->value, ConflictStatus::INVESTIGATING->value])
            ->whereRaw('stay && ?::daterange', [$range->toPostgres()])
            ->select('stay');

        return $events->unionAll($conflicts);
    }

    private function insert(
        Unit $unit,
        StayRange $stay,
        OccupancyKind $kind,
        OccupancyPhase $phase,
        ?OccupancyTempReason $reason,
        ?CarbonImmutable $expiresAt,
        ?string $reservationId,
        ?string $note,
    ): InventoryOccupancy {
        $id = (string) Str::uuid7();

        $occupancy = new InventoryOccupancy;
        $occupancy->id = $id;
        $occupancy->forceFill([
            'unit_id' => $unit->id,
            'stay' => $stay,
            'kind' => $kind,
            'phase' => $phase,
            'temp_reason' => $reason,
            'expires_at' => $expiresAt,
            'reservation_id' => $reservationId,
            'status' => OccupancyStatus::ACTIVE,
            'ics_uid' => 'occ-'.$id.'@'.self::icsDomain(),
            'note' => $note,
        ])->save();

        return $occupancy;
    }

    /** @param  Closure(InventoryOccupancy): void  $change */
    private function updateActive(Reservation $reservation, string $action, Closure $change): InventoryOccupancy
    {
        $this->assertOccupying($reservation);
        $occupancy = $this->activeFor($reservation);

        return DB::transaction(function () use ($occupancy, $reservation, $action, $change): InventoryOccupancy {
            $this->lockUnit($occupancy->unit_id);
            $change($occupancy);
            $occupancy->save();

            $this->audit->record($action, 'inventory_occupancy', $occupancy->id, [
                'reservation_id' => $reservation->id,
                'phase' => $occupancy->phase->value,
                'temp_reason' => $occupancy->temp_reason?->value,
                'expires_at' => $occupancy->expires_at?->toIso8601String(),
            ]);

            return $occupancy;
        });
    }

    private function markReleased(InventoryOccupancy $occupancy, string $reason): void
    {
        $occupancy->forceFill([
            'status' => OccupancyStatus::RELEASED,
            'released_at' => now()->toImmutable(),
            'release_reason' => $reason,
        ])->save();

        $this->audit->record('occupancy.released', 'inventory_occupancy', $occupancy->id, [
            'reservation_id' => $occupancy->reservation_id, 'kind' => $occupancy->kind->value, 'reason' => $reason,
        ]);
    }

    private function unavailable(string $unitId, StayRange $stay, ?string $excludeOccupancyId = null): BusinessRuleViolation
    {
        return BusinessRuleViolation::make(409, 'DATES_UNAVAILABLE', [
            'unit_id' => $unitId,
            'nights' => $this->unavailableNights($unitId, $stay, $excludeOccupancyId),
        ]);
    }

    /**
     * Notti del soggiorno non vendibili secondo le tre fonti di 02. In una gara tra due richieste
     * l'occupazione dell'altra può non essere ancora visibile: l'elenco può risultare vuoto.
     *
     * @return list<string>
     */
    private function unavailableNights(string $unitId, StayRange $stay, ?string $excludeOccupancyId): array
    {
        $ranges = $this->blockedRanges($unitId, $stay, $excludeOccupancyId);

        $nights = [];

        foreach ($stay->nightDates() as $night) {
            foreach ($ranges as $range) {
                if ($night->greaterThanOrEqualTo($range->checkIn) && $night->lessThan($range->checkOut)) {
                    $nights[] = $night->toDateString();
                    break;
                }
            }
        }

        return $nights;
    }

    public static function icsDomain(): string
    {
        return (string) (config('inventory.ics_uid_domain') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }
}
