<?php

use App\Domain\Tenancy\Enums\OutboxStatus;
use App\Domain\Tenancy\Exceptions\IllegalStatusWrite;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\Jobs\DeliverOutboxMessageJob;
use App\Domain\Tenancy\Jobs\DispatchOutboxJob;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\Outbox\AdminOpsPendingOutboxMessages;
use App\Domain\Tenancy\Outbox\Outbox;
use App\Domain\Tenancy\Outbox\OutboxHandler;
use App\Domain\Tenancy\Outbox\PendingOutboxMessages;
use App\Domain\Tenancy\StateMachines\OutboxMessageStateMachine;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * 1.6: OutboxMessage con dedupe_key unico per tenant, dispatcher cross-tenant e retry con backoff.
 */

final class RecordingOutboxHandler implements OutboxHandler
{
    /** @var list<string> */
    public array $delivered = [];

    public bool $fail = false;

    public bool $breakTransaction = false;

    public function handle(OutboxMessage $message): void
    {
        if ($this->breakTransaction) {
            DB::statement('select * from tabella_inesistente');
        }

        if ($this->fail) {
            throw new RuntimeException('Errore del provider con dati: ospite@example.test');
        }

        $this->delivered[] = $message->id;
    }
}

beforeEach(function () {
    $this->handler = new RecordingOutboxHandler;
    app()->instance(RecordingOutboxHandler::class, $this->handler);
    config(['outbox.handlers' => ['test.message' => RecordingOutboxHandler::class]]);
    $this->tenant = createTenantRow();
});

afterEach(fn () => $this->travelBack());

function enqueueTest(string $tenant, string $key = 'chiave-1', string $type = 'test.message'): OutboxMessage
{
    return inTenant($tenant, fn () => app(Outbox::class)->enqueue($type, $key, ['n' => 1]));
}

function deliver(string $tenant, OutboxMessage $message): OutboxMessage
{
    DeliverOutboxMessageJob::dispatchSync($tenant, $message->id);

    return inTenant($tenant, fn () => OutboxMessage::query()->findOrFail($message->id));
}

it('accoda un solo messaggio per dedupe_key senza interrompere la transazione', function () {
    [$first, $second, $count] = inTenant($this->tenant, function () {
        $outbox = app(Outbox::class);
        $first = $outbox->enqueue('test.message', 'conferma:123', ['a' => 1]);
        $second = $outbox->enqueue('test.message', 'conferma:123', ['a' => 2]);

        return [$first, $second, DB::table('outbox_messages')->count()];
    });

    expect($second->id)->toBe($first->id)
        ->and($count)->toBe(1)
        ->and($first->status)->toBe(OutboxStatus::PENDING)
        ->and($second->payload)->toBe(['a' => 1]);
});

it('permette la stessa dedupe_key in tenant diversi', function () {
    $other = createTenantRow();

    expect(enqueueTest($this->tenant)->id)->not->toBe(enqueueTest($other)->id);
});

it('richiede il contesto tenant per accodare', function () {
    app(Outbox::class)->enqueue('test.message', 'x');
})->throws(MissingTenantContext::class);

it('consegna il messaggio e lo segna SENT', function () {
    $message = deliver($this->tenant, enqueueTest($this->tenant));

    expect($message->status)->toBe(OutboxStatus::SENT)
        ->and($message->attempts)->toBe(1)
        ->and($message->sent_at)->not->toBeNull()
        ->and($this->handler->delivered)->toBe([$message->id]);

    // Un secondo job per lo stesso messaggio non lo invia di nuovo.
    deliver($this->tenant, $message);
    expect($this->handler->delivered)->toHaveCount(1);
});

it('ripete con backoff dopo un errore e registra solo la classe dell\'eccezione', function () {
    $this->handler->fail = true;
    $this->freezeTime();

    $message = deliver($this->tenant, enqueueTest($this->tenant));

    expect($message->status)->toBe(OutboxStatus::PENDING)
        ->and($message->attempts)->toBe(1)
        ->and($message->available_at->getTimestamp())->toBe(now()->addSeconds(60)->getTimestamp())
        ->and($message->last_error)->toBe(RuntimeException::class);

    // Prima della scadenza del backoff il job non consegna.
    $this->travel(30)->seconds();
    expect(deliver($this->tenant, $message)->attempts)->toBe(1);

    $this->travel(31)->seconds();
    $message = deliver($this->tenant, $message);
    expect($message->attempts)->toBe(2)
        ->and($message->available_at->getTimestamp())->toBe(now()->addSeconds(300)->getTimestamp());
});

it('segna FAILED dopo l\'ultimo tentativo', function () {
    $this->handler->fail = true;
    config(['outbox.max_attempts' => 3]);
    $message = enqueueTest($this->tenant);

    foreach (range(1, 3) as $i) {
        $message = deliver($this->tenant, $message);
        $this->travel(2)->hours();
    }

    expect($message->status)->toBe(OutboxStatus::FAILED)
        ->and($message->attempts)->toBe(3)
        ->and($message->failed_at)->not->toBeNull();

    // Reinvio manuale tramite la macchina a stati.
    $this->handler->fail = false;
    inTenant($this->tenant, fn () => app(OutboxMessageStateMachine::class)->retry($message));
    expect(deliver($this->tenant, $message)->status)->toBe(OutboxStatus::SENT);
});

it('registra il tentativo anche se il gestore rompe la transazione', function () {
    $this->handler->breakTransaction = true;

    $message = deliver($this->tenant, enqueueTest($this->tenant));

    expect($message->status)->toBe(OutboxStatus::PENDING)
        ->and($message->attempts)->toBe(1);
});

it('tratta un tipo senza gestore come errore da ripetere', function () {
    $message = deliver($this->tenant, enqueueTest($this->tenant, type: 'tipo.sconosciuto'));

    expect($message->attempts)->toBe(1)
        ->and($message->status)->toBe(OutboxStatus::PENDING);
});

it('calcola il backoff crescente e mantiene l\'ultimo passo', function () {
    expect(OutboxMessageStateMachine::backoffSeconds(1))->toBe(60)
        ->and(OutboxMessageStateMachine::backoffSeconds(2))->toBe(300)
        ->and(OutboxMessageStateMachine::backoffSeconds(7))->toBe(14400)
        ->and(OutboxMessageStateMachine::backoffSeconds(20))->toBe(14400);
});

it('vieta di scrivere lo stato fuori dalla macchina a stati', function () {
    $message = enqueueTest($this->tenant);

    inTenant($this->tenant, function () use ($message) {
        $message->status = OutboxStatus::SENT;
    });
})->throws(IllegalStatusWrite::class);

it('il dispatcher accoda un job per ogni messaggio pronto, con il suo tenant', function () {
    Queue::fake();
    $other = createTenantRow();
    $rows = [['tenant_id' => $this->tenant, 'id' => uuid7()], ['tenant_id' => $other, 'id' => uuid7()]];

    app()->instance(PendingOutboxMessages::class, new class($rows) implements PendingOutboxMessages
    {
        public function __construct(private readonly array $rows) {}

        public function due(int $limit): array
        {
            return $this->rows;
        }
    });

    (new DispatchOutboxJob)->handle(app(PendingOutboxMessages::class));

    Queue::assertPushed(DeliverOutboxMessageJob::class, 2);
    Queue::assertPushed(DeliverOutboxMessageJob::class, fn ($job) => $job->tenantId() === $other && $job->messageId === $rows[1]['id']);
});

it('il dispatcher legge con app_admin_ops', function () {
    // Le righe dei test sono nella transazione di RefreshDatabase, invisibile ad altre connessioni:
    // si verifica che la query giri con il ruolo giusto.
    expect(app(AdminOpsPendingOutboxMessages::class)->due(10))->toBeArray()
        ->and(DB::connection(config('outbox.dispatcher_connection'))->selectOne('select current_user as u')->u)->toBe('app_admin_ops')
        ->and(app(PendingOutboxMessages::class))->toBeInstanceOf(AdminOpsPendingOutboxMessages::class);
});

it('pianifica il dispatcher ogni minuto', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => $e->description === 'outbox:dispatch');

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});
