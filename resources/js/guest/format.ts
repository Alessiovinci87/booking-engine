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

/** Codici ISO 3166-1 alpha-2 per la scelta del paese. */
export const COUNTRY_CODES = (
    'AD AE AF AG AL AM AO AR AT AU AZ BA BB BD BE BF BG BH BI BJ BN BO BR BS BT BW BY BZ CA CD CF CG CH CI CL CM CN CO CR CU CV CY CZ ' +
    'DE DJ DK DM DO DZ EC EE EG ER ES ET FI FJ FM FR GA GB GD GE GH GM GN GQ GR GT GW GY HK HN HR HT HU ID IE IL IN IQ IR IS IT JM JO JP ' +
    'KE KG KH KI KM KN KP KR KW KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MG MH MK ML MM MN MO MR MT MU MV MW MX MY MZ NA NE NG NI ' +
    'NL NO NP NR NZ OM PA PE PG PH PK PL PS PT PW PY QA RO RS RU RW SA SB SC SD SE SG SI SK SL SM SN SO SR SS ST SV SY SZ TD TG TH TJ TL ' +
    'TM TN TO TR TT TV TW TZ UA UG US UY UZ VA VC VE VN VU WS XK YE ZA ZM ZW'
).split(' ');
