<?php

use App\Domain\Payments\Gateway\ConnectedAccounts;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Stripe\StripeClientFactory;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;

/*
 * Supporto ai test con Stripe simulato (4.11): il client HTTP di stripe-php è sostituito da
 * FakeStripeHttp, che risponde con risposte registrate nel formato dell'API di Stripe e conserva le
 * richieste ricevute (metodo, percorso, parametri, header Stripe-Account e Idempotency-Key). Nessuna
 * chiamata esce dalla macchina. La suite tests/Stripe/* usa invece la vera API di test.
 */

final class FakeStripeHttp implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>, account: ?string, idempotency_key: ?string}> */
    public array $requests = [];

    /** @var list<array{method: string, pattern: string, status: int, body: array<string, mixed>|Closure, times: ?int}> */
    private array $routes = [];

    /**
     * Risposta per le richieste che corrispondono a metodo e percorso (espressione regolare sul percorso).
     * Le risposte registrate più tardi hanno la precedenza; con times la risposta vale per N richieste.
     *
     * @param  array<string, mixed>|Closure(array<string, mixed>, array{method: string, path: string, params: array<string, mixed>, account: ?string, idempotency_key: ?string}): array<string, mixed>  $body
     */
    public function on(string $method, string $pathPattern, array|Closure $body, int $status = 200, ?int $times = null): self
    {
        array_unshift($this->routes, ['method' => strtolower($method), 'pattern' => $pathPattern, 'status' => $status, 'body' => $body, 'times' => $times]);

        return $this;
    }

    /** Errore nel formato di Stripe. */
    public function fail(string $method, string $pathPattern, int $status, string $type, ?string $code = null, ?string $declineCode = null, ?int $times = null): self
    {
        return $this->on($method, $pathPattern, ['error' => array_filter([
            'type' => $type, 'code' => $code, 'decline_code' => $declineCode, 'message' => 'Errore simulato.',
        ])], $status, $times);
    }

    /**
     * @return list<array{method: string, path: string, params: array<string, mixed>, account: ?string, idempotency_key: ?string}>
     */
    public function requestsTo(string $method, string $pathPattern): array
    {
        return array_values(array_filter($this->requests, fn (array $r): bool => $r['method'] === strtolower($method)
            && preg_match('#^'.$pathPattern.'$#', $r['path']) === 1));
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $request = [
            'method' => strtolower($method),
            'path' => $path,
            'params' => is_array($params) ? $params : [],
            'account' => self::header($headers, 'Stripe-Account'),
            'idempotency_key' => self::header($headers, 'Idempotency-Key'),
        ];
        $this->requests[] = $request;

        foreach ($this->routes as $i => $route) {
            if ($route['method'] !== $request['method'] || preg_match('#^'.$route['pattern'].'$#', $path) !== 1) {
                continue;
            }

            if ($route['times'] !== null) {
                if ($route['times'] <= 0) {
                    continue;
                }

                $this->routes[$i]['times']--;
            }

            $body = $route['body'] instanceof Closure ? ($route['body'])($request['params'], $request) : $route['body'];

            return [json_encode($body, JSON_THROW_ON_ERROR), $route['status'], new CaseInsensitiveArray(['request-id' => 'req_test_'.count($this->requests)])];
        }

        throw new RuntimeException("Richiesta Stripe non prevista nel test: {$method} {$path}");
    }

    /** @param  list<string>  $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            [$key, $value] = array_pad(explode(':', $header, 2), 2, '');

            if (strcasecmp(trim($key), $name) === 0) {
                return trim($value);
            }
        }

        return null;
    }
}

/**
 * Attiva il gateway Stripe con il client HTTP simulato. Da chiamare prima di risolvere il gateway.
 */
function fakeStripe(): FakeStripeHttp
{
    $fake = new FakeStripeHttp;
    ApiRequestor::setHttpClient($fake);

    config(['payments.gateway' => 'stripe', 'services.stripe.secret' => 'sk_test_simulated', 'services.stripe.webhook_secret' => 'whsec_test_simulated']);
    app()->forgetInstance(PaymentGateway::class);
    app()->forgetInstance(ConnectedAccounts::class);
    app()->forgetInstance(StripeClientFactory::class);

    return $fake;
}

function resetStripeHttp(): void
{
    ApiRequestor::setHttpClient(null);
}

/**
 * Oggetti dell'API di Stripe per le risposte registrate (solo i campi letti dal gateway).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeSession(string $id, string $status = 'open', array $overrides = []): array
{
    return array_replace([
        'id' => $id, 'object' => 'checkout.session', 'status' => $status, 'url' => 'https://checkout.stripe.com/c/pay/'.$id,
        'expires_at' => now()->addMinutes(32)->getTimestamp(), 'amount_total' => 14400, 'metadata' => [],
        'payment_intent' => null, 'livemode' => false,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeIntent(string $id, string $status, int $amount = 14400, array $overrides = []): array
{
    return array_replace([
        'id' => $id, 'object' => 'payment_intent', 'status' => $status, 'amount' => $amount,
        'amount_received' => $status === 'succeeded' ? $amount : 0, 'metadata' => [], 'last_payment_error' => null,
        'latest_charge' => [
            'id' => 'ch_'.substr($id, 3), 'object' => 'charge',
            'payment_method_details' => ['type' => 'card', 'card' => ['capture_before' => $status === 'requires_capture' ? now()->addDays(7)->getTimestamp() : null]],
        ],
    ], $overrides);
}

/** @return array<string, mixed> */
function stripeRefund(string $id, string $status = 'succeeded', int $amount = 14400): array
{
    return ['id' => $id, 'object' => 'refund', 'status' => $status, 'amount' => $amount, 'metadata' => []];
}

/**
 * Account v2 con lo stato delle capability merchant.
 *
 * @return array<string, mixed>
 */
function stripeV2Account(string $id, string $cardPayments = 'active', string $payouts = 'active', ?string $deadline = null): array
{
    return [
        'id' => $id, 'object' => 'v2.core.account', 'livemode' => false, 'dashboard' => 'full', 'applied_configurations' => ['merchant'],
        'configuration' => ['merchant' => ['applied' => true, 'capabilities' => [
            'card_payments' => ['status' => $cardPayments, 'status_details' => []],
            'stripe_balance' => ['payouts' => ['status' => $payouts, 'status_details' => []]],
        ]]],
        'requirements' => ['entries' => [], 'summary' => $deadline === null ? null : ['minimum_deadline' => ['status' => $deadline]]],
        'created' => '2026-11-07T00:00:00.000Z',
    ];
}
