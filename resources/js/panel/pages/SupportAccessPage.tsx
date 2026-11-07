import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { supportApi, type SupportAccessGrant } from '../api/support';
import { useErrorMessage } from '../auth/session';
import { dangerButton, FormError, secondaryButton } from '../components/Form';
import { ReauthPrompt, needsReauth } from '../components/Reauth';

const dateTime = (iso: string | null) => (iso === null ? '—' : new Date(iso).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' }));

/**
 * Accessi di supporto (7.5; 08 "Accesso di supporto"): quando la piattaforma ha aperto il pannello in
 * sola lettura, con motivo, durata e letture; il gestore può chiudere l'accesso attivo.
 */
export function SupportAccessPage() {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const grants = useQuery({ queryKey: ['support-access'], queryFn: supportApi.list });

    if (grants.isPending) {
        return (
            <main className="mx-auto max-w-5xl px-4 py-6 text-sm text-slate-600" role="status">
                {t('support.loading')}
            </main>
        );
    }

    if (grants.isError) {
        return (
            <main className="mx-auto max-w-5xl px-4 py-6">
                <FormError message={errorMessage(grants.error)} />
            </main>
        );
    }

    const items = grants.data.data;

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-6" data-testid="support-access-page">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">{t('support.title')}</h1>
                <p className="mt-1 text-sm text-slate-600">{t('support.intro')}</p>
            </div>
            {items.length === 0 ? (
                <p className="text-sm text-slate-500" data-testid="support-access-empty">
                    {t('support.empty')}
                </p>
            ) : (
                <ul className="divide-y divide-slate-200 rounded-md border border-slate-200 bg-white text-sm" data-testid="support-access-list">
                    {items.map((grant) => (
                        <GrantRow key={grant.id} grant={grant} />
                    ))}
                </ul>
            )}
        </main>
    );
}

function GrantRow({ grant }: { grant: SupportAccessGrant }) {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const queryClient = useQueryClient();
    const [confirming, setConfirming] = useState(false);
    const revoke = useMutation({
        mutationFn: () => supportApi.revoke(grant.id),
        onSuccess: () => {
            setConfirming(false);
            void queryClient.invalidateQueries({ queryKey: ['support-access'] });
        },
    });
    const status = grant.active ? 'active' : grant.revoked_at !== null ? (grant.revoked_by_member ? 'revoked_by_member' : 'revoked') : 'expired';

    return (
        <li className="space-y-2 p-3" data-testid={`support-grant-${grant.id}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                    <dt className="text-slate-500">{t('support.status')}</dt>
                    <dd className="font-medium text-slate-900" data-testid="support-grant-status">
                        {t(`support.${status}`)}
                    </dd>
                    <dt className="text-slate-500">{t('support.operator')}</dt>
                    <dd>{grant.operator_email ?? '—'}</dd>
                    <dt className="text-slate-500">{t('support.reason')}</dt>
                    <dd>{grant.reason}</dd>
                    <dt className="text-slate-500">{t('support.opened')}</dt>
                    <dd>{dateTime(grant.created_at)}</dd>
                    <dt className="text-slate-500">{t('support.expires')}</dt>
                    <dd>{dateTime(grant.revoked_at ?? grant.expires_at)}</dd>
                    <dt className="text-slate-500">{t('support.used')}</dt>
                    <dd>{grant.first_used_at === null ? t('support.never_used') : `${dateTime(grant.first_used_at)} – ${dateTime(grant.last_used_at)}`}</dd>
                </dl>
                {grant.active && !confirming && (
                    <button type="button" className={dangerButton} onClick={() => setConfirming(true)} data-testid="support-grant-revoke">
                        {t('support.revoke')}
                    </button>
                )}
            </div>
            {confirming && (
                <div className="space-y-3 rounded-md border border-red-200 bg-red-50 p-3">
                    <p className="text-red-900">{t('support.revoke_confirm')}</p>
                    <div className="flex gap-3">
                        <button type="button" className={dangerButton} disabled={revoke.isPending} onClick={() => revoke.mutate()} data-testid="support-grant-revoke-confirm">
                            {t('support.revoke')}
                        </button>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => {
                                setConfirming(false);
                                revoke.reset();
                            }}
                        >
                            {t('common.cancel')}
                        </button>
                    </div>
                </div>
            )}
            {revoke.isError &&
                (needsReauth(revoke.error) ? (
                    <ReauthPrompt onConfirmed={() => revoke.mutate()} onCancel={() => revoke.reset()} />
                ) : (
                    <FormError message={errorMessage(revoke.error)} />
                ))}
        </li>
    );
}
