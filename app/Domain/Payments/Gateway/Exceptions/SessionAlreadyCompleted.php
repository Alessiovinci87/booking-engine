<?php

namespace App\Domain\Payments\Gateway\Exceptions;

/**
 * expireSession su una sessione già completata (05): non si rilascia, si legge il PaymentIntent e si
 * applica T3 o T4 (T6).
 */
final class SessionAlreadyCompleted extends PaymentGatewayException
{
    public function __construct(public readonly string $sessionId)
    {
        parent::__construct('Sessione di checkout già completata.');
    }
}
