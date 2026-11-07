<?php

namespace App\Domain\Payments\StateMachines;

use App\Domain\Payments\Enums\WebhookEventStatus;
use App\Domain\Payments\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Stati di un WebhookEvent (02 "WebhookEvent", I3): RECEIVED → PROCESSED | IGNORED | FAILED, FAILED →
 * nuovo tentativo. PROCESSED e IGNORED sono finali: una nuova consegna dello stesso evento non ha effetti.
 * Tabella globale senza tenant (02 "Integrità tra tenant"), nessun audit: contiene solo ID e hash.
 */
final class WebhookEventStateMachine
{
    /**
     * Registra l'evento una sola volta per (provider, event_id); con due consegne contemporanee vince la prima.
     *
     * @param  array{type: string, account_id: ?string, livemode: bool, payload_hash: string}  $attributes
     */
    public function receive(string $provider, string $eventId, array $attributes): WebhookEvent
    {
        $attributes['status'] = WebhookEventStatus::RECEIVED;
        $attributes['received_at'] = CarbonImmutable::now();

        try {
            return WebhookEvent::query()->firstOrCreate(['provider' => $provider, 'event_id' => $eventId], $attributes);
        } catch (UniqueConstraintViolationException) {
            return WebhookEvent::query()->where('provider', $provider)->where('event_id', $eventId)->firstOrFail();
        }
    }

    public function isFinal(WebhookEvent $event): bool
    {
        return in_array($event->status, [WebhookEventStatus::PROCESSED, WebhookEventStatus::IGNORED], true);
    }

    /** Chiusura senza elaborazione (tipo non gestito, account sconosciuto, oggetto estraneo, tenant incoerente). */
    public function close(WebhookEvent $event, WebhookEventStatus $status, ?string $reason): WebhookEventStatus
    {
        $event->forceFill(['status' => $status, 'last_error' => $reason, 'processed_at' => CarbonImmutable::now()])->save();

        return $status;
    }

    /** Esito dell'elaborazione nel job: un tentativo in più. */
    public function completed(WebhookEvent $event, bool $processed): void
    {
        $event->forceFill([
            'status' => $processed ? WebhookEventStatus::PROCESSED : WebhookEventStatus::IGNORED,
            'attempts' => $event->attempts + 1,
            'last_error' => null,
            'processed_at' => CarbonImmutable::now(),
        ])->save();
    }

    /** Tentativo fallito: solo il nome della classe dell'errore, mai il messaggio. */
    public function failed(WebhookEvent $event, string $error): void
    {
        $event->forceFill(['status' => WebhookEventStatus::FAILED, 'attempts' => $event->attempts + 1, 'last_error' => $error])->save();
    }
}
