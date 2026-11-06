<?php

namespace App\Domain\Payments\Gateway\Fake;

/** Pulsanti della pagina "Pagamento simulato". */
enum FakeCustomerAction: string
{
    case PAY = 'pay';
    case DECLINE = 'decline';
    case ABANDON = 'abandon';
}
