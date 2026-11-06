<?php

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Models\AuditLog;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantLifecycleService;
use Illuminate\Support\Facades\DB;

/*
 * 2.5: sospensione delle vendite (PATCH /admin/units/{id}/sales), stato dell'unità tramite UnitService,
 * stato del tenant tramite TenantLifecycleService.
 */

beforeEach(function () {
    $this->tenants = twoTenantsWithUnits();
    $this->a = $this->tenants['a'];
    $this->owner = signedInOwner($this, $this->a['tenant']);
});

it('sospende e riattiva le vendite con If-Match', function () {
    $url = "/api/v1/admin/units/{$this->a['unit']}/sales";

    panelPatch($this, $url, ['sales_suspended' => true], 1)
        ->assertOk()
        ->assertJsonPath('sales_suspended', true)
        ->assertJsonPath('version', 2);

    panelPatch($this, $url, ['sales_suspended' => false], 1)->assertStatus(412)->assertJsonPath('error.code', 'VERSION_MISMATCH');

    panelPatch($this, $url, ['sales_suspended' => false], 2)
        ->assertOk()
        ->assertJsonPath('sales_suspended', false);

    $actions = inTenant($this->a['tenant'], fn () => AuditLog::query()->where('subject_id', $this->a['unit'])->orderBy('id')->pluck('action')->all());
    expect($actions)->toBe(['unit.sales_suspended', 'unit.sales_resumed']);
});

it('valida il corpo e isola i tenant', function () {
    panelPatch($this, "/api/v1/admin/units/{$this->a['unit']}/sales", ['sales_suspended' => 'forse'], 1)
        ->assertStatus(400)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['sales_suspended']]]]);

    panelPatch($this, "/api/v1/admin/units/{$this->tenants['b']['unit']}/sales", ['sales_suspended' => true], 1)
        ->assertNotFound();
});

it('disattiva e riattiva l\'unità tramite UnitService', function () {
    panelPatch($this, "/api/v1/admin/units/{$this->a['unit']}", ['status' => 'INACTIVE'], 1)
        ->assertOk()
        ->assertJsonPath('status', 'INACTIVE');

    panelPatch($this, "/api/v1/admin/units/{$this->a['unit']}", ['status' => 'ARCHIVED'], 2)
        ->assertStatus(400)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);
});

it('cambia lo stato del tenant solo con transizioni ammesse', function () {
    $service = app(TenantLifecycleService::class);

    $tenant = inTenant($this->a['tenant'], fn () => $service->suspend(Tenant::query()->findOrFail($this->a['tenant'])));
    expect($tenant->status)->toBe(TenantStatus::SUSPENDED)
        ->and(inTenant($this->a['tenant'], fn () => DB::table('tenants')->where('id', $this->a['tenant'])->value('status')))->toBe('SUSPENDED');

    expect(fn () => inTenant($this->a['tenant'], fn () => $service->suspend(Tenant::query()->findOrFail($this->a['tenant']))))
        ->toThrow(fn (BusinessRuleViolation $e) => expect($e->errorCode)->toBe('ILLEGAL_TRANSITION'));

    $tenant = inTenant($this->a['tenant'], fn () => $service->reactivate(Tenant::query()->findOrFail($this->a['tenant'])));
    expect($tenant->status)->toBe(TenantStatus::ACTIVE);
});
