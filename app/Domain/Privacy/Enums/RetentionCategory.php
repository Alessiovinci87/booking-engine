<?php

namespace App\Domain\Privacy\Enums;

/** Categorie di conservazione (09, "Conservazione"). */
enum RetentionCategory: string
{
    case A_ADMINISTRATIVE = 'A_ADMINISTRATIVE';
    case B_GUEST_IDENTITY = 'B_GUEST_IDENTITY';
    case C_PAYMENT_REFERENCES = 'C_PAYMENT_REFERENCES';
    case D_UNCONCLUDED_ATTEMPTS = 'D_UNCONCLUDED_ATTEMPTS';
    case E_IMPORTED_EVENTS = 'E_IMPORTED_EVENTS';
    case F_SECURITY_AUDIT = 'F_SECURITY_AUDIT';
    case G_COMMUNICATIONS = 'G_COMMUNICATIONS';
    // Link di reimpostazione della password usati o scaduti (6.11, ADR-0016).
    case H_PASSWORD_RESET_LINKS = 'H_PASSWORD_RESET_LINKS';
}
