<?php

namespace App\Http\Api;

use RuntimeException;

/**
 * Lanciata dentro un savepoint per annullare di proposito un'operazione eseguita solo per
 * anteprima (dry run): nessuna scrittura resta nel database.
 */
final class DryRunRollback extends RuntimeException {}
