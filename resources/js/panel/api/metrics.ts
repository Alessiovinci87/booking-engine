/**
 * Metriche del pannello ed export CSV (6.2, 01 "Metriche del pannello", 07 GET /admin/metrics).
 * Importi in centesimi interi. Il beneficio si chiama "beneficio diretto stimato", mai "risparmio netto".
 */
import { apiRequest } from './client';

export interface PropertyMetrics {
    property_id: string;
    name: string;
    ota_commission_bps: number;
    reservations: number;
    direct_revenue_cents: number;
    ota_commission_avoided_cents: number;
}

export interface Metrics {
    from: string;
    to: string;
    currency: string;
    reservations: number;
    nights: number;
    direct_revenue_cents: number;
    ota_commission_avoided_cents: number;
    payment_fees_cents: number;
    payment_fees_estimated: boolean;
    direct_discounts_cents: number;
    estimated_direct_benefit_cents: number;
    properties: PropertyMetrics[];
}

export const metricsApi = {
    get: (from: string, to: string) => apiRequest<{ data: Metrics }>('GET', `/metrics?from=${from}&to=${to}`),
    /** Download diretto con il cookie di sessione (GET, nessun token CSRF necessario). */
    csvUrl: (from: string, to: string) => `/api/v1/admin/exports/reservations.csv?from=${from}&to=${to}`,
};
