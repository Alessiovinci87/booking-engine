<?php

use App\Http\Middleware\EnforceIdempotencyKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
 * I9 (07 "Convenzioni"): Idempotency-Key obbligatoria sulle POST che creano prenotazioni o cambiano
 * stato; 24 h per utente o IP; stessa chiave e stessa richiesta → stessa risposta; corpo diverso →
 * 422 IDEMPOTENCY_KEY_REUSED. Le risposte 5xx non si salvano: l'operazione è ritentabile.
 */

beforeEach(function () {
    $this->calls = 0;
    Route::middleware(['api', 'idempotent'])->post('/api/v1/_test/idempotent', function () {
        $this->calls++;

        return response()->json(['call' => $this->calls, 'body' => request()->all()], 201);
    });
    Route::middleware(['api', 'idempotent'])->post('/api/v1/_test/fails', function () {
        $this->calls++;

        return response()->json(['error' => 'boom'], 503);
    });
});

function idempotentPost(object $test, string $key, array $body, string $path = '/api/v1/_test/idempotent', string $ip = '203.0.113.7')
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('Idempotency-Key', $key)->postJson($path, $body);
}

it('richiede una Idempotency-Key UUID', function () {
    $this->postJson('/api/v1/_test/idempotent', ['a' => 1])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['fields' => [EnforceIdempotencyKey::HEADER]]]]);

    $this->withHeader('Idempotency-Key', 'non-un-uuid')->postJson('/api/v1/_test/idempotent', [])->assertStatus(400);
    expect($this->calls)->toBe(0);
});

it('restituisce la stessa risposta per la stessa chiave e lo stesso corpo, senza rieseguire', function () {
    $key = uuid7();

    $first = idempotentPost($this, $key, ['b' => 2, 'a' => 1])->assertStatus(201);
    $second = idempotentPost($this, $key, ['a' => 1, 'b' => 2])->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json())->and($this->calls)->toBe(1);
});

it('risponde 422 IDEMPOTENCY_KEY_REUSED con un corpo o un percorso diverso', function () {
    $key = uuid7();
    idempotentPost($this, $key, ['a' => 1])->assertStatus(201);

    idempotentPost($this, $key, ['a' => 2])->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    idempotentPost($this, $key, ['a' => 1], '/api/v1/_test/fails')->assertStatus(422);
    expect($this->calls)->toBe(1);
});

it('separa le chiavi per IP sulla superficie pubblica', function () {
    $key = uuid7();
    idempotentPost($this, $key, ['a' => 1], ip: '203.0.113.7')->assertJsonPath('call', 1);
    idempotentPost($this, $key, ['a' => 2], ip: '198.51.100.9')->assertStatus(201)->assertJsonPath('call', 2);
});

it('non salva le risposte 5xx: la stessa chiave si può ritentare', function () {
    $key = uuid7();
    idempotentPost($this, $key, [], '/api/v1/_test/fails')->assertStatus(503);
    idempotentPost($this, $key, [], '/api/v1/_test/fails')->assertStatus(503);

    expect($this->calls)->toBe(2);
});

it('conserva la chiave per 24 ore', function () {
    $key = uuid7();
    idempotentPost($this, $key, ['a' => 1]);

    $this->travel(23)->hours();
    idempotentPost($this, $key, ['a' => 1])->assertHeader('Idempotent-Replayed', 'true');

    $this->travel(2)->hours();
    idempotentPost($this, $key, ['a' => 1])->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');
    expect($this->calls)->toBe(2);
});

it('risponde 409 IDEMPOTENCY_KEY_IN_USE se la stessa chiave è ancora in lavorazione', function () {
    $key = uuid7();
    $lock = Cache::lock('idempotency:ip:203.0.113.7:'.$key.':lock', 60);
    $lock->get();

    config(['security.idempotency_wait_seconds' => 1]);

    try {
        idempotentPost($this, $key, ['a' => 1])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_IN_USE');
        expect($this->calls)->toBe(0);
    } finally {
        $lock->release();
    }
});
