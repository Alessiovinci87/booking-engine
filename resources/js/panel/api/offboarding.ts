/**
 * Uscita dalla piattaforma (7.1; 07 "Privacy", 06 "Ciclo di vita dei feed e uscita del cliente", 09).
 * Avvio, richiesta dell'export e conferme richiedono la password confermata (REAUTH_REQUIRED).
 */
import { apiRequest } from './client';

export interface OffboardingFeed {
    id: string;
    unit_name: string | null;
    destination: string;
    status: 'ACTIVE' | 'FROZEN' | 'RETIRED';
    rotated: boolean;
    last_fetched_at: string | null;
    read_recently: boolean;
    removal_confirmed_at: string | null;
}

export interface TenantExport {
    id: string;
    status: 'PENDING' | 'READY' | 'FAILED' | 'EXPIRED';
    size_bytes: number | null;
    future_reservations: number | null;
    requested_at: string | null;
    completed_at: string | null;
    expires_at: string | null;
    download_path: string | null;
}

export interface OffboardingOverview {
    status: 'ACTIVE' | 'SUSPENDED' | 'OFFBOARDING' | 'CLOSED';
    offboarding_started_at: string | null;
    auto_retire_from: string | null;
    channels_disconnected_confirmed_at: string | null;
    feeds_disabled_at: string | null;
    feeds: OffboardingFeed[];
    exports?: TenantExport[];
}

export const offboardingApi = {
    show: () => apiRequest<{ data: OffboardingOverview }>('GET', '/tenant/offboarding'),
    start: () => apiRequest<{ data: OffboardingOverview }>('POST', '/tenant/offboarding/start'),
    requestExport: () => apiRequest<{ data: TenantExport }>('POST', '/tenant/export'),
    confirmRemoved: (feedId: string) => apiRequest<{ data: OffboardingOverview }>('POST', `/tenant/offboarding/channels/${feedId}/confirm-removed`),
};
