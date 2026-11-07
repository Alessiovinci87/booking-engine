import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { offboardingApi, type OffboardingFeed, type TenantExport } from '../api/offboarding';
import { useErrorMessage } from '../auth/session';
import { dangerButton, EmptyState, FormError, Loading, primaryButton, secondaryButton, Section } from '../components/Form';
import { ProtectedDownload } from '../components/ProtectedDownload';
import { ReauthPrompt, needsReauth } from '../components/Reauth';

const dateTime = (iso: string | null) => (iso === null ? '—' : new Date(iso).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' }));

/**
 * Uscita dalla piattaforma (7.1; 06, 09 "Uscita del cliente"): avvio con conferma, export completo
 * (scaricabile per 30 giorni), conferma per ogni feed della rimozione dall'OTA con l'ultima lettura.
 * La cancellazione definitiva dei dati la esegue la piattaforma secondo il DPA.
 */
export function OffboardingPage() {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const queryClient = useQueryClient();
    const overview = useQuery({
        queryKey: ['offboarding'],
        queryFn: offboardingApi.show,
        refetchInterval: (query) => (query.state.data?.data.exports?.some((e) => e.status === 'PENDING') ? 5_000 : false),
    });
    const refresh = () => {
        void queryClient.invalidateQueries({ queryKey: ['offboarding'] });
        void queryClient.invalidateQueries({ queryKey: ['me'] });
    };
    const [confirmingStart, setConfirmingStart] = useState(false);
    const start = useMutation({
        mutationFn: offboardingApi.start,
        onSuccess: () => {
            setConfirmingStart(false);
            refresh();
        },
    });
    const requestExport = useMutation({ mutationFn: offboardingApi.requestExport, onSuccess: refresh });

    if (overview.isPending) {
        return (
            <Loading page />
        );
    }

    if (overview.isError) {
        return (
            <main className="mx-auto max-w-5xl px-4 py-6">
                <FormError message={errorMessage(overview.error)} />
            </main>
        );
    }

    const data = overview.data.data;
    const offboarding = data.status === 'OFFBOARDING';
    const closed = data.status === 'CLOSED';
    const exports = data.exports ?? [];

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-6" data-testid="offboarding-page">
            <div>
                <h1 className="text-xl font-semibold text-ink">{t('offboarding.title')}</h1>
                <p className="mt-1 text-sm text-muted">{t('offboarding.intro')}</p>
            </div>

            <Section title={t('offboarding.statusTitle')}>
                <div className="space-y-3 text-sm">
                    <p data-testid="offboarding-status">{t(`offboarding.status.${data.status}`)}</p>
                    {offboarding && data.auto_retire_from && <p className="text-muted">{t('offboarding.autoRetire', { date: dateTime(data.auto_retire_from) })}</p>}
                    {data.feeds_disabled_at && <p className="text-muted">{t('offboarding.feedsDisabled', { date: dateTime(data.feeds_disabled_at) })}</p>}
                    {!offboarding && !closed && !confirmingStart && (
                        <button type="button" className={dangerButton} onClick={() => setConfirmingStart(true)} data-testid="offboarding-start">
                            {t('offboarding.start')}
                        </button>
                    )}
                    {confirmingStart && (
                        <div className="space-y-3 rounded-control border border-red-200 bg-danger-soft p-3">
                            <p className="text-danger">{t('offboarding.startConfirm')}</p>
                            <div className="flex gap-3">
                                <button type="button" className={dangerButton} disabled={start.isPending} onClick={() => start.mutate()} data-testid="offboarding-start-confirm">
                                    {t('offboarding.start')}
                                </button>
                                <button
                                    type="button"
                                    className={secondaryButton}
                                    onClick={() => {
                                        setConfirmingStart(false);
                                        start.reset();
                                    }}
                                >
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </div>
                    )}
                    {start.isError &&
                        (needsReauth(start.error) ? (
                            <ReauthPrompt onConfirmed={() => start.mutate()} onCancel={() => start.reset()} />
                        ) : (
                            <FormError message={errorMessage(start.error)} />
                        ))}
                </div>
            </Section>

            <Section title={t('offboarding.exportTitle')}>
                <div className="space-y-3 text-sm">
                    <p className="text-muted">{t('offboarding.exportHint')}</p>
                    {!closed && (
                        <button type="button" className={primaryButton} disabled={requestExport.isPending} onClick={() => requestExport.mutate()} data-testid="offboarding-export">
                            {requestExport.isPending ? t('common.wait') : t('offboarding.exportRequest')}
                        </button>
                    )}
                    {requestExport.isError &&
                        (needsReauth(requestExport.error) ? (
                            <ReauthPrompt onConfirmed={() => requestExport.mutate()} onCancel={() => requestExport.reset()} />
                        ) : (
                            <FormError message={errorMessage(requestExport.error)} />
                        ))}
                    {exports.length === 0 ? (
                        <EmptyState title={t('offboarding.noExports')} />
                    ) : (
                        <ul className="divide-y divide-line-soft rounded-control border border-line-soft" data-testid="offboarding-exports">
                            {exports.map((item) => (
                                <ExportRow key={item.id} item={item} />
                            ))}
                        </ul>
                    )}
                </div>
            </Section>

            <Section title={t('offboarding.feedsTitle')}>
                <div className="space-y-3 text-sm">
                    <p className="text-muted">{t('offboarding.feedsHint')}</p>
                    {data.feeds.length === 0 ? (
                        <EmptyState title={t('offboarding.noFeeds')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left" data-testid="offboarding-feeds">
                                <thead className="text-xs uppercase text-muted">
                                    <tr>
                                        <th className="py-2 pr-4">{t('offboarding.feedUnit')}</th>
                                        <th className="py-2 pr-4">{t('offboarding.feedDestination')}</th>
                                        <th className="py-2 pr-4">{t('offboarding.feedStatus')}</th>
                                        <th className="py-2 pr-4">{t('offboarding.feedLastRead')}</th>
                                        <th className="py-2">
                                            <span className="sr-only">{t('offboarding.feedActions')}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line-soft">
                                    {data.feeds.map((feed) => (
                                        <FeedRow key={feed.id} feed={feed} canConfirm={offboarding} onDone={refresh} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </Section>
        </main>
    );
}

function ExportRow({ item }: { item: TenantExport }) {
    const { t } = useTranslation();

    return (
        <li className="flex flex-wrap items-center justify-between gap-3 p-3" data-testid={`export-${item.status}`}>
            <div>
                <p className="font-medium text-ink">{t(`offboarding.exportStatus.${item.status}`)}</p>
                <p className="text-xs text-muted">
                    {t('offboarding.exportRequestedAt', { date: dateTime(item.requested_at) })}
                    {item.expires_at && item.status === 'READY' && ` · ${t('offboarding.exportExpiresAt', { date: dateTime(item.expires_at) })}`}
                    {item.future_reservations !== null && ` · ${t('offboarding.exportFuture', { count: item.future_reservations })}`}
                </p>
            </div>
            {item.download_path && <ProtectedDownload path={item.download_path} fallbackName="export.zip" label={t('offboarding.exportDownload')} testId="offboarding-export-download" />}
        </li>
    );
}

function FeedRow({ feed, canConfirm, onDone }: { feed: OffboardingFeed; canConfirm: boolean; onDone: () => void }) {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const [confirming, setConfirming] = useState(false);
    const confirm = useMutation({
        mutationFn: () => offboardingApi.confirmRemoved(feed.id),
        onSuccess: () => {
            setConfirming(false);
            onDone();
        },
    });
    const pending = canConfirm && feed.status !== 'RETIRED' && !feed.rotated;

    return (
        <tr data-testid={`feed-${feed.id}`}>
            <td className="py-2 pr-4">{feed.unit_name ?? '—'}</td>
            <td className="py-2 pr-4">{t(`channelSource.${feed.destination}`, { defaultValue: feed.destination })}</td>
            <td className="py-2 pr-4">{t(`offboarding.feedState.${feed.status}`)}</td>
            <td className="py-2 pr-4">
                {dateTime(feed.last_fetched_at)}
                {feed.read_recently && feed.status !== 'RETIRED' && <span className="ml-2 text-xs text-warning">{t('offboarding.readRecently')}</span>}
            </td>
            <td className="py-2 text-right">
                {pending && !confirming && (
                    <button type="button" className={secondaryButton} onClick={() => setConfirming(true)} data-testid="feed-confirm-removed">
                        {t('offboarding.confirmRemoved')}
                    </button>
                )}
                {confirming && (
                    <div className="space-y-2 text-left">
                        <p className="text-sm text-ink">{t('offboarding.confirmRemovedQuestion')}</p>
                        <div className="flex gap-2">
                            <button type="button" className={dangerButton} disabled={confirm.isPending} onClick={() => confirm.mutate()} data-testid="feed-confirm-removed-yes">
                                {t('offboarding.confirmRemovedYes')}
                            </button>
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() => {
                                    setConfirming(false);
                                    confirm.reset();
                                }}
                            >
                                {t('common.cancel')}
                            </button>
                        </div>
                    </div>
                )}
                {confirm.isError &&
                    (needsReauth(confirm.error) ? (
                        <ReauthPrompt onConfirmed={() => confirm.mutate()} onCancel={() => confirm.reset()} />
                    ) : (
                        <FormError message={errorMessage(confirm.error)} />
                    ))}
            </td>
        </tr>
    );
}
