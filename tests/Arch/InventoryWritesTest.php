<?php

/*
 * Controllo statico (10): solo InventoryService scrive inventory_occupancies (02, I1, I15;
 * CLAUDE.md regola 13). Regola M0: nessuna classe fuori da InventoryService usa il modello
 * o la tabella inventory_occupancies.
 */

const INVENTORY_SERVICE = 'App\Domain\Inventory\InventoryService';

arch('il modello delle occupazioni è usato solo da InventoryService')
    ->expect([
        'App\Domain\Inventory\Models\InventoryOccupancy',
        'App\Models\InventoryOccupancy',
    ])
    ->toOnlyBeUsedIn([INVENTORY_SERVICE]);

it('nessun file di app/ fuori da InventoryService nomina la tabella o il modello delle occupazioni', function () {
    $allowed = [
        'app/Domain/Inventory/InventoryService.php',
        // Il modello dichiara la propria tabella; è a sua volta usabile solo da InventoryService.
        'app/Domain/Inventory/Models/InventoryOccupancy.php',
    ];

    $offenders = sourceFilesContaining(['inventory_occupancies', 'InventoryOccupancy'], except: $allowed);

    expect($offenders)->toBeEmpty(
        "Accesso a inventory_occupancies fuori da InventoryService:\n- ".implode("\n- ", $offenders)
    );
});
