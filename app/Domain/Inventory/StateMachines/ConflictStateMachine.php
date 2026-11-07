<?php

namespace App\Domain\Inventory\StateMachines;

use App\Domain\Inventory\Enums\ConflictResolution;
use App\Domain\Inventory\Enums\ConflictStatus as S;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Outbox\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Macchina a stati del conflitto (03 sezione 5):
 *
 * | Da | Evento | A |
 * |---|---|---|
 * | — | rilevamento (06) | OPEN, notifica immediata |
 * | OPEN | prendo in carico | INVESTIGATING |
 * | OPEN, INVESTIGATING | risolve | RESOLVED, `resolution` obbligatoria |
 * | OPEN, INVESTIGATING | ignora | IGNORED, note obbligatorie |
 * | OPEN | evento esterno rimosso con conferma | RESOLVED, nota automatica, notifica |
 *
 * La chiusura rimuove solo il blocco del conflitto (I13): le altre fonti restano. Le note sono dati
 * del gestore (09): nell'audit solo "[personal]".
 */
final class ConflictStateMachine
{
    public const AUTO_NOTE = 'Risolto automaticamente: l\'evento esterno è stato rimosso dal calendario.';

    public function __construct(private readonly AuditLogger $audit, private readonly Outbox $outbox) {}

    public function open(string $unitId, StayRange $stay, ?string $occupancyId, ?string $externalEventId, ?string $otherExternalEventId, bool $suspectedEcho): InventoryConflict
    {
        $conflict = new InventoryConflict;
        $conflict->id = (string) Str::uuid7();
        $conflict->forceFill([
            'unit_id' => $unitId,
            'stay' => $stay,
            'occupancy_id' => $occupancyId,
            'external_event_id' => $externalEventId,
            'other_external_event_id' => $otherExternalEventId,
            'suspected_echo' => $suspectedEcho,
            'status' => S::OPEN,
            'detected_at' => CarbonImmutable::now(),
        ])->save();

        $this->audit->record('conflict.opened', 'inventory_conflict', $conflict->id, [
            'unit_id' => $unitId, 'check_in' => $stay->checkIn->toDateString(), 'check_out' => $stay->checkOut->toDateString(),
            'occupancy_id' => $occupancyId, 'external_event_id' => $externalEventId,
            'other_external_event_id' => $otherExternalEventId, 'suspected_echo' => $suspectedEcho,
        ]);
        $this->outbox->enqueue('calendar.conflict_opened', 'calendar.conflict_opened:'.$conflict->id, ['conflict_id' => $conflict->id]);

        return $conflict;
    }

    public function investigate(InventoryConflict $conflict, ?string $userId): InventoryConflict
    {
        $this->assertFrom($conflict, [S::OPEN], 'investigate');
        $this->move($conflict, S::INVESTIGATING, ['by' => $userId]);

        return $conflict;
    }

    public function resolve(InventoryConflict $conflict, ConflictResolution $resolution, ?string $notes, ?string $userId): InventoryConflict
    {
        $this->assertFrom($conflict, [S::OPEN, S::INVESTIGATING], 'resolve');

        $conflict->resolution = $resolution;
        $conflict->resolution_notes = $notes;
        $conflict->resolved_at = CarbonImmutable::now();
        $conflict->resolved_by = $userId;
        $this->move($conflict, S::RESOLVED, ['resolution' => $resolution->value, 'notes' => $notes === null ? null : '[personal]', 'by' => $userId]);

        return $conflict;
    }

    public function ignore(InventoryConflict $conflict, string $notes, ?string $userId): InventoryConflict
    {
        $this->assertFrom($conflict, [S::OPEN, S::INVESTIGATING], 'ignore');

        $conflict->resolution_notes = $notes;
        $conflict->resolved_at = CarbonImmutable::now();
        $conflict->resolved_by = $userId;
        $this->move($conflict, S::IGNORED, ['notes' => '[personal]', 'by' => $userId]);

        return $conflict;
    }

    /** Evento esterno rimosso con conferma (06 "Rimozione" punto 4): solo dai conflitti OPEN. */
    public function resolveAutomatically(InventoryConflict $conflict): bool
    {
        if ($conflict->status !== S::OPEN) {
            return false;
        }

        $conflict->resolution = ConflictResolution::CANCELLED_EXTERNAL;
        $conflict->resolution_notes = self::AUTO_NOTE;
        $conflict->resolved_at = CarbonImmutable::now();
        $this->move($conflict, S::RESOLVED, ['resolution' => ConflictResolution::CANCELLED_EXTERNAL->value, 'automatic' => true]);
        $this->outbox->enqueue('calendar.conflict_auto_resolved', 'calendar.conflict_auto_resolved:'.$conflict->id, ['conflict_id' => $conflict->id]);

        return true;
    }

    /** @param  list<S>  $allowed */
    private function assertFrom(InventoryConflict $conflict, array $allowed, string $event): void
    {
        if (! in_array($conflict->status, $allowed, true)) {
            throw IllegalTransition::of('conflict', $conflict->status->value, $event);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function move(InventoryConflict $conflict, S $to, array $extra = []): void
    {
        $from = $conflict->status;
        $conflict->status = $to;
        $conflict->save();

        $this->audit->record('conflict.status_changed', 'inventory_conflict', $conflict->id, [
            'from' => $from->value, 'to' => $to->value, ...$extra,
        ]);
    }
}
