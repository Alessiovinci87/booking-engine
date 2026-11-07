<?php

use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\IcalImportService;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\SyncHealthService;
use App\Domain\Inventory\Enums\PropertyStatus;
use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\PropertySetupService;
use App\Domain\Tenancy\Models\User;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioning;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

// Verifica di M2 (ADR-0010): account Stripe finto perché la struttura demo si possa pubblicare.
it('crea un account Stripe finto con cui la struttura demo si pubblica', function () {
    $this->seed(DemoSeeder::class);

    inTenant(demoTenantId(), function () {
        $account = DB::table('stripe_accounts')->sole();
        expect($account->stripe_account_id)->toStartWith(DemoSeeder::STRIPE_ACCOUNT_PREFIX)
            ->and($account->livemode)->toBeFalse()
            ->and($account->charges_enabled)->toBeTrue()
            ->and($account->payouts_enabled)->toBeTrue();

        $property = app(PropertySetupService::class)->publish(Property::query()->sole());

        expect($property->status)->toBe(PropertyStatus::PUBLISHED);
    });
});

it('usa solo dati inventati', function () {
    $this->seed(DemoSeeder::class);

    $emails = inTenant(demoTenantId(), fn () => DB::table('properties')->pluck('contact_email')->all());

    foreach ([...$emails, DemoSeeder::OWNER_EMAIL] as $email) {
        expect($email)->toEndWith('@example.test');
    }
});

// 3.8: struttura pubblicata e prenotazioni di esempio in vari stati, create con i flussi veri.
it('pubblica la struttura e crea prenotazioni di esempio coerenti con l\'inventario', function () {
    $this->seed(DemoSeeder::class);
    $tenant = demoTenantId();

    inTenant($tenant, function () {
        expect(DB::table('properties')->value('status'))->toBe('PUBLISHED')
            ->and(DB::table('units')->orderBy('name')->pluck('booking_mode')->all())->toBe(['INSTANT', 'REQUEST']);

        $byStatus = DB::table('reservations')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        ksort($byStatus);

        expect($byStatus)->toBe([
            'AWAITING_APPROVAL' => 1,
            'AWAITING_PAYMENT' => 1,
            'CANCELLED' => 1,
            'CONFIRMED' => 5, // 4 di M3 + quella del preventivo accettato (M5)
            'PAYMENT_FAILED' => 1,
            'REJECTED' => 1,
        ])
            ->and(DB::table('guests')->pluck('email')->every(fn ($email) => str_ends_with($email, '@example.test')))->toBeTrue()
            ->and(DB::table('payments')->where('purpose', 'OFFLINE')->count())->toBe(2)
            ->and(DB::table('inventory_occupancies')->where('kind', 'MANUAL_BLOCK')->where('status', 'ACTIVE')->count())->toBe(1);
    });

    // I15: ogni prenotazione occupante ha la sua occupazione, nessuna occupazione orfana.
    expect(occupancyViolations($tenant))->toBe([]);
});

// Verifica di M5 (ADR-0013 punto 44): calendari importati dimostrativi, feed, conflitto, preventivi.
it('crea i dati di M5 senza chiamate esterne', function () {
    Http::preventStrayRequests();
    $this->seed(DemoSeeder::class);
    $tenant = demoTenantId();

    inTenant($tenant, function () {
        $garden = DB::table('units')->where('booking_mode', 'REQUEST')->value('id');
        $calendars = DB::table('external_calendars')->orderBy('source')->get();

        expect($calendars->pluck('source')->all())->toBe(['AIRBNB', 'BOOKING'])
            ->and($calendars->every(fn ($c) => $c->demo && $c->unit_id === $garden && $c->status === 'ACTIVE' && $c->last_success_at !== null))->toBeTrue()
            ->and(DB::table('external_calendar_events')->where('status', 'ACTIVE')->count())->toBe(4)
            ->and(DB::table('sync_runs')->count())->toBe(0)
            ->and(DB::table('ics_feeds')->where('status', 'ACTIVE')->count())->toBe(4)
            ->and(DB::table('ics_feeds')->where('unit_id', $garden)->whereNotNull('paired_external_calendar_id')->count())->toBe(2);

        $conflict = DB::table('inventory_conflicts')->sole();
        expect($conflict->status)->toBe('OPEN')
            ->and($conflict->unit_id)->toBe($garden)
            ->and(DB::table('reservations')->where('id', DB::table('inventory_occupancies')->where('id', $conflict->occupancy_id)->value('reservation_id'))->value('status'))->toBe('CONFIRMED');

        expect(DB::table('quotes')->orderBy('status')->pluck('status')->all())->toBe(['ACCEPTED', 'SENT'])
            ->and(DB::table('reservations')->where('channel', 'QUOTE')->value('status'))->toBe('CONFIRMED');
    });

    expect(occupancyViolations($tenant))->toBe([]);
});

it('i calendari dimostrativi non si scaricano e restano sani (il dispatcher li esclude: IcalImportTest)', function () {
    $this->seed(DemoSeeder::class);
    $tenant = demoTenantId();
    Carbon::setTestNow(now()->addDay());

    try {
        inTenant($tenant, function () {
            $calendar = ExternalCalendar::query()->firstOrFail();
            expect(SyncHealthService::healthFor($calendar, CarbonImmutable::now()))->toBe(ExternalCalendarStatus::ACTIVE)
                ->and(app(IcalImportService::class)->sync($calendar->tenant_id, $calendar->id))->toBeNull();
        });
    } finally {
        Carbon::setTestNow();
    }
});

it('non duplica i dati se rieseguito', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(inTenant(demoTenantId(), fn () => DB::table('units')->count()))->toBe(2)
        ->and(User::query()->where('email', DemoSeeder::OWNER_EMAIL)->count())->toBe(1);
});

it('in produzione si ferma senza scrivere nulla', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => app(DemoSeeder::class)->run(app(TenantProvisioning::class), app(TenantContext::class)))
            ->toThrow(RuntimeException::class);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    expect(demoTenantId())->toBeNull()
        ->and(User::query()->where('email', DemoSeeder::OWNER_EMAIL)->exists())->toBeFalse();
});
