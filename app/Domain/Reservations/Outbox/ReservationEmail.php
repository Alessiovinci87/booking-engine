<?php

namespace App\Domain\Reservations\Outbox;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email di una transizione della prenotazione (solo testo). I testi arrivano dalle chiavi di
 * traduzione nella lingua del destinatario; l'invio vero (Resend) arriva in M6, in locale il mailer
 * è "log".
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
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.reservation');
    }
}
