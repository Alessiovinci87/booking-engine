<?php

namespace App\Domain\Calendar\Outbox;

use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Communications\TransactionalEmail;
use App\Domain\Communications\TransactionalMailer;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\Outbox\OutboxHandler;

/**
 * Email al gestore sui calendari (06): conflitto aperto, conflitto risolto automaticamente, calendario
 * importato non sincronizzato da oltre 3 ore. Il payload contiene solo ID; i testi sono in italiano
 * (pannello) e non contengono dati dell'ospite.
 */
final class SendCalendarEmail implements OutboxHandler
{
    public function __construct(private readonly TransactionalMailer $mailer) {}

    public function handle(OutboxMessage $message): void
    {
        $payload = $message->payload;
        $replace = [];

        if (isset($payload['conflict_id'])) {
            $conflict = InventoryConflict::query()->find($message->payloadId('conflict_id'));

            if ($conflict === null) {
                return;
            }

            $unitId = $conflict->unit_id;
            $replace = ['check_in' => $conflict->stay->checkIn->toDateString(), 'check_out' => $conflict->stay->checkOut->toDateString()];
            $path = '/app/conflitti';
        } else {
            $calendar = ExternalCalendar::query()->find($message->payloadId('external_calendar_id'));

            if ($calendar === null) {
                return;
            }

            $unitId = $calendar->unit_id;
            $replace = ['source' => __('calendar.sources.'.$calendar->source->value, [], 'it')];
            $path = '/app/calendari';
        }

        $unit = Unit::query()->with('property')->find($unitId);
        $recipient = $unit?->property->contact_email;

        if ($unit === null || $recipient === null || $recipient === '') {
            return;
        }

        $replace += ['unit' => $unit->name, 'property' => $unit->property->name];
        $key = 'emails.'.str_replace('.', '_', $message->type);

        /** @var list<string> $lines */
        $lines = array_values((array) __($key.'.lines', $replace, 'it'));

        $this->mailer->send(new TransactionalEmail(
            to: $recipient,
            locale: 'it',
            subject: (string) __($key.'.subject', $replace, 'it'),
            greeting: (string) __('emails.greeting', [], 'it'),
            lines: $lines,
            link: rtrim((string) config('app.url'), '/').$path,
            signature: (string) __('emails.signature', ['property' => $unit->property->name], 'it'),
            category: $message->type,
            idempotencyKey: 'outbox:'.$message->id,
        ));
    }
}
