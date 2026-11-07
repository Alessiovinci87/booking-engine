<?php

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\IcsFeedStatus;
use App\Domain\Calendar\IcsFeedLifecycle;
use App\Domain\Calendar\Jobs\DispatchFeedRetirementJob;
use App\Domain\Calendar\Jobs\RetireRotatedFeedsJob;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Calendar\StateMachines\IcsFeedStateMachine;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * I19 (06): un feed RETIRED risponde 410 FEED_RETIRED; dopo una rotazione il vecchio feed resta FROZEN
 * per 7 giorni e solo dopo viene ritirato (dispatcher orario con app_admin_ops).
 */

it('feed ritirato → 410 FEED_RETIRED, senza calendario', function () {
    $fx = bookableTenant();
    reservationIn($fx, ReservationStatus::CONFIRMED);
    $feed = inTenant($fx['tenant'], fn () => app(IcsFeedLifecycle::class)->create(Unit::query()->findOrFail($fx['unit']), ChannelSource::BOOKING));
    inTenant($fx['tenant'], fn () => app(IcsFeedStateMachine::class)->retire($feed, 'manager_confirmed'));

    $response = $this->get("/ical/{$feed->token_encrypted}.ics");

    $response->assertStatus(410)->assertJsonPath('error.code', 'FEED_RETIRED');
    expect($response->getContent())->not->toContain('VCALENDAR');
});

it('rotazione: FROZEN per 7 giorni, poi RETIRED e 410', function () {
    $fx = bookableTenant();
    reservationIn($fx, ReservationStatus::CONFIRMED);
    $old = inTenant($fx['tenant'], fn () => app(IcsFeedLifecycle::class)->create(Unit::query()->findOrFail($fx['unit']), ChannelSource::BOOKING));
    inTenant($fx['tenant'], fn () => app(IcsFeedLifecycle::class)->rotate($old));

    $this->travel(6)->days();
    expect(inTenant($fx['tenant'], fn () => app(IcsFeedLifecycle::class)->retireExpiredRotations(now()->toImmutable())))->toBe(0);
    $this->get("/ical/{$old->token_encrypted}.ics")->assertOk();

    $this->travel(2)->days();
    (new RetireRotatedFeedsJob($fx['tenant']))->handle(app(TenantContext::class), app(IcsFeedLifecycle::class));

    expect(inTenant($fx['tenant'], fn () => IcsFeed::query()->findOrFail($old->id)->status))->toBe(IcsFeedStatus::RETIRED);
    $this->get("/ical/{$old->token_encrypted}.ics")->assertStatus(410);
});

it('RETIRED è definitivo', function () {
    $fx = bookableTenant();
    $feed = inTenant($fx['tenant'], fn () => app(IcsFeedLifecycle::class)->create(Unit::query()->findOrFail($fx['unit']), ChannelSource::OTHER));
    inTenant($fx['tenant'], fn () => app(IcsFeedStateMachine::class)->retire($feed, 'manager_confirmed'));

    expect(fn () => inTenant($fx['tenant'], fn () => app(IcsFeedStateMachine::class)->retire($feed, 'ancora')))
        ->toThrow(IllegalTransition::class)
        ->and(fn () => inTenant($fx['tenant'], fn () => app(IcsFeedStateMachine::class)->freeze($feed, 'offboarding')))
        ->toThrow(IllegalTransition::class);
});

it('il dispatcher (app_admin_ops) accoda il ritiro solo per i tenant con rotazioni scadute', function () {
    Queue::fake();
    $fx = committedTenantWithUnit();
    $db = DB::connection(committedConnection('pgsql_migrator', $fx['tenant']));

    try {
        foreach ([[now()->subDays(8), 'FROZEN'], [now()->subDays(2), 'FROZEN'], [null, 'ACTIVE']] as $i => [$rotated, $status]) {
            $token = IcsFeedLifecycle::newToken();
            $db->table('ics_feeds')->insert([
                'id' => uuid7(), 'tenant_id' => $fx['tenant'], 'unit_id' => $fx['unit'], 'destination' => ['BOOKING', 'AIRBNB', 'OTHER'][$i],
                'token_encrypted' => 'x', 'token_hash' => IcsFeedLifecycle::hashToken($token), 'status' => $status,
                'rotated_at' => $rotated, 'frozen_at' => $rotated, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        (new DispatchFeedRetirementJob)->handle(app('db'));

        Queue::assertPushed(RetireRotatedFeedsJob::class, fn (RetireRotatedFeedsJob $job) => $job->tenantId === $fx['tenant']);
    } finally {
        $db->table('ics_feeds')->where('tenant_id', $fx['tenant'])->delete();
        deleteCommittedTenant($fx['tenant']);
    }
});
