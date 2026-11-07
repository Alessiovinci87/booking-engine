<?php

namespace App\Domain\Payments\Gateway\Fake;

/**
 * Esiti programmabili del gateway finto:
 * - TIMEOUT: nessun effetto, PaymentProviderUnavailable;
 * - TIMEOUT_AFTER_EFFECT: l'effetto avviene ma la risposta si perde (crash tra passo 2 e 3 di 05);
 * - DECLINED: rifiuto definitivo (capture, rimborso);
 * - PENDING: rimborso accettato ma non ancora concluso.
 */
enum FakeFailure: string
{
    case TIMEOUT = 'timeout';
    case TIMEOUT_AFTER_EFFECT = 'timeout_after_effect';
    case DECLINED = 'declined';
    case PENDING = 'pending';
}
