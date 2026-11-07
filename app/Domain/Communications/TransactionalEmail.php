<?php

namespace App\Domain\Communications;

/**
 * Email transazionale pronta per l'invio (6.1): destinatario, lingua e testi già tradotti. Il tipo
 * (`category`, per esempio `reservation.confirmed`) e la chiave di idempotenza viaggiano come
 * intestazioni interne che i trasporti tolgono prima della consegna. Nessun dato personale in
 * `category` o `idempotencyKey`: sono identificativi tecnici (09).
 */
final readonly class TransactionalEmail
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $to,
        public string $locale,
        public string $subject,
        public string $greeting,
        public array $lines,
        public ?string $link,
        public string $signature,
        public string $category,
        public string $idempotencyKey,
        public ?string $replyTo = null,
    ) {}
}
