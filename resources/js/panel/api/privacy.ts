/**
 * Privacy dell'ospite (6.3, 07 "Privacy", 09): export dei dati (accesso e portabilità) e
 * anonimizzazione (ri-autenticazione; 409 GUEST_HAS_ACTIVE_RESERVATION su prenotazione attiva).
 */
import { apiRequest } from './client';

export interface GuestExport {
    generated_at: string;
    guest: {
        id: string;
        first_name: string;
        last_name: string;
        email: string;
        phone: string;
        country_code: string;
        locale: string;
        notes: string | null;
        anonymized_at: string | null;
    };
    reservations: {
        code: string;
        status: string;
        channel: string;
        property: string | null;
        unit: string | null;
        check_in: string;
        check_out: string;
        adults: number;
        children: number;
        contract_total_cents: number;
        currency: string;
        confirmed_at: string | null;
        cancelled_at: string | null;
        payments: { purpose: string; status: string; amount_cents: number; captured_cents: number; created_at: string | null }[];
        refunds: { amount_cents: number; method: string; status: string; completed_at: string | null }[];
    }[];
    other_guest_records: string[];
}

export const privacyApi = {
    exportGuest: (guestId: string) => apiRequest<{ data: GuestExport }>('GET', `/guests/${guestId}/export`),
    /** Percorso del download (ProtectedDownload): password confermata negli ultimi 15 minuti (08). */
    exportPath: (guestId: string) => `/guests/${guestId}/export`,
    eraseGuest: (guestId: string) => apiRequest<{ data: { id: string; anonymized_at: string } }>('POST', `/guests/${guestId}/erase`),
};
