<?php

namespace App\Domain\Inventory;

use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Enums\SyncHealth;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Money;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Configurazione dell'unità (01, 02, 07; 11, attività 2.1 e 2.5). Ogni unità nasce con il suo piano
 * tariffario attivo (04: un solo RatePlan attivo per unità), con il prezzo base indicato alla creazione.
 *
 * I12: booking_mode = INSTANT rifiutato con 409 EXTERNAL_CALENDARS_ACTIVE se l'unità ha calendari
 * esterni attivi (stato diverso da DISABLED); lo stesso vincolo è garantito da un trigger nel database
 * (SQLSTATE BE012), che qui si traduce nello stesso errore.
 *
 * Stato dell'unità (ACTIVE/INACTIVE) e sospensione delle vendite cambiano solo da qui (eccezione
 * esplicita di Arch/StateTransitionsTest; non sono macchine a stati di 03).
 */
final class UnitService
{
    /** SQLSTATE dei trigger di I12 (migration enforce_instant_without_external_calendars). */
    public const I12_SQLSTATE = 'BE012';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  campi validati, senza stato né tenant
     * @param  array<string, array<string, string|null>|null>  $translations
     */
    public function create(Property $property, array $attributes, int $baseNightlyCents, array $translations = []): Unit
    {
        $unit = new Unit;
        $unit->fill($attributes);
        $unit->property_id = $property->id;
        $this->assertOccupancy($unit);
        $this->save($unit);

        $ratePlan = new RatePlan;
        $ratePlan->forceFill([
            'unit_id' => $unit->id,
            'name' => __('pricing.default_rate_plan_name'),
            'base_nightly_cents' => $baseNightlyCents,
            'currency' => Money::CURRENCY,
        ])->save();

        Translations::sync($unit->translations(), $translations);
        $this->audit->recordModelChange('unit.created', $unit, ['base_nightly_cents' => $baseNightlyCents]);

        return $unit->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes  campi validati; status solo ACTIVE o INACTIVE
     * @param  array<string, array<string, string|null>|null>|null  $translations
     */
    public function update(Unit $unit, array $attributes, ?array $translations = null): Unit
    {
        $status = $attributes['status'] ?? null;
        unset($attributes['status']);

        $unit->fill($attributes);

        if ($status !== null) {
            $unit->status = $status instanceof UnitStatus ? $status : UnitStatus::from((string) $status);
        }

        $this->assertOccupancy($unit);

        if ($unit->isDirty('booking_mode') && $unit->booking_mode === BookingMode::INSTANT) {
            $this->assertNoActiveCalendars($unit);
        }

        $dirty = $unit->isDirty();
        $this->save($unit);

        if ($translations !== null && Translations::sync($unit->translations(), $translations)) {
            if (! $dirty) {
                $unit->bumpVersion();
            }

            $this->audit->record('unit.translations_updated', 'unit', $unit->id, ['locales' => array_keys($translations)]);
        }

        if ($dirty) {
            $this->audit->recordModelChange('unit.updated', $unit);
        }

        return $unit->refresh();
    }

    /** Sospende o riattiva le vendite dell'unità (07, PATCH /units/{id}/sales). */
    public function setSalesSuspended(Unit $unit, bool $suspended): Unit
    {
        if ($unit->sales_suspended !== $suspended) {
            $unit->sales_suspended = $suspended;
            $this->save($unit);
            $this->audit->record($suspended ? 'unit.sales_suspended' : 'unit.sales_resumed', 'unit', $unit->id);
        }

        return $unit->refresh();
    }

    /**
     * Salute della sincronizzazione dell'unità (06 "Salute"), calcolata da SyncHealthService dagli stati
     * dei calendari importati. Non cambia la versione dell'unità: non è una modifica del gestore.
     */
    public function setSyncHealth(Unit $unit, SyncHealth $health): bool
    {
        if ($unit->sync_health === $health) {
            return false;
        }

        $from = $unit->sync_health;
        $unit->sync_health = $health;
        $unit->saveQuietly();
        $this->audit->record('unit.sync_health_changed', 'unit', $unit->id, ['from' => $from->value, 'to' => $health->value]);

        return true;
    }

    /**
     * Conferma esplicita di un passo della checklist di collegamento dei calendari (06).
     */
    public function confirmChecklistStep(Unit $unit, string $step, ?string $userId): Unit
    {
        $checklist = $unit->calendar_checklist;
        $checklist[$step] = ['confirmed_at' => now()->toIso8601ZuluString(), 'confirmed_by' => $userId];
        $unit->calendar_checklist = $checklist;
        $unit->saveQuietly();
        $this->audit->record('unit.calendar_checklist_confirmed', 'unit', $unit->id, ['step' => $step]);

        return $unit;
    }

    /** I12 in applicazione: nessun calendario esterno attivo per un'unità INSTANT. */
    public function assertNoActiveCalendars(Unit $unit): void
    {
        $active = ExternalCalendar::query()
            ->where('unit_id', $unit->id)
            ->where('status', '<>', ExternalCalendarStatus::DISABLED->value)
            ->count();

        if ($active > 0) {
            throw BusinessRuleViolation::make(409, 'EXTERNAL_CALENDARS_ACTIVE', ['active_calendars' => $active]);
        }
    }

    private function save(Unit $unit): void
    {
        try {
            $unit->save();
        } catch (QueryException $e) {
            if ($e->getCode() === self::I12_SQLSTATE) {
                throw BusinessRuleViolation::make(409, 'EXTERNAL_CALENDARS_ACTIVE');
            }

            throw $e;
        }
    }

    private function assertOccupancy(Unit $unit): void
    {
        if ($unit->base_occupancy > $unit->max_occupancy) {
            throw ValidationException::withMessages([
                'max_occupancy' => __('inventory.validation.max_below_base'),
            ]);
        }
    }
}
