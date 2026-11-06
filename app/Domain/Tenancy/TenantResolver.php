<?php

namespace App\Domain\Tenancy;

use Illuminate\Http\Request;

/**
 * Ricava il tenant di una richiesta solo da fonti verificate (08): membership dell'utente autenticato,
 * slug o token pubblico, account Stripe del webhook. Mai da body o query del client.
 */
interface TenantResolver
{
    /** UUID del tenant, oppure null se la richiesta non appartiene a nessun tenant verificabile. */
    public function resolve(Request $request): ?string;
}
