/**
 * API admin di prenotazioni, calendario e blocchi (07). Stati e azioni ammesse arrivano dal server
 * (macchina a stati, 03): il pannello non replica le regole, mostra solo le azioni elencate in
 * `actions`. Importi in centesimi interi.
 */
import type { Money } from './admin';
import { apiRequest } from './client';

export type ReservationStatus =
    | 'AWAITING_PAYMENT'
    | 'AWAITING_APPROVAL'
    | 'CAPTURING'
    | 'CAPTURE_FAILED'
    | 'CONFIRMED'
    | 'REJECTED'
    | 'EXPIRED'
    | 'PAYMENT_FAILED'
    | 'CANCELLED';

export const RESERVATION_STATUSES: ReservationStatus[] = [
    'AWAITING_PAYMENT',
    'AWAITING_APPROVAL',
    'CAPTURING',
    'CAPTURE_FAILED',
    'CONFIRMED',
    'REJECTED',
    'EXPIRED',
    'PAYMENT_FAILED',
    'CANCELLED',
];

export type OfflineMethod = 'BANK_TRANSFER' | 'CASH' | 'POS' | 'OTHER';
export const OFFLINE_METHODS: OfflineMethod[] = ['BANK_TRANSFER', 'CASH', 'POS', 'OTHER'];

export type ReservationAction = 'approve' | 'reject' | 'cancel' | 'modify' | 'recovery_link' | 'release_dates' | 'offline_payment';

export interface ReservationSummary {
    id: string;
    code: string;
    status: ReservationStatus;
    channel: 'DIRECT_WEB' | 'QUOTE' | 'MANUAL';
    confirmation_kind: 'ONLINE_PAYMENT' | 'ADMINISTRATIVE' | null;
    unit: { id: string; name: string };
    guest: { first_name: string; last_name: string };
    check_in: string;
    check_out: string;
    nights: number;
    adults: number;
    children: number;
    booking_mode: 'INSTANT' | 'REQUEST';
    contract_total: Money;
    paid: Money;
    economic_status: string;
    refund_status: string;
    approval_deadline: string | null;
    decision_deadline: string | null;
    recovery_deadline: string | null;
    created_at: string | null;
    version: number;
}

export interface ReservationDetail extends Omit<ReservationSummary, 'guest'> {
    guest: {
        first_name: string;
        last_name: string;
        email: string | null;
        phone: string | null;
        country_code: string | null;
        locale: string;
        notes: string | null;
        anonymized: boolean;
    };
    payment_mode: string;
    deposit_due: Money;
    balance_due: Money;
    outstanding: Money;
    collected: Money;
    refunded: Money;
    confirmed_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    actions: ReservationAction[];
    cancellation_refund: { proposed: Money; max: Money } | null;
    price_lines: PriceLine[];
    payments: {
        id: string;
        purpose: string;
        status: string;
        amount: Money;
        captured: Money;
        capture_method: string;
        method_offline: OfflineMethod | null;
        failure_code: string | null;
        created_at: string | null;
        events: { type: string; amount: Money | null; occurred_at: string }[];
    }[];
    refunds: {
        id: string;
        payment_id: string;
        status: string;
        method: string;
        amount: Money;
        attempts: number;
        next_attempt_at: string | null;
        completed_at: string | null;
        created_at: string | null;
    }[];
    changes: {
        previous: { check_in: string; check_out: string; adults: number; children: number; contract_total: Money };
        new: { check_in: string; check_out: string; adults: number; children: number; contract_total: Money };
        note: string | null;
        changed_by_user_id: string | null;
        occurred_at: string;
    }[];
    history: { action: string; actor_type: string; actor_id: string | null; occurred_at: string }[];
    recovery?: { checkout_url: string; expires_at: string };
}

export interface CalendarOccupancy {
    id: string;
    unit_id: string;
    kind: 'RESERVATION' | 'MANUAL_BLOCK';
    phase: 'TEMPORARY' | 'FIRM';
    temp_reason: string | null;
    status: 'ACTIVE' | 'RELEASED';
    check_in: string;
    check_out: string;
    expires_at: string | null;
    reservation_id: string | null;
    note: string | null;
    reservation: {
        id: string;
        code: string;
        status: ReservationStatus;
        channel: string;
        guest_name: string;
        adults: number;
        children: number;
    } | null;
}

export interface CalendarData {
    unit_id: string;
    from: string;
    to: string;
    occupancies: CalendarOccupancy[];
    external_events: { id: string; source: string; check_in: string; check_out: string; summary: string | null; echo_kind: string; status: string }[];
    conflicts: { id: string; check_in: string; check_out: string; status: string; suspected_echo: boolean }[];
}

export interface ReservationFilters {
    status?: ReservationStatus[];
    from?: string;
    to?: string;
    unit_id?: string;
    q?: string;
    cursor?: string;
}

export interface GuestInput {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    country_code: string;
    notes: string | null;
}

export interface ManualReservationInput {
    unit_id: string;
    check_in: string;
    check_out: string;
    adults: number;
    children: number;
    discount_cents?: number;
    offline_payment?: { amount_cents: number; method: OfflineMethod } | null;
    guest: GuestInput;
    locale: 'it' | 'en';
}

function query(filters: ReservationFilters): string {
    const params = new URLSearchParams();

    for (const status of filters.status ?? []) {
        params.append('status[]', status);
    }

    for (const key of ['from', 'to', 'unit_id', 'q', 'cursor'] as const) {
        const value = filters[key];

        if (value !== undefined && value !== '') {
            params.set(key, value);
        }
    }

    const text = params.toString();

    return text === '' ? '' : `?${text}`;
}

export type PriceLine = { kind: string; date: string | null; quantity: number; unit_amount: Money; amount: Money };

export type StayPrice = {
    check_in: string;
    check_out: string;
    nights: number;
    adults: number;
    children: number;
    price_lines: PriceLine[];
    contract_total: Money;
    deposit_due: Money;
    balance_due: Money;
};

export type ModifyInput = { check_in?: string; check_out?: string; adults?: number; children?: number; note?: string | null };

/** Anteprima della modifica (ADR-0011 punto 28): prima e dopo, senza salvare. */
export type ModifyPreview = { version: number; current: StayPrice; proposed: StayPrice; difference: Money };

export const reservationsApi = {
    list: (filters: ReservationFilters) =>
        apiRequest<{ data: ReservationSummary[]; next_cursor: string | null }>('GET', `/reservations${query(filters)}`),
    get: (id: string) => apiRequest<ReservationDetail>('GET', `/reservations/${id}`),
    create: (input: ManualReservationInput) => apiRequest<ReservationDetail>('POST', '/reservations', input),
    previewModify: (id: string, input: ModifyInput) => apiRequest<ModifyPreview>('POST', `/reservations/${id}/modify-preview`, input),
    modify: (id: string, version: number, input: ModifyInput & { expected_total_cents?: number }) =>
        apiRequest<ReservationDetail>('PATCH', `/reservations/${id}`, input, { version }),
    approve: (id: string) => apiRequest<ReservationDetail>('POST', `/reservations/${id}/approve`),
    reject: (id: string, reason: string | null) => apiRequest<ReservationDetail>('POST', `/reservations/${id}/reject`, { reason }),
    cancel: (id: string, refundCents: number, reason: string | null) =>
        apiRequest<ReservationDetail>('POST', `/reservations/${id}/cancel`, { refund_cents: refundCents, reason }),
    offlinePayment: (id: string, amountCents: number, method: OfflineMethod) =>
        apiRequest<ReservationDetail>('POST', `/reservations/${id}/offline-payments`, { amount_cents: amountCents, method }),
    recoveryLink: (id: string, deadlineHours: number) =>
        apiRequest<ReservationDetail>('POST', `/reservations/${id}/recovery-link`, { deadline_hours: deadlineHours }),
    releaseDates: (id: string) => apiRequest<ReservationDetail>('POST', `/reservations/${id}/release-dates`),

    calendar: (unitId: string, from: string, to: string) =>
        apiRequest<CalendarData>('GET', `/calendar?unit_id=${encodeURIComponent(unitId)}&from=${from}&to=${to}`),
    createBlock: (unitId: string, input: { check_in: string; check_out: string; note: string | null }) =>
        apiRequest<CalendarOccupancy>('POST', `/units/${unitId}/blocks`, input),
    deleteBlock: (unitId: string, blockId: string) => apiRequest<void>('DELETE', `/units/${unitId}/blocks/${blockId}`),
};
