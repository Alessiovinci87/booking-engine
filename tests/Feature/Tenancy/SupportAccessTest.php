<?php

use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Models\AuditLog;
use App\Domain\Tenancy\Models\SupportAccessGrant;
use App\Domain\Tenancy\Models\User;
use App\Domain\Tenancy\Support\SupportAccessService;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
 * Accesso di supporto in sola lettura (7.5; 08 "Accesso di supporto"; ADR-0018): operatore di
 * piattaforma con MFA, autorizzazione a tempo (massimo 2 ore) con motivazione, pannello del tenant solo
 * in lettura con rifiuto lato server, audit e pagina "Accessi di supporto" visibili al gestore, revoca.
 */

const SUPPORT_REASON = 'Verifica segnalazione sul calendario';

/** Operatore di piattaforma senza membership, con password nota e (di norma) MFA attiva. */
function platformOperator(bool $mfa = true): User
{
    $user = User::query()->findOrFail(createUserRow());
    $user->forceFill(['password_hash' => app(Hasher::class)->make(TEST_PANEL_PASSWORD), 'is_platform_operator' => true]);

    if ($mfa) {
        $user->forceFill(['mfa_secret_encrypted' => app(Google2FA::class)->generateSecretKey(32), 'mfa_enabled_at' => now()]);
    }

    $user->save();

    return $user->refresh();
}

function openSupport(string $tenantId, User $operator, int $hours = 1): SupportAccessGrant
{
    return app(SupportAccessService::class)->open($tenantId, $operator, SUPPORT_REASON, $hours);
}

/**
 * Cambio di utente nello stesso test: le guardie ricordano l'utente della richiesta precedente e la
 * sessione conserva l'hash della password dell'utente precedente (AuthenticateSession la chiuderebbe).
 */
function switchPanelUser(TestCase $test, User $user, bool $passwordConfirmed = false): void
{
    $test->flushSession();
    app('auth')->forgetGuards();
    actingAsPanelUser($test, $user, passwordConfirmed: $passwordConfirmed);
}

function tenantSlug(string $tenantId): string
{
    return (string) inTenant($tenantId, fn () => DB::table('tenants')->value('slug'));
}

it('durante l\'autorizzazione l\'operatore vede il pannello del tenant e /me riporta l\'accesso di supporto', function () {
    $fx = bookableTenant();
    $reservation = reservationIn($fx, ReservationStatus::CONFIRMED);
    $operator = platformOperator();
    $grant = openSupport($fx['tenant'], $operator);
    actingAsPanelUser($this, $operator);

    panelGet($this, '/api/v1/admin/me')
        ->assertOk()
        ->assertJsonPath('tenant.id', $fx['tenant'])
        ->assertJsonPath('role', null)
        ->assertJsonPath('support.id', $grant->id)
        ->assertJsonPath('support.reason', SUPPORT_REASON);
    panelGet($this, '/api/v1/admin/reservations')->assertOk()->assertJsonPath('data.0.id', $reservation->id);
    panelGet($this, '/api/v1/admin/properties')->assertOk();
});

it('le richieste diverse da GET sono rifiutate dal server con SUPPORT_READ_ONLY', function () {
    $fx = bookableTenant();
    $reservation = reservationIn($fx, ReservationStatus::CONFIRMED);
    $operator = platformOperator();
    openSupport($fx['tenant'], $operator);
    actingAsPanelUser($this, $operator, passwordConfirmed: true);

    panelPost($this, "/api/v1/admin/reservations/{$reservation->id}/cancel", ['reason' => 'prova'])
        ->assertStatus(403)->assertJsonPath('error.code', 'SUPPORT_READ_ONLY');
    panelPatch($this, "/api/v1/admin/units/{$fx['unit']}", ['name' => 'Cambiata'])
        ->assertStatus(403)->assertJsonPath('error.code', 'SUPPORT_READ_ONLY');
    $this->withHeader('Referer', TEST_PANEL_ORIGIN.'/app')->deleteJson("/api/v1/admin/units/{$fx['unit']}/photos/".uuid7())
        ->assertStatus(403)->assertJsonPath('error.code', 'SUPPORT_READ_ONLY');
    panelPost($this, '/api/v1/admin/tenant/export')->assertStatus(403)->assertJsonPath('error.code', 'SUPPORT_READ_ONLY');

    expect(inTenant($fx['tenant'], fn () => DB::table('reservations')->where('id', $reservation->id)->value('status')))->toBe('CONFIRMED');
});

it('anche in lettura restano chiusi gli export con dati personali (rotte con ri-autenticazione)', function () {
    $fx = bookableTenant();
    $reservation = reservationIn($fx, ReservationStatus::CONFIRMED);
    $operator = platformOperator();
    openSupport($fx['tenant'], $operator);
    actingAsPanelUser($this, $operator, passwordConfirmed: true);

    panelGet($this, "/api/v1/admin/guests/{$reservation->guest_id}/export")->assertStatus(403)->assertJsonPath('error.code', 'SUPPORT_READ_ONLY');
    panelGet($this, '/api/v1/admin/exports/reservations.csv')->assertStatus(403)->assertJsonPath('error.code', 'SUPPORT_READ_ONLY');
});

it('senza autorizzazione, dopo la scadenza o dopo la revoca l\'operatore non vede nulla', function () {
    $fx = bookableTenant();
    $operator = platformOperator();
    actingAsPanelUser($this, $operator);

    panelGet($this, '/api/v1/admin/me')->assertStatus(403)->assertJsonPath('error.code', 'SUPPORT_ACCESS_INACTIVE');

    openSupport($fx['tenant'], $operator, hours: 2);
    panelGet($this, '/api/v1/admin/me')->assertOk();

    $this->travel(2)->hours();
    $this->travel(1)->seconds();
    switchPanelUser($this, $operator);
    panelGet($this, '/api/v1/admin/me')->assertStatus(403)->assertJsonPath('error.code', 'SUPPORT_ACCESS_INACTIVE');
});

it('un operatore non vede altri tenant: il tenant è solo quello dell\'autorizzazione', function () {
    $a = bookableTenant();
    $b = bookableTenant();
    $reservationB = reservationIn($b, ReservationStatus::CONFIRMED);
    $operator = platformOperator();
    openSupport($a['tenant'], $operator);
    actingAsPanelUser($this, $operator);

    panelGet($this, '/api/v1/admin/me')->assertOk()->assertJsonPath('tenant.id', $a['tenant']);
    panelGet($this, "/api/v1/admin/reservations/{$reservationB->id}")->assertNotFound();
});

it('support:open: operatore con flag e MFA, motivazione valida, al massimo 2 ore', function () {
    $fx = bookableTenant();
    $slug = tenantSlug($fx['tenant']);
    $operator = platformOperator();
    $noMfa = platformOperator(mfa: false);
    $plain = panelUser()['user'];

    $this->artisan('support:open', ['tenant' => $slug, '--operator' => $operator->email, '--reason' => SUPPORT_REASON, '--hours' => 3])->assertFailed();
    $this->artisan('support:open', ['tenant' => $slug, '--operator' => $operator->email, '--reason' => 'breve'])->assertFailed();
    $this->artisan('support:open', ['tenant' => $slug, '--operator' => $operator->email, '--reason' => 'Chiamata del gestore 333 1234567'])->assertFailed();
    $this->artisan('support:open', ['tenant' => $slug, '--operator' => $noMfa->email, '--reason' => SUPPORT_REASON])->assertFailed();
    $this->artisan('support:open', ['tenant' => $slug, '--operator' => $plain->email, '--reason' => SUPPORT_REASON])->assertFailed();
    $this->artisan('support:open', ['tenant' => 'inesistente', '--operator' => $operator->email, '--reason' => SUPPORT_REASON])->assertFailed();
    expect(inTenant($fx['tenant'], fn () => SupportAccessGrant::query()->count()))->toBe(0);

    $this->freezeSecond();
    $this->artisan('support:open', ['tenant' => $slug, '--operator' => $operator->email, '--reason' => SUPPORT_REASON, '--hours' => 2])->assertSuccessful();

    $grant = inTenant($fx['tenant'], fn () => SupportAccessGrant::query()->sole());
    expect($grant->operator_user_id)->toBe($operator->id)
        ->and($grant->expires_at->equalTo(now()->addHours(2)))->toBeTrue()
        ->and($grant->reason)->toBe(SUPPORT_REASON);

    $audit = inTenant($fx['tenant'], fn () => AuditLog::query()->where('action', 'support.opened')->sole());
    expect($audit->subject_id)->toBe($grant->id)
        ->and($audit->changes)->toMatchArray(['reason' => SUPPORT_REASON, 'hours' => 2]);
});

it('il database rifiuta autorizzazioni oltre le 2 ore', function () {
    $fx = bookableTenant();
    $operator = platformOperator();

    expect(fn () => inTenant($fx['tenant'], fn () => DB::table('support_access_grants')->insert([
        'id' => uuid7(), 'tenant_id' => $fx['tenant'], 'operator_user_id' => $operator->id, 'reason' => SUPPORT_REASON,
        'expires_at' => now()->addHours(3), 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('una nuova autorizzazione chiude la precedente dello stesso operatore', function () {
    $a = bookableTenant();
    $b = bookableTenant();
    $operator = platformOperator();
    $first = openSupport($a['tenant'], $operator);
    openSupport($b['tenant'], $operator);

    expect(inTenant($a['tenant'], fn () => SupportAccessGrant::query()->findOrFail($first->id))->revoked_at)->not->toBeNull();
    actingAsPanelUser($this, $operator);
    panelGet($this, '/api/v1/admin/me')->assertOk()->assertJsonPath('tenant.id', $b['tenant']);
});

it('il gestore vede gli accessi di supporto con prima e ultima lettura e l\'audit', function () {
    $fx = bookableTenant();
    $operator = platformOperator();
    $grant = openSupport($fx['tenant'], $operator);

    $this->travelTo(now()->addMinutes(5));
    actingAsPanelUser($this, $operator);
    panelGet($this, '/api/v1/admin/me')->assertOk();
    $this->travelTo(now()->addMinutes(5));
    panelGet($this, '/api/v1/admin/properties')->assertOk();

    switchPanelUser($this, panelUser($fx['tenant'])['user']);
    $response = panelGet($this, '/api/v1/admin/support-access')->assertOk()
        ->assertJsonPath('data.0.id', $grant->id)
        ->assertJsonPath('data.0.operator_email', $operator->email)
        ->assertJsonPath('data.0.reason', SUPPORT_REASON)
        ->assertJsonPath('data.0.active', true);
    expect($response->json('data.0.first_used_at'))->not->toBe($response->json('data.0.last_used_at'));

    $actions = inTenant($fx['tenant'], fn () => AuditLog::query()->where('action', 'like', 'support.%')->pluck('action')->all());
    expect($actions)->toContain('support.opened', 'support.first_use')
        ->and(collect($actions)->filter(fn ($a) => $a === 'support.first_use'))->toHaveCount(1);
});

it('il gestore revoca l\'accesso (con la password confermata) e l\'operatore perde subito la lettura', function () {
    $fx = bookableTenant();
    $operator = platformOperator();
    $grant = openSupport($fx['tenant'], $operator);
    $owner = panelUser($fx['tenant'])['user'];

    actingAsPanelUser($this, $owner);
    panelPost($this, "/api/v1/admin/support-access/{$grant->id}/revoke")->assertStatus(403)->assertJsonPath('error.code', 'REAUTH_REQUIRED');

    switchPanelUser($this, $owner, passwordConfirmed: true);
    panelPost($this, "/api/v1/admin/support-access/{$grant->id}/revoke")->assertOk()
        ->assertJsonPath('data.active', false)
        ->assertJsonPath('data.revoked_by_member', true);
    panelPost($this, "/api/v1/admin/support-access/{$grant->id}/revoke")->assertStatus(409)->assertJsonPath('error.code', 'SUPPORT_ACCESS_NOT_ACTIVE');

    $audit = inTenant($fx['tenant'], fn () => AuditLog::query()->where('action', 'support.revoked')->sole());
    expect($audit->actor_id)->toBe($owner->id);

    switchPanelUser($this, $operator);
    panelGet($this, '/api/v1/admin/me')->assertStatus(403)->assertJsonPath('error.code', 'SUPPORT_ACCESS_INACTIVE');
});

it('la revoca resta possibile durante l\'uscita del cliente', function () {
    $fx = bookableTenant();
    $operator = platformOperator();
    $grant = openSupport($fx['tenant'], $operator);
    inTenant($fx['tenant'], fn () => DB::table('tenants')->update(['status' => 'OFFBOARDING']));
    signedInOwner($this, $fx['tenant'], passwordConfirmed: true);

    panelPost($this, "/api/v1/admin/support-access/{$grant->id}/revoke")->assertOk();
});

it('un gestore non vede le autorizzazioni di un altro tenant e non può revocarle', function () {
    $a = bookableTenant();
    $b = bookableTenant();
    $grant = openSupport($a['tenant'], platformOperator());
    signedInOwner($this, $b['tenant'], passwordConfirmed: true);

    panelGet($this, '/api/v1/admin/support-access')->assertOk()->assertJsonCount(0, 'data');
    panelPost($this, "/api/v1/admin/support-access/{$grant->id}/revoke")->assertNotFound();
});

it('support:close chiude dalla piattaforma gli accessi attivi', function () {
    $fx = bookableTenant();
    $operator = platformOperator();
    openSupport($fx['tenant'], $operator);

    $this->artisan('support:close', ['tenant' => tenantSlug($fx['tenant'])])->assertSuccessful();

    expect(app(SupportAccessService::class)->activeGrantFor($operator->id))->toBeNull()
        ->and(inTenant($fx['tenant'], fn () => AuditLog::query()->where('action', 'support.closed')->count()))->toBe(1);
});

it('support:operator imposta e toglie il flag; un utente con membership non può essere operatore', function () {
    $user = User::query()->findOrFail(createUserRow());
    $member = panelUser()['user'];

    $this->artisan('support:operator', ['email' => $user->email])->assertSuccessful();
    expect($user->refresh()->is_platform_operator)->toBeTrue();

    $this->artisan('support:operator', ['email' => $user->email, '--remove' => true])->assertSuccessful();
    expect($user->refresh()->is_platform_operator)->toBeFalse();

    $this->artisan('support:operator', ['email' => $member->email])->assertFailed();
    expect($member->refresh()->is_platform_operator)->toBeFalse();
});

it('un tenant chiuso non riceve accessi di supporto', function () {
    $fx = bookableTenant();
    inTenant($fx['tenant'], fn () => DB::table('tenants')->update(['status' => 'CLOSED']));

    expect(fn () => openSupport($fx['tenant'], platformOperator()))->toThrow(BusinessRuleViolation::class);
});
