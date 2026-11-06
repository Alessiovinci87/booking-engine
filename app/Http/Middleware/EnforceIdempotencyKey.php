<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiError;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key (07 "Convenzioni", I9): le POST che creano prenotazioni o cambiano stato richiedono
 * l'header con un UUID. La chiave vale 24 ore per utente autenticato o, sulla superficie pubblica,
 * per IP. Stessa chiave e stessa richiesta → stessa risposta (header Idempotent-Replayed); stessa
 * chiave con metodo, percorso o corpo diversi → 422 IDEMPOTENCY_KEY_REUSED.
 *
 * Le risposte si salvano nella cache (Redis fuori dai test). Non si salvano gli errori 5xx né le
 * risposte che dipendono dalla sessione (401, 419, 429): la stessa chiave si può ritentare. Due
 * richieste contemporanee con la stessa chiave si serializzano con un lock; se la prima non finisce
 * entro l'attesa, la seconda riceve 409 IDEMPOTENCY_KEY_IN_USE (ADR-0011).
 *
 * Va prima del middleware tenant: la risposta si salva dopo il commit della transazione.
 */
final class EnforceIdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    private const TTL_SECONDS = 24 * 3600;

    private const LOCK_SECONDS = 60;

    private const WAIT_SECONDS = 10;

    /** @var list<int> */
    private const NOT_STORED = [401, 419, 429];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header(self::HEADER, '');

        if (! Str::isUuid($key)) {
            return ApiError::response(400, 'VALIDATION_FAILED', ['fields' => [self::HEADER => [__('api.idempotency.key_required')]]]);
        }

        $cacheKey = 'idempotency:'.$this->scope($request).':'.strtolower($key);
        $fingerprint = $this->fingerprint($request);
        $store = $this->store();

        $stored = $this->replay($store, $cacheKey, $fingerprint);

        if ($stored !== null) {
            return $stored;
        }

        $backend = $store->getStore();

        if (! $backend instanceof LockProvider) {
            throw new LogicException('Lo store dell\'Idempotency-Key deve supportare i lock (Redis).');
        }

        $lock = $backend->lock($cacheKey.':lock', self::LOCK_SECONDS);

        try {
            $lock->block((int) config('security.idempotency_wait_seconds', self::WAIT_SECONDS));
        } catch (LockTimeoutException) {
            return ApiError::response(409, 'IDEMPOTENCY_KEY_IN_USE');
        }

        try {
            // Una richiesta contemporanea può aver finito mentre si aspettava il lock.
            $stored = $this->replay($store, $cacheKey, $fingerprint);

            if ($stored !== null) {
                return $stored;
            }

            $response = $next($request);

            if ($response->getStatusCode() < 500 && ! in_array($response->getStatusCode(), self::NOT_STORED, true)) {
                $store->put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'content' => (string) $response->getContent(),
                    'content_type' => (string) $response->headers->get('Content-Type', 'application/json'),
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function replay(Repository $store, string $cacheKey, string $fingerprint): ?Response
    {
        $stored = $store->get($cacheKey);

        if (! is_array($stored)) {
            return null;
        }

        if (($stored['fingerprint'] ?? null) !== $fingerprint) {
            return ApiError::response(422, 'IDEMPOTENCY_KEY_REUSED');
        }

        return new Response((string) $stored['content'], (int) $stored['status'], [
            'Content-Type' => (string) $stored['content_type'],
            'Idempotent-Replayed' => 'true',
        ]);
    }

    /** Utente autenticato oppure IP (07: "conservato 24 h per utente/IP"). */
    private function scope(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? 'user:'.$id : 'ip:'.$request->ip();
    }

    /** Metodo, percorso e corpo in forma canonica (chiavi ordinate): l'ordine dei campi non conta. */
    private function fingerprint(Request $request): string
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();

        return hash('sha256', $request->method()."\n".$request->path()."\n".json_encode($this->canonical($body), JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }

    private function store(): Repository
    {
        return Cache::store($this->storeName());
    }

    private function storeName(): ?string
    {
        $name = config('security.idempotency_store');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
