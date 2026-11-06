<?php

use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * La richiesta con contesto tenant è una sola transazione: se il controller fallisce dopo aver
 * scritto, nessuna scrittura parziale resta nel database (WithTenantContext).
 */

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'panel.fresh', 'mfa', 'tenant'])
        ->post('/api/v1/admin/_test/write-then-fail', function () {
            DB::table('properties')->where('slug', 'casa-prova')->update(['name' => 'Scrittura parziale']);

            throw BusinessRuleViolation::make(409, 'ILLEGAL_TRANSITION');
        });
});

it('annulla le scritture se la richiesta termina con un errore', function () {
    $owner = signedInOwner($this);
    $property = createPropertyRow($owner['tenant']);

    $this->postJson('/api/v1/admin/_test/write-then-fail')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ILLEGAL_TRANSITION');

    expect(inTenant($owner['tenant'], fn () => DB::table('properties')->where('id', $property)->value('name')))->toBe('Casa di prova');
});
