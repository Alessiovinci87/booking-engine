<?php

use App\Domain\Inventory\Models\Unit;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\QuoteLinks;
use App\Domain\Quotes\QuoteService;
use App\Domain\Reservations\LegalAcceptance;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Enums\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Supporto ai test dei preventivi (5.7): preventivo creato e inviato con QuoteService sull'unità del
 * tenant prenotabile (luglio 2027: 120 €/notte, pulizia 60 €), accettazione come la pagina ospite.
 */

/**
 * @param  array{stay?: string, discount?: int, adults?: int, draft?: bool, expires_at?: CarbonImmutable|null, max_attempts?: int}  $options
 */
function quoteFor(array $fx, array $options = []): Quote
{
    return inTenant($fx['tenant'], function () use ($fx, $options): Quote {
        $service = app(QuoteService::class);
        $quote = $service->create(
            Unit::query()->findOrFail($fx['unit']),
            StayRange::fromPostgres($options['stay'] ?? '[2027-07-10,2027-07-14)'),
            $options['adults'] ?? 2, 0, $options['discount'] ?? 4000,
            'Ada Lovelace', 'ada@example.test', Locale::EN, $options['expires_at'] ?? null,
        );

        if (isset($options['max_attempts'])) {
            Quote::query()->whereKey($quote->id)->update(['max_checkout_attempts' => $options['max_attempts']]);
        }

        if (! ($options['draft'] ?? false)) {
            $service->send($quote->id, 'link');
        }

        return Quote::query()->findOrFail($quote->id);
    });
}

function quoteToken(Quote|string $quote): string
{
    return QuoteLinks::token($quote instanceof Quote ? $quote->id : $quote);
}

function freshQuote(array $fx, string $id): Quote
{
    return inTenant($fx['tenant'], fn () => Quote::query()->findOrFail($id));
}

function acceptBody(array $overrides = []): array
{
    return array_replace_recursive([
        'guest' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'phone' => '+44 20 7946 0000', 'country_code' => 'GB'],
        'locale' => 'en',
        'accepted_terms_version' => LegalAcceptance::version('https://example.test/termini'),
        'accepted_privacy_version' => LegalAcceptance::version('https://example.test/privacy'),
    ], $overrides);
}

function acceptQuote(TestCase $test, string $token, array $overrides = [], ?string $key = null): TestResponse
{
    return $test->withHeaders(['Idempotency-Key' => $key ?? uuid7(), 'Accept-Language' => 'en'])
        ->postJson("/api/v1/public/quotes/{$token}/accept", acceptBody($overrides));
}

function quoteReservation(array $fx, string $code): Reservation
{
    return inTenant($fx['tenant'], fn () => Reservation::query()->where('code', $code)->firstOrFail());
}
