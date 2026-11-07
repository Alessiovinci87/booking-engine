<?php

namespace App\Domain\Communications;

use App\Domain\Reservations\Outbox\ReservationEmail;
use Illuminate\Contracts\Mail\Factory as MailFactory;

/**
 * Mailer astratto delle email transazionali (6.1, ADR-0006 "Mailer tramite outbox", ADR-0014). I
 * gestori dell'outbox costruiscono una TransactionalEmail e la consegnano qui; il trasporto dipende
 * da MAIL_MAILER:
 *
 * - `log` (locale, sviluppo): PreviewLogTransport, una riga di log senza dati personali e, solo con
 *   APP_ENV=local, la casella di anteprima consultabile su /dev/mail;
 * - `resend` (staging e produzione): ResendTransport, API HTTP di Resend con chiave da RESEND_API_KEY
 *   e Idempotency-Key = chiave dell'outbox;
 * - `array` (test): nessun invio.
 *
 * Un errore del trasporto risale all'outbox, che riprova con il suo backoff.
 */
final class TransactionalMailer
{
    public const CATEGORY_HEADER = 'X-Booking-Category';

    public const IDEMPOTENCY_HEADER = 'X-Booking-Idempotency-Key';

    public function __construct(private readonly MailFactory $mail) {}

    public function send(TransactionalEmail $email): void
    {
        $this->mail->mailer()->to($email->to)->locale($email->locale)->send(new ReservationEmail(
            subjectLine: $email->subject,
            greeting: $email->greeting,
            lines: $email->lines,
            link: $email->link,
            signature: $email->signature,
            category: $email->category,
            idempotencyKey: $email->idempotencyKey,
            replyToAddress: $email->replyTo,
        ));
    }
}
