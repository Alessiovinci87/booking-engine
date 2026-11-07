<?php

namespace App\Http\Controllers;

use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Payments\Gateway\Fake\FakePaymentGateway;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\SimulatedPaymentEvents;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Number;

/**
 * Pagina "Pagamento simulato" (3.3): sostituisce Stripe Checkout con PAYMENT_GATEWAY=fake, solo con
 * APP_ENV local o testing. I pulsanti cambiano lo stato della sessione finta e poi producono gli stessi
 * eventi che arriveranno dai webhook di Stripe, gestiti dallo stesso codice (SimulatedPaymentEvents).
 * La conferma non dipende mai dal redirect (I2): il ritorno porta solo alla pagina di attesa.
 */
final class SimulatedCheckoutController extends Controller
{
    public function show(string $session, PaymentGateway $gateway): View
    {
        $page = $this->fake($gateway)->checkoutPage($session) ?? abort(404);
        App::setLocale($page['locale']);

        return view('simulated-checkout', [
            'session' => $page,
            'amount' => Number::currency($page['amount_cents'] / 100, in: $page['currency'], locale: $page['locale']),
        ]);
    }

    public function act(string $session, string $action, PaymentGateway $paymentGateway, SimulatedPaymentEvents $events): RedirectResponse
    {
        $gateway = $this->fake($paymentGateway);
        $page = $gateway->checkoutPage($session) ?? abort(404);
        $customerAction = FakeCustomerAction::tryFrom($action) ?? abort(404);

        if ($customerAction === FakeCustomerAction::ABANDON) {
            return redirect()->away($page['cancel_url']);
        }

        $state = $gateway->simulateCustomer($session, $customerAction);
        $events->dispatch($gateway->accountOf($session), $state);

        return redirect()->away($page['success_url']);
    }

    private function fake(PaymentGateway $gateway): FakePaymentGateway
    {
        if (! $gateway instanceof FakePaymentGateway) {
            abort(404);
        }

        return $gateway;
    }
}
