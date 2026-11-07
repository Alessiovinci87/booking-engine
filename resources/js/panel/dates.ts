/**
 * Date di soggiorno nel pannello: stringhe "YYYY-MM-DD" senza fuso orario, intervalli [check_in,
 * check_out) (CLAUDE.md regola 6). Le notti di un soggiorno sono i giorni da check_in incluso a
 * check_out escluso. I timestamp (scadenze, storico) arrivano in UTC e si mostrano nell'ora locale.
 */

export function toIsoDate(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function fromIsoDate(value: string): Date {
    const [year, month, day] = value.split('-').map((part) => Number.parseInt(part, 10));

    return new Date(year ?? 1970, (month ?? 1) - 1, day ?? 1);
}

export function addDays(value: string, days: number): string {
    const date = fromIsoDate(value);
    date.setDate(date.getDate() + days);

    return toIsoDate(date);
}

export function today(): string {
    return toIsoDate(new Date());
}

/** Primo giorno del mese di `value`, spostato di `months` mesi. */
export function monthStart(value: string, months = 0): string {
    const date = fromIsoDate(value);

    return toIsoDate(new Date(date.getFullYear(), date.getMonth() + months, 1));
}

/** La notte `night` appartiene al soggiorno [checkIn, checkOut)? Confronto lessicografico sicuro. */
export function coversNight(checkIn: string, checkOut: string, night: string): boolean {
    return checkIn <= night && night < checkOut;
}

export function formatDate(value: string): string {
    return new Intl.DateTimeFormat('it-IT', { day: 'numeric', month: 'short', year: 'numeric' }).format(fromIsoDate(value));
}

export function formatMonth(value: string): string {
    return new Intl.DateTimeFormat('it-IT', { month: 'long', year: 'numeric' }).format(fromIsoDate(value));
}

export function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('it-IT', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

export function weekdayNames(): string[] {
    // 2024-01-01 è un lunedì: settimana da lunedì a domenica.
    return Array.from({ length: 7 }, (_, i) => new Intl.DateTimeFormat('it-IT', { weekday: 'short' }).format(new Date(2024, 0, 1 + i)));
}
