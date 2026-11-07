<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Communications\Alerts\AlertSignal;
use App\Domain\Communications\Alerts\OperationalAlerts;
use App\Domain\Payments\Models\WebhookEvent;
use App\Domain\Payments\StateMachines\WebhookEventStateMachine;
use App\Domain\Payments\Stripe\StripeEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Passo 6 del webhook (05): elaborazione dell'evento con rilettura via API. Il tenant arriva già
 * verificato dal ricevitore (account connesso e metadata). Non usa InteractsWithTenant: le letture a
 * Stripe stanno fuori dalle transazioni e ogni passo apre il proprio contesto.
 *
 * Errore → FAILED con il tipo d'errore e nuovo tentativo con backoff; dal terzo tentativo fallito di
 * seguito un'allerta (08 "Webhook Stripe falliti ≥ 3").
 */
final class ProcessStripeWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800, 3600];

    /**
     * @param  array{object_id: string, payment_id: ?string, refund_id: ?string, payment_intent: ?string}  $reference
     */
    public function __construct(
        public readonly string $webhookEventId,
        public readonly string $tenantId,
        public readonly string $type,
        public readonly array $reference,
    ) {}

    public function handle(StripeEventProcessor $processor, WebhookEventStateMachine $events): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event === null || $events->isFinal($event)) {
            return;
        }

        try {
            $outcome = $processor->process($this->tenantId, $this->type, $this->reference);
        } catch (Throwable $e) {
            $events->failed($event, class_basename($e));

            if ($event->attempts >= 3) {
                app(OperationalAlerts::class)->raise(AlertSignal::WEBHOOK_FAILING, ['event_id' => $event->event_id, 'type' => $event->type, 'attempts' => $event->attempts], key: 'webhooks');
            }

            throw $e;
        }

        $events->completed($event, $outcome === 'processed');
    }
}
