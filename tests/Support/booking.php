<?php

use App\Domain\Inventory\Enums\OccupancyStatus;
use App\Domain\Inventory\Models\InventoryOccupancy;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Payments\Gateway\Fake\FakeFailure;
use App\Domain\Payments\Gateway\Fake\FakeOperation;
use App\Domain\Payments\Gateway\Fake\FakePaymentGateway;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\SimulatedPaymentEvents;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Pricing\PriceQuoteService;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\Enums\ReservationEvent;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\GuestDetails;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\NewReservation;
use App\Domain\Reservations\StateMachines\ReservationStateMachine;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Enums\Locale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Supporto ai test delle prenotazioni (3.4–3.6): tenant prenotabile con l'unità del test golden
 * (luglio 2027: 120 €/notte, minimo 3 notti), account Stripe finto e politica FLEXIBLE_7D; funzioni
 * che portano una prenotazione in ogni stato usando i flussi veri (checkout, gateway finto, macchina).
 */

/**
 * @return array{tenant: string, slug: string, property: string, property_slug: string, unit: string, account: string}
 */
function bookableTenant(string $bookingMode = 'INSTANT', array $unitOverrides = []): array
{
    $slug = 'tenant-bk-'.substr(uuid7(), -8);
    $tenant = createTenantRow($slug);
    $golden = goldenUnitRows($tenant);
    $account = 'acct_fake_'.substr(uuid7(), -10);

    $propertySlug = inTenant($tenant, function () use ($tenant, $golden, $bookingMode, $unitOverrides, $account): string {
        $policy = uuid7();
        DB::table('cancellation_policies')->insert([
            'id' => $policy, 'tenant_id' => $tenant, 'property_id' => $golden['property'], 'code' => 'FLEXIBLE_7D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('properties')->where('id', $golden['property'])->update([
            'status' => 'PUBLISHED', 'default_cancellation_policy_id' => $policy, 'timezone' => 'Europe/Rome',
            'privacy_policy_url' => 'https://example.test/privacy', 'terms_url' => 'https://example.test/termini',
            'contact_email' => 'gestore@example.test',
        ]);
        DB::table('units')->where('id', $golden['unit'])->update(array_merge(['booking_mode' => $bookingMode], $unitOverrides));
        DB::table('stripe_accounts')->insert([
            'id' => uuid7(), 'tenant_id' => $tenant, 'stripe_account_id' => $account, 'charges_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (string) DB::table('properties')->where('id', $golden['property'])->value('slug');
    });

    return ['tenant' => $tenant, 'slug' => $slug, 'property' => $golden['property'], 'property_slug' => $propertySlug, 'unit' => $golden['unit'], 'account' => $account];
}

function guestDetails(): GuestDetails
{
    return new GuestDetails('Ada', 'Lovelace', 'ada@example.test', '+39 333 0000000', 'IT', Locale::EN, null);
}

function newReservationData(array $fx, string $stay = '[2027-07-10,2027-07-14)', int $adults = 2, int $children = 0, ReservationChannel $channel = ReservationChannel::DIRECT_WEB, int $discount = 0): NewReservation
{
    $unit = Unit::query()->findOrFail($fx['unit']);
    $range = StayRange::fromPostgres($stay);
    $price = app(PriceQuoteService::class)->quote($unit, new PriceRequest($range, $adults, $children, $discount, $channel !== ReservationChannel::MANUAL));

    return new NewReservation($unit, $range, $adults, $children, guestDetails(), $channel, $price);
}

function fakeGateway(): FakePaymentGateway
{
    $gateway = app(PaymentGateway::class);
    assert($gateway instanceof FakePaymentGateway);

    return $gateway;
}

function machine(): ReservationStateMachine
{
    return app(ReservationStateMachine::class);
}

/** T1 con i tre passi di 05; restituisce la prenotazione. */
function startCheckout(array $fx, string $stay = '[2027-07-10,2027-07-14)'): Reservation
{
    $result = app(CheckoutService::class)->start($fx['tenant'], inTenant($fx['tenant'], fn () => newReservationData($fx, $stay)));

    return reloadReservation($fx, $result['created']->reservation->id);
}

function reloadReservation(array $fx, string $id): Reservation
{
    return inTenant($fx['tenant'], fn () => Reservation::query()->findOrFail($id));
}

function latestPayment(array $fx, string $reservationId): Payment
{
    return inTenant($fx['tenant'], fn () => Payment::query()->where('reservation_id', $reservationId)->orderByDesc('created_at')->orderByDesc('id')->firstOrFail());
}

/** L'ospite agisce sulla pagina "Pagamento simulato": stesso percorso del webhook. */
function simulateCustomer(array $fx, Reservation $reservation, FakeCustomerAction $action): Reservation
{
    $payment = latestPayment($fx, $reservation->id);
    $state = fakeGateway()->simulateCustomer((string) $payment->checkout_session_id, $action);
    app(SimulatedPaymentEvents::class)->dispatch($fx['account'], $state);

    return reloadReservation($fx, $reservation->id);
}

/**
 * Porta una nuova prenotazione nello stato richiesto con i flussi veri.
 */
function reservationIn(array $fx, ReservationStatus $status, string $stay = '[2027-07-10,2027-07-14)'): Reservation
{
    $inTenant = fn (callable $fn) => inTenant($fx['tenant'], $fn);

    return match ($status) {
        ReservationStatus::AWAITING_PAYMENT => startCheckout($fx, $stay),
        ReservationStatus::CONFIRMED => simulateCustomer($fx, startCheckout($fx, $stay), FakeCustomerAction::PAY),
        ReservationStatus::AWAITING_APPROVAL => simulateCustomer($fx, startCheckout($fx, $stay), FakeCustomerAction::PAY),
        ReservationStatus::PAYMENT_FAILED => simulateCustomer($fx, startCheckout($fx, $stay), FakeCustomerAction::DECLINE),
        ReservationStatus::EXPIRED => (function () use ($fx, $stay) {
            $r = startCheckout($fx, $stay);
            Carbon::setTestNow(now()->addMinutes(40));
            app(CheckoutService::class)->releaseExpired($fx['tenant'], $r->id);

            return reloadReservation($fx, $r->id);
        })(),
        ReservationStatus::CAPTURING => (function () use ($fx, $stay, $inTenant) {
            $r = reservationIn($fx, ReservationStatus::AWAITING_APPROVAL, $stay);
            fakeGateway()->failNext(FakeOperation::CAPTURE, FakeFailure::TIMEOUT);
            $inTenant(fn () => machine()->approve($r));

            return reloadReservation($fx, $r->id);
        })(),
        ReservationStatus::CAPTURE_FAILED => (function () use ($fx, $stay, $inTenant) {
            $r = reservationIn($fx, ReservationStatus::AWAITING_APPROVAL, $stay);
            fakeGateway()->failNext(FakeOperation::CAPTURE, FakeFailure::DECLINED);
            $inTenant(fn () => machine()->approve($r));

            return reloadReservation($fx, $r->id);
        })(),
        ReservationStatus::REJECTED => (function () use ($fx, $stay, $inTenant) {
            $r = reservationIn($fx, ReservationStatus::AWAITING_APPROVAL, $stay);
            $inTenant(fn () => machine()->reject($r));

            return reloadReservation($fx, $r->id);
        })(),
        ReservationStatus::CANCELLED => (function () use ($fx, $stay, $inTenant) {
            $r = reservationIn($fx, ReservationStatus::CONFIRMED, $stay);
            $inTenant(fn () => machine()->cancel($r, CancellationRefund::for($r, null), null, null));

            return reloadReservation($fx, $r->id);
        })(),
    };
}

/**
 * I15: ogni prenotazione in stato occupante ha esattamente un'occupazione ACTIVE; ogni occupazione
 * RESERVATION attiva appartiene a una prenotazione in stato occupante.
 *
 * @return list<string> violazioni (vuoto se coerente)
 */
function occupancyViolations(string $tenant): array
{
    return inTenant($tenant, function (): array {
        $violations = [];

        foreach (Reservation::query()->get() as $reservation) {
            $active = InventoryOccupancy::query()->where('reservation_id', $reservation->id)->where('status', OccupancyStatus::ACTIVE->value)->count();
            $expected = $reservation->status->occupies() ? 1 : 0;

            if ($active !== $expected) {
                $violations[] = "{$reservation->code} {$reservation->status->value}: {$active} occupazioni attive";
            }
        }

        $orphans = InventoryOccupancy::query()->where('kind', 'RESERVATION')->where('status', OccupancyStatus::ACTIVE->value)
            ->whereNotIn('reservation_id', Reservation::query()->select('id')->whereIn('status', array_map(
                fn (ReservationStatus $s): string => $s->value,
                array_values(array_filter(ReservationStatus::cases(), fn (ReservationStatus $s): bool => $s->occupies())),
            )))->count();

        if ($orphans > 0) {
            $violations[] = "{$orphans} occupazioni RESERVATION attive senza prenotazione occupante";
        }

        return $violations;
    });
}

/** Invoca il metodo della macchina corrispondente all'evento, con argomenti plausibili. */
function fireEvent(array $fx, Reservation $r, ReservationEvent $event): mixed
{
    $payment = Payment::query()->where('reservation_id', $r->id)->orderByDesc('created_at')->first()
        // Prenotazione manuale senza pagamento: è sempre CONFIRMED, dove gli eventi di pagamento sono
        // vietati dalla tabella prima di toccare il pagamento.
        ?? new Payment;
    $m = machine();

    return match ($event) {
        ReservationEvent::PAYMENT_SUCCEEDED => $m->paymentSucceeded($r, $payment, 'pi_x_'.substr(uuid7(), -12), 100),
        ReservationEvent::AUTHORIZATION_SUCCEEDED => $m->authorizationSucceeded($r, $payment, 'pi_x_'.substr(uuid7(), -12), null),
        ReservationEvent::CHECKOUT_EXPIRED => $m->checkoutExpired($r, $payment),
        ReservationEvent::LATE_PAYMENT => $m->latePayment($r, $payment, 'pi_x_'.substr(uuid7(), -12), false, 100, null),
        ReservationEvent::PAYMENT_FAILED => $m->paymentFailed($r, $payment, 'card_declined'),
        ReservationEvent::APPROVE => $m->approve($r),
        ReservationEvent::REJECT => $m->reject($r),
        ReservationEvent::APPROVAL_EXPIRED => $m->approvalExpired($r),
        ReservationEvent::CAPTURE_SUCCEEDED => $m->captureSucceeded($r, $payment, 100),
        ReservationEvent::CAPTURE_FAILED => $m->captureFailed($r, $payment, 'card_declined'),
        ReservationEvent::CAPTURE_UNKNOWN => $m->captureUnknown($r),
        ReservationEvent::SEND_RECOVERY_LINK => $m->sendRecoveryLink($r, 24, $fx['account']),
        ReservationEvent::RELEASE_DATES => $m->releaseDates($r),
        ReservationEvent::CANCEL => $m->cancel($r, CancellationRefund::for($r, 0), null, null),
        ReservationEvent::MODIFY => $m->modify($r, $r->stay, $r->adults, $r->children,
            app(PriceQuoteService::class)->quote($r->unit, new PriceRequest($r->stay, $r->adults, $r->children, 0, false)), null, null),
        default => throw new LogicException('Evento di creazione.'),
    };
}
