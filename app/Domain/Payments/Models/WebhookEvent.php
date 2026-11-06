<?php

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\WebhookEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Webhook ricevuto (02, 05): tabella globale, unica per (provider, event_id) (I3). Del payload si
 * salva solo l'hash (08).
 *
 * @property string $id
 * @property string $provider
 * @property string $event_id
 * @property string $type
 * @property string|null $account_id
 * @property bool $livemode
 * @property string $payload_hash
 * @property WebhookEventStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
class WebhookEvent extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'status' => WebhookEventStatus::class,
            'attempts' => 'integer',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
