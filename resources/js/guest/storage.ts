/**
 * Dati della prenotazione appena avviata, nella sessionStorage della scheda (ADR-0011): il token di
 * accesso per la pagina di ritorno e la sessione di pagamento per riprenderla dopo un annullamento.
 * Nessun dato personale dell'ospite. Se la sessionStorage non è disponibile si prosegue senza.
 */

export interface StoredBooking {
    token: string;
    checkoutUrl: string | null;
    checkoutExpiresAt: string | null;
}

const key = (code: string): string => `guest.reservation.${code}`;

export function saveBooking(code: string, booking: StoredBooking): void {
    try {
        window.sessionStorage.setItem(key(code), JSON.stringify(booking));
    } catch {
        // Archiviazione non disponibile (navigazione privata, iframe di terze parti).
    }
}

export function loadBooking(code: string): StoredBooking | null {
    try {
        const raw = window.sessionStorage.getItem(key(code));

        if (raw === null) {
            return null;
        }

        const parsed = JSON.parse(raw) as Partial<StoredBooking>;

        return typeof parsed.token === 'string'
            ? { token: parsed.token, checkoutUrl: parsed.checkoutUrl ?? null, checkoutExpiresAt: parsed.checkoutExpiresAt ?? null }
            : null;
    } catch {
        return null;
    }
}
