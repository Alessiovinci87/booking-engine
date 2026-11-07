<?php

use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\Enums\ReservationEvent;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StateMachines\ReservationTransitions;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Carbon;

/*
 * I15 (02, 03 "Test minimi"): ogni prenotazione in stato occupante ha esattamente un'occupazione
 * ACTIVE e ogni occupazione RESERVATION attiva appartiene a una prenotazione in stato occupante.
 * Sequenze casuali di eventi con seme fisso (riproducibili): dopo ogni passo l'invariante vale, e un
 * evento rifiutato non lascia effetti.
 */

beforeEach(fn () => Carbon::setTestNow('2027-06-01 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('mantiene I15 dopo ogni passo di sequenze casuali di eventi', function (int $seed) {
    mt_srand($seed);
    $fixtures = ['INSTANT' => bookableTenant('INSTANT'), 'REQUEST' => bookableTenant('REQUEST')];
    $events = array_values(array_filter(ReservationEvent::cases(), fn (ReservationEvent $e): bool => ! $e->creates()));
    $stays = ['[2027-07-01,2027-07-04)', '[2027-07-03,2027-07-07)', '[2027-07-10,2027-07-13)', '[2027-07-12,2027-07-16)'];
    $applied = 0;

    for ($step = 0; $step < 80; $step++) {
        $mode = mt_rand(0, 1) === 0 ? 'INSTANT' : 'REQUEST';
        $fx = $fixtures[$mode];
        $existing = inTenant($fx['tenant'], fn () => Reservation::query()->orderBy('created_at')->orderBy('id')->get()->all());
        $roll = mt_rand(1, 10);

        if ($existing === [] || $roll <= 3) {
            // Nuova prenotazione: checkout dell'ospite o prenotazione manuale, anche su date occupate.
            $stay = $stays[mt_rand(0, count($stays) - 1)];

            try {
                mt_rand(0, 2) === 0
                    ? inTenant($fx['tenant'], fn () => machine()->createManual(newReservationData($fx, $stay, channel: ReservationChannel::MANUAL)))
                    : startCheckout($fx, $stay);
                $applied++;
            } catch (BusinessRuleViolation $e) {
                expect($e->errorCode)->toBe('DATES_UNAVAILABLE');
            }
        } elseif ($roll === 4) {
            // Il tempo passa: scadenze di checkout, approvazione e decisione diventano raggiungibili.
            Carbon::setTestNow(now()->addHours(mt_rand(1, 30)));
        } elseif ($roll === 5) {
            // L'ospite agisce sulla pagina di pagamento di una prenotazione in attesa.
            $waiting = array_values(array_filter($existing, fn (Reservation $r): bool => $r->status === ReservationStatus::AWAITING_PAYMENT));

            if ($waiting !== []) {
                simulateCustomer($fx, $waiting[mt_rand(0, count($waiting) - 1)], mt_rand(0, 3) === 0
                    ? FakeCustomerAction::DECLINE
                    : FakeCustomerAction::PAY);
                $applied++;
            }
        } else {
            $reservation = $existing[mt_rand(0, count($existing) - 1)];
            $event = $events[mt_rand(0, count($events) - 1)];
            $allowed = ReservationTransitions::find($reservation->status, $event) !== null;

            try {
                inTenant($fx['tenant'], fn () => fireEvent($fx, $reservation, $event));
                expect($allowed)->toBeTrue();
                $applied++;
            } catch (IllegalTransition|BusinessRuleViolation|LogicException $e) {
                // Rifiutato (tabella, guardia o pagamento non coerente): nessun effetto.
                $after = reloadReservation($fx, $reservation->id);
                expect($after->status)->toBe($reservation->status)->and($after->version)->toBe($reservation->version);
            }
        }

        foreach ($fixtures as $fixture) {
            expect(occupancyViolations($fixture['tenant']))->toBe([], "passo {$step}, seme {$seed}");
        }
    }

    expect($applied)->toBeGreaterThan(5);
})->with([20261207, 20261218, 42]);
