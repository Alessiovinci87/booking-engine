/**
 * API admin dei preventivi (07 "Preventivi", 5.7). Il prezzo lo calcola e lo congela il server alla
 * creazione; l'invio restituisce link e testo per WhatsApp. Importi in centesimi interi.
 */
import type { Money } from './admin';
import { apiRequest } from './client';

export type QuoteStatus = 'DRAFT' | 'SENT' | 'IN_CHECKOUT' | 'ACCEPTED' | 'EXPIRED' | 'REVOKED' | 'EXHAUSTED';

export const QUOTE_STATUSES: QuoteStatus[] = ['DRAFT', 'SENT', 'IN_CHECKOUT', 'ACCEPTED', 'EXPIRED', 'REVOKED', 'EXHAUSTED'];

export interface QuoteLine {
    kind: string;
    date: string | null;
    quantity: number;
    unit_amount: Money;
    amount: Money;
}

export interface Quote {
    id: string;
    status: QuoteStatus;
    unit: { id: string; name: string };
    check_in: string;
    check_out: string;
    nights: number;
    adults: number;
    children: number;
    guest_name: string | null;
    guest_email: string | null;
    guest_locale: 'it' | 'en';
    lines: QuoteLine[];
    subtotal: Money;
    discount: Money;
    total: Money;
    deposit: Money;
    balance: Money;
    booking_mode: 'INSTANT' | 'REQUEST';
    payment_mode: string;
    expires_at: string;
    expired: boolean;
    checkout_attempts: number;
    max_checkout_attempts: number;
    reservation: { id: string; code: string; status: string } | null;
    link: string | null;
    created_at: string | null;
}

export interface SentQuote extends Quote {
    link: string;
    whatsapp_text: string;
    whatsapp_url: string;
}

export interface NewQuote {
    unit_id: string;
    check_in: string;
    check_out: string;
    adults: number;
    children: number;
    discount_cents: number;
    guest_name: string | null;
    guest_email: string | null;
    guest_locale: 'it' | 'en';
    expires_at?: string | null;
}

export const quotesApi = {
    list: (status: QuoteStatus[]) => {
        const query = status.map((s) => `status[]=${s}`).join('&');

        return apiRequest<{ data: Quote[]; next_cursor: string | null }>('GET', `/quotes${query !== '' ? `?${query}` : ''}`).then((r) => r.data);
    },
    create: (body: NewQuote) => apiRequest<{ data: Quote }>('POST', '/quotes', body).then((r) => r.data),
    send: (id: string, channel: 'email' | 'link') => apiRequest<{ data: SentQuote }>('POST', `/quotes/${id}/send`, { channel }).then((r) => r.data),
    revoke: (id: string) => apiRequest<{ data: Quote }>('POST', `/quotes/${id}/revoke`).then((r) => r.data),
};
