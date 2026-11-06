<?php

namespace App\Domain\Tenancy\StateMachines;

use App\Domain\Tenancy\Enums\OutboxStatus;
use App\Domain\Tenancy\Models\OutboxMessage;
use LogicException;

/**
 * Stati di OutboxMessage: PENDING → SENT, PENDING → PENDING (nuovo tentativo con backoff),
 * PENDING → FAILED dopo l'ultimo tentativo, FAILED → PENDING solo per un nuovo invio manuale.
 */
final class OutboxMessageStateMachine
{
    public function markSent(OutboxMessage $message): void
    {
        $this->assertPending($message);

        $message->status = OutboxStatus::SENT;
        $message->attempts++;
        $message->sent_at = now()->toImmutable();
        $message->last_error = null;
        $message->save();
    }

    /**
     * Registra un tentativo fallito: nuovo tentativo dopo il backoff, oppure FAILED dopo l'ultimo.
     * $error non deve contenere dati personali (si passa la classe dell'eccezione).
     */
    public function recordFailure(OutboxMessage $message, string $error): void
    {
        $this->assertPending($message);

        $message->attempts++;
        $message->last_error = mb_substr($error, 0, 500);

        if ($message->attempts >= (int) config('outbox.max_attempts')) {
            $message->status = OutboxStatus::FAILED;
            $message->failed_at = now()->toImmutable();
        } else {
            $message->available_at = now()->addSeconds(self::backoffSeconds($message->attempts))->toImmutable();
        }

        $message->save();
    }

    /** Nuovo invio manuale di un messaggio fallito (pannello o supporto). */
    public function retry(OutboxMessage $message): void
    {
        if ($message->status !== OutboxStatus::FAILED) {
            throw new LogicException('Solo un messaggio FAILED si può reinviare.');
        }

        $message->status = OutboxStatus::PENDING;
        $message->attempts = 0;
        $message->failed_at = null;
        $message->available_at = now()->toImmutable();
        $message->save();
    }

    public static function backoffSeconds(int $attempts): int
    {
        /** @var list<int> $steps */
        $steps = config('outbox.backoff_seconds');

        return (int) ($steps[min($attempts, count($steps)) - 1] ?? end($steps));
    }

    private function assertPending(OutboxMessage $message): void
    {
        if ($message->status !== OutboxStatus::PENDING) {
            throw new LogicException('Il messaggio non è in attesa di consegna.');
        }
    }
}
