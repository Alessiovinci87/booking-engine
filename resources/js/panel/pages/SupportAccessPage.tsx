import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { supportApi, type SupportAccessGrant } from '../api/support';
import { useErrorMessage } from '../auth/session';
import { dangerButton, FormError, secondaryButton } from '../components/Form';
import { ReauthPrompt, needsReauth } from '../components/Reauth';
import { Badge, EmptyState, Skeleton, type Tone } from '../../shared/ui';

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
            <main className="mx-auto max-w-5xl px-4 py-6">
                <Skeleton label={t('support.loading')} />
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
                <h1 className="text-xl font-semibold text-ink">{t('support.title')}</h1>
                <p className="mt-1 text-sm text-muted">{t('support.intro')}</p>
            </div>
            {items.length === 0 ? (
                <EmptyState title={t('support.empty')} testId="support-access-empty" />
            ) : (
                <ul className="divide-y divide-line-soft rounded-control border border-line-soft bg-surface text-sm" data-testid="support-access-list">
                    {items.map((grant) => (
                        <GrantRow key={grant.id} grant={grant} />
                    ))}
                </ul>
            )}
        </main>
    );
}

const statusTone: Record<string, Tone> = { active: 'warning', revoked_by_member: 'neutral', revoked: 'neutral', expired: 'neutral' };

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
                    <dt className="text-muted">{t('support.status')}</dt>
                    <dd>
                        <Badge tone={statusTone[status]} testId="support-grant-status">
                            {t(`support.${status}`)}
                        </Badge>
                    </dd>
                    <dt className="text-muted">{t('support.operator')}</dt>
                    <dd>{grant.operator_email ?? '—'}</dd>
                    <dt className="text-muted">{t('support.reason')}</dt>
                    <dd>{grant.reason}</dd>
                    <dt className="text-muted">{t('support.opened')}</dt>
                    <dd>{dateTime(grant.created_at)}</dd>
                    <dt className="text-muted">{t('support.expires')}</dt>
                    <dd>{dateTime(grant.revoked_at ?? grant.expires_at)}</dd>
                    <dt className="text-muted">{t('support.used')}</dt>
                    <dd>{grant.first_used_at === null ? t('support.never_used') : `${dateTime(grant.first_used_at)} – ${dateTime(grant.last_used_at)}`}</dd>
                </dl>
                {grant.active && !confirming && (
                    <button type="button" className={dangerButton} onClick={() => setConfirming(true)} data-testid="support-grant-revoke">
                        {t('support.revoke')}
                    </button>
                )}
            </div>
            {confirming && (
                <div className="space-y-3 rounded-control border border-red-200 bg-danger-soft p-3">
                    <p className="text-danger">{t('support.revoke_confirm')}</p>
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
