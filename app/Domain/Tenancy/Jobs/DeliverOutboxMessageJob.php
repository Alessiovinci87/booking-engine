<?php

namespace App\Domain\Tenancy\Jobs;

use App\Domain\Tenancy\Enums\OutboxStatus;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\Outbox\OutboxHandler;
use App\Domain\Tenancy\StateMachines\OutboxMessageStateMachine;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Consegna di un singolo messaggio, con app_runtime nel contesto del suo tenant. La riga si blocca
 * (FOR UPDATE) per tutta la consegna: due worker non inviano lo stesso messaggio. Un errore del
 * gestore registra il tentativo e programma il successivo con backoff (OutboxMessageStateMachine);
 * il job in sé non si ripete, ci pensa il dispatcher.
 */
final class DeliverOutboxMessageJob implements ShouldBeUnique, ShouldQueue, TenantAwareJob
{
    use InteractsWithTenant;
    use Queueable;

    public int $tries = 1;

    /** Il lock di unicità decade comunque dopo 10 minuti (worker interrotto). */
    public int $uniqueFor = 600;

    public function __construct(string $tenantId, public readonly string $messageId)
    {
        $this->tenantId = $tenantId;
    }

    public function uniqueId(): string
    {
        return $this->messageId;
    }

    public function handle(Container $container, OutboxMessageStateMachine $states): void
    {
        $message = OutboxMessage::query()->whereKey($this->messageId)->lockForUpdate()->first();

        if ($message === null || $message->status !== OutboxStatus::PENDING || $message->available_at->isFuture()) {
            return;
        }

        try {
            // Savepoint: un errore di database del gestore non interrompe la transazione del job,
            // che deve ancora registrare il tentativo.
            DB::transaction(fn () => $this->handler($container, $message->type)->handle($message));
        } catch (Throwable $e) {
            // Solo la classe dell'eccezione: i messaggi dei provider possono contenere dati personali.
            $states->recordFailure($message, $e::class);

            return;
        }

        $states->markSent($message);
    }

    private function handler(Container $container, string $type): OutboxHandler
    {
        /** @var array<string, class-string<OutboxHandler>> $handlers */
        $handlers = config('outbox.handlers', []);

        if (! isset($handlers[$type])) {
            throw new RuntimeException('Nessun gestore per il tipo di messaggio.');
        }

        return $container->make($handlers[$type]);
    }
}
