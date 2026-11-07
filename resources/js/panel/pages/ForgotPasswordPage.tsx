import { useMutation } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { api } from '../api/client';
import { useErrorMessage } from '../auth/session';
import { AuthCard, buttonClass, FormError, inputClass } from '../components/AuthCard';

/**
 * Password dimenticata (6.11): la risposta del server è sempre la stessa, che l'email esista o no;
 * anche il messaggio mostrato qui non dice se l'account esiste.
 */
export function ForgotPasswordPage() {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const [email, setEmail] = useState('');

    const request = useMutation({ mutationFn: () => api.forgotPassword(email) });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        request.mutate();
    };

    return (
        <AuthCard title={t('password.forgotTitle')}>
            {request.isSuccess ? (
                <p className="rounded-md bg-green-50 px-3 py-2 text-sm text-green-800" role="status" data-testid="forgot-sent">
                    {t('password.forgotSent')}
                </p>
            ) : (
                <form className="space-y-4" onSubmit={submit} noValidate>
                    <p className="text-sm text-slate-600">{t('password.forgotIntro')}</p>
                    <FormError message={request.isError ? errorMessage(request.error) : null} />
                    <label className="block text-sm font-medium text-slate-700">
                        {t('login.email')}
                        <input
                            className={inputClass}
                            type="email"
                            autoComplete="username"
                            required
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                        />
                    </label>
                    <button className={buttonClass} type="submit" disabled={request.isPending}>
                        {request.isPending ? t('common.wait') : t('password.forgotSubmit')}
                    </button>
                </form>
            )}
            <p className="mt-6 text-center text-sm">
                <Link to="/login" className="font-medium text-blue-700 hover:underline">
                    {t('password.backToLogin')}
                </Link>
            </p>
        </AuthCard>
    );
}
