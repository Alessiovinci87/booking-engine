<?php

/*
 * Processo figlio: checkout dell'ospite (T1 e sessione sul gateway finto) sulle stesse notti tramite
 * CheckoutService, come POST /public/{t}/{p}/reservations.
 * Argomenti: start, tenant, unità, check-in, check-out.
 */

use App\Domain\Inventory\Models\Unit;
use App\Domain\Payments\CheckoutService;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Pricing\PriceQuoteService;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\GuestDetails;
use App\Domain\Reservations\NewReservation;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;

require __DIR__.'/bootstrap.php';

[, , $tenant, $unit, $checkIn, $checkOut] = $argv;

waitForStart($argv);

try {
    $data = app(TenantContext::class)->run($tenant, function () use ($unit, $checkIn, $checkOut): NewReservation {
        $model = Unit::query()->findOrFail($unit);
        $stay = StayRange::of($checkIn, $checkOut);
        $price = app(PriceQuoteService::class)->quote($model, new PriceRequest($stay, 2));

        return new NewReservation($model, $stay, 2, 0, new GuestDetails('Ospite', 'Parallelo', 'ospite@example.test', '+39 000', 'IT', Locale::IT), ReservationChannel::DIRECT_WEB, $price);
    });

    $result = app(CheckoutService::class)->start($tenant, $data);

    report(['ok' => true, 'id' => $result['created']->reservation->id]);
} catch (BusinessRuleViolation $e) {
    report(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    report(['ok' => false, 'code' => 'EXCEPTION', 'message' => $e::class.': '.mb_substr($e->getMessage(), 0, 300)]);
}
