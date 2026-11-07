<?php

/*
 * Processo figlio: un blocco manuale sulle stesse notti tramite InventoryService.
 * Argomenti: start, tenant, unità, check-in, check-out.
 */

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;

require __DIR__.'/bootstrap.php';

[, , $tenant, $unit, $checkIn, $checkOut] = $argv;

waitForStart($argv);

try {
    $occupancy = app(TenantContext::class)->run($tenant, fn () => app(InventoryService::class)->acquireBlock(
        Unit::query()->findOrFail($unit),
        StayRange::of($checkIn, $checkOut),
        null,
    ));

    report(['ok' => true, 'id' => $occupancy->id]);
} catch (BusinessRuleViolation $e) {
    report(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    report(['ok' => false, 'code' => 'EXCEPTION', 'message' => $e::class.': '.mb_substr($e->getMessage(), 0, 300)]);
}
