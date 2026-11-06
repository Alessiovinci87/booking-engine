import type { Money } from './api/admin';

/**
 * Importi nel pannello: il server lavora in centesimi interi (CLAUDE.md, regola 6). L'input in euro
 * ("120", "120,5", "1.585,00") si converte in centesimi senza passare dai numeri in virgola mobile.
 * Restituisce null se il testo non è un importo valido.
 */
export function parseEuroToCents(input: string): number | null {
    const text = input.trim().replace(/\s|€/g, '');
    const match = /^(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{1,2}))?$/.exec(text) ?? /^(\d+)(?:\.(\d{1,2}))$/.exec(text);

    if (match === null) {
        return null;
    }

    const euros = Number.parseInt((match[1] ?? '0').replace(/\./g, ''), 10);
    const cents = Number.parseInt((match[2] ?? '0').padEnd(2, '0'), 10);

    return Number.isSafeInteger(euros * 100 + cents) ? euros * 100 + cents : null;
}

/** Centesimi → testo per un campo di input ("120,50"). */
export function centsToEuroInput(cents: number): string {
    const euros = Math.trunc(cents / 100);
    const rest = String(Math.abs(cents % 100)).padStart(2, '0');

    return `${euros},${rest}`;
}

const formatters = new Map<string, Intl.NumberFormat>();

/** Importo da mostrare ("1.585,00 €"); solo visualizzazione, mai calcoli. */
export function formatMoney(money: Money): string {
    let formatter = formatters.get(money.currency);

    if (formatter === undefined) {
        // useGrouping true = "always": in it-IT il default ("auto") non separa le migliaia sotto 10.000 ("1585,00 €").
        formatter = new Intl.NumberFormat('it-IT', { style: 'currency', currency: money.currency, useGrouping: true });
        formatters.set(money.currency, formatter);
    }

    return formatter.format(money.amount_cents / 100);
}
