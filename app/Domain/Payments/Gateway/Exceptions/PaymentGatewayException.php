<?php

namespace App\Domain\Payments\Gateway\Exceptions;

use RuntimeException;

/** Errore del fornitore di pagamento. Il messaggio non contiene mai dati personali. */
abstract class PaymentGatewayException extends RuntimeException {}
