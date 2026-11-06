import type { Money } from './api';

/**
 * Formattazione per la pagina ospite: importi (solo visualizzazione, i calcoli li fa il server) e date
 * di soggiorno come stringhe YYYY-MM-DD senza fuso orario, così una notte resta la stessa notte
 * ovunque si trovi l'ospite (CLAUDE.md, regola 6).
 */

const localeTag = (language: string): string => (language === 'en' ? 'en-GB' : 'it-IT');

export function formatMoney(money: Money, language: string): string {
    return new Intl.NumberFormat(localeTag(language), { style: 'currency', currency: money.currency, useGrouping: true }).format(
        money.amount_cents / 100,
    );
}

/** Data locale (mezzanotte) → "YYYY-MM-DD". */
export function toIsoDate(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** "YYYY-MM-DD" → data locale a mezzanotte. */
export function fromIsoDate(value: string): Date {
    const [year, month, day] = value.split('-').map((part) => Number.parseInt(part, 10));

    return new Date(year ?? 1970, (month ?? 1) - 1, day ?? 1);
}

export function addDays(date: Date, days: number): Date {
    const copy = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    copy.setDate(copy.getDate() + days);

    return copy;
}

export function formatDate(value: string, language: string): string {
    return new Intl.DateTimeFormat(localeTag(language), { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).format(
        fromIsoDate(value),
    );
}

export function formatDateTime(value: string, language: string): string {
    return new Intl.DateTimeFormat(localeTag(language), { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

/** Nome del paese nella lingua dell'ospite (Intl.DisplayNames). */
export function countryName(code: string, language: string): string {
    try {
        return new Intl.DisplayNames([localeTag(language)], { type: 'region' }).of(code) ?? code;
    } catch {
        return code;
    }
}

export { COUNTRY_CODES } from '../shared/countries';
