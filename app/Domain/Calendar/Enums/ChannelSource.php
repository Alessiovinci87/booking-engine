<?php

namespace App\Domain\Calendar\Enums;

/** Origine di un calendario importato e destinazione di un feed (02, 06). */
enum ChannelSource: string
{
    case BOOKING = 'BOOKING';
    case AIRBNB = 'AIRBNB';
    case OTHER = 'OTHER';
}
