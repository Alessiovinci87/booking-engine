import { useMutation, useQuery } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { api, ApiError, fieldErrors } from '../api/client';
import { useErrorMessage } from '../auth/session';
import { buttonClass, FormError, inputClass } from '../components/AuthCard';

/**
 * Account del gestore (6.11): cambio password con la password attuale. La MFA è già verificata nella
 * sessione; le altre sessioni aperte si chiudono, questa resta aperta. Arriva un'email di avviso.
 */
export function AccountPage() {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const me = useQuery({ queryKey: ['me'], queryFn: api.me, staleTime: 60_000 });
    const [current, setCurrent] = useState('');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');

    const change = useMutation({
        mutationFn: () => api.changePassword(current, password, confirmation),
        onSuccess: () => {
            setCurrent('');
            setPassword('');
            setConfirmation('');
        },
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        change.mutate();
    };

    const errors = fieldErrors(change.error);

    return (
        <main className="mx-auto max-w-5xl px-4 py-6">
            <h1 className="text-xl font-semibold text-slate-900">{t('account.title')}</h1>
            {me.data && <p className="mt-1 text-sm text-slate-600">{me.data.user.email}</p>}
            <section className="mt-6 max-w-md rounded-lg border border-slate-200 bg-white p-6">
                <h2 className="font-semibold text-slate-900">{t('account.changePassword')}</h2>
                <p className="mt-1 text-sm text-slate-600">{t('account.changeIntro')}</p>
                <form className="mt-4 space-y-4" onSubmit={submit} noValidate>
                    {change.isSuccess && (
                        <p className="rounded-md bg-green-50 px-3 py-2 text-sm text-green-800" role="status" data-testid="password-changed">
                            {t('account.changed')}
                        </p>
                    )}
                    <FormError
                        message={
                            !change.isError
                                ? null
                                : change.error instanceof ApiError && change.error.code === 'INVALID_CREDENTIALS'
                                  ? t('account.currentInvalid')
                                  : errorMessage(change.error)
                        }
                    />
                    <label className="block text-sm font-medium text-slate-700">
                        {t('account.current')}
                        <input
                            className={inputClass}
                            type="password"
                            autoComplete="current-password"
                            required
                            value={current}
                            onChange={(e) => setCurrent(e.target.value)}
                        />
                    </label>
                    <label className="block text-sm font-medium text-slate-700">
                        {t('password.new')}
                        <input
                            className={inputClass}
                            type="password"
                            autoComplete="new-password"
                            required
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                        />
                    </label>
                    {errors.password?.map((message) => (
                        <p key={message} className="text-sm text-red-700">
                            {message}
                        </p>
                    ))}
                    <label className="block text-sm font-medium text-slate-700">
                        {t('password.confirm')}
                        <input
                            className={inputClass}
                            type="password"
                            autoComplete="new-password"
                            required
                            value={confirmation}
                            onChange={(e) => setConfirmation(e.target.value)}
                        />
                    </label>
                    <p className="text-xs text-slate-500">{t('password.rules')}</p>
                    <button className={buttonClass} type="submit" disabled={change.isPending}>
                        {change.isPending ? t('common.wait') : t('account.submit')}
                    </button>
                </form>
            </section>
            <section className="mt-6 max-w-md rounded-lg border border-slate-200 bg-white p-6">
                <h2 className="font-semibold text-slate-900">{t('offboarding.title')}</h2>
                <p className="mt-1 text-sm text-slate-600">{t('offboarding.accountHint')}</p>
                <Link to="/uscita" className="mt-3 inline-block text-sm font-medium text-blue-700 hover:underline" data-testid="account-offboarding-link">
                    {t('offboarding.open')}
                </Link>
            </section>
        </main>
    );
}
