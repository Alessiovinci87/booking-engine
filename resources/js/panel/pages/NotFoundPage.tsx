import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

export function NotFoundPage() {
    const { t } = useTranslation();

    return (
        <main className="mx-auto max-w-3xl px-4 py-10">
            <h1 className="text-2xl font-semibold text-slate-900">{t('notFound.title')}</h1>
            <Link to="/" className="mt-4 inline-block text-blue-700 underline">
                {t('notFound.back')}
            </Link>
        </main>
    );
}
