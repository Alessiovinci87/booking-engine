<?php

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * 3.7, 08 "Header e frontend", ADR-0009 e ADR-0010:
 * - CSP con default-src 'self' e nonce sugli script; frame-ancestors 'none' per pannello, pagina
 *   ospite e API; vista embed con frame-ancestors = embed_allowed_origins del tenant;
 * - pagina ospite, vista embed e API pubblica senza cookie di sessione;
 * - API pubblica: chiamate da un'altra origine ammesse solo dagli embed_allowed_origins.
 */

beforeEach(function () {
    $this->withoutVite();
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->fx = bookableTenant('INSTANT');
});
afterEach(fn () => Carbon::setTestNow());

function allowEmbed(string $tenant, array $origins): void
{
    inTenant($tenant, fn () => DB::table('tenants')->where('id', $tenant)->update(['embed_allowed_origins' => '{'.implode(',', $origins).'}']));
}

function csp(TestResponse $response): array
{
    $directives = [];

    foreach (explode(';', (string) $response->headers->get('Content-Security-Policy')) as $part) {
        $tokens = preg_split('/\s+/', trim($part)) ?: [];
        $name = array_shift($tokens);

        if ($name) {
            $directives[$name] = $tokens;
        }
    }

    return $directives;
}

it('pannello e pagina ospite: CSP restrittiva con nonce e non incorporabili', function (string $path) {
    $path = $path === 'ospite' ? "/p/{$this->fx['slug']}/{$this->fx['property_slug']}" : $path;
    $response = $this->get($path)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $policy = csp($response);

    expect($policy['default-src'])->toBe(["'self'"])
        ->and($policy['frame-ancestors'])->toBe(["'none'"])
        ->and($policy['object-src'])->toBe(["'none'"])
        ->and(collect($policy['script-src'])->contains(fn ($t) => str_starts_with($t, "'nonce-")))->toBeTrue()
        ->and($policy['script-src'])->not->toContain("'unsafe-inline'");
})->with(['/app', 'ospite']);

it('la pagina ospite non imposta cookie', function () {
    $response = $this->get("/p/{$this->fx['slug']}/{$this->fx['property_slug']}")->assertOk();

    expect($response->headers->getCookies())->toBe([]);
});

it('vista embed: frame-ancestors = domini registrati, niente cookie, flag embed per la SPA', function () {
    allowEmbed($this->fx['tenant'], ['https://www.casa-esempio.test', 'https://blog.casa-esempio.test:8443']);

    $response = $this->get("/e/{$this->fx['slug']}/{$this->fx['property_slug']}")
        ->assertOk()
        ->assertSee('data-embed="1"', escape: false);

    expect(csp($response)['frame-ancestors'])->toBe(['https://www.casa-esempio.test', 'https://blog.casa-esempio.test:8443'])
        ->and($response->headers->getCookies())->toBe([]);
});

it('vista embed senza domini registrati: non incorporabile; struttura inesistente: 404', function () {
    expect(csp($this->get("/e/{$this->fx['slug']}/{$this->fx['property_slug']}")->assertOk())['frame-ancestors'])->toBe(["'none'"]);

    $this->get("/e/{$this->fx['slug']}/inesistente")->assertNotFound();
    $this->get("/e/tenant-inesistente/{$this->fx['property_slug']}")->assertNotFound();
});

it('API: CSP che vieta tutto e nessun cookie sulle rotte pubbliche', function () {
    $response = $this->getJson("/api/v1/public/{$this->fx['slug']}/{$this->fx['property_slug']}")->assertOk();

    expect(csp($response))->toMatchArray(['default-src' => ["'none'"], 'frame-ancestors' => ["'none'"]])
        ->and($response->headers->getCookies())->toBe([]);

    // Anche con il Referer del pannello (dominio "stateful" di Sanctum) l'API pubblica non apre sessioni.
    $response = $this->withHeader('Referer', TEST_PANEL_ORIGIN.'/p/x/y')->getJson("/api/v1/public/{$this->fx['slug']}/{$this->fx['property_slug']}");
    expect($response->headers->getCookies())->toBe([]);
});

it('API pubblica: Origin della stessa applicazione o registrato ammessi, altri 403', function () {
    $url = "/api/v1/public/{$this->fx['slug']}/{$this->fx['property_slug']}";
    allowEmbed($this->fx['tenant'], ['https://www.casa-esempio.test']);

    $this->withHeader('Origin', rtrim((string) config('app.url'), '/'))->getJson($url)->assertOk();
    $this->withHeader('Origin', 'https://www.casa-esempio.test')->getJson($url)->assertOk();
    $this->withHeader('Origin', 'https://WWW.casa-esempio.test:443')->getJson($url)->assertOk();
    $this->withHeader('Origin', 'https://malevolo.test')->getJson($url)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    $this->withHeader('Origin', 'https://www.casa-esempio.test.malevolo.test')->getJson($url)->assertForbidden();
    $this->withHeader('Origin', 'null')->getJson($url)->assertForbidden();

    // Anche la POST che crea la prenotazione (modalità a passi, senza transazione unica).
    $this->withHeaders(['Origin' => 'https://malevolo.test', 'Idempotency-Key' => uuid7()])
        ->postJson("{$url}/reservations", [])->assertForbidden();

    expect(inTenant($this->fx['tenant'], fn () => Tenant::query()->whereKey($this->fx['tenant'])->value('embed_allowed_origins')))->toBe(['https://www.casa-esempio.test']);
});
