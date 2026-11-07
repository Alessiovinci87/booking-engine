import { useInfiniteQuery } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';

import { RESERVATION_STATUSES, type ReservationFilters, type ReservationStatus, reservationsApi } from '../api/reservations';
import { useErrorMessage } from '../auth/session';
import { EmptyState, FormError, inputClass, Loading, primaryButton, secondaryButton } from '../components/Form';
import { StatusBadge, UnitSelect, useAllUnits } from '../components/Reservations';
import { formatDate } from '../dates';
import { formatMoney } from '../money';

/** Elenco delle prenotazioni (3.8) con filtri per stato, periodo, unità e codice; filtri nell'URL. */
export function ReservationsPage() {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const [params, setParams] = useSearchParams();
    const { options } = useAllUnits();
    const filters: ReservationFilters = {
        status: params.getAll('status') as ReservationStatus[],
        from: params.get('from') ?? undefined,
        to: params.get('to') ?? undefined,
        unit_id: params.get('unit') ?? undefined,
        q: params.get('q') ?? undefined,
    };
    const list = useInfiniteQuery({
        queryKey: ['reservations', filters],
        queryFn: ({ pageParam }) => reservationsApi.list({ ...filters, cursor: pageParam ?? undefined }),
        initialPageParam: null as string | null,
        getNextPageParam: (last) => last.next_cursor,
    });
    const rows = list.data?.pages.flatMap((page) => page.data) ?? [];

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <h1 className="text-2xl font-semibold text-ink">{t('reservations.title')}</h1>
                <Link to="/prenotazioni/nuova" className={primaryButton} data-testid="new-reservation">
                    {t('reservations.new')}
                </Link>
            </div>

            <Filters key={params.toString()} initial={filters} options={options} onApply={setParams} />

            {list.isError && <FormError message={errorMessage(list.error)} />}
            {list.isPending ? (
                <Loading />
            ) : rows.length === 0 ? (
                <EmptyState title={t('reservations.empty')} testId="reservations-empty" />
            ) : (
                <div className="overflow-x-auto rounded-card border border-line-soft bg-surface shadow-card">
                    <table className="min-w-full divide-y divide-line-soft text-sm" data-testid="reservations-table">
                        <thead className="bg-canvas text-left text-xs uppercase tracking-wide text-muted">
                            <tr>
                                <th className="px-3 py-2">{t('reservations.code')}</th>
                                <th className="px-3 py-2">{t('reservations.guest')}</th>
                                <th className="px-3 py-2">{t('reservations.unit')}</th>
                                <th className="px-3 py-2">{t('reservations.stay')}</th>
                                <th className="px-3 py-2">{t('reservations.status')}</th>
                                <th className="px-3 py-2">{t('reservations.economic')}</th>
                                <th className="px-3 py-2 text-right">{t('reservations.total')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((r) => (
                                <tr key={r.id} className="hover:bg-canvas">
                                    <td className="px-3 py-2 font-mono">
                                        <Link to={`/prenotazioni/${r.id}`} className="text-brand-800 hover:underline">
                                            {r.code}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2">{`${r.guest.first_name} ${r.guest.last_name}`}</td>
                                    <td className="px-3 py-2">{r.unit.name}</td>
                                    <td className="whitespace-nowrap px-3 py-2">
                                        {`${formatDate(r.check_in)} → ${formatDate(r.check_out)}`}
                                        <span className="block text-xs text-muted">{t('reservations.nights', { count: r.nights })}</span>
                                    </td>
                                    <td className="px-3 py-2">
                                        <StatusBadge status={r.status} />
                                    </td>
                                    <td className="px-3 py-2 text-ink">{t(`economicStatus.${r.economic_status}`)}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{formatMoney(r.contract_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {list.hasNextPage && (
                <button type="button" className={secondaryButton} onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>
                    {t('reservations.loadMore')}
                </button>
            )}
        </main>
    );
}

function Filters({
    initial,
    options,
    onApply,
}: {
    initial: ReservationFilters;
    options: ReturnType<typeof useAllUnits>['options'];
    onApply: (params: URLSearchParams) => void;
}) {
    const { t } = useTranslation();
    const [statuses, setStatuses] = useState<ReservationStatus[]>(initial.status ?? []);
    const [from, setFrom] = useState(initial.from ?? '');
    const [to, setTo] = useState(initial.to ?? '');
    const [unit, setUnit] = useState(initial.unit_id ?? '');
    const [q, setQ] = useState(initial.q ?? '');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const next = new URLSearchParams();
        statuses.forEach((s) => next.append('status', s));

        for (const [key, value] of [['from', from], ['to', to], ['unit', unit], ['q', q.trim()]] as const) {
            if (value !== '') {
                next.set(key, value);
            }
        }

        onApply(next);
    };

    const toggle = (status: ReservationStatus) =>
        setStatuses((current) => (current.includes(status) ? current.filter((s) => s !== status) : [...current, status]));

    return (
        <form onSubmit={submit} className="space-y-3 rounded-card border border-line-soft bg-surface p-4 shadow-card" data-testid="reservation-filters">
            <fieldset>
                <legend className="text-sm font-medium text-ink">{t('reservations.filterStatus')}</legend>
                <div className="mt-2 flex flex-wrap gap-2">
                    {RESERVATION_STATUSES.map((status) => (
                        <label key={status} className="flex items-center gap-1.5 rounded-full border border-line px-2.5 py-1 text-xs text-ink">
                            <input type="checkbox" checked={statuses.includes(status)} onChange={() => toggle(status)} />
                            {t(`reservationStatus.${status}`)}
                        </label>
                    ))}
                </div>
            </fieldset>
            <div className="grid gap-3 sm:grid-cols-4">
                <label className="text-sm font-medium text-ink">
                    {t('reservations.filterFrom')}
                    <input type="date" className={inputClass} value={from} onChange={(e) => setFrom(e.target.value)} />
                </label>
                <label className="text-sm font-medium text-ink">
                    {t('reservations.filterTo')}
                    <input type="date" className={inputClass} value={to} onChange={(e) => setTo(e.target.value)} />
                </label>
                <label className="text-sm font-medium text-ink">
                    {t('reservations.unit')}
                    <UnitSelect options={options} value={unit} onChange={setUnit} allowEmpty className={inputClass} />
                </label>
                <label className="text-sm font-medium text-ink">
                    {t('reservations.filterCode')}
                    <input className={inputClass} value={q} onChange={(e) => setQ(e.target.value)} placeholder="K7Q2MZ4A" />
                </label>
            </div>
            <div className="flex gap-2">
                <button type="submit" className={primaryButton}>
                    {t('reservations.applyFilters')}
                </button>
                <button type="button" className={secondaryButton} onClick={() => onApply(new URLSearchParams())}>
                    {t('reservations.clearFilters')}
                </button>
            </div>
        </form>
    );
}
