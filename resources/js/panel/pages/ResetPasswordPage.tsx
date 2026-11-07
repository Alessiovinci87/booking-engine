import { useMutation } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { api, fieldErrors } from '../api/client';
import { useErrorMessage } from '../auth/session';
import { AuthCard, buttonClass, FormError, inputClass } from '../components/AuthCard';

/** Token del link: sta nel frammento (#token=…), che il browser non invia al server. */
function tokenFromHash(): string {
    return new URLSearchParams(window.location.hash.replace(/^#/, '')).get('token') ?? '';
}

/**
 * Nuova password dal link monouso (6.11). Non apre la sessione: dopo si accede con la nuova password e
 * il codice della verifica in due passaggi (o un codice di recupero).
 */
export function ResetPasswordPage() {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const [token] = useState(tokenFromHash);
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');

    const reset = useMutation({
        mutationFn: () => api.resetPassword(token, password, confirmation),
        onSuccess: () => {
            setPassword('');
            setConfirmation('');
            // Il token non resta nella barra degli indirizzi né nella cronologia.
            window.history.replaceState(null, '', window.location.pathname);
        },
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        reset.mutate();
    };

    const errors = fieldErrors(reset.error);

    if (reset.isSuccess) {
        return (
            <AuthCard title={t('password.resetTitle')}>
                <p className="rounded-control bg-green-50 px-3 py-2 text-sm text-green-800" role="status" data-testid="reset-done">
                    {t('password.resetDone')}
                </p>
                <p className="mt-6 text-center text-sm">
                    <Link to="/login" className="font-medium text-brand-800 hover:underline">
                        {t('password.goToLogin')}
                    </Link>
                </p>
            </AuthCard>
        );
    }

    return (
        <AuthCard title={t('password.resetTitle')}>
            {token === '' ? (
                <FormError message={t('errors.PASSWORD_RESET_INVALID')} />
            ) : (
                <form className="space-y-4" onSubmit={submit} noValidate>
                    <p className="text-sm text-muted">{t('password.rules')}</p>
                    <FormError message={reset.isError ? errorMessage(reset.error) : null} />
                    <label className="block text-sm font-medium text-ink">
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
                        <p key={message} className="text-sm text-danger">
                            {message}
                        </p>
                    ))}
                    <label className="block text-sm font-medium text-ink">
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
                    <button className={buttonClass} type="submit" disabled={reset.isPending}>
                        {reset.isPending ? t('common.wait') : t('password.resetSubmit')}
                    </button>
                </form>
            )}
            <p className="mt-6 text-center text-sm">
                <Link to="/password-dimenticata" className="font-medium text-brand-800 hover:underline">
                    {t('password.requestNewLink')}
                </Link>
            </p>
        </AuthCard>
    );
}
