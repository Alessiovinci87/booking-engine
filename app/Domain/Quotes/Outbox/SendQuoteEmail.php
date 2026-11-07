<?php

namespace App\Domain\Quotes\Outbox;

use App\Domain\Communications\TransactionalEmail;
use App\Domain\Communications\TransactionalMailer;
use App\Domain\Quotes\Enums\QuoteStatus;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\QuoteLinks;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\Outbox\OutboxHandler;
use Illuminate\Support\Number;

/**
 * Email del preventivo all'ospite (07 POST /quotes/{id}/send), nella lingua del preventivo. Il payload
 * contiene solo l'ID: destinatario e link si ricavano alla consegna. Un preventivo nel frattempo
 * ritirato o scaduto, o senza email (anonimizzato, 09), non parte.
 */
final class SendQuoteEmail implements OutboxHandler
{
    public function __construct(private readonly TransactionalMailer $mailer) {}

    public function handle(OutboxMessage $message): void
    {
        $quote = Quote::query()->with('unit.property')->find($message->payload['quote_id'] ?? null);

        if ($quote === null || $quote->status !== QuoteStatus::SENT || $quote->guest_email === null || $quote->guest_email === '') {
            return;
        }

        $locale = $quote->guest_locale->value;
        $property = $quote->unit->property;
        $replace = [
            'property' => $property->name,
            'unit' => $quote->unit->name,
            'check_in' => $quote->stay->checkIn->toDateString(),
            'check_out' => $quote->stay->checkOut->toDateString(),
            'amount' => (string) Number::currency($quote->final_total_cents / 100, in: $quote->currency, locale: $locale),
            'expires_at' => $quote->expires_at->format('Y-m-d H:i'),
        ];

        /** @var list<string> $lines */
        $lines = array_values((array) __('emails.quote_sent.lines', $replace, $locale));

        $this->mailer->send(new TransactionalEmail(
            to: $quote->guest_email,
            locale: $locale,
            subject: (string) __('emails.quote_sent.subject', $replace, $locale),
            greeting: (string) __('emails.greeting', [], $locale),
            lines: $lines,
            link: QuoteLinks::url($quote),
            signature: (string) __('emails.signature', ['property' => $property->name], $locale),
            category: $message->type,
            idempotencyKey: 'outbox:'.$message->id,
            replyTo: $property->contact_email,
        ));
    }
}
