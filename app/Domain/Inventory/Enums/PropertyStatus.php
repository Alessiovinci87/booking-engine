<?php

namespace App\Domain\Inventory\Enums;

/** Property.status (02). */
enum PropertyStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case ARCHIVED = 'ARCHIVED';
}
