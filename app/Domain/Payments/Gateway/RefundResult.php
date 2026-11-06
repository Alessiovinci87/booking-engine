<?php

namespace App\Domain\Payments\Gateway;

/**
 * Esito della richiesta di rimborso: un rimborso può restare in attesa e chiudersi più tardi
 * (refund.updated, 03 sezione 3).
 */
final readonly class RefundResult
{
    public function __construct(
        public string $refundId,
        public RefundResultStatus $status,
    ) {}
}
