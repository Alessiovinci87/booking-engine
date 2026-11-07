<?php

namespace App\Domain\Payments\Gateway\Fake;

/** Operazioni del gateway finto a cui si può programmare un errore. */
enum FakeOperation: string
{
    case CREATE = 'create';
    case RETRIEVE = 'retrieve';
    case EXPIRE = 'expire';
    case CAPTURE = 'capture';
    case CANCEL = 'cancel';
    case REFUND = 'refund';
}
