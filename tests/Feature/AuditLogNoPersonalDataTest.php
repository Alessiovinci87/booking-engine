<?php

use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Payments\Models\Refund;
use App\Domain\Privacy\PersonalDataRegistry;
use App\Domain\Reservations\Models\Guest;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Models\ReservationChange;
use App\Domain\Tenancy\Audit\AuditChanges;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * I22, 02 "AuditLog": changes non contiene mai valori di campi #[PersonalData], solo il nome del
 * campo con "[personal]".
 */

/** @return list<class-string<Model>> */
function modelsWithPersonalData(): array
{
    $classes = [];

    foreach (glob(app_path('Domain/*/Models/*.php')) ?: [] as $file) {
        $relative = Str::after($file, app_path().DIRECTORY_SEPARATOR);
        $class = 'App\\'.str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $relative);

        if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract() && PersonalDataRegistry::fieldsOf($class) !== []) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('trova i modelli con campi personali', function () {
    expect(modelsWithPersonalData())->toContain(Guest::class, User::class);
});

it('sostituisce ogni campo personale con "[personal]" in ogni modello', function () {
    foreach (modelsWithPersonalData() as $class) {
        $fields = PersonalDataRegistry::fieldsOf($class);

        /** @var Model $model */
        $model = new $class;
        $model->exists = true;
        $model->setRawAttributes(array_fill_keys($fields, 'valore-vecchio-personale'), true);

        foreach ($fields as $field) {
            $model->setRawAttributes(array_merge($model->getAttributes(), [$field => 'valore-nuovo-personale']));
        }

        $model->syncChanges();
        $changes = AuditChanges::fromModel($model);
        $json = json_encode($changes);

        expect($json)->not->toContain('valore-vecchio-personale', $class)
            ->and($json)->not->toContain('valore-nuovo-personale', $class);

        foreach ($fields as $field) {
            expect($changes[$field] ?? null)->toBe(AuditChanges::PERSONAL, $class.'::'.$field);
        }
    }
});

it('registra creazione e modifica di un ospite senza valori personali', function () {
    $tenant = createTenantRow();
    $values = ['first_name' => 'Nomeinventato', 'last_name' => 'Cognomeinventato', 'email' => 'inventato@example.test', 'phone' => '+390011223344', 'notes' => 'Nota riservata inventata'];

    inTenant($tenant, function () use ($values) {
        $logger = app(AuditLogger::class);

        $guest = new Guest;
        $guest->forceFill($values + ['country_code' => 'IT'])->save();
        $logger->recordModelChange('guest.created', $guest);

        $guest->forceFill(['email' => 'altro-inventato@example.test', 'country_code' => 'FR'])->save();
        $logger->recordModelChange('guest.updated', $guest);
    });

    $rows = inTenant($tenant, fn () => DB::table('audit_log')->whereIn('action', ['guest.created', 'guest.updated'])->get());
    expect($rows)->toHaveCount(2);

    $dump = json_encode($rows->all());
    foreach ([...array_values($values), 'altro-inventato@example.test'] as $value) {
        expect($dump)->not->toContain($value);
    }

    $updated = json_decode($rows->firstWhere('action', 'guest.updated')->changes, true);
    expect($updated)->toEqual([
        'country_code' => ['from' => 'IT', 'to' => 'FR'],
        'email' => AuditChanges::PERSONAL,
    ]);
});

it('nasconde i campi segreti del modello', function () {
    $tenant = createTenantRow();
    $user = User::query()->findOrFail(createUserRow());
    createMemberRow($tenant, $user->id);

    // L'utente è globale: la riga d'audit prende il tenant dal contesto corrente.
    inTenant($tenant, function () use ($user) {
        $user->forceFill(['password_hash' => 'Una-password-segreta-2026'])->save();
        app(AuditLogger::class)->recordModelChange('user.password_changed', $user);
    });

    $row = inTenant($tenant, fn () => DB::table('audit_log')->where('action', 'user.password_changed')->first());

    expect(json_decode($row->changes, true))->toBe(['password_hash' => AuditChanges::HIDDEN])
        ->and($row->changes)->not->toContain('Una-password-segreta-2026');
});

it('tratta come personali i campi di testo libero (ADR-0009)', function (string $class, string $field) {
    expect(PersonalDataRegistry::isPersonal($class, $field))->toBeTrue();
})->with([
    'reservations.cancel_reason' => [Reservation::class, 'cancel_reason'],
    'reservation_changes.note' => [ReservationChange::class, 'note'],
    'inventory_occupancies.note' => ['App\\Domain\\Inventory\\Models\\InventoryOccupancy', 'note'],
    'inventory_conflicts.resolution_notes' => [InventoryConflict::class, 'resolution_notes'],
    'refunds.reason' => [Refund::class, 'reason'],
    'external_calendar_events.summary_redacted' => [ExternalCalendarEvent::class, 'summary_redacted'],
    'guests.notes' => [Guest::class, 'notes'],
]);

it('registra il motivo di cancellazione solo come "[personal]"', function () {
    $t = twoTenantsWithUnits()['a'];
    $guest = createGuestRow($t['tenant']);
    $row = reservationRow($t['tenant'], $t['unit'], $guest);
    inTenant($t['tenant'], fn () => DB::table('reservations')->insert($row));

    inTenant($t['tenant'], function () use ($row) {
        $reservation = Reservation::query()->findOrFail($row['id']);
        $reservation->forceFill(['cancel_reason' => 'Motivo con nome Ospite Inventato'])->save();
        app(AuditLogger::class)->recordModelChange('reservation.updated', $reservation);
    });

    $changes = inTenant($t['tenant'], fn () => DB::table('audit_log')->where('action', 'reservation.updated')->value('changes'));

    expect(json_decode($changes, true))->toBe(['cancel_reason' => AuditChanges::PERSONAL])
        ->and($changes)->not->toContain('Ospite Inventato');
});
