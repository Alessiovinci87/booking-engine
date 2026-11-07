import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { metricsApi } from '../api/metrics';
import { useErrorMessage } from '../auth/session';
import { Field, FormError, inputClass, Section } from '../components/Form';
import { ProtectedDownload } from '../components/ProtectedDownload';
import { today } from '../dates';
import { formatMoney } from '../money';

function monthBounds(date: string): { from: string; to: string } {
    const [year, month] = date.split('-').map((part) => Number.parseInt(part, 10));
    const last = new Date(Date.UTC(year ?? 2000, month ?? 1, 0)).getUTCDate();
    const prefix = date.slice(0, 7);

    return { from: `${prefix}-01`, to: `${prefix}-${String(last).padStart(2, '0')}` };
}

/** Basis point → percentuale leggibile ("15%", "12,5%"). */
function percent(bps: number): string {
    return `${(bps / 100).toLocaleString('it-IT', { maximumFractionDigits: 2 })}%`;
}

/**
 * Metriche (6.2): fatturato diretto, commissioni OTA teoricamente evitate e beneficio diretto stimato
 * del periodo, con la percentuale OTA usata per ogni struttura e la nota di attendibilità (01); export
 * CSV delle prenotazioni con l'anagrafica.
 */
export function MetricsPage() {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const [period, setPeriod] = useState(() => monthBounds(today()));
    const valid = period.from !== '' && period.to !== '' && period.from.localeCompare(period.to) !== 1;
    const metrics = useQuery({ queryKey: ['metrics', period.from, period.to], queryFn: () => metricsApi.get(period.from, period.to), enabled: valid });
    const data = metrics.data?.data;
    const money = (cents: number) => formatMoney({ amount_cents: cents, currency: data?.currency ?? 'EUR' });

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <h1 className="text-2xl font-semibold text-ink">{t('metrics.title')}</h1>

            <Section
                title={t('metrics.period')}
                actions={
                    valid ? (
                        <ProtectedDownload
                            path={metricsApi.csvPath(period.from, period.to)}
                            fallbackName={`prenotazioni-${period.from}-${period.to}.csv`}
                            label={t('metrics.csv')}
                            testId="metrics-csv"
                        />
                    ) : undefined
                }
            >
                <div className="flex flex-wrap gap-4">
                    <Field label={t('metrics.from')}>
                        <input type="date" className={inputClass} value={period.from} onChange={(e) => setPeriod({ ...period, from: e.target.value })} name="from" />
                    </Field>
                    <Field label={t('metrics.to')}>
                        <input type="date" className={inputClass} value={period.to} onChange={(e) => setPeriod({ ...period, to: e.target.value })} name="to" />
                    </Field>
                </div>
                {!valid && <FormError message={t('metrics.invalidPeriod')} />}
                <p className="mt-2 text-sm text-muted">{t('metrics.csvHint')}</p>
            </Section>

            {metrics.isError && <FormError message={errorMessage(metrics.error)} />}
            {valid && data === undefined && !metrics.isError && <p className="text-muted">{t('common.loading')}</p>}

            {data !== undefined && (
                <>
                    <section className="grid gap-4 sm:grid-cols-3" data-testid="metrics-summary">
                        <Metric label={t('metrics.directRevenue')} value={money(data.direct_revenue_cents)} testId="metric-revenue" hint={t('metrics.reservationsCount', { count: data.reservations, nights: data.nights })} />
                        <Metric label={t('metrics.otaAvoided')} value={money(data.ota_commission_avoided_cents)} testId="metric-avoided" hint={t('metrics.otaAvoidedHint')} />
                        <Metric label={t('metrics.benefit')} value={money(data.estimated_direct_benefit_cents)} testId="metric-benefit" hint={t('metrics.benefitHint')} />
                    </section>

                    <Section title={t('metrics.breakdown')}>
                        <dl className="grid grid-cols-[1fr_auto] gap-x-6 gap-y-2 text-sm">
                            <dt>{t('metrics.otaAvoided')}</dt>
                            <dd className="text-right">{money(data.ota_commission_avoided_cents)}</dd>
                            <dt>
                                {t('metrics.paymentFees')}
                                {data.payment_fees_estimated && <span className="ml-1 text-amber-700">({t('metrics.estimated')})</span>}
                            </dt>
                            <dd className="text-right">− {money(data.payment_fees_cents)}</dd>
                            <dt>{t('metrics.discounts')}</dt>
                            <dd className="text-right">− {money(data.direct_discounts_cents)}</dd>
                            <dt className="font-semibold">{t('metrics.benefit')}</dt>
                            <dd className="text-right font-semibold">{money(data.estimated_direct_benefit_cents)}</dd>
                        </dl>
                        <p className="mt-4 text-sm text-muted" data-testid="metrics-reliability">
                            {t('metrics.reliability')}
                        </p>
                        {data.payment_fees_estimated && <p className="mt-2 text-sm text-amber-700">{t('metrics.feesEstimatedNote')}</p>}
                    </Section>

                    <Section title={t('metrics.byProperty')}>
                        <table className="w-full text-left text-sm">
                            <thead className="text-muted">
                                <tr>
                                    <th className="py-1">{t('metrics.property')}</th>
                                    <th className="py-1">{t('metrics.otaPercent')}</th>
                                    <th className="py-1 text-right">{t('metrics.reservations')}</th>
                                    <th className="py-1 text-right">{t('metrics.directRevenue')}</th>
                                    <th className="py-1 text-right">{t('metrics.otaAvoided')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.properties.map((p) => (
                                    <tr key={p.property_id} className="border-t border-slate-100" data-testid="metrics-property">
                                        <td className="py-1">{p.name}</td>
                                        <td className="py-1">{percent(p.ota_commission_bps)}</td>
                                        <td className="py-1 text-right">{p.reservations}</td>
                                        <td className="py-1 text-right">{money(p.direct_revenue_cents)}</td>
                                        <td className="py-1 text-right">{money(p.ota_commission_avoided_cents)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <p className="mt-2 text-sm text-muted">{t('metrics.otaPercentHint')}</p>
                    </Section>
                </>
            )}
        </main>
    );
}

function Metric({ label, value, hint, testId }: { label: string; value: string; hint: string; testId: string }) {
    return (
        <div className="rounded-card border border-line-soft bg-surface p-4" data-testid={testId}>
            <p className="text-sm text-muted">{label}</p>
            <p className="mt-1 text-2xl font-semibold text-ink">{value}</p>
            <p className="mt-1 text-xs text-muted">{hint}</p>
        </div>
    );
}
