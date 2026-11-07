import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { api } from '../api/client';
import { sessionQueryKey, useErrorMessage } from '../auth/session';
import { AuthCard, buttonClass, FormError, inputClass } from '../components/AuthCard';
import { Loading } from '../components/Form';

/** QR generato dal server (SVG locale): mostrato come immagine, mai inserito come HTML. */
function svgDataUri(svg: string): string {
    let binary = '';
    new TextEncoder().encode(svg).forEach((byte) => {
        binary += String.fromCharCode(byte);
    });

    return `data:image/svg+xml;base64,${btoa(binary)}`;
}

export function MfaSetupPage() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const [code, setCode] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [saved, setSaved] = useState(false);

    // Il segreto cambia a ogni richiesta: nessuna cache e nessun nuovo tentativo automatico.
    const setup = useQuery({
        queryKey: ['auth', 'mfa-setup'],
        queryFn: api.mfaSetup,
        enabled: recoveryCodes === null,
        staleTime: Infinity,
        gcTime: 0,
        retry: false,
        refetchOnWindowFocus: false,
    });

    const confirm = useMutation({
        mutationFn: () => api.mfaConfirm(code),
        onSuccess: (data) => {
            setCode('');
            setRecoveryCodes(data.recovery_codes);
        },
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        confirm.mutate();
    };

    if (recoveryCodes !== null) {
        return (
            <AuthCard title={t('mfaSetup.recoveryTitle')}>
                <p className="text-sm text-ink">{t('mfaSetup.recoveryIntro')}</p>
                <ul className="mt-4 grid grid-cols-2 gap-2 rounded-control bg-surface-muted p-4 font-mono text-sm text-ink">
                    {recoveryCodes.map((recovery) => (
                        <li key={recovery}>{recovery}</li>
                    ))}
                </ul>
                <label className="mt-4 flex items-center gap-2 text-sm text-ink">
                    <input type="checkbox" checked={saved} onChange={(e) => setSaved(e.target.checked)} />
                    {t('mfaSetup.recoverySaved')}
                </label>
                <button
                    className={`${buttonClass} mt-4`}
                    type="button"
                    disabled={!saved}
                    onClick={() => void queryClient.invalidateQueries({ queryKey: sessionQueryKey })}
                >
                    {t('mfaSetup.continue')}
                </button>
            </AuthCard>
        );
    }

    return (
        <AuthCard title={t('mfaSetup.title')}>
            <ol className="list-decimal space-y-2 pl-5 text-sm text-ink">
                <li>{t('mfaSetup.step1')}</li>
                <li>{t('mfaSetup.step2')}</li>
                <li>{t('mfaSetup.step3')}</li>
            </ol>

            {setup.isPending && (
                <Loading />
            )}
            {setup.isError && (
                <div className="mt-6">
                    <FormError message={errorMessage(setup.error)} />
                </div>
            )}
            {setup.data && (
                <div className="mt-6 flex flex-col items-center">
                    <img className="h-48 w-48" src={svgDataUri(setup.data.qr_svg)} alt={t('mfaSetup.qrAlt')} />
                    <p className="mt-3 text-xs text-muted">{t('mfaSetup.manualKey')}</p>
                    <code className="mt-1 break-all rounded bg-surface-muted px-2 py-1 text-sm text-ink">
                        {setup.data.secret}
                    </code>
                </div>
            )}

            <form className="mt-6 space-y-4" onSubmit={submit} noValidate>
                <FormError message={confirm.isError ? errorMessage(confirm.error) : null} />
                <label className="block text-sm font-medium text-ink">
                    {t('mfa.code')}
                    <input
                        className={inputClass}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={6}
                        pattern="[0-9]{6}"
                        required
                        value={code}
                        onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                    />
                </label>
                <button className={buttonClass} type="submit" disabled={confirm.isPending || !setup.data}>
                    {confirm.isPending ? t('common.wait') : t('mfaSetup.submit')}
                </button>
            </form>
        </AuthCard>
    );
}
