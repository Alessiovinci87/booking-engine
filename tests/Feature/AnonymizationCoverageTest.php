<?php

use App\Domain\Inventory\Models\InventoryOccupancy;
use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Privacy\PersonalDataRegistry;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Guest;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Outbox\SendReservationEmail;
use App\Domain\Tenancy\Models\AuditLog;
use App\Domain\Tenancy\Models\OutboxMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * I22 (09 "Anonimizzazione effettiva", 6.3): dopo POST /admin/guests/{id}/erase nessuna colonna
 * #[PersonalData], nessun payload outbox e nessuna riga di audit contiene i valori originali
 * dell'ospite. Export JSON (accesso e portabilità), blocco sulle prenotazioni attive non concluse,
 * ri-autenticazione, isolamento tra tenant.
 */

const ERASE_ORIGINALS = ['Zelda', 'Unicorno', 'zelda.unicorno@example.test', '+39 333 1234567', 'Allergia di Zelda'];

/** @return list<class-string<Model>> */
function personalDataModels(): array
{
    $classes = [];

    foreach (glob(app_path('Domain/*/Models/*.php')) ?: [] as $file) {
        $class = 'App\\'.str_replace(['/', '\\', '.php'], ['\\', '\\', ''], Str::after($file, app_path().DIRECTORY_SEPARATOR));

        if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract() && PersonalDataRegistry::fieldsOf($class) !== []) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * Valori originali ancora presenti nel tenant: colonne #[PersonalData], payload outbox, audit.
 *
 * @return list<string>
 */
function originalsLeft(string $tenantId): array
{
    return inTenant($tenantId, function (): array {
        $found = [];

        foreach (personalDataModels() as $class) {
            $model = new $class;

            foreach (PersonalDataRegistry::fieldsOf($class) as $field) {
                foreach (DB::table($model->getTable())->whereNotNull($field)->pluck($field) as $value) {
                    foreach (ERASE_ORIGINALS as $original) {
                        if (str_contains((string) $value, $original)) {
                            $found[] = "{$model->getTable()}.{$field}";
                        }
                    }
                }
            }
        }

        foreach ([['outbox_messages', 'payload'], ['audit_log', 'changes']] as [$table, $column]) {
            foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $value) {
                foreach (ERASE_ORIGINALS as $original) {
                    if (str_contains((string) $value, $original)) {
                        $found[] = "{$table}.{$column}";
                    }
                }
            }
        }

        return array_values(array_unique($found));
    });
}

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->fx = bookableTenant('INSTANT');

    // Prenotazione da preventivo, poi cancellata con rimborso: copie in guests, quotes, refunds,
    // reservations.cancel_reason, reservation_changes, occupazioni, conflitti, eventi esterni, outbox.
    $quote = quoteFor($this->fx, ['stay' => '[2027-07-10,2027-07-14)']);
    $code = acceptQuote($this, quoteToken($quote))->assertCreated()->json('reservation_code');
    $this->reservation = simulateCustomer($this->fx, quoteReservation($this->fx, $code), FakeCustomerAction::PAY);
    $r = $this->reservation;

    inTenant($this->fx['tenant'], function () use ($r, $quote) {
        DB::table('guests')->where('id', $r->guest_id)->update([
            'first_name' => 'Zelda', 'last_name' => 'Unicorno', 'email' => 'zelda.unicorno@example.test',
            'phone' => '+39 333 1234567', 'notes' => 'Allergia di Zelda',
        ]);
        DB::table('quotes')->where('id', $quote->id)->update(['guest_name' => 'Zelda Unicorno', 'guest_email' => 'zelda.unicorno@example.test']);
        $model = Reservation::query()->findOrFail($r->id);
        machine()->cancel($model, CancellationRefund::for($model, null), 'Zelda ha chiamato per disdire', null);
        DB::table('refunds')->update(['reason' => 'Richiesta di Zelda Unicorno']);

        DB::table('reservation_changes')->insert([
            'id' => uuid7(), 'tenant_id' => $r->tenant_id, 'reservation_id' => $r->id,
            'previous_stay' => '[2027-07-10,2027-07-14)', 'new_stay' => '[2027-07-10,2027-07-14)',
            'previous_adults' => 2, 'new_adults' => 2, 'previous_children' => 0, 'new_children' => 0,
            'previous_contract_total_cents' => $r->contract_total_cents, 'new_contract_total_cents' => $r->contract_total_cents,
            'note' => 'Zelda chiede la culla', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    // I12: calendario esterno solo su unità REQUEST; la prenotazione è già cancellata.
    inTenant($this->fx['tenant'], fn () => DB::table('units')->where('id', $this->fx['unit'])->update(['booking_mode' => 'REQUEST']));
    $event = createExternalEventRow($this->fx['tenant'], $this->fx['unit'], '[2027-07-11,2027-07-13)');
    inTenant($this->fx['tenant'], function () use ($event, $r) {
        DB::table('external_calendar_events')->where('id', $event)->update(['summary_redacted' => 'Zelda (Booking)']);
        DB::table('inventory_conflicts')->insert([
            'id' => uuid7(), 'tenant_id' => $r->tenant_id, 'unit_id' => $this->fx['unit'],
            'occupancy_id' => InventoryOccupancy::query()->where('reservation_id', $r->id)->value('id'),
            'external_event_id' => $event, 'stay' => '[2027-07-11,2027-07-13)', 'status' => 'RESOLVED',
            'resolution' => 'CANCELLED_DIRECT', 'resolution_notes' => 'Sentita Zelda al telefono', 'detected_at' => now(), 'resolved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $this->guestId = $r->guest_id;
    $this->owner = panelUser($this->fx['tenant']);
});
afterEach(fn () => Carbon::setTestNow());

it('I22: dopo erase nessuna copia contiene i valori originali; importi, paese e stato restano', function () {
    expect(originalsLeft($this->fx['tenant']))->not->toBe([]);
    $pendingBefore = inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->where('payload->reservation_id', $this->reservation->id)->count());
    expect($pendingBefore)->toBeGreaterThan(0);

    actingAsPanelUser($this, $this->owner['user'], passwordConfirmed: true);
    panelPost($this, "/api/v1/admin/guests/{$this->guestId}/erase")->assertOk()->assertJsonPath('data.id', $this->guestId);

    expect(originalsLeft($this->fx['tenant']))->toBe([]);

    $guest = inTenant($this->fx['tenant'], fn () => Guest::query()->findOrFail($this->guestId));
    $reservation = reloadReservation($this->fx, $this->reservation->id);
    expect($guest->anonymized_at)->not->toBeNull()
        ->and($guest->country_code)->toBe('GB')
        ->and($guest->notes)->toBeNull()
        ->and($reservation->status)->toBe(ReservationStatus::CANCELLED)
        ->and($reservation->contract_total_cents)->toBe($this->reservation->contract_total_cents)
        ->and($reservation->cancel_reason)->toBe('[rimosso]')
        ->and(inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->where('payload->reservation_id', $this->reservation->id)->count()))->toBe(0)
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);

    // Audit: azione registrata con i soli conteggi.
    $audit = inTenant($this->fx['tenant'], fn () => AuditLog::query()->where('action', 'guest.erased')->sole());
    expect($audit->changes['reservations'])->toBe(1)
        ->and($audit->changes['outbox_payloads'])->toBe($pendingBefore);

    // Nessuna email parte più per l'ospite: i payload svuotati non trovano la prenotazione.
    Mail::fake();
    inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->whereNotNull('payload_purged_at')->get()
        ->each(fn (OutboxMessage $m) => app(SendReservationEmail::class)->handle($m)));
    Mail::assertNothingSent();

    // Idempotente.
    panelPost($this, "/api/v1/admin/guests/{$this->guestId}/erase")->assertOk();
});

it('export JSON: dati personali, prenotazioni, pagamenti e rimborsi; registrato in audit senza dati personali', function () {
    actingAsPanelUser($this, $this->owner['user'], passwordConfirmed: true);
    $response = panelGet($this, "/api/v1/admin/guests/{$this->guestId}/export")->assertOk()
        ->assertHeader('Content-Disposition', "attachment; filename=\"ospite-{$this->guestId}.json\"");

    $data = $response->json('data');
    expect($data['guest']['email'])->toBe('zelda.unicorno@example.test')
        ->and($data['guest']['phone'])->toBe('+39 333 1234567')
        ->and($data['reservations'])->toHaveCount(1)
        ->and($data['reservations'][0]['code'])->toBe($this->reservation->code)
        ->and($data['reservations'][0]['payments'])->not->toBeEmpty()
        ->and($data['reservations'][0]['refunds'][0]['reason'])->toBe('Richiesta di Zelda Unicorno')
        ->and($data['reservations'][0]['quote']['guest_name'])->toBe('Zelda Unicorno');

    $audit = inTenant($this->fx['tenant'], fn () => AuditLog::query()->where('action', 'guest.exported')->sole());
    expect(json_encode($audit->changes))->not->toContain('Zelda');
});

it('erase bloccato su una prenotazione attiva non conclusa; ammesso dopo il check-out', function () {
    $active = reservationIn($this->fx, ReservationStatus::CONFIRMED, '[2027-08-20,2027-08-25)');
    actingAsPanelUser($this, $this->owner['user'], passwordConfirmed: true);

    panelPost($this, "/api/v1/admin/guests/{$active->guest_id}/erase")->assertStatus(409)
        ->assertJsonPath('error.code', 'GUEST_HAS_ACTIVE_RESERVATION');
    expect(inTenant($this->fx['tenant'], fn () => Guest::query()->findOrFail($active->guest_id))->anonymized_at)->toBeNull();

    Carbon::setTestNow('2027-08-25 12:00:00');
    actingAsPanelUser($this, $this->owner['user'], passwordConfirmed: true);
    panelPost($this, "/api/v1/admin/guests/{$active->guest_id}/erase")->assertOk();
});

it('erase ed export richiedono la ri-autenticazione; un ospite di un altro tenant è 404', function () {
    actingAsPanelUser($this, $this->owner['user']);
    panelPost($this, "/api/v1/admin/guests/{$this->guestId}/erase")->assertForbidden()->assertJsonPath('error.code', 'REAUTH_REQUIRED');
    // Anche l'export dei dati (08 "export completo", 6.7).
    panelGet($this, "/api/v1/admin/guests/{$this->guestId}/export")->assertForbidden()->assertJsonPath('error.code', 'REAUTH_REQUIRED');
    expect(inTenant($this->fx['tenant'], fn () => Guest::query()->findOrFail($this->guestId))->anonymized_at)->toBeNull();
});

it('un ospite di un altro tenant non si esporta né si anonimizza', function () {
    $other = bookableTenant('INSTANT');
    actingAsPanelUser($this, panelUser($other['tenant'])['user'], passwordConfirmed: true);

    panelGet($this, "/api/v1/admin/guests/{$this->guestId}/export")->assertNotFound();
    panelPost($this, "/api/v1/admin/guests/{$this->guestId}/erase")->assertNotFound();
    expect(inTenant($this->fx['tenant'], fn () => Guest::query()->findOrFail($this->guestId))->anonymized_at)->toBeNull();
});
