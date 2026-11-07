<?php

use App\Domain\Reservations\Outbox\ReservationEmail;
use App\Domain\Tenancy\Auth\PasswordManagement;
use App\Domain\Tenancy\Auth\RecoveryCodes;
use App\Domain\Tenancy\Models\AuditLog;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\Models\User;
use App\Domain\Tenancy\Outbox\SendAccountEmail;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
 * 6.11 — Gestione password del gestore (ADR-0016):
 * - password dimenticata: risposta sempre identica, link monouso di 60 minuti salvato come hash, limite
 *   per email e per IP, email tramite outbox; la reimpostazione chiude le sessioni e NON salta la MFA;
 * - cambio password dal pannello: password attuale e MFA verificata, politica di M1, chiusura delle
 *   altre sessioni (la corrente resta aperta), audit, email di avviso.
 */

const NEW_PANEL_PASSWORD = 'nuova-password-molto-lunga-2027';

beforeEach(function () {
    // Have I Been Pwned simulato: nessuna password compromessa, salvo quando un test imposta pwnedBody.
    $this->pwnedBody = '';
    Http::fake(['api.pwnedpasswords.com/*' => fn () => Http::response($this->pwnedBody, 200)]);
    Carbon::setTestNow('2027-02-08 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function forgot(object $test, string $email, string $ip = '10.1.0.1'): TestResponse
{
    $test->withServerVariables(['REMOTE_ADDR' => $ip]);

    return panelPost($test, '/api/v1/admin/auth/password/forgot', ['email' => $email]);
}

function resetPassword(object $test, string $token, string $password = NEW_PANEL_PASSWORD): TestResponse
{
    return panelPost($test, '/api/v1/admin/auth/password/reset', ['token' => $token, 'password' => $password, 'password_confirmation' => $password]);
}

/** Token in chiaro dell'ultimo link richiesto (nel database solo cifrato e come hash). */
function lastResetToken(string $userId): string
{
    $encrypted = DB::table('user_password_resets')->where('user_id', $userId)->orderByDesc('created_at')->value('token_encrypted');

    return Crypt::decryptString((string) $encrypted);
}

function outboxOf(string $tenant, string $type): Collection
{
    return inTenant($tenant, fn () => OutboxMessage::query()->where('type', $type)->get());
}

function auditOf(string $tenant, string $action): Collection
{
    return inTenant($tenant, fn () => AuditLog::query()->where('action', $action)->get());
}

it('password dimenticata: risposta identica per email esistente e inesistente; link solo per l\'utente esistente', function () {
    $p = panelUser();

    $existing = forgot($this, strtoupper($p['user']->email))->assertStatus(202);
    $missing = forgot($this, 'nessuno@example.test')->assertStatus(202);

    expect($missing->json())->toBe($existing->json())
        ->and($existing->json())->toBe(['status' => 'accepted'])
        ->and(DB::table('user_password_resets')->count())->toBe(1);

    $row = DB::table('user_password_resets')->first();
    $token = lastResetToken($p['user']->id);

    // Solo hash e copia cifrata nel database; scadenza a 60 minuti.
    expect($row->token_hash)->toBe(PasswordManagement::hashToken($token))
        ->and($row->token_hash)->not->toBe($token)
        ->and($row->token_encrypted)->not->toContain($token)
        ->and(Carbon::parse($row->expires_at)->equalTo(now()->addMinutes(60)))->toBeTrue();

    // Outbox con soli ID; email con il link nel frammento.
    $messages = outboxOf($p['tenant'], 'account.password_reset');
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->payload)->toBe(['user_id' => $p['user']->id, 'password_reset_id' => $row->id])
        ->and(json_encode($messages[0]->payload))->not->toContain($p['user']->email);

    Mail::fake();
    inTenant($p['tenant'], fn () => app(SendAccountEmail::class)->handle($messages[0]));
    Mail::assertSent(ReservationEmail::class, fn ($mail) => $mail->hasTo($p['user']->email)
        && $mail->link === url('/app/reimposta-password#token='.$token));

    $audit = auditOf($p['tenant'], 'auth.password_reset.requested');
    expect($audit)->toHaveCount(1)->and(json_encode($audit[0]->changes))->not->toContain($p['user']->email);
});

it('password dimenticata: limite di 3 richieste all\'ora per email, anche da IP diversi', function () {
    $p = panelUser();

    foreach ([1, 2, 3] as $i) {
        forgot($this, $p['user']->email, '10.2.0.'.$i)->assertStatus(202);
    }

    forgot($this, $p['user']->email, '10.2.0.9')->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertHeader('Retry-After');

    // Stesso limite e stessa risposta per un'email inesistente: il 429 non rivela nulla.
    foreach ([1, 2, 3] as $i) {
        forgot($this, 'nessuno@example.test', '10.3.0.'.$i)->assertStatus(202);
    }
    forgot($this, 'nessuno@example.test', '10.3.0.9')->assertStatus(429);

    expect(DB::table('user_password_resets')->count())->toBe(3);

    // Dopo un'ora si può chiedere di nuovo.
    Carbon::setTestNow(now()->addMinutes(61));
    forgot($this, $p['user']->email, '10.2.0.10')->assertStatus(202);
});

it('password dimenticata: limite di 10 richieste all\'ora per IP', function () {
    foreach (range(1, 10) as $i) {
        forgot($this, "persona{$i}@example.test", '10.4.0.1')->assertStatus(202);
    }

    forgot($this, 'altra@example.test', '10.4.0.1')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    forgot($this, 'altra@example.test', '10.4.0.2')->assertStatus(202);
});

it('reimpostazione: nuova password, link usato, sessioni chiuse, audit ed email di avviso', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user']);
    panelGet($this, '/api/v1/admin/me')->assertOk();

    forgot($this, $p['user']->email)->assertStatus(202);
    $token = lastResetToken($p['user']->id);

    resetPassword($this, $token)->assertNoContent();

    $user = User::query()->findOrFail($p['user']->id);
    $row = DB::table('user_password_resets')->first();
    expect(app(Hasher::class)->check(NEW_PANEL_PASSWORD, $user->password_hash))->toBeTrue()
        ->and($row->used_at)->not->toBeNull()
        ->and($row->token_encrypted)->toBeNull()
        ->and($user->sessions_revoked_at?->equalTo(now()))->toBeTrue()
        // La MFA non è stata toccata.
        ->and($user->hasMfaEnabled())->toBeTrue();

    // La sessione aperta prima della reimpostazione è chiusa (stessa istanza usata da actingAs).
    $p['user']->forceFill(['sessions_revoked_at' => $user->sessions_revoked_at]);
    panelGet($this, '/api/v1/admin/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');

    expect(auditOf($p['tenant'], 'auth.password_reset.completed'))->toHaveCount(1)
        ->and(outboxOf($p['tenant'], 'account.password_changed'))->toHaveCount(1);

    $message = outboxOf($p['tenant'], 'account.password_changed')->first();
    expect($message->payload)->toBe(['user_id' => $p['user']->id]);

    Mail::fake();
    inTenant($p['tenant'], fn () => app(SendAccountEmail::class)->handle($message));
    Mail::assertSent(ReservationEmail::class, fn ($mail) => $mail->hasTo($p['user']->email)
        && $mail->subjectLine === 'La tua password è stata cambiata'
        && $mail->link === url('/app/password-dimenticata'));
});

it('reimpostazione: la MFA resta obbligatoria, con il codice o con un codice di recupero', function () {
    $p = panelUser();
    $codes = app(RecoveryCodes::class)->regenerate($p['user']);

    forgot($this, $p['user']->email)->assertStatus(202);
    resetPassword($this, lastResetToken($p['user']->id))->assertNoContent();

    // La reimpostazione non apre la sessione.
    $this->assertGuest('web');

    // Il gestore torna al login poco dopo.
    Carbon::setTestNow(now()->addSeconds(5));

    // La vecchia password non vale più.
    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => TEST_PANEL_PASSWORD])
        ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => NEW_PANEL_PASSWORD])
        ->assertOk()->assertJson(['mfa_setup_required' => false]);

    panelGet($this, '/api/v1/admin/me')->assertUnauthorized()->assertJsonPath('error.code', 'MFA_REQUIRED');

    panelPost($this, '/api/v1/admin/auth/mfa/verify', ['recovery_code' => $codes[0]])->assertOk();
    panelGet($this, '/api/v1/admin/me')->assertOk();

    // Secondo accesso: con il codice TOTP.
    panelPost($this, '/api/v1/admin/auth/logout')->assertNoContent();
    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => NEW_PANEL_PASSWORD])->assertOk();
    panelGet($this, '/api/v1/admin/me')->assertUnauthorized()->assertJsonPath('error.code', 'MFA_REQUIRED');
    panelPost($this, '/api/v1/admin/auth/mfa/verify', ['code' => currentOtp($p['secret'])])->assertOk();
    panelGet($this, '/api/v1/admin/me')->assertOk();
});

it('reimpostazione: link già usato rifiutato', function () {
    $p = panelUser();
    forgot($this, $p['user']->email)->assertStatus(202);
    $token = lastResetToken($p['user']->id);

    resetPassword($this, $token)->assertNoContent();
    resetPassword($this, $token, 'ancora-unaltra-password-2027')
        ->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');

    expect(app(Hasher::class)->check(NEW_PANEL_PASSWORD, User::query()->findOrFail($p['user']->id)->password_hash))->toBeTrue()
        ->and(auditOf($p['tenant'], 'auth.password_reset.failed')->first()?->changes)->toBe(['reason' => 'used']);
});

it('reimpostazione: link scaduto dopo 60 minuti rifiutato, e l\'email non parte più', function () {
    $p = panelUser();
    forgot($this, $p['user']->email)->assertStatus(202);
    $token = lastResetToken($p['user']->id);
    $message = outboxOf($p['tenant'], 'account.password_reset')->first();

    Carbon::setTestNow(now()->addMinutes(60));

    resetPassword($this, $token)->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');

    expect(app(Hasher::class)->check(TEST_PANEL_PASSWORD, User::query()->findOrFail($p['user']->id)->password_hash))->toBeTrue()
        ->and(DB::table('user_password_resets')->value('token_encrypted'))->toBeNull()
        ->and(auditOf($p['tenant'], 'auth.password_reset.failed')->first()?->changes)->toBe(['reason' => 'expired']);

    Mail::fake();
    inTenant($p['tenant'], fn () => app(SendAccountEmail::class)->handle($message));
    Mail::assertNothingSent();
});

it('reimpostazione: token inesistente e link sostituito da una richiesta successiva rifiutati', function () {
    $p = panelUser();

    resetPassword($this, 'token-inventato')->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');

    forgot($this, $p['user']->email)->assertStatus(202);
    $first = lastResetToken($p['user']->id);
    Carbon::setTestNow(now()->addMinute());
    forgot($this, $p['user']->email)->assertStatus(202);
    $second = lastResetToken($p['user']->id);

    resetPassword($this, $first)->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');
    resetPassword($this, $second)->assertNoContent();
});

it('reimpostazione: stessa politica di robustezza di M1 e conferma obbligatoria', function () {
    $p = panelUser();
    forgot($this, $p['user']->email)->assertStatus(202);
    $token = lastResetToken($p['user']->id);

    resetPassword($this, $token, 'corta')->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    panelPost($this, '/api/v1/admin/auth/password/reset', ['token' => $token, 'password' => NEW_PANEL_PASSWORD, 'password_confirmation' => 'diversa-diversa-diversa'])
        ->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    // Password compromessa nota (Have I Been Pwned).
    $hash = strtoupper(sha1(NEW_PANEL_PASSWORD));
    $this->pwnedBody = substr($hash, 5).':42';
    resetPassword($this, $token)->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    // Il link resta valido dopo gli errori di validazione.
    expect(DB::table('user_password_resets')->value('used_at'))->toBeNull();
});

it('cambio password: password attuale, sessione corrente aperta, altre sessioni chiuse, audit ed email', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user']);

    panelPost($this, '/api/v1/admin/auth/password', [
        'current_password' => TEST_PANEL_PASSWORD,
        'password' => NEW_PANEL_PASSWORD,
        'password_confirmation' => NEW_PANEL_PASSWORD,
    ])->assertNoContent();

    $user = User::query()->findOrFail($p['user']->id);
    expect(app(Hasher::class)->check(NEW_PANEL_PASSWORD, $user->password_hash))->toBeTrue()
        ->and($user->sessions_revoked_at?->equalTo(now()))->toBeTrue();

    // La sessione che ha cambiato la password resta aperta, con la MFA verificata.
    panelGet($this, '/api/v1/admin/me')->assertOk();

    expect(auditOf($p['tenant'], 'auth.password_changed'))->toHaveCount(1)
        ->and(outboxOf($p['tenant'], 'account.password_changed'))->toHaveCount(1)
        ->and(outboxOf($p['tenant'], 'account.password_changed')->first()->payload)->toBe(['user_id' => $p['user']->id]);

    // Un'altra sessione dello stesso utente, iniziata prima del cambio, si chiude.
    $this->flushSession();
    actingAsPanelUser($this, $p['user']);
    panelGet($this, '/api/v1/admin/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');

    // Una sessione nuova, aperta dopo il cambio, funziona.
    Carbon::setTestNow(now()->addSecond());
    actingAsPanelUser($this, $p['user']);
    panelGet($this, '/api/v1/admin/me')->assertOk();
});

it('cambio password: password attuale errata rifiutata, con audit e limite di tentativi', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user']);
    $body = ['current_password' => 'sbagliata-sbagliata', 'password' => NEW_PANEL_PASSWORD, 'password_confirmation' => NEW_PANEL_PASSWORD];

    foreach (range(1, 5) as $i) {
        panelPost($this, '/api/v1/admin/auth/password', $body)->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    panelPost($this, '/api/v1/admin/auth/password', [...$body, 'current_password' => TEST_PANEL_PASSWORD])
        ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');

    expect(app(Hasher::class)->check(TEST_PANEL_PASSWORD, User::query()->findOrFail($p['user']->id)->password_hash))->toBeTrue()
        ->and(auditOf($p['tenant'], 'auth.password_change.failed'))->toHaveCount(5)
        ->and(outboxOf($p['tenant'], 'account.password_changed'))->toHaveCount(0);
});

it('cambio password: politica di M1, conferma e password diversa dall\'attuale', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user']);

    foreach ([
        ['password' => 'corta', 'password_confirmation' => 'corta'],
        ['password' => NEW_PANEL_PASSWORD, 'password_confirmation' => 'diversa-diversa-diversa'],
        ['password' => TEST_PANEL_PASSWORD, 'password_confirmation' => TEST_PANEL_PASSWORD],
    ] as $body) {
        panelPost($this, '/api/v1/admin/auth/password', ['current_password' => TEST_PANEL_PASSWORD, ...$body])
            ->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }
});

it('cambio password: richiede la MFA già verificata nella sessione', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user'], mfaVerified: false);

    panelPost($this, '/api/v1/admin/auth/password', [
        'current_password' => TEST_PANEL_PASSWORD,
        'password' => NEW_PANEL_PASSWORD,
        'password_confirmation' => NEW_PANEL_PASSWORD,
    ])->assertUnauthorized()->assertJsonPath('error.code', 'MFA_REQUIRED');

    expect(app(Hasher::class)->check(TEST_PANEL_PASSWORD, User::query()->findOrFail($p['user']->id)->password_hash))->toBeTrue();
});

it('cambio password: senza sessione non è disponibile', function () {
    panelPost($this, '/api/v1/admin/auth/password', [
        'current_password' => TEST_PANEL_PASSWORD,
        'password' => NEW_PANEL_PASSWORD,
        'password_confirmation' => NEW_PANEL_PASSWORD,
    ])->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('revoca al microsecondo: una sessione aperta subito dopo la revoca, nello stesso secondo, resta aperta', function () {
    $p = panelUser();
    Carbon::setTestNow('2027-02-08 10:00:00.100000');
    actingAsPanelUser($this, $p['user']);

    Carbon::setTestNow('2027-02-08 10:00:00.400000');
    panelPost($this, '/api/v1/admin/auth/password', [
        'current_password' => TEST_PANEL_PASSWORD,
        'password' => NEW_PANEL_PASSWORD,
        'password_confirmation' => NEW_PANEL_PASSWORD,
    ])->assertNoContent();

    // Salvato con i microsecondi (timestamptz) e riletto uguale dal database.
    $p['user']->refresh();
    expect($p['user']->sessions_revoked_at?->format('Y-m-d H:i:s.u'))->toBe('2027-02-08 10:00:00.400000')
        ->and(DB::table('users')->where('id', $p['user']->id)->value('sessions_revoked_at'))->toContain('10:00:00.4');

    // La sessione che ha cambiato la password resta aperta.
    panelGet($this, '/api/v1/admin/me')->assertOk();

    // Aperta 0,1 s prima della revoca: si chiude.
    $this->flushSession();
    Carbon::setTestNow('2027-02-08 10:00:00.300000');
    actingAsPanelUser($this, $p['user']);
    Carbon::setTestNow('2027-02-08 10:00:00.700000');
    panelGet($this, '/api/v1/admin/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');

    // Aperta 0,2 s dopo la revoca, nello stesso secondo: resta aperta.
    $this->flushSession();
    Carbon::setTestNow('2027-02-08 10:00:00.600000');
    actingAsPanelUser($this, $p['user']);
    Carbon::setTestNow('2027-02-08 10:00:00.900000');
    panelGet($this, '/api/v1/admin/me')->assertOk();
});

it('revoca al microsecondo: il login subito dopo la reimpostazione apre una sessione valida', function () {
    $p = panelUser();
    Carbon::setTestNow('2027-02-08 10:00:00.250000');
    forgot($this, $p['user']->email)->assertStatus(202);
    resetPassword($this, lastResetToken($p['user']->id))->assertNoContent();

    // Login nello stesso secondo della revoca: la sessione nasce dopo e resta valida.
    Carbon::setTestNow('2027-02-08 10:00:00.750000');
    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => NEW_PANEL_PASSWORD])->assertOk();
    panelPost($this, '/api/v1/admin/auth/mfa/verify', ['code' => currentOtp($p['secret'])])->assertOk();
    panelGet($this, '/api/v1/admin/me')->assertOk();
});
