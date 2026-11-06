import { useTranslation } from 'react-i18next';

export function HomePage() {
    const { t } = useTranslation();

    return (
        <main className="mx-auto max-w-3xl px-4 py-10">
            <p className="text-sm font-medium text-slate-500">{t('app.name')}</p>
            <h1 className="mt-2 text-2xl font-semibold text-slate-900">{t('home.title')}</h1>
            <p className="mt-3 text-slate-700">{t('home.subtitle')}</p>
        </main>
    );
}
