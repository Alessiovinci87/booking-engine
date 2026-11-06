import { useMutation, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { api } from '../api/client';
import { sessionQueryKey, useErrorMessage } from '../auth/session';
import { AuthCard, buttonClass, FormError, inputClass } from '../components/AuthCard';

export function LoginPage() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');

    const login = useMutation({
        mutationFn: () => api.login(email, password),
        onSuccess: () => {
            setPassword('');
            void queryClient.invalidateQueries({ queryKey: sessionQueryKey });
        },
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        login.mutate();
    };

    return (
        <AuthCard title={t('login.title')}>
            <form className="space-y-4" onSubmit={submit} noValidate>
                <FormError message={login.isError ? errorMessage(login.error) : null} />
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
                <label className="block text-sm font-medium text-slate-700">
                    {t('login.password')}
                    <input
                        className={inputClass}
                        type="password"
                        autoComplete="current-password"
                        required
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                    />
                </label>
                <button className={buttonClass} type="submit" disabled={login.isPending}>
                    {login.isPending ? t('common.wait') : t('login.submit')}
                </button>
            </form>
        </AuthCard>
    );
}
