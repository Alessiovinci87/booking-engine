<?php

use App\Domain\Tenancy\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
 * Utenti del pannello per i test di autenticazione. Password e segreti inventati.
 */

const TEST_PANEL_PASSWORD = 'Password-di-prova-2026!';

/** Origine del pannello: Sanctum avvia la sessione solo per le richieste dal dominio stateful. */
const TEST_PANEL_ORIGIN = 'http://localhost';

/**
 * Utente con password nota e membership nel tenant; con $mfa la MFA è già attiva.
 *
 * @return array{user: User, tenant: string, secret: string|null}
 */
function panelUser(?string $tenantId = null, bool $mfa = true, string $role = 'OWNER'): array
{
    $tenantId ??= createTenantRow();
    $userId = createUserRow();
    createMemberRow($tenantId, $userId, $role);

    $user = User::query()->findOrFail($userId);
    $user->forceFill(['password_hash' => app(Hasher::class)->make(TEST_PANEL_PASSWORD)]);

    $secret = null;

    if ($mfa) {
        $secret = app(Google2FA::class)->generateSecretKey(32);
        $user->forceFill(['mfa_secret_encrypted' => $secret, 'mfa_enabled_at' => now()]);
    }

    $user->save();

    return ['user' => $user->refresh(), 'tenant' => $tenantId, 'secret' => $secret];
}

function currentOtp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/**
 * Sessione del pannello già completa (login e MFA verificata), senza passare dalle API di accesso.
 */
function actingAsPanelUser(TestCase $test, User $user, bool $mfaVerified = true, bool $passwordConfirmed = false): TestCase
{
    $now = now()->getTimestamp();
    $session = ['panel.authenticated_at' => $now, 'panel.authenticated_at_us' => (int) now()->format('Uu'), 'panel.last_activity_at' => $now];

    // Ri-autenticazione per le azioni sensibili (08): password confermata adesso.
    if ($passwordConfirmed) {
        $session['panel.password_confirmed_at'] = $now;
    }

    if ($mfaVerified) {
        $session['panel.mfa_verified_at'] = $now;
    }

    return $test->actingAs($user, 'web')
        ->withSession($session)
        ->withHeader('Referer', TEST_PANEL_ORIGIN.'/app');
}

/** POST del pannello: come il client del pannello, una Idempotency-Key nuova per ogni chiamata (07, I9). */
function panelPost(TestCase $test, string $uri, array $data = [], ?string $idempotencyKey = null): TestResponse
{
    return $test->withHeaders(['Referer' => TEST_PANEL_ORIGIN.'/app', 'Idempotency-Key' => $idempotencyKey ?? uuid7()])->postJson($uri, $data);
}

function panelGet(TestCase $test, string $uri): TestResponse
{
    return $test->withHeader('Referer', TEST_PANEL_ORIGIN.'/app')->getJson($uri);
}

/** PATCH del pannello con If-Match (07); senza versione l'header non viene inviato. */
function panelPatch(TestCase $test, string $uri, array $data = [], ?int $version = null): TestResponse
{
    $headers = ['Referer' => TEST_PANEL_ORIGIN.'/app'];

    if ($version !== null) {
        $headers['If-Match'] = '"'.$version.'"';
    }

    return $test->withHeaders($headers)->patchJson($uri, $data);
}

/**
 * Proprietario con sessione completa nel tenant indicato (o in un tenant nuovo).
 *
 * @return array{user: User, tenant: string, secret: string|null}
 */
function signedInOwner(TestCase $test, ?string $tenantId = null, bool $passwordConfirmed = false): array
{
    $owner = panelUser($tenantId);
    actingAsPanelUser($test, $owner['user'], passwordConfirmed: $passwordConfirmed);

    return $owner;
}
