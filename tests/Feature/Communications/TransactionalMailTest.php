<?php

use App\Domain\Communications\MailPreviewBox;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Outbox\SendReservationEmail;
use App\Domain\Tenancy\Models\OutboxMessage;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * 6.1 — Email transazionali tramite outbox con mailer astratto (ADR-0014):
 * - driver "log": una riga di log senza dati personali; casella di anteprima /dev/mail solo con
 *   APP_ENV=local;
 * - adattatore Resend (API HTTP) attivabile da configurazione, provato con un client simulato.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->fx = bookableTenant('INSTANT');
    $this->reservation = reservationIn($this->fx, ReservationStatus::CONFIRMED);
    $this->previewDir = storage_path('framework/testing/mail-preview-'.substr(uuid7(), -8));
    config(['mail.preview.path' => $this->previewDir]);
    app()->forgetInstance(MailPreviewBox::class);
});
afterEach(function () {
    File::deleteDirectory($this->previewDir);
    Carbon::setTestNow();
});

function deliverOutbox(array $fx, string $type): OutboxMessage
{
    return inTenant($fx['tenant'], function () use ($type): OutboxMessage {
        $message = OutboxMessage::query()->where('type', $type)->sole();
        app(SendReservationEmail::class)->handle($message);

        return $message;
    });
}

function useMailer(string $name, array $overrides = []): void
{
    config(["mail.mailers.{$name}" => array_merge(config("mail.mailers.{$name}"), $overrides), 'mail.default' => $name]);
    app('mail.manager')->forgetMailers();
}

it('driver log: una riga di log con tipo e destinatario mascherato, nessun dato dell\'ospite', function () {
    useMailer('log');
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event;
    });

    deliverOutbox($this->fx, 'reservation.confirmed');

    $line = collect($logged)->firstWhere('message', 'mail.sent');
    expect($line)->not->toBeNull()
        ->and($line->context['category'])->toBe('reservation.confirmed')
        ->and($line->context['to'])->toBe(['a***@example.test']);

    $everything = json_encode(array_map(fn (MessageLogged $e) => [$e->message, $e->context], $logged));
    expect($everything)->not->toContain('ada@example.test')
        ->not->toContain('Lovelace')
        ->not->toContain($this->reservation->code);

    // In testing la casella di anteprima non è attiva.
    expect(File::exists($this->previewDir))->toBeFalse();
});

it('casella di anteprima: solo con APP_ENV=local, consultabile su /dev/mail', function () {
    useMailer('log');
    $this->get('/dev/mail')->assertNotFound();

    app()->detectEnvironment(fn () => 'local');
    deliverOutbox($this->fx, 'reservation.confirmed');
    deliverOutbox($this->fx, 'reservation.confirmed_manager');

    $messages = app(MailPreviewBox::class)->all();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]['category'])->toBe('reservation.confirmed_manager')
        ->and($messages[0]['to'])->toBe(['gestore@example.test'])
        ->and($messages[1]['to'])->toBe(['ada@example.test'])
        ->and($messages[1]['reply_to'])->toBe(['gestore@example.test'])
        ->and($messages[1]['text'])->toContain($this->reservation->code);

    $this->get('/dev/mail')->assertOk()->assertSee('Nuova prenotazione confermata')->assertSee('gestore@example.test');
    $this->get('/dev/mail/'.$messages[1]['id'])->assertOk()->assertSee($this->reservation->code);
    $this->get('/dev/mail/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();

    $this->withSession(['_token' => 'csrf-dev'])->post('/dev/mail/clear', ['_token' => 'csrf-dev'])->assertRedirect('/dev/mail');
    expect(app(MailPreviewBox::class)->all())->toBe([]);
});

it('Resend: POST /emails con chiave, Idempotency-Key dell\'outbox, tag del tipo, senza intestazioni interne', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.resend.test/emails' => Http::response(['id' => 're_123'])]);
    useMailer('resend', ['key' => 're_test_simulata', 'endpoint' => 'https://api.resend.test']);
    config(['mail.from' => ['address' => 'prenotazioni@example.test', 'name' => 'Booking']]);

    $message = deliverOutbox($this->fx, 'reservation.confirmed');

    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($message) {
        $body = $request->data();

        return $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer re_test_simulata')
            && $request->hasHeader('Idempotency-Key', 'outbox:'.$message->id)
            && $body['to'] === ['ada@example.test']
            && $body['reply_to'] === ['gestore@example.test']
            && str_contains($body['from'], 'prenotazioni@example.test')
            && str_contains($body['text'], $this->reservation->code)
            && $body['tags'] === [['name' => 'category', 'value' => 'reservation_confirmed']]
            && ! str_contains(json_encode($body), 'X-Booking');
    });
});

it('Resend: senza chiave non invia nulla e l\'outbox riprova', function () {
    Http::preventStrayRequests();
    Http::fake();
    useMailer('resend', ['key' => null]);

    expect(fn () => deliverOutbox($this->fx, 'reservation.confirmed'))->toThrow(TransportException::class, 'RESEND_API_KEY');
    Http::assertNothingSent();
});

it('Resend: un errore riporta solo stato e nome, mai il corpo della risposta', function (int $status) {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['statusCode' => $status, 'name' => 'validation_error', 'message' => 'Invalid `to` field: ada@example.test'], $status)]);
    useMailer('resend', ['key' => 're_test_simulata', 'endpoint' => 'https://api.resend.test']);

    try {
        deliverOutbox($this->fx, 'reservation.confirmed');
        $this->fail('Eccezione attesa');
    } catch (TransportException $e) {
        expect($e->getMessage())->toContain((string) $status)->toContain('validation_error')->not->toContain('ada@example.test');
    }
})->with([422, 500]);

it('ogni tipo dell\'outbox ha oggetto e testi in italiano e in inglese', function () {
    $it = require lang_path('it/emails.php');
    $en = require lang_path('en/emails.php');

    foreach (array_keys(config('outbox.handlers')) as $type) {
        $key = str_starts_with($type, 'reservation.') ? substr($type, strlen('reservation.')) : str_replace('.', '_', $type);

        expect($it)->toHaveKey($key)
            ->and($en)->toHaveKey($key)
            ->and($it[$key]['subject'] ?? null)->toBeString()
            ->and($en[$key]['subject'] ?? null)->toBeString()
            ->and(count($it[$key]['lines']))->toBe(count($en[$key]['lines']));
    }
});
