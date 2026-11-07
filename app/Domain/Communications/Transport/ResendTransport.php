<?php

namespace App\Domain\Communications\Transport;

use App\Domain\Communications\TransactionalMailer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Adattatore Resend (6.1, ADR-0014): API HTTP `POST /emails` con il client HTTP di Laravel, senza la
 * libreria resend-php (ADR-0006: nessuna nuova dipendenza). Si attiva con MAIL_MAILER=resend e
 * RESEND_API_KEY; senza chiave non parte. Nei test il client è simulato (Http::fake).
 *
 * - `Idempotency-Key` = chiave dell'outbox: un nuovo tentativo dopo un timeout non produce un doppio
 *   invio (Resend la conserva 24 ore).
 * - Le intestazioni interne (tipo, chiave) non arrivano al destinatario; il tipo diventa un tag
 *   Resend senza dati personali.
 * - Gli errori riportano solo lo stato HTTP e il nome dell'errore, mai il corpo (potrebbe contenere
 *   l'indirizzo): l'outbox riprova con il suo backoff.
 */
final class ResendTransport extends AbstractTransport
{
    private readonly HttpFactory $http;

    public function __construct(
        private readonly ?string $key,
        private readonly string $endpoint = 'https://api.resend.com',
        private readonly int $timeoutSeconds = 10,
        ?HttpFactory $http = null,
    ) {
        parent::__construct();
        // Il client HTTP si risolve qui: è l'unica classe delle email autorizzata a usarlo (Arch/ExternalHttpTest).
        $this->http = $http ?? app(HttpFactory::class);
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->key === null || $this->key === '') {
            throw new TransportException('Resend: RESEND_API_KEY mancante.');
        }

        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException('Messaggio non supportato.');
        }

        $email = MessageConverter::toEmail($original);
        $headers = $email->getHeaders();
        $category = $headers->get(TransactionalMailer::CATEGORY_HEADER)?->getBodyAsString();
        $idempotencyKey = $headers->get(TransactionalMailer::IDEMPOTENCY_HEADER)?->getBodyAsString();

        $payload = array_filter([
            'from' => $this->addresses($email->getFrom())[0] ?? null,
            'to' => $this->addresses($email->getTo()),
            'reply_to' => $this->addresses($email->getReplyTo()),
            'subject' => (string) $email->getSubject(),
            'text' => (string) $email->getTextBody(),
            'html' => $email->getHtmlBody() === null ? null : (string) $email->getHtmlBody(),
            'tags' => $category === null ? [] : [['name' => 'category', 'value' => (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $category)]],
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        $request = $this->http->baseUrl($this->endpoint)
            ->withToken($this->key)
            ->acceptJson()
            ->timeout($this->timeoutSeconds);

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            // Resend accetta al massimo 256 caratteri: la chiave dell'outbox resta riconoscibile.
            $request = $request->withHeaders(['Idempotency-Key' => mb_substr($idempotencyKey, 0, 256)]);
        }

        try {
            $response = $request->post('/emails', $payload);
        } catch (ConnectionException) {
            throw new TransportException('Resend: connessione non riuscita.');
        }

        if ($response->failed()) {
            $name = $response->json('name');

            throw new TransportException(sprintf('Resend: risposta %d (%s).', $response->status(), is_string($name) ? $name : 'errore'), $response->status());
        }

        $id = $response->json('id');

        if (is_string($id) && $id !== '') {
            $message->setMessageId($id);
        }
    }

    /**
     * @param  array<Address>  $addresses
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address): string => $address->toString(), $addresses));
    }

    public function __toString(): string
    {
        return 'resend';
    }
}
