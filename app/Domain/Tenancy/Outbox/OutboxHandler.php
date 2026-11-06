<?php

namespace App\Domain\Tenancy\Outbox;

use App\Domain\Tenancy\Models\OutboxMessage;

/**
 * Consegna un tipo di messaggio in uscita. Gira dentro il contesto del tenant del messaggio.
 * Un'eccezione fa ripetere la consegna con backoff: il gestore deve essere idempotente rispetto
 * a dedupe_key (es. chiave di idempotenza verso il provider).
 */
interface OutboxHandler
{
    public function handle(OutboxMessage $message): void;
}
