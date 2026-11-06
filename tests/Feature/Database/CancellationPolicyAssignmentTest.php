<?php

use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\EffectiveCancellationPolicy;
use App\Domain\Reservations\Enums\CancellationPolicyCode;
use App\Domain\Reservations\Models\CancellationPolicy;
use App\Domain\Tenancy\Enums\Locale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * ADR-0009 (2b): politica predefinita della struttura e politica facoltativa dell'unità, entrambe
 * della stessa struttura (FK composite). Politica effettiva: unità, altrimenti struttura.
 */

function createPolicyRow(string $tenantId, string $propertyId, string $code): string
{
    $id = uuid7();

    inTenant($tenantId, fn () => DB::table('cancellation_policies')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'property_id' => $propertyId, 'code' => $code,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    return $id;
}

beforeEach(function () {
    $this->t = twoTenantsWithUnits()['a'];
    $this->flexible = createPolicyRow($this->t['tenant'], $this->t['property'], 'FLEXIBLE_7D');
    $this->nonRefundable = createPolicyRow($this->t['tenant'], $this->t['property'], 'NON_REFUNDABLE');
});

function effectiveCode(array $t): ?CancellationPolicyCode
{
    return inTenant($t['tenant'], fn () => app(EffectiveCancellationPolicy::class)->for(Unit::query()->findOrFail($t['unit']))?->code);
}

it('usa la politica della struttura se l\'unità non ne ha una', function () {
    expect(effectiveCode($this->t))->toBeNull();

    inTenant($this->t['tenant'], fn () => DB::table('properties')->where('id', $this->t['property'])->update(['default_cancellation_policy_id' => $this->flexible]));

    expect(effectiveCode($this->t))->toBe(CancellationPolicyCode::FLEXIBLE_7D);
});

it('preferisce la politica dell\'unità', function () {
    inTenant($this->t['tenant'], function () {
        DB::table('properties')->where('id', $this->t['property'])->update(['default_cancellation_policy_id' => $this->flexible]);
        DB::table('units')->where('id', $this->t['unit'])->update(['cancellation_policy_id' => $this->nonRefundable]);
    });

    expect(effectiveCode($this->t))->toBe(CancellationPolicyCode::NON_REFUNDABLE);
});

it('rifiuta come predefinita una politica di un\'altra struttura dello stesso tenant', function () {
    $other = createPropertyRow($this->t['tenant'], 'altra-casa');
    $foreign = createPolicyRow($this->t['tenant'], $other, 'FLEXIBLE_7D');

    inTenant($this->t['tenant'], fn () => DB::table('properties')->where('id', $this->t['property'])->update(['default_cancellation_policy_id' => $foreign]));
})->throws(QueryException::class, 'properties_default_cancellation_policy_id_fk');

it('rifiuta per l\'unità una politica di un\'altra struttura dello stesso tenant', function () {
    $other = createPropertyRow($this->t['tenant'], 'altra-casa');
    $foreign = createPolicyRow($this->t['tenant'], $other, 'FLEXIBLE_7D');

    inTenant($this->t['tenant'], fn () => DB::table('units')->where('id', $this->t['unit'])->update(['cancellation_policy_id' => $foreign]));
})->throws(QueryException::class, 'units_cancellation_policy_id_fk');

it('ha testi standard IT/EN per ogni codice, non salvati nel database', function () {
    expect(DB::getSchemaBuilder()->getColumnListing('cancellation_policies'))->not->toContain('text_it', 'text_en');

    foreach (CancellationPolicyCode::cases() as $code) {
        $policy = (new CancellationPolicy)->forceFill(['code' => $code]);

        foreach (Locale::cases() as $locale) {
            expect($policy->title($locale))->not->toBe('')->not->toStartWith('cancellation.')
                ->and($policy->text($locale))->not->toBe('')->not->toStartWith('cancellation.');
        }
    }

    expect((new CancellationPolicy)->forceFill(['code' => CancellationPolicyCode::FLEXIBLE_7D])->text(Locale::IT))->toContain('7 giorni');
});
