<?php

namespace App\Domain\Payments\Stripe;

use App\Domain\Communications\Alerts\AlertSignal;
use App\Domain\Communications\Alerts\OperationalAlerts;
use App\Domain\Payments\Enums\WebhookEventStatus;
use App\Domain\Payments\Jobs\ProcessStripeWebhookJob;
use App\Domain\Payments\Models\WebhookEvent;
use App\Domain\Payments\StateMachines\WebhookEventStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Event;
use Stripe\StripeObject;

/**
 * Passi 2–5 della procedura del webhook (05), dopo la verifica della firma:
 *
 * 2. WebhookEvent unico per (provider, event_id): già PROCESSED o IGNORED → nessun effetto (I3);
 * 3. tenant da event.account con la funzione SECURITY DEFINER app_tenant_by_stripe_account; account
 *    sconosciuto o assente → IGNORED;
 * 4. metadata.tenant_id dell'oggetto, se presente, deve coincidere: altrimenti FAILED e allerta di
 *    sicurezza. Sessioni e PaymentIntent senza i nostri metadata non sono nostri (per esempio un link
 *    di pagamento creato dal gestore nella sua dashboard) → IGNORED;
 * 5. accoda il job con i soli identificativi; il chiamante risponde 200.
 *
 * Del payload si salva solo l'hash (08). Nei log solo ID e tipo.
 */
final class StripeWebhookReceiver
{
    public const PROVIDER = 'stripe';

    /** Eventi di oggetti creati da noi con metadata.tenant_id: senza metadata non ci riguardano. */
    private const OWN_METADATA_PREFIXES = ['checkout.session.', 'payment_intent.'];

    public function __construct(private readonly WebhookEventStateMachine $events) {}

    public function receive(Event $event, string $payload): WebhookEventStatus
    {
        $record = $this->events->receive(self::PROVIDER, (string) $event->id, [
            'type' => (string) $event->type,
            'account_id' => is_string($event->account ?? null) ? $event->account : null,
            'livemode' => (bool) $event->livemode,
            'payload_hash' => hash('sha256', $payload),
        ]);

        if ($this->events->isFinal($record)) {
            return $record->status;
        }

        $type = (string) $event->type;
        $account = is_string($event->account ?? null) ? $event->account : null;
        $object = $event->data->object ?? null;

        if (! $object instanceof StripeObject || ! StripeEventProcessor::handles($type) || $account === null) {
            return $this->finish($record, WebhookEventStatus::IGNORED, null);
        }

        $tenantId = DB::selectOne('SELECT app_tenant_by_stripe_account(?) AS id', [$account])?->id;

        if (! is_string($tenantId)) {
            Log::info('stripe.webhook_unknown_account', ['event_id' => $record->event_id, 'type' => $type]);

            return $this->finish($record, WebhookEventStatus::IGNORED, 'unknown_account');
        }

        $metadata = $object['metadata'] ?? null;
        $metadataTenant = $metadata instanceof StripeObject && is_string($metadata['tenant_id'] ?? null) ? $metadata['tenant_id'] : null;

        if ($metadataTenant !== null && $metadataTenant !== $tenantId) {
            app(OperationalAlerts::class)->raise(AlertSignal::WEBHOOK_TENANT_MISMATCH, ['event_id' => $record->event_id, 'type' => $type, 'tenant_id' => $tenantId], key: $record->event_id);

            return $this->finish($record, WebhookEventStatus::FAILED, 'tenant_mismatch');
        }

        if ($metadataTenant === null && Str::startsWith($type, self::OWN_METADATA_PREFIXES)) {
            return $this->finish($record, WebhookEventStatus::IGNORED, 'foreign_object');
        }

        ProcessStripeWebhookJob::dispatch($record->id, $tenantId, $type, self::reference($object, $metadata instanceof StripeObject ? $metadata : null));

        return WebhookEventStatus::RECEIVED;
    }

    private function finish(WebhookEvent $record, WebhookEventStatus $status, ?string $reason): WebhookEventStatus
    {
        return $this->events->close($record, $status, $reason);
    }

    /**
     * Identificativi per trovare i nostri record: l'oggetto, i nostri ID nei metadata e il
     * PaymentIntent collegato (Charge, contestazioni). Nessun dato personale.
     *
     * @return array{object_id: string, payment_id: ?string, refund_id: ?string, payment_intent: ?string}
     */
    private static function reference(StripeObject $object, ?StripeObject $metadata): array
    {
        $intent = $object['payment_intent'] ?? null;

        return [
            'object_id' => (string) $object['id'],
            'payment_id' => $metadata !== null && is_string($metadata['payment_id'] ?? null) ? $metadata['payment_id'] : null,
            'refund_id' => $metadata !== null && is_string($metadata['refund_id'] ?? null) ? $metadata['refund_id'] : null,
            'payment_intent' => is_string($intent) ? $intent : ($intent instanceof StripeObject ? (string) $intent['id'] : null),
        ];
    }
}
