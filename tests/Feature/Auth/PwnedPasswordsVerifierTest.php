<?php

use App\Domain\Tenancy\Auth\PasswordPolicy;
use App\Domain\Tenancy\Auth\PwnedPasswordsVerifier;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/*
 * Controllo delle password compromesse (08): fail-open, ma ogni mancata risposta del servizio produce
 * un avviso nel log senza password, hash né email (decisione dopo la verifica di M1).
 */

const PWNED_TEST_PASSWORD = 'Password-di-prova-HIBP-2026';

function passwordPasses(string $password): bool
{
    return Validator::make(['password' => $password], ['password' => PasswordPolicy::rules()])->passes();
}

function assertSafeWarning(): void
{
    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
        $dump = $message.json_encode($context);
        $hash = strtoupper(sha1(PWNED_TEST_PASSWORD));

        return ! str_contains($dump, PWNED_TEST_PASSWORD)
            && ! str_contains($dump, $hash)
            && ! str_contains($dump, substr($hash, 0, 5))
            && ! str_contains($dump, '@');
    });
}

beforeEach(fn () => Log::spy());

it('usa il verificatore con log al posto di quello di Laravel', function () {
    expect(app(UncompromisedVerifier::class))->toBeInstanceOf(PwnedPasswordsVerifier::class);
});

it('accetta la password e registra un avviso se il servizio non è raggiungibile', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::failedConnection()]);

    expect(passwordPasses(PWNED_TEST_PASSWORD))->toBeTrue();
    assertSafeWarning();
});

it('accetta la password e registra un avviso se il servizio risponde con un errore', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);

    expect(passwordPasses(PWNED_TEST_PASSWORD))->toBeTrue();
    assertSafeWarning();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c): bool => ($c['status'] ?? null) === 503);
});

it('rifiuta una password compromessa senza avvisi', function () {
    $hash = strtoupper(sha1(PWNED_TEST_PASSWORD));
    Http::fake(['api.pwnedpasswords.com/*' => Http::response("0000000000000000000000000000000000A:1\n".substr($hash, 5).':42', 200)]);

    expect(passwordPasses(PWNED_TEST_PASSWORD))->toBeFalse();
    Log::shouldNotHaveReceived('warning');

    // Si invia solo il prefisso di 5 caratteri dello SHA-1.
    Http::assertSent(fn ($request) => $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5));
});

it('accetta una password non compromessa senza avvisi', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response("0000000000000000000000000000000000A:1\n", 200)]);

    expect(passwordPasses(PWNED_TEST_PASSWORD))->toBeTrue();
    Log::shouldNotHaveReceived('warning');
});
