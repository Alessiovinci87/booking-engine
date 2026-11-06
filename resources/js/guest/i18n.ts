import i18next from 'i18next';
import { initReactI18next } from 'react-i18next';

import en from './locales/en.json';
import it from './locales/it.json';

/** Pagina ospite: italiano e inglese (ADR-0006). */
export const guestLanguages = ['it', 'en'] as const;
export type GuestLanguage = (typeof guestLanguages)[number];

const STORAGE_KEY = 'guest.language';

function isGuestLanguage(value: unknown): value is GuestLanguage {
    return typeof value === 'string' && (guestLanguages as readonly string[]).includes(value);
}

function initialLanguage(): GuestLanguage {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);
        if (isGuestLanguage(stored)) {
            return stored;
        }
    } catch {
        // localStorage non disponibile: si usa la lingua del browser.
    }

    const browser = navigator.language.slice(0, 2);

    return isGuestLanguage(browser) ? browser : 'it';
}

export const i18n = i18next.createInstance();

void i18n.use(initReactI18next).init({
    lng: initialLanguage(),
    fallbackLng: 'it',
    supportedLngs: [...guestLanguages],
    resources: { it: { translation: it }, en: { translation: en } },
    interpolation: { escapeValue: false },
});

i18n.on('languageChanged', (language) => {
    document.documentElement.lang = language;
    try {
        window.localStorage.setItem(STORAGE_KEY, language);
    } catch {
        // Preferenza non salvata: nessun effetto sul funzionamento.
    }
});

document.documentElement.lang = i18n.language;
