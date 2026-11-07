import { useMutation, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { api } from '../api/client';
import { sessionQueryKey, useErrorMessage } from '../auth/session';
import { AuthCard, buttonClass, FormError, inputClass } from '../components/AuthCard';
import { LogoutButton } from '../components/LogoutButton';

export function MfaVerifyPage() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const [useRecovery, setUseRecovery] = useState(false);
    const [value, setValue] = useState('');

    const verify = useMutation({
        mutationFn: () => api.mfaVerify(useRecovery ? { recovery_code: value } : { code: value }),
        onSuccess: () => {
            setValue('');
            void queryClient.invalidateQueries({ queryKey: sessionQueryKey });
        },
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        verify.mutate();
    };

    const toggle = () => {
        setUseRecovery((current) => !current);
        setValue('');
        verify.reset();
    };

    return (
        <AuthCard title={t('mfaVerify.title')}>
            <p className="text-sm text-ink">{useRecovery ? t('mfaVerify.recoveryIntro') : t('mfaVerify.intro')}</p>
            <form className="mt-6 space-y-4" onSubmit={submit} noValidate>
                <FormError message={verify.isError ? errorMessage(verify.error) : null} />
                <label className="block text-sm font-medium text-ink">
                    {useRecovery ? t('mfa.recoveryCode') : t('mfa.code')}
                    {useRecovery ? (
                        <input
                            className={inputClass}
                            autoComplete="off"
                            maxLength={32}
                            required
                            value={value}
                            onChange={(e) => setValue(e.target.value)}
                        />
                    ) : (
                        <input
                            className={inputClass}
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            pattern="[0-9]{6}"
                            required
                            value={value}
                            onChange={(e) => setValue(e.target.value.replace(/\D/g, ''))}
                        />
                    )}
                </label>
                <button className={buttonClass} type="submit" disabled={verify.isPending}>
                    {verify.isPending ? t('common.wait') : t('mfaVerify.submit')}
                </button>
            </form>
            <div className="mt-4 flex items-center justify-between text-sm">
                <button type="button" className="text-brand-800 underline" onClick={toggle}>
                    {useRecovery ? t('mfaVerify.useTotp') : t('mfaVerify.useRecovery')}
                </button>
                <LogoutButton className="text-muted underline" />
            </div>
        </AuthCard>
    );
}
