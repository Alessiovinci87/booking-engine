<?php

use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Privacy\PersonalDataRegistry;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Guest;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Exceptions\IllegalStatusWrite;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\Models\Concerns\StatusGuardedBuilder;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Attività 1.3: modelli Eloquent in app/Domain/<Area>/Models, tenant_id solo dal contesto, global
 * scope come difesa aggiuntiva a RLS, enum e StayRange come cast, #[PersonalData], protezione a
 * runtime degli stati (decisione dopo M0, regola 3).
 */

/*
 * Macchina a stati di prova nello spazio dei nomi ammesso. Definita con eval per non aggiungere a
 * tests/ una classe fuori dallo standard PSR-4 di Composer.
 */
function fakeStateMachine(): object
{
    if (! class_exists('App\Domain\Reservations\StateMachines\TestOnlyStateMachine', false)) {
        eval(<<<'PHP'
            namespace App\Domain\Reservations\StateMachines;

            final class TestOnlyStateMachine
            {
                public function run(callable $fn): mixed
                {
                    return $fn();
                }
            }
            PHP);
    }

    return new ('App\Domain\Reservations\StateMachines\TestOnlyStateMachine');
}

function makeReservation(string $tenant, string $unit, string $guest): Reservation
{
    return fakeStateMachine()->run(fn () => Reservation::create([
        'unit_id' => $unit, 'guest_id' => $guest, 'code' => strtoupper(Str::random(8)), 'channel' => 'MANUAL',
        'stay' => StayRange::of('2027-08-10', '2027-08-17'), 'adults' => 2, 'children' => 0,
        'status' => ReservationStatus::CONFIRMED, 'confirmation_kind' => 'ADMINISTRATIVE',
        'booking_mode_snapshot' => 'REQUEST', 'payment_mode_snapshot' => 'DEPOSIT_30',
        'cancellation_policy_snapshot' => ['code' => 'FLEXIBLE_7D'], 'contract_total_cents' => 158500,
        'deposit_due_cents' => 47550, 'balance_due_cents' => 110950,
    ]));
}

beforeEach(function () {
    $this->tenant = createTenantRow();
    $this->property = createPropertyRow($this->tenant);
    $this->unit = createUnitRow($this->tenant, $this->property);
    $this->guest = createGuestRow($this->tenant);
});

it('genera ID UUID v7 nell\'applicazione', function () {
    $property = inTenant($this->tenant, fn () => Property::create(['name' => 'Casa', 'slug' => 'casa-v7']));

    expect(Str::isUuid($property->id))->toBeTrue()
        ->and($property->id[14])->toBe('7');
});

it('assegna tenant_id dal contesto e non lo accetta in massa', function () {
    $property = inTenant($this->tenant, fn () => Property::create([
        'name' => 'Casa', 'slug' => 'casa-contesto', 'tenant_id' => uuid7(),
    ]));

    expect($property->tenant_id)->toBe($this->tenant);
});

it('rifiuta la creazione di un modello tenant-scoped fuori dal contesto', function () {
    Property::create(['name' => 'Casa', 'slug' => 'senza-contesto']);
})->throws(MissingTenantContext::class);

it('rifiuta un tenant_id diverso dal contesto impostato a mano', function () {
    inTenant($this->tenant, function () {
        $property = new Property(['name' => 'Casa', 'slug' => 'altro-tenant']);
        $property->forceFill(['tenant_id' => uuid7()])->save();
    });
})->throws(MissingTenantContext::class);

it('legge solo le righe del tenant corrente, anche via Eloquent', function () {
    $other = createTenantRow();
    createUnitRow($other, createPropertyRow($other));

    $ids = inTenant($this->tenant, fn () => Unit::query()->pluck('id')->all());

    expect($ids)->toBe([$this->unit])
        ->and(Unit::query()->count())->toBe(0);
});

it('converte stay in StayRange e gli stati in enum', function () {
    $reservation = inTenant($this->tenant, function () {
        $created = makeReservation($this->tenant, $this->unit, $this->guest);

        return Reservation::query()->findOrFail($created->id);
    });

    expect($reservation->stay)->toBeInstanceOf(StayRange::class)
        ->and($reservation->stay->toPostgres())->toBe('[2027-08-10,2027-08-17)')
        ->and($reservation->stay->nights())->toBe(7)
        ->and($reservation->status)->toBe(ReservationStatus::CONFIRMED)
        ->and($reservation->cancellation_policy_snapshot)->toBe(['code' => 'FLEXIBLE_7D']);
});

it('vieta di scrivere status fuori da una macchina a stati', function (Closure $write) {
    inTenant($this->tenant, function () use ($write) {
        $reservation = makeReservation($this->tenant, $this->unit, $this->guest);
        $write($reservation);
    });
})->with([
    'assegnazione' => [fn (Reservation $r) => $r->status = ReservationStatus::CANCELLED],
    'update del modello' => [fn (Reservation $r) => $r->update(['status' => ReservationStatus::CANCELLED])],
    'forceFill' => [fn (Reservation $r) => $r->forceFill(['status' => ReservationStatus::CANCELLED])],
    'update di massa' => [fn (Reservation $r) => Reservation::query()->whereKey($r->id)->update(['status' => 'CANCELLED'])],
    'creazione' => [fn (Reservation $r) => Reservation::create(['status' => ReservationStatus::CONFIRMED])],
])->throws(IllegalStatusWrite::class);

it('consente di scrivere status da una classe in StateMachines', function () {
    $status = inTenant($this->tenant, function () {
        $reservation = makeReservation($this->tenant, $this->unit, $this->guest);
        fakeStateMachine()->run(fn () => $reservation->update(['status' => ReservationStatus::CANCELLED, 'cancelled_at' => now()]));

        return DB::table('reservations')->where('id', $reservation->id)->value('status');
    });

    expect($status)->toBe('CANCELLED');
});

it('consente le modifiche degli altri campi senza macchina a stati', function () {
    $adults = inTenant($this->tenant, function () {
        $reservation = makeReservation($this->tenant, $this->unit, $this->guest);
        $reservation->update(['adults' => 3]);

        return $reservation->fresh()?->adults;
    });

    expect($adults)->toBe(3);
});

it('applica la protezione a tutti i modelli con macchina a stati di 03 e all\'outbox', function () {
    $guarded = collect(glob(app_path('Domain/*/Models/*.php')))
        ->map(fn (string $file) => 'App\\Domain\\'.basename(dirname($file, 2)).'\\Models\\'.basename($file, '.php'))
        ->filter(fn (string $class) => in_array(
            'App\Domain\Tenancy\Models\Concerns\GuardsStatusWrites',
            class_uses_recursive($class),
            true,
        ))
        ->map(fn (string $class) => class_basename($class))
        ->sort()->values()->all();

    expect($guarded)->toBe(['InventoryConflict', 'OutboxMessage', 'Payment', 'Quote', 'Refund', 'Reservation']);

    // Gli update di massa passano dal builder che controlla status.
    foreach ($guarded as $name) {
        $class = collect(glob(app_path("Domain/*/Models/{$name}.php")))
            ->map(fn (string $file) => 'App\\Domain\\'.basename(dirname($file, 2)).'\\Models\\'.$name)
            ->first();

        expect($class::query())->toBeInstanceOf(StatusGuardedBuilder::class);
    }
});

it('ha un modello per ogni tabella di dominio', function () {
    $tables = collect(glob(app_path('Domain/*/Models/*.php')))
        ->map(fn (string $file) => 'App\\Domain\\'.basename(dirname($file, 2)).'\\Models\\'.basename($file, '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract())
        ->map(fn (string $class) => (new $class)->getTable())
        ->sort()->values()->all();

    $schema = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))
        ->pluck('tablename')
        ->reject(fn (string $t) => in_array($t, ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'], true))
        ->sort()->values()->all();

    expect($tables)->toBe($schema);
});

it('marca con #[PersonalData] i campi personali di 09', function () {
    expect(PersonalDataRegistry::fieldsOf(Guest::class))->toBe(['first_name', 'last_name', 'email', 'phone', 'notes'])
        ->and(PersonalDataRegistry::fieldsOf(Quote::class))->toBe(['guest_name', 'guest_email'])
        ->and(PersonalDataRegistry::fieldsOf(User::class))->toBe(['email'])
        ->and(PersonalDataRegistry::fieldsOf(Reservation::class))->toBe([])
        ->and(PersonalDataRegistry::isPersonal(new Guest, 'country_code'))->toBeFalse();
});

it('legge il tenant solo nel suo contesto', function () {
    expect(Tenant::query()->count())->toBe(0)
        ->and(inTenant($this->tenant, fn () => Tenant::query()->pluck('id')->all()))->toBe([$this->tenant]);
});
