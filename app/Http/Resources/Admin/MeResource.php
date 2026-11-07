<?php

namespace App\Http\Resources\Admin;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMember;
use App\Domain\Tenancy\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property User $resource
 */
final class MeResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(User $user, private readonly Tenant $tenant, private readonly TenantMember $member)
    {
        parent::__construct($user);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->resource->id,
                'email' => $this->resource->email,
                'locale' => $this->resource->locale->value,
            ],
            'tenant' => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
                // Stato del tenant: in uscita il pannello mostra il banner e resta in sola lettura (7.1).
                'status' => $this->tenant->status->value,
                // DPA (09 requisito 1): visibile al gestore, registrato dalla piattaforma.
                'dpa_signed_at' => $this->tenant->dpa_signed_at?->toIso8601ZuluString(),
                'dpa_version' => $this->tenant->dpa_version,
            ],
            'role' => $this->member->role->value,
        ];
    }
}
