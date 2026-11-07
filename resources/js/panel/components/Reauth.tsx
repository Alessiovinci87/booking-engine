import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError, api } from '../api/client';
import { useErrorMessage } from '../auth/session';
import { FormError, inputClass, primaryButton, secondaryButton } from './Form';

/** true se l'azione è stata respinta perché serve confermare la password (08). */
export function needsReauth(error: unknown): boolean {
    return error instanceof ApiError && error.code === 'REAUTH_REQUIRED';
}

/**
 * Richiesta della password per le azioni sensibili (08: rimborsi, account Stripe): dopo la conferma
 * l'azione respinta con REAUTH_REQUIRED si ripete da sola.
 */
export function ReauthPrompt({ onConfirmed, onCancel }: { onConfirmed: () => void; onCancel: () => void }) {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const [password, setPassword] = useState('');
    const confirm = useMutation({ mutationFn: () => api.confirmPassword(password), onSuccess: onConfirmed });

    return (
        <form
            className="space-y-2 rounded-control border border-amber-300 bg-warning-soft p-3 text-sm"
            data-testid="reauth"
            onSubmit={(event) => {
                event.preventDefault();
                confirm.mutate();
            }}
        >
            <p className="text-warning">{t('reauth.hint')}</p>
            <label className="block text-ink">
                {t('login.password')}
                <input type="password" autoComplete="current-password" className={`${inputClass} mt-1`} value={password} onChange={(e) => setPassword(e.target.value)} required />
            </label>
            {confirm.isError && <FormError message={errorMessage(confirm.error)} />}
            <div className="flex gap-2">
                <button type="submit" className={primaryButton} disabled={confirm.isPending || password === ''}>
                    {t('reauth.confirm')}
                </button>
                <button type="button" className={secondaryButton} onClick={onCancel}>
                    {t('common.cancel')}
                </button>
            </div>
        </form>
    );
}
