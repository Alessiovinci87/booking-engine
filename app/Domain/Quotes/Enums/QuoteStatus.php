<?php

namespace App\Domain\Quotes\Enums;

/** Quote.status: macchina a stati del preventivo (03, sezione 4). */
enum QuoteStatus: string
{
    case DRAFT = 'DRAFT';
    case SENT = 'SENT';
    case IN_CHECKOUT = 'IN_CHECKOUT';
    case ACCEPTED = 'ACCEPTED';
    case EXPIRED = 'EXPIRED';
    case REVOKED = 'REVOKED';
    case EXHAUSTED = 'EXHAUSTED';
}
