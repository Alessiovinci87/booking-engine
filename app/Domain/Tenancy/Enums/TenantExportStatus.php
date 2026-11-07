<?php

namespace App\Domain\Tenancy\Enums;

/** Stato dell'export completo del tenant (7.1, 09 "Uscita del cliente" punto 2). */
enum TenantExportStatus: string
{
    case PENDING = 'PENDING';
    case READY = 'READY';
    case FAILED = 'FAILED';
    // Scaduto dopo 30 giorni oppure cancellato perché conteneva un ospite anonimizzato (09, I22).
    case EXPIRED = 'EXPIRED';
}
