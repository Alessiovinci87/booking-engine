<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Tenancy\Models\SupportAccessGrant;
use App\Domain\Tenancy\Models\User;
use App\Domain\Tenancy\Support\SupportAccessService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pagina "Accessi di supporto" (7.5; 08; 07 /admin/support-access): elenco delle autorizzazioni di
 * supporto del tenant con motivazione, durata, prima e ultima lettura, e revoca di quella attiva (con la
 * ri-autenticazione). L'audit completo resta in audit_log con le azioni support.*.
 */
final class SupportAccessController extends Controller
{
    public function __construct(private readonly SupportAccessService $support) {}

    public function index(): JsonResponse
    {
        $now = CarbonImmutable::now();
        $grants = SupportAccessGrant::query()->with('operator')->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        return new JsonResponse(['data' => $grants->map(fn (SupportAccessGrant $g): array => self::grantData($g, $now))->values()->all()]);
    }

    public function revoke(Request $request, string $grant): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->support->revoke($grant, $user->id);

        return new JsonResponse(['data' => self::grantData($model, CarbonImmutable::now())]);
    }

    /** @return array<string, mixed> */
    private static function grantData(SupportAccessGrant $grant, CarbonImmutable $now): array
    {
        return [
            'id' => $grant->id,
            // Chi della piattaforma ha avuto accesso (08: audit visibile al cliente). Non è un dato dell'ospite.
            'operator_email' => $grant->operator?->email,
            'reason' => $grant->reason,
            'created_at' => $grant->created_at?->toIso8601ZuluString(),
            'expires_at' => $grant->expires_at->toIso8601ZuluString(),
            'revoked_at' => $grant->revoked_at?->toIso8601ZuluString(),
            'revoked_by_member' => $grant->revoked_by !== null,
            'first_used_at' => $grant->first_used_at?->toIso8601ZuluString(),
            'last_used_at' => $grant->last_used_at?->toIso8601ZuluString(),
            'active' => $grant->isActive($now),
        ];
    }
}
