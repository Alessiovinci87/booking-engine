<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Enums\SyncHealth;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Pricing\PriceQuoteService;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;

/**
 * Disponibilità effettiva (02): una notte è vendibile se non è contenuta in un'occupazione ACTIVE
 * (anche scaduta ma non rilasciata), in un evento esterno ACTIVE o PENDING_REMOVAL, in un conflitto
 * OPEN o INVESTIGATING. Le notti già passate (fuso della struttura) non sono vendibili.
 *
 * Le condizioni sull'unità (ACTIVE, vendite non sospese, sync_health per le unità INSTANT) danno
 * 409 UNIT_NOT_BOOKABLE (ADR-0010); struttura PUBLISHED e tenant ACTIVE li verifica PublicCatalog
 * (altrimenti 404). Le occupazioni si leggono tramite InventoryService, unico punto che usa il modello.
 */
final class AvailabilityService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PriceQuoteService $prices,
    ) {}

    public function assertBookable(Unit $unit): void
    {
        $unhealthyInstant = $unit->booking_mode === BookingMode::INSTANT && $unit->sync_health === SyncHealth::UNHEALTHY;

        if ($unit->status !== UnitStatus::ACTIVE || $unit->sales_suspended || $unhealthyInstant) {
            throw BusinessRuleViolation::make(409, 'UNIT_NOT_BOOKABLE', ['unit_id' => $unit->id]);
        }
    }

    public function assertAvailable(Unit $unit, StayRange $stay): void
    {
        $nights = $this->unavailableNights($unit, $stay);

        if ($nights !== []) {
            throw BusinessRuleViolation::make(409, 'DATES_UNAVAILABLE', ['unit_id' => $unit->id, 'nights' => $nights]);
        }
    }

    /**
     * Notti non vendibili nell'intervallo, ordinate, senza rivelare il motivo (07).
     *
     * @return list<string>
     */
    public function unavailableNights(Unit $unit, StayRange $range): array
    {
        $blocked = $this->inventory->blockedRanges($unit->id, $range);

        $today = $this->today($unit);
        $nights = [];

        foreach ($range->nightDates() as $night) {
            if ($night->toDateString() < $today || $this->contained($night, $blocked)) {
                $nights[] = $night->toDateString();
            }
        }

        return $nights;
    }

    /**
     * Risposta di GET /public/{t}/{p}/availability (07). min_nights è il minimo dell'unità;
     * min_nights_by_night elenca solo le notti con un minimo diverso (stagioni).
     *
     * @return array{unit_id: string, from: string, to: string, unavailable_nights: list<string>, min_nights: int, min_nights_by_night: array<string, int>}
     */
    public function calendar(Unit $unit, StayRange $range): array
    {
        $this->assertBookable($unit);

        return [
            'unit_id' => $unit->id,
            'from' => $range->checkIn->toDateString(),
            'to' => $range->checkOut->toDateString(),
            'unavailable_nights' => $this->unavailableNights($unit, $range),
            'min_nights' => $unit->min_nights,
            'min_nights_by_night' => $this->prices->minNightsByNight($unit, $range),
        ];
    }

    /** Data di oggi (YYYY-MM-DD) nel fuso della struttura: le notti precedenti non si vendono. */
    private function today(Unit $unit): string
    {
        return CarbonImmutable::now($unit->property->timezone)->toDateString();
    }

    /** @param  list<StayRange>  $ranges */
    private function contained(CarbonImmutable $night, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($night->greaterThanOrEqualTo($range->checkIn) && $night->lessThan($range->checkOut)) {
                return true;
            }
        }

        return false;
    }
}
