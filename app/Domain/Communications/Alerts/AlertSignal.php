<?php

namespace App\Domain\Communications\Alerts;

/**
 * Segnali della tabella "Monitoraggio e allerte" di 08 gestiti nell'applicazione (6.5). Il valore è
 * il messaggio di log. Esclusi qui: errori applicativi (Sentry), backup e test di ripristino, uptime
 * della pagina ospite (6.4); le email al gestore (capture fallita, rimborso fallito, conflitto,
 * calendario UNHEALTHY) passano dall'outbox del tenant.
 */
enum AlertSignal: string
{
    case QUEUE_DELAYED = 'alert.queue_delayed';
    case WEBHOOK_FAILING = 'stripe.webhook_failing';
    case CAPTURING_OVERDUE = 'payments.capturing_overdue';
    case OCCUPANCY_RELEASE_OVERDUE = 'occupancy_release.overdue';
    case I15_VIOLATION = 'invariant.i15_violation';
    case REFUND_FAILED_FINAL = 'payments.refund_failed_final';
    case REFUND_REVERSED = 'payments.refund_reversed';
    case WEBHOOK_TENANT_MISMATCH = 'security.webhook_tenant_mismatch';
    case CALENDARS_UNHEALTHY_DAILY = 'alert.calendars_unhealthy_daily';

    /** Livello PSR-3 del log. */
    public function level(): string
    {
        return match ($this) {
            self::I15_VIOLATION, self::REFUND_REVERSED, self::WEBHOOK_TENANT_MISMATCH => 'critical',
            self::QUEUE_DELAYED, self::WEBHOOK_FAILING, self::CAPTURING_OVERDUE, self::OCCUPANCY_RELEASE_OVERDUE, self::REFUND_FAILED_FINAL => 'error',
            self::CALENDARS_UNHEALTHY_DAILY => 'warning',
        };
    }

    /**
     * Segnali con canale "push" (o interno) in 08: email alla piattaforma finché le notifiche push non
     * arrivano con 6.4. Il rimborso fallito e quello annullato hanno già l'email al gestore; resta il
     * log (e per l'annullato anche l'email interna: è urgente).
     */
    public function emailsPlatform(): bool
    {
        return $this !== self::REFUND_FAILED_FINAL;
    }
}
