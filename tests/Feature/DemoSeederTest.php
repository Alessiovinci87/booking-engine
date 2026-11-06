<?php

use App\Domain\Tenancy\Models\User;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioning;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

/*
 * 1.8: seeder demo con dati inventati (tenant demo-alghero, Casa Demo Alghero it/en, due unità,
 * una tariffa base per unità con due stagioni).
 */

function demoTenantId(): ?string
{
    return DB::selectOne('select app_tenant_by_slug(?) as id', [DemoSeeder::TENANT_SLUG])->id;
}

it('crea tenant, struttura con traduzioni, due unità e le tariffe base', function () {
    $this->artisan('db:seed', ['--class' => DemoSeeder::class])
        ->expectsOutputToContain(DemoSeeder::OWNER_EMAIL)
        ->assertSuccessful();

    $tenant = demoTenantId();
    expect($tenant)->not->toBeNull();

    inTenant($tenant, function () {
        $property = DB::table('properties')->sole();
        expect($property->name)->toBe('Casa Demo Alghero')
            ->and($property->city)->toBe('Alghero')
            ->and(DB::table('property_translations')->orderBy('locale')->pluck('locale')->all())->toBe(['en', 'it']);

        $units = DB::table('units')->get();
        expect($units)->toHaveCount(2)
            ->and(DB::table('unit_translations')->count())->toBe(4)
            ->and(DB::table('rate_plans')->where('active', true)->count())->toBe(2)
            ->and(DB::table('rate_plans')->pluck('base_nightly_cents')->sort()->values()->all())->toBe([9500, 14500])
            ->and(DB::table('season_rules')->count())->toBe(4)
            ->and(DB::table('season_rules')->where('name', 'Ferragosto')->pluck('date_range')->unique()->all())->toBe(['[2027-08-13,2027-08-18)']);

        // Politiche standard della struttura, flessibile come predefinita (ADR-0009).
        expect(DB::table('cancellation_policies')->orderBy('code')->pluck('code')->all())->toBe(['FLEXIBLE_7D', 'NON_REFUNDABLE'])
            ->and(DB::table('cancellation_policies')->where('id', $property->default_cancellation_policy_id)->value('code'))->toBe('FLEXIBLE_7D');
    });

    $owner = User::query()->where('email', DemoSeeder::OWNER_EMAIL)->sole();
    expect($owner->hasMfaEnabled())->toBeFalse();
});

it('usa solo dati inventati', function () {
    $this->seed(DemoSeeder::class);

    $emails = inTenant(demoTenantId(), fn () => DB::table('properties')->pluck('contact_email')->all());

    foreach ([...$emails, DemoSeeder::OWNER_EMAIL] as $email) {
        expect($email)->toEndWith('@example.test');
    }
});

it('non duplica i dati se rieseguito', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(inTenant(demoTenantId(), fn () => DB::table('units')->count()))->toBe(2)
        ->and(User::query()->where('email', DemoSeeder::OWNER_EMAIL)->count())->toBe(1);
});

it('non gira in produzione', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        app(DemoSeeder::class)->run(app(TenantProvisioning::class), app(TenantContext::class));
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
})->throws(RuntimeException::class);
