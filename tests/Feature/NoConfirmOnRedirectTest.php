<?php

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Reservations\Enums\ReservationStatus;
use Illuminate\Support\Carbon;

/*
 * I2 (02, 05): nessuna conferma dal solo redirect. Aprire la pagina di pagamento, tornare sulla
 * success_url o sulla cancel_url non cambia lo stato: la conferma arriva solo dall'evento del servizio
 * di pagamento (webhook verificato, qui l'evento del checkout simulato) o da una lettura API.
 */

beforeEach(function () {
    // La pagina ospite si rende senza asset: in CI i test girano prima della build di Vite.
    $this->withoutVite();
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->fx = bookableTenant('INSTANT');
    $this->reservation = startCheckout($this->fx);
    $this->payment = latestPayment($this->fx, $this->reservation->id);
    $this->page = fakeGateway()->checkoutPage((string) $this->payment->checkout_session_id);
});
afterEach(fn () => Carbon::setTestNow());

function relativeUrl(string $url): string
{
    $parts = parse_url($url);

    return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
}

it('la success_url non contiene segreti né dati dell\'ospite', function () {
    expect($this->page['success_url'])->toContain('/prenotazione/'.$this->reservation->code)
        ->toContain('session='.$this->payment->checkout_session_id)
        ->not->toContain('token')
        ->not->toContain('ada@example.test')
        ->not->toContain('Lovelace');
});

it('aprire la pagina di pagamento e tornare sulla success_url non conferma', function () {
    $this->get('/_simulated-checkout/'.$this->payment->checkout_session_id)->assertOk()->assertSee(__('payments.simulated.pay'));
    $this->get(relativeUrl($this->page['success_url']))->assertOk();

    $r = reloadReservation($this->fx, $this->reservation->id);
    expect($r->status)->toBe(ReservationStatus::AWAITING_PAYMENT)->and($r->confirmation_kind)->toBeNull()
        ->and(latestPayment($this->fx, $r->id)->status)->toBe(PaymentStatus::PENDING);
});

it('una sessione pagata senza evento resta in attesa anche dopo il redirect', function () {
    // Il cliente ha pagato sul servizio, ma l'evento non è ancora arrivato: il ritorno non basta.
    fakeGateway()->simulateCustomer((string) $this->payment->checkout_session_id, FakeCustomerAction::PAY);
    $this->get(relativeUrl($this->page['success_url']))->assertOk();

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT);
});

it('la cancel_url non annulla né rilascia', function () {
    $this->get(relativeUrl($this->page['cancel_url']))->assertOk();

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT)
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);
});

it('la conferma arriva dall\'evento del pagamento (pulsante "Pagamento riuscito")', function () {
    $this->post('/_simulated-checkout/'.$this->payment->checkout_session_id.'/pay')
        ->assertRedirect($this->page['success_url']);

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::CONFIRMED);
});
