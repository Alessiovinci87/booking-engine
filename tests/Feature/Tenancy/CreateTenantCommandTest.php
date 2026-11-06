<?php

use App\Domain\Tenancy\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * 1.5: tenant:create crea tenant e proprietario (01: nessuna registrazione pubblica). La password si
 * chiede nascosta, mai come argomento; la MFA si configura al primo accesso (08).
 */

beforeEach(function () {
    // Controllo delle password compromesse: nessuna chiamata esterna nei test.
    // La risposta si sceglie per test con $this->pwnedBody (vuota = password non compromessa).
    $this->pwnedBody = '';
    Http::fake(['api.pwnedpasswords.com/*' => fn () => Http::response($this->pwnedBody, 200)]);
});

function tenantIdBySlug(string $slug): ?string
{
    return DB::selectOne('select app_tenant_by_slug(?) as id', [$slug])->id;
}

it('crea tenant, proprietario OWNER e audit', function () {
    $this->artisan('tenant:create', ['slug' => 'casa-nuova', 'name' => 'Casa Nuova', 'owner-email' => 'Proprietario@Example.test'])
        ->expectsQuestion(__('tenancy.create.password_prompt'), TEST_PANEL_PASSWORD)
        ->expectsQuestion(__('tenancy.create.password_confirm_prompt'), TEST_PANEL_PASSWORD)
        ->expectsOutputToContain('casa-nuova')
        ->assertSuccessful();

    $tenantId = tenantIdBySlug('casa-nuova');
    expect($tenantId)->not->toBeNull();

    $user = User::query()->where('email', 'proprietario@example.test')->firstOrFail();
    expect($user->hasMfaEnabled())->toBeFalse()
        ->and(DB::table('users')->where('id', $user->id)->value('password_hash'))->toStartWith('$argon2id$');

    inTenant($tenantId, function () use ($tenantId, $user) {
        expect(DB::table('tenants')->where('id', $tenantId)->value('name'))->toBe('Casa Nuova')
            ->and(DB::table('tenant_members')->where('user_id', $user->id)->value('role'))->toBe('OWNER')
            ->and(DB::table('audit_log')->pluck('action')->all())->toContain('tenant.created', 'tenant_member.created');

        expect(json_encode(DB::table('audit_log')->get()->all()))->not->toContain('proprietario@example.test');
    });
});

it('impone la configurazione MFA al primo accesso del proprietario', function () {
    $this->artisan('tenant:create', ['slug' => 'casa-mfa', 'name' => 'Casa MFA', 'owner-email' => 'mfa@example.test'])
        ->expectsQuestion(__('tenancy.create.password_prompt'), TEST_PANEL_PASSWORD)
        ->expectsQuestion(__('tenancy.create.password_confirm_prompt'), TEST_PANEL_PASSWORD)
        ->assertSuccessful();

    panelPost($this, '/api/v1/admin/auth/login', ['email' => 'mfa@example.test', 'password' => TEST_PANEL_PASSWORD])
        ->assertOk()
        ->assertJson(['mfa_setup_required' => true]);

    panelGet($this, '/api/v1/admin/me')->assertUnauthorized()->assertJsonPath('error.code', 'MFA_SETUP_REQUIRED');
});

it('non accetta la password come argomento o opzione', function () {
    $definition = $this->app->make(Kernel::class)->all()['tenant:create']->getDefinition();

    expect(array_keys($definition->getArguments()))->toBe(['slug', 'name', 'owner-email'])
        ->and($definition->hasOption('password'))->toBeFalse();
});

it('rifiuta password diverse, corte o compromesse senza creare nulla', function (string $password, string $confirmation) {
    $this->artisan('tenant:create', ['slug' => 'casa-rifiutata', 'name' => 'Casa', 'owner-email' => 'rifiuto@example.test'])
        ->expectsQuestion(__('tenancy.create.password_prompt'), $password)
        ->expectsQuestion(__('tenancy.create.password_confirm_prompt'), $confirmation)
        ->assertFailed();

    expect(tenantIdBySlug('casa-rifiutata'))->toBeNull()
        ->and(User::query()->where('email', 'rifiuto@example.test')->exists())->toBeFalse();
})->with([
    'diverse' => [TEST_PANEL_PASSWORD, TEST_PANEL_PASSWORD.'x'],
    'corta' => ['Corta-123!', 'Corta-123!'],
]);

it('rifiuta una password compromessa', function () {
    // Risposta del servizio con il suffisso dell'hash della password: risulta compromessa.
    $sha1 = strtoupper(sha1(TEST_PANEL_PASSWORD));
    $this->pwnedBody = substr($sha1, 5).':42';

    $this->artisan('tenant:create', ['slug' => 'casa-pwned', 'name' => 'Casa', 'owner-email' => 'pwned@example.test'])
        ->expectsQuestion(__('tenancy.create.password_prompt'), TEST_PANEL_PASSWORD)
        ->expectsQuestion(__('tenancy.create.password_confirm_prompt'), TEST_PANEL_PASSWORD)
        ->expectsOutputToContain(__('validation.password.uncompromised'))
        ->assertFailed();

    expect(tenantIdBySlug('casa-pwned'))->toBeNull();
});

it('rifiuta slug non valido, slug già usato ed email già usata prima di chiedere la password', function (string $slug, string $email, string $reason) {
    $existing = createTenantRow('casa-esistente');
    $userId = createUserRow('esistente@example.test');
    createMemberRow($existing, $userId);

    $this->artisan('tenant:create', ['slug' => $slug, 'name' => 'Casa', 'owner-email' => $email])
        ->expectsOutputToContain(__($reason))
        ->assertFailed();
})->with([
    'slug non valido' => ['Casa_Non_Valida', 'nuovo@example.test', 'tenancy.create.errors.invalid_slug'],
    'slug già usato' => ['casa-esistente', 'nuovo@example.test', 'tenancy.create.errors.slug_taken'],
    'email già usata' => ['casa-altra', 'ESISTENTE@example.test', 'tenancy.create.errors.email_taken'],
]);
