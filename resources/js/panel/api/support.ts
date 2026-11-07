/**
 * Accessi di supporto (7.5; 08, 07 /admin/support-access): elenco e revoca (password confermata).
 */
import { apiRequest } from './client';

export interface SupportAccessGrant {
    id: string;
    operator_email: string | null;
    reason: string;
    created_at: string | null;
    expires_at: string;
    revoked_at: string | null;
    revoked_by_member: boolean;
    first_used_at: string | null;
    last_used_at: string | null;
    active: boolean;
}

export const supportApi = {
    list: () => apiRequest<{ data: SupportAccessGrant[] }>('GET', '/support-access'),
    revoke: (id: string) => apiRequest<{ data: SupportAccessGrant }>('POST', `/support-access/${id}/revoke`),
};
