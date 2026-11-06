<?php

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Dati di prova minimi inseriti come app_runtime, sempre dentro TenantContext (RLS forzata).
 * Valori inventati, nessun dato reale.
 */

function uuid7(): string
{
    return (string) Str::uuid7();
}

function inTenant(string $tenantId, callable $fn): mixed
{
    return app(TenantContext::class)->run($tenantId, $fn);
}

function createTenantRow(?string $slug = null, ?string $name = null): string
{
    $id = uuid7();
    $slug ??= 'tenant-'.substr(str_replace('-', '', $id), -10);

    inTenant($id, fn () => DB::table('tenants')->insert([
        'id' => $id,
        'name' => $name ?? 'Tenant di prova '.$slug,
        'slug' => $slug,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    return $id;
}

function createUserRow(?string $email = null): string
{
    $id = uuid7();

    DB::table('users')->insert([
        'id' => $id,
        'email' => $email ?? 'utente-'.substr($id, -8).'@example.test',
        'password_hash' => 'non-usato',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function createMemberRow(string $tenantId, string $userId, string $role = 'OWNER'): string
{
    $id = uuid7();

    inTenant($tenantId, fn () => DB::table('tenant_members')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'role' => $role,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    return $id;
}

function createPropertyRow(string $tenantId, string $slug = 'casa-prova'): string
{
    $id = uuid7();

    inTenant($tenantId, fn () => DB::table('properties')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'name' => 'Casa di prova', 'slug' => $slug,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    return $id;
}

function createUnitRow(string $tenantId, string $propertyId, string $name = 'Unità di prova'): string
{
    $id = uuid7();

    inTenant($tenantId, fn () => DB::table('units')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'property_id' => $propertyId, 'name' => $name,
        'base_occupancy' => 2, 'max_occupancy' => 4,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    return $id;
}

function createGuestRow(string $tenantId, array $overrides = []): string
{
    $id = uuid7();

    inTenant($tenantId, fn () => DB::table('guests')->insert(array_merge([
        'id' => $id, 'tenant_id' => $tenantId,
        'first_name' => 'Ospite', 'last_name' => 'Inventato', 'email' => 'ospite@example.test',
        'phone' => '+390000000000', 'country_code' => 'IT',
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides)));

    return $id;
}

/**
 * Riga di prenotazione per i test sul database (le transizioni vere arrivano con 3.4).
 */
function reservationRow(string $tenantId, string $unitId, string $guestId, array $overrides = []): array
{
    return array_merge([
        'id' => uuid7(), 'tenant_id' => $tenantId, 'unit_id' => $unitId, 'guest_id' => $guestId,
        'code' => strtoupper(Str::random(8)), 'channel' => 'MANUAL', 'adults' => 2, 'children' => 0,
        'status' => 'CONFIRMED', 'confirmation_kind' => 'ADMINISTRATIVE',
        'booking_mode_snapshot' => 'REQUEST', 'payment_mode_snapshot' => 'DEPOSIT_30',
        'cancellation_policy_snapshot' => '{}', 'contract_total_cents' => 10000,
        'deposit_due_cents' => 3000, 'balance_due_cents' => 7000,
        'stay' => '[2027-08-10,2027-08-17)',
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides);
}

/**
 * Due tenant completi (A e B) con struttura e unità, per i test di isolamento.
 *
 * @return array{a: array{tenant: string, property: string, unit: string}, b: array{tenant: string, property: string, unit: string}}
 */
function twoTenantsWithUnits(): array
{
    $result = [];

    foreach (['a', 'b'] as $key) {
        $tenant = createTenantRow('tenant-'.$key.'-'.substr(uuid7(), -6));
        $property = createPropertyRow($tenant);
        $result[$key] = ['tenant' => $tenant, 'property' => $property, 'unit' => createUnitRow($tenant, $property)];
    }

    return $result;
}

function createRatePlanRow(string $tenantId, string $unitId, int $baseNightlyCents = 12000): string
{
    $id = uuid7();

    inTenant($tenantId, fn () => DB::table('rate_plans')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'unit_id' => $unitId, 'name' => 'Tariffa base',
        'base_nightly_cents' => $baseNightlyCents, 'currency' => 'EUR', 'active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    return $id;
}

/**
 * Unità con i valori del test golden di 04: base 4, max 6, pulizie 60 €, extra 15 €/notte, minimo 3
 * notti, base 120 €; Agosto 180 € (priorità 10, minimo 5), Ferragosto 13–17/08 220 € (priorità 20).
 *
 * @return array{property: string, unit: string, plan: string, seasons: array{agosto: string, ferragosto: string}}
 */
function goldenUnitRows(string $tenantId, ?string $propertyId = null): array
{
    $propertyId ??= createPropertyRow($tenantId, 'casa-golden-'.substr(uuid7(), -6));
    $unitId = uuid7();

    inTenant($tenantId, fn () => DB::table('units')->insert([
        'id' => $unitId, 'tenant_id' => $tenantId, 'property_id' => $propertyId, 'name' => 'Unità golden',
        'base_occupancy' => 4, 'max_occupancy' => 6, 'min_nights' => 3,
        'cleaning_fee_cents' => 6000, 'extra_guest_fee_cents' => 1500, 'payment_mode' => 'DEPOSIT_30',
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $plan = createRatePlanRow($tenantId, $unitId, 12000);
    $seasons = [];

    foreach ([
        'agosto' => ['Agosto', '[2027-08-01,2027-09-01)', 18000, 10, 5],
        'ferragosto' => ['Ferragosto', '[2027-08-13,2027-08-18)', 22000, 20, null],
    ] as $key => [$name, $range, $cents, $priority, $min]) {
        $seasons[$key] = uuid7();
        inTenant($tenantId, fn () => DB::table('season_rules')->insert([
            'id' => $seasons[$key], 'tenant_id' => $tenantId, 'rate_plan_id' => $plan, 'name' => $name,
            'date_range' => $range, 'nightly_cents' => $cents, 'priority' => $priority, 'min_nights' => $min,
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    return ['property' => $propertyId, 'unit' => $unitId, 'plan' => $plan, 'seasons' => $seasons];
}
