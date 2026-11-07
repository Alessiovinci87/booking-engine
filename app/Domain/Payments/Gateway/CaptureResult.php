<?php

namespace App\Domain\Payments\Gateway;

/** Esito di una capture riuscita. */
final readonly class CaptureResult
{
    public function __construct(
        public string $paymentIntentId,
        public int $capturedCents,
    ) {}
}
