<?php

namespace App\Domain\Reservations\Outbox;

use App\Domain\Communications\TransactionalMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Email transazionale (solo testo). I testi arrivano dalle chiavi di traduzione nella lingua del
 * destinatario; l'invio passa da TransactionalMailer (6.1). Tipo e chiave di idempotenza sono
 * intestazioni interne, tolte dai trasporti prima della consegna.
 */
final class ReservationEmail extends Mailable
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $greeting,
        public readonly array $lines,
        public readonly ?string $link,
        public readonly string $signature,
        public readonly ?string $category = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->replyToAddress === null || $this->replyToAddress === '' ? [] : [new Address($this->replyToAddress)],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: array_filter([
            TransactionalMailer::CATEGORY_HEADER => $this->category,
            TransactionalMailer::IDEMPOTENCY_HEADER => $this->idempotencyKey,
        ], fn (?string $value): bool => $value !== null && $value !== ''));
    }

    public function content(): Content
    {
        return new Content(text: 'emails.reservation');
    }
}
