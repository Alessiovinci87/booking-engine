/**
 * API admin dei pagamenti (4.10): stato dell'account Stripe e link di onboarding, rimborsi da gestire,
 * contestazioni aperte. Importi in centesimi interi.
 */
import { apiRequest } from './client';

export type StripeAccountStatus = 'NOT_CONNECTED' | 'ENABLED' | 'IN_REVIEW' | 'ONBOARDING_INCOMPLETE';

export interface StripeAccountInfo {
    connected: boolean;
    status: StripeAccountStatus;
    stripe_account_id?: string;
    charges_enabled?: boolean;
    payouts_enabled?: boolean;
    details_submitted?: boolean;
    livemode?: boolean;
    demo?: boolean;
    last_synced_at?: string | null;
}

export type RefundRowStatus = 'PENDING' | 'SUCCEEDED' | 'FAILED' | 'CANCELLED';

export interface RefundRow {
    id: string;
    payment_id: string;
    amount_cents: number;
    method: 'STRIPE' | 'OFFLINE';
    status: RefundRowStatus;
    attempts: number;
    next_attempt_at: string | null;
    completed_at: string | null;
    reservation: { id: string; code: string; status: string } | null;
}

export interface DisputeRow {
    dispute_id: string;
    payment_id: string;
    amount_cents: number | null;
    opened_at: string;
    reservation: { id: string; code: string; status: string } | null;
}

export const paymentsApi = {
    stripeAccount: (refresh = false) => apiRequest<{ data: StripeAccountInfo }>('GET', `/stripe/account${refresh ? '?refresh=1' : ''}`),
    onboardingLink: () => apiRequest<{ data: { url: string } }>('POST', '/stripe/onboarding-link'),
    refunds: (statuses: RefundRowStatus[]) =>
        apiRequest<{ data: RefundRow[] }>('GET', `/refunds${statuses.length > 0 ? `?${statuses.map((s) => `status[]=${s}`).join('&')}` : ''}`),
    retryRefund: (id: string) => apiRequest<{ data: RefundRow }>('POST', `/refunds/${id}/retry`),
    markRefundOffline: (id: string, note: string) => apiRequest<{ data: RefundRow }>('POST', `/refunds/${id}/mark-offline`, { note }),
    disputes: () => apiRequest<{ data: DisputeRow[] }>('GET', '/disputes'),
};
