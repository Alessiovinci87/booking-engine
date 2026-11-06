import i18next from 'i18next';
import { initReactI18next } from 'react-i18next';

import it from './locales/it.json';

/** Pannello del gestore: solo italiano (ADR-0006). */
export const i18n = i18next.createInstance();

void i18n.use(initReactI18next).init({
    lng: 'it',
    fallbackLng: 'it',
    supportedLngs: ['it'],
    resources: { it: { translation: it } },
    interpolation: { escapeValue: false },
});
