<?php

namespace App\Domain\Payments\Models;

use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;

/**
 * Account Stripe Connect della struttura (02, 05), uno per tenant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $stripe_account_id
 * @property bool $charges_enabled
 * @property bool $payouts_enabled
 * @property bool $details_submitted
 * @property bool $livemode
 * @property CarbonImmutable|null $last_synced_at
 */
class StripeAccount extends TenantModel
{
    protected function casts(): array
    {
        return [
            'charges_enabled' => 'boolean',
            'payouts_enabled' => 'boolean',
            'details_submitted' => 'boolean',
            'livemode' => 'boolean',
            'last_synced_at' => 'immutable_datetime',
        ];
    }
}
