import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { buttonClass as sharedButton, inputClass as sharedInput } from '../../shared/ui';

/** Riquadro centrato delle pagine di accesso. */
export function AuthCard({ title, children }: { title: string; children: ReactNode }) {
    const { t } = useTranslation();

    return (
        <main className="flex min-h-screen items-center justify-center px-4 py-10">
            <div className="w-full max-w-md rounded-card border border-line-soft bg-surface p-8 shadow-card">
                <p className="text-sm font-medium text-muted">{t('app.name')}</p>
                <h1 className="mt-1 text-xl font-semibold text-ink">{title}</h1>
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
        <p className="rounded-control border border-red-200 bg-danger-soft px-3 py-2 text-sm text-danger" role="alert">
            {message}
        </p>
    );
}

export const inputClass = sharedInput;

export const buttonClass = sharedButton('primary', { block: true });
