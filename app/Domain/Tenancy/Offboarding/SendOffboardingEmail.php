<?php

namespace App\Domain\Tenancy\Offboarding;

use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Communications\TransactionalEmail;
use App\Domain\Communications\TransactionalMailer;
use App\Domain\Tenancy\Enums\MemberRole;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\Models\TenantExport;
use App\Domain\Tenancy\Models\TenantMember;
use App\Domain\Tenancy\Models\User;
use App\Domain\Tenancy\Outbox\OutboxHandler;

/**
 * Email ai proprietari del tenant durante l'uscita (7.1), in italiano come il pannello:
 *
 * - `tenant.export_ready`: export completo pronto, scaricabile dal pannello per 30 giorni (il link porta
 *   alla pagina "Uscita", che chiede la password: nessun link di download nell'email);
 * - `offboarding.feed_still_read`: un feed congelato è ancora letto dall'OTA dopo 30 giorni, quindi non
 *   viene ritirato; il gestore deve toglierlo dall'OTA e confermarlo (06 punto 5).
 */
final class SendOffboardingEmail implements OutboxHandler
{
    public function __construct(private readonly TransactionalMailer $mailer) {}

    public function handle(OutboxMessage $message): void
    {
        $replace = [];

        if ($message->type === 'tenant.export_ready') {
            $export = TenantExport::query()->find($message->payloadId('tenant_export_id'));

            if ($export === null || ! $export->isDownloadable() || $export->expires_at === null) {
                return;
            }

            $replace['expires_at'] = $export->expires_at->setTimezone('Europe/Rome')->format('d/m/Y');
        } else {
            $feed = IcsFeed::query()->with('unit:id,name')->find($message->payloadId('ics_feed_id'));

            if ($feed === null || $feed->removal_confirmed_at !== null) {
                return;
            }

            $replace['unit'] = $feed->unit->name ?? '';
            $replace['destination'] = __('calendar.sources.'.$feed->destination->value, [], 'it');
            $replace['last_fetched_at'] = $feed->last_fetched_at?->setTimezone('Europe/Rome')->format('d/m/Y H:i') ?? '-';
        }

        $key = 'emails.'.str_replace('.', '_', $message->type);

        /** @var list<string> $lines */
        $lines = array_values((array) __($key.'.lines', $replace, 'it'));
        $ownerIds = TenantMember::query()->where('role', MemberRole::OWNER->value)->pluck('user_id')->all();

        foreach (User::query()->whereIn('id', $ownerIds)->orderBy('id')->get() as $user) {
            $this->mailer->send(new TransactionalEmail(
                to: $user->email,
                locale: 'it',
                subject: (string) __($key.'.subject', $replace, 'it'),
                greeting: (string) __('emails.account.greeting', [], 'it'),
                lines: $lines,
                link: rtrim((string) config('app.url'), '/').'/app/uscita',
                signature: (string) __('emails.account.signature', ['app' => config('app.name')], 'it'),
                category: $message->type,
                idempotencyKey: 'outbox:'.$message->id.':'.$user->id,
            ));
        }
    }
}
