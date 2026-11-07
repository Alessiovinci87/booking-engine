<?php

namespace App\Domain\Tenancy\Http;

use App\Domain\Tenancy\PublicSlugTenantResolver;
use App\Domain\Tenancy\QuoteTokenTenantResolver;
use App\Domain\Tenancy\ReservationAccessTenantResolver;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware delle richieste admin e pubbliche: risolve il tenant da fonti verificate e gestisce
 * l'intera richiesta dentro TenantContext::run(). Senza tenant risolto risponde 404, mai 403 (08).
 *
 * `tenant` usa il risolutore del pannello (membership); `tenant:public` ricava il tenant dallo slug
 * nel percorso (API pubblica, 07); `tenant:reservation` lo ricava da codice e token della prenotazione
 * (GET /public/reservations/{code}?token=); `tenant:quote` dal token del preventivo (/public/quotes/{token}).
 *
 * Con il secondo parametro `steps` (`tenant:panel,steps`, `tenant:public,steps`) la richiesta non viene
 * avvolta in una transazione: il tenant risolto finisce nell'attributo `tenant_id` e il servizio apre i
 * propri `TenantContext::run()` per passi. Serve dove una chiamata al servizio di pagamento deve stare
 * fuori transazione (05, sequenza in tre passi; CLAUDE.md regola 15).
 *
 * Laravel trasforma in risposta le eccezioni del controller già dentro la pipeline, quindi qui
 * arriverebbe una risposta normale e la transazione verrebbe confermata con le scritture parziali.
 * Se la risposta porta un'eccezione, la transazione si annulla e si restituisce la stessa risposta.
 */
final class WithTenantContext
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantResolver $resolver,
        private readonly PublicSlugTenantResolver $publicResolver,
        private readonly ReservationAccessTenantResolver $reservationResolver,
        private readonly QuoteTokenTenantResolver $quoteResolver,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public const TENANT_ATTRIBUTE = 'tenant_id';

    public function handle(Request $request, Closure $next, string $surface = 'panel', string $mode = 'transaction'): Response
    {
        $resolver = match ($surface) {
            'public' => $this->publicResolver,
            'reservation' => $this->reservationResolver,
            'quote' => $this->quoteResolver,
            default => $this->resolver,
        };
        $tenantId = $resolver->resolve($request);

        if ($tenantId === null) {
            abort(404);
        }

        if ($mode === 'steps') {
            $request->attributes->set(self::TENANT_ATTRIBUTE, $tenantId);

            return $next($request);
        }

        try {
            return $this->tenantContext->run($tenantId, function () use ($request, $next): Response {
                $response = $next($request);

                if (($response->exception ?? null) !== null) {
                    throw new RollbackWithResponse($response);
                }

                return $response;
            });
        } catch (RollbackWithResponse $rollback) {
            return $rollback->response;
        }
    }
}
