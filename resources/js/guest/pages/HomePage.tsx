import { useTranslation } from 'react-i18next';

import { guestLanguages } from '../i18n';
import { LanguageSelector } from '../LanguageSelector';

export function HomePage() {
    const { t } = useTranslation();

    return (
        <main className="mx-auto max-w-md px-4 py-8">
            <div className="flex justify-end">
                <LanguageSelector languages={guestLanguages} />
            </div>
            <h1 className="mt-6 text-2xl font-semibold text-slate-900">{t('home.title')}</h1>
            <p className="mt-3 text-slate-700">{t('home.subtitle')}</p>
        </main>
    );
}
