<?php

namespace App\Domain\Tenancy\Outbox;

use App\Domain\Communications\TransactionalEmail;
use App\Domain\Communications\TransactionalMailer;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\Models\User;
use App\Domain\Tenancy\Models\UserPasswordReset;
use Carbon\CarbonImmutable;

/**
 * Email dell'account del gestore (6.11, ADR-0016), in italiano come il pannello:
 *
 * - `account.password_reset`: link monouso per la nuova password. Il token non è nel payload: si legge
 *   cifrato da `user_password_resets` e va nel frammento dell'indirizzo (`#token=`), che il browser non
 *   invia al server, quindi non finisce nei log né nel Referer. Link usato, scaduto o sostituito: niente
 *   email.
 * - `account.password_changed`: avviso dopo un cambio o una reimpostazione della password.
 */
final class SendAccountEmail implements OutboxHandler
{
    public function __construct(private readonly TransactionalMailer $mailer) {}

    public function handle(OutboxMessage $message): void
    {
        $user = User::query()->find($message->payloadId('user_id'));

        if ($user === null) {
            return;
        }

        $base = rtrim((string) config('app.url'), '/');
        $replace = [];

        if ($message->type === 'account.password_reset') {
            $reset = UserPasswordReset::query()
                ->where('user_id', $user->id)
                ->find($message->payloadId('password_reset_id'));

            if ($reset === null || ! $reset->isUsable()) {
                return;
            }

            $link = $base.'/app/reimposta-password#token='.$reset->token_encrypted;
        } else {
            $replace['changed_at'] = CarbonImmutable::parse($message->created_at)->utc()->format('d/m/Y H:i');
            $link = $base.'/app/password-dimenticata';
        }

        $key = 'emails.'.str_replace('.', '_', $message->type);

        /** @var list<string> $lines */
        $lines = array_values((array) __($key.'.lines', $replace, 'it'));

        $this->mailer->send(new TransactionalEmail(
            to: $user->email,
            locale: 'it',
            subject: (string) __($key.'.subject', [], 'it'),
            greeting: (string) __('emails.account.greeting', [], 'it'),
            lines: $lines,
            link: $link,
            signature: (string) __('emails.account.signature', ['app' => config('app.name')], 'it'),
            category: $message->type,
            idempotencyKey: 'outbox:'.$message->id,
        ));
    }
}
