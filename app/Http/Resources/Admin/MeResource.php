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
            ],
            'role' => $this->member->role->value,
        ];
    }
}
