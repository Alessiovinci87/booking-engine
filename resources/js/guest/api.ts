/**
 * Client dell'API pubblica (07): nessun cookie (credentials: 'omit'), nessun CSRF (ADR-0010), lingua
 * da Accept-Language, Idempotency-Key sulle POST (generata una volta per tentativo e riusata nei
 * nuovi invii dello stesso modulo). Gli errori arrivano come { error: { code, details } }.
 */

import { i18n } from './i18n';

export interface Money {
    amount_cents: number;
    currency: string;
}

export interface CancellationPolicy {
    code: string;
    title: string | null;
    text: string | null;
}

export interface Photo {
    id: string;
    urls: { large: string; thumbnail: string };
    width?: number | null;
    height?: number | null;
}

export interface PublicUnit {
    id: string;
    name: string;
    description: string | null;
    house_rules: string | null;
    base_occupancy: number;
    max_occupancy: number;
    booking_mode: 'INSTANT' | 'REQUEST';
    payment_mode: string;
    min_nights: number;
    cancellation_policy: CancellationPolicy | null;
    photos: Photo[];
}

export interface PublicProperty {
    slug: string;
    name: string;
    description: string | null;
    house_rules: string | null;
    address: { line1: string | null; city: string | null; province: string | null; country_code: string | null };
    contact_email: string | null;
    contact_phone: string | null;
    checkin_time: string | null;
    checkout_time: string | null;
    privacy_policy_url: string | null;
    terms_url: string | null;
    privacy_version: string | null;
    terms_version: string | null;
    cancellation_policy: CancellationPolicy | null;
    units: PublicUnit[];
}

export interface Availability {
    unit_id: string;
    unavailable_nights: string[];
    min_nights_by_night: Record<string, number>;
}

export interface PriceLine {
    kind: string;
    date: string | null;
    quantity: number;
    unit_amount: Money;
    amount: Money;
}

export interface PriceQuote {
    unit_id: string;
    check_in: string;
    check_out: string;
    nights: number;
    adults: number;
    children: number;
    required_min_nights: number;
    lines: PriceLine[];
    subtotal: Money;
    discount: Money;
    total: Money;
    deposit: Money;
    balance: Money;
    payment_mode: string;
    booking_mode: 'INSTANT' | 'REQUEST';
    cancellation_policy: CancellationPolicy | null;
}

export interface GuestInput {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    country_code: string;
    notes: string;
}

export interface ReservationStarted {
    reservation_code: string;
    status: string;
    checkout_url: string;
    checkout_expires_at: string;
    access_token: string;
}

export interface ReservationStatus {
    reservation_code: string;
    status: string;
    booking_mode: 'INSTANT' | 'REQUEST';
    property: { slug: string; name: string; contact_email: string | null; contact_phone: string | null };
    unit: { id: string; name: string };
    check_in: string;
    check_out: string;
    nights: number;
    adults: number;
    children: number;
    contract_total: Money;
    deposit_due: Money;
    balance_due: Money;
    paid: Money;
    outstanding: Money;
    economic_status: string;
    refund_status: string;
    approval_deadline: string | null;
    confirmed_at: string | null;
    cancellation_policy: CancellationPolicy | null;
}

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly code: string,
        public readonly details: Record<string, unknown> = {},
    ) {
        super(code);
    }
}

/** /p/{tenant}/{struttura} o /e/{tenant}/{struttura} (vista embed). */
export function guestLocation(pathname: string = window.location.pathname): { tenantSlug: string; propertySlug: string; basename: string } | null {
    const [, prefix, tenantSlug, propertySlug] = pathname.split('/');

    if ((prefix !== 'p' && prefix !== 'e') || !tenantSlug || !propertySlug) {
        return null;
    }

    return { tenantSlug, propertySlug, basename: `/${prefix}/${tenantSlug}/${propertySlug}` };
}

async function request<T>(method: 'GET' | 'POST', url: string, body?: unknown, idempotencyKey?: string): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json', 'Accept-Language': i18n.language };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    if (idempotencyKey !== undefined) {
        headers['Idempotency-Key'] = idempotencyKey;
    }

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'omit',
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        const error = (payload as { error?: { code?: string; details?: Record<string, unknown> } } | null)?.error;

        throw new ApiError(response.status, error?.code ?? 'SERVER_ERROR', error?.details ?? {});
    }

    return payload as T;
}

function base(): string {
    const location = guestLocation();

    if (location === null) {
        throw new Error('Percorso della pagina ospite non valido');
    }

    return `/api/v1/public/${location.tenantSlug}/${location.propertySlug}`;
}

export const publicApi = {
    property: () => request<PublicProperty>('GET', base()),
    availability: (unitId: string, from: string, to: string) =>
        request<Availability>('GET', `${base()}/availability?unit_id=${encodeURIComponent(unitId)}&from=${from}&to=${to}`),
    quote: (input: { unit_id: string; check_in: string; check_out: string; adults: number; children: number }) =>
        request<PriceQuote>('POST', `${base()}/quote-price`, input),
    reserve: (body: Record<string, unknown>, idempotencyKey: string) =>
        request<ReservationStarted>('POST', `${base()}/reservations`, body, idempotencyKey),
    status: (code: string, token: string) =>
        request<ReservationStatus>('GET', `/api/v1/public/reservations/${encodeURIComponent(code)}?token=${encodeURIComponent(token)}`),
};

/** Errori per campo di VALIDATION_FAILED (07: details.fields). */
export function fieldErrors(error: unknown): Record<string, string[]> {
    if (error instanceof ApiError && error.code === 'VALIDATION_FAILED') {
        const fields = error.details.fields;

        if (fields !== null && typeof fields === 'object') {
            return fields as Record<string, string[]>;
        }
    }

    return {};
}
