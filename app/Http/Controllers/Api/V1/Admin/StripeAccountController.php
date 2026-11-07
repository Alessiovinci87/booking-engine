<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Payments\Gateway\Exceptions\PaymentProviderRejected;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Models\StripeAccount;
use App\Domain\Payments\StripeAccountService;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Http\WithTenantContext;
use App\Domain\Tenancy\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Collegamento Stripe del gestore (07 "Pagamenti e account", 4.1):
 * - GET /admin/stripe/account: stato dell'onboarding; con `refresh=1` (rientro da Stripe) lo rilegge;
 * - POST /admin/stripe/onboarding-link: crea l'account connesso se manca e restituisce il link
 *   ospitato da Stripe. Il ritorno e il rinnovo del link puntano alla pagina del pannello.
 *
 * Rotte in modalità `tenant:panel,steps`: le chiamate a Stripe stanno fuori dalle transazioni.
 */
final class StripeAccountController extends Controller
{
    public function __construct(private readonly StripeAccountService $service) {}

    public function show(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get(WithTenantContext::TENANT_ATTRIBUTE);

        $account = $request->boolean('refresh')
            ? $this->guard(fn (): ?StripeAccount => $this->service->sync($tenantId))
            : $this->service->current($tenantId);

        return new JsonResponse(['data' => self::present($account)]);
    }

    public function onboardingLink(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get(WithTenantContext::TENANT_ATTRIBUTE);
        /** @var User $user */
        $user = $request->user();
        $page = rtrim((string) config('app.url'), '/').'/app/pagamenti';

        $url = $this->guard(fn (): string => $this->service->onboardingLink($tenantId, $page.'?stripe=refresh', $page.'?stripe=return', $user->email));

        return new JsonResponse(['data' => ['url' => $url]]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function guard(callable $call): mixed
    {
        try {
            return $call();
        } catch (PaymentProviderUnavailable) {
            throw BusinessRuleViolation::make(503, 'PAYMENT_PROVIDER_UNAVAILABLE');
        } catch (PaymentProviderRejected $e) {
            throw BusinessRuleViolation::make(502, 'PAYMENT_PROVIDER_REJECTED', ['provider_code' => $e->providerCode]);
        }
    }

    /** @return array<string, mixed> */
    public static function present(?StripeAccount $account): array
    {
        if ($account === null) {
            return ['connected' => false, 'status' => 'NOT_CONNECTED'];
        }

        return [
            'connected' => true,
            'status' => match (true) {
                $account->charges_enabled => 'ENABLED',
                $account->details_submitted => 'IN_REVIEW',
                default => 'ONBOARDING_INCOMPLETE',
            },
            'stripe_account_id' => $account->stripe_account_id,
            'charges_enabled' => $account->charges_enabled,
            'payouts_enabled' => $account->payouts_enabled,
            'details_submitted' => $account->details_submitted,
            'livemode' => $account->livemode,
            'demo' => str_starts_with($account->stripe_account_id, StripeAccountService::DEMO_PREFIX),
            'last_synced_at' => $account->last_synced_at?->toIso8601ZuluString(),
        ];
    }
}
