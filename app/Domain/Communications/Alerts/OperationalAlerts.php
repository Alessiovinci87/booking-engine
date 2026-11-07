<?php

namespace App\Domain\Communications\Alerts;

use App\Domain\Communications\TransactionalEmail;
use App\Domain\Communications\TransactionalMailer;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Allerte operative interne (08 "Monitoraggio e allerte", 6.5, ADR-0014): ogni segnale va nel log al
 * suo livello e, per i segnali "push" di 08, per email agli indirizzi della piattaforma
 * (ALERT_EMAILS). Le notifiche push e Sentry arrivano con 6.4; le email al gestore restano
 * nell'outbox, per tenant.
 *
 * - Contesto: solo identificativi e conteggi, mai dati personali (08, 09).
 * - Email al più una volta per segnale e chiave nella finestra `alerts.throttle_minutes`, inviata dopo
 *   il commit della transazione in corso; un errore di invio resta nel log e non blocca il chiamante.
 */
final class OperationalAlerts
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly Cache $cache,
    ) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function raise(AlertSignal $signal, array $context = [], ?string $key = null): void
    {
        match ($signal->level()) {
            'critical' => Log::critical($signal->value, $context),
            'error' => Log::error($signal->value, $context),
            default => Log::warning($signal->value, $context),
        };

        if (! $signal->emailsPlatform()) {
            return;
        }

        $recipients = self::recipients();
        $throttleKey = 'alerts:'.$signal->value.':'.hash('sha256', $key ?? json_encode($context, JSON_THROW_ON_ERROR));
        $minutes = max(1, (int) config('alerts.throttle_minutes', 60));

        if ($recipients === [] || ! $this->cache->add($throttleKey, 1, $minutes * 60)) {
            return;
        }

        DB::afterCommit(function () use ($signal, $context, $recipients, $throttleKey): void {
            foreach ($recipients as $recipient) {
                try {
                    $this->mailer->send(new TransactionalEmail(
                        to: $recipient,
                        locale: 'it',
                        subject: (string) __('emails.ops_alert.subject', ['signal' => __('alerts.'.$signal->value)], 'it'),
                        greeting: (string) __('emails.greeting', [], 'it'),
                        lines: [
                            (string) __('emails.ops_alert.intro', ['signal' => __('alerts.'.$signal->value), 'level' => $signal->level()], 'it'),
                            ...array_map(fn (string $k, mixed $v): string => $k.': '.(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v), array_keys($context), $context),
                        ],
                        link: rtrim((string) config('app.url'), '/').'/up',
                        signature: (string) __('emails.ops_alert.signature', [], 'it'),
                        category: 'ops.'.$signal->value,
                        idempotencyKey: $throttleKey.':'.$recipient,
                    ));
                } catch (Throwable $e) {
                    Log::warning('alerts.email_failed', ['signal' => $signal->value, 'exception' => $e::class]);
                }
            }
        });
    }

    /** @return list<string> */
    public static function recipients(): array
    {
        $configured = config('alerts.emails', []);
        $list = is_array($configured) ? $configured : explode(',', (string) $configured);

        return array_values(array_filter(array_map(fn (mixed $email): string => trim((string) $email), $list), fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false));
    }
}
