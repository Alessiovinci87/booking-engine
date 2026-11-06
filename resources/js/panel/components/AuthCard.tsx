import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

/** Riquadro centrato delle pagine di accesso. */
export function AuthCard({ title, children }: { title: string; children: ReactNode }) {
    const { t } = useTranslation();

    return (
        <main className="flex min-h-screen items-center justify-center px-4 py-10">
            <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
                <p className="text-sm font-medium text-slate-500">{t('app.name')}</p>
                <h1 className="mt-1 text-xl font-semibold text-slate-900">{title}</h1>
                <div className="mt-6">{children}</div>
            </div>
        </main>
    );
}

export function FormError({ message }: { message: string | null }) {
    if (message === null) {
        return null;
    }

    return (
        <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
            {message}
        </p>
    );
}

export const inputClass =
    'mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600';

export const buttonClass =
    'w-full rounded-md bg-blue-700 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60';
