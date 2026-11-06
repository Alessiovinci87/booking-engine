<?php

namespace App\Domain\Inventory;

/**
 * Unico scrittore di inventory_occupancies (02, I1, I15; CLAUDE.md regola 13).
 *
 * Segnaposto di M0: i metodi acquire, promoteToFirm, setTemporaryReason, move e release arrivano
 * con l'attività 3.1. Il controllo tests/Arch/InventoryWritesTest impedisce già oggi a qualsiasi
 * altra classe di usare il modello o la tabella delle occupazioni.
 */
final class InventoryService {}
