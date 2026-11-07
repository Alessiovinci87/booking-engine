<?php

use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;

/*
 * I16 con Stripe in modalità test: la sessione è stata creata su Stripe ma il passo 3 non si è
 * completato (crash prima di salvarne l'ID). Il recupero con la stessa idempotency key restituisce la
 * stessa sessione, senza crearne una seconda.
 */

beforeEach(fn () => $this->account = liveStripe());

it('passo 3 mai completato: stessa sessione recuperata con checkout:{payment_id}', function () {
    $fx = liveTenant($this->account, 'INSTANT');
    $reservation = startCheckout($fx);
    $payment = latestPayment($fx, $reservation->id);
    $original = (string) $payment->checkout_session_id;

    // Simulazione del crash (solo test): l'ID della sessione non risulta salvato.
    inTenant($fx['tenant'], fn () => DB::table('payments')->where('id', $payment->id)->update(['checkout_session_id' => null]));

    $recovered = app(CheckoutService::class)->openSession($fx['tenant'], $payment->id);

    expect($recovered->id)->toBe($original)
        ->and(inTenant($fx['tenant'], fn () => Payment::query()->findOrFail($payment->id)->checkout_session_id))->toBe($original);
});
