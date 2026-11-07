import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';

import { calendarsApi, CHANNEL_SOURCES, type ChannelSource, type ExternalCalendar, type IcsFeed } from '../api/calendars';
import { ApiError, fieldErrors } from '../api/client';
import { useErrorMessage } from '../auth/session';
import {
    dangerButton,
    EmptyState,
    Field,
    FormError,
    inputClass,
    Loading,
    primaryButton,
    secondaryButton,
    Section,
    TextInput,
    useConfirm,
} from '../components/Form';
import { UnitSelect, useAllUnits } from '../components/Reservations';
import { formatDateTime } from '../dates';

const healthColors: Record<ExternalCalendar['health'], string> = {
    ACTIVE: 'bg-emerald-100 text-emerald-900 border-emerald-300',
    DEGRADED: 'bg-amber-100 text-warning border-amber-300',
    UNHEALTHY: 'bg-red-100 text-danger border-red-300',
    DISABLED: 'bg-surface-muted text-muted border-line',
};

/**
 * Calendari dell'unità (06 "Topologia richiesta: a stella", 5.6–5.8): calendari importati dalle OTA con
 * stato della sincronizzazione e storico, feed esportati per destinazione (uno per OTA, mai un feed
 * generico) e checklist di collegamento con le conferme del gestore.
 */
export function CalendarsPage() {
    const { t } = useTranslation();
    const [params, setParams] = useSearchParams();
    const { options, isPending } = useAllUnits();
    const unit = params.get('unit') ?? options[0]?.unit.id ?? '';

    if (isPending) {
        return <Loading page />;
    }

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <h1 className="text-2xl font-semibold text-ink">{t('calendars.title')}</h1>
            <p className="text-sm text-muted">{t('calendars.intro')}</p>
            <div className="max-w-sm">
                <Field label={t('reservation.unit')}>
                    <UnitSelect options={options} value={unit} onChange={(id) => setParams({ unit: id })} className={inputClass} />
                </Field>
            </div>
            {unit !== '' && (
                <div className="space-y-6" key={unit}>
                    <ImportedCalendars unitId={unit} />
                    <ExportedFeeds unitId={unit} />
                    <ChecklistSection unitId={unit} />
                </div>
            )}
        </main>
    );
}

function ImportedCalendars({ unitId }: { unitId: string }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const calendars = useQuery({ queryKey: ['external-calendars', unitId], queryFn: () => calendarsApi.calendars(unitId) });
    const [source, setSource] = useState<ChannelSource>('BOOKING');
    const [url, setUrl] = useState('');
    const [switchToRequest, setSwitchToRequest] = useState(false);
    const refresh = () => {
        void queryClient.invalidateQueries({ queryKey: ['external-calendars', unitId] });
        void queryClient.invalidateQueries({ queryKey: ['calendar-checklist', unitId] });
        void queryClient.invalidateQueries({ queryKey: ['ics-feeds', unitId] });
    };
    const add = useMutation({
        mutationFn: () => calendarsApi.addCalendar(unitId, { source, url: url.trim(), switch_to_request: switchToRequest }),
        onSuccess: () => {
            setUrl('');
            setSwitchToRequest(false);
            refresh();
        },
    });
    const needsSwitch = add.error instanceof ApiError && add.error.code === 'UNIT_IS_INSTANT';
    const reason = add.error instanceof ApiError && add.error.code === 'INVALID_ICAL_URL' ? String(add.error.details.reason ?? '') : '';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        add.mutate();
    };

    return (
        <Section title={t('calendars.imported')}>
            <p className="mb-4 text-sm text-muted">{t('calendars.importedIntro')}</p>
            {calendars.isPending && <Loading />}
            {calendars.isError && <FormError message={errorMessage(calendars.error)} />}
            {calendars.data?.length === 0 && <EmptyState title={t('calendars.noImported')} />}
            <ul className="divide-y divide-line-soft" data-testid="external-calendar-list">
                {calendars.data?.map((calendar) => <CalendarRow key={calendar.id} calendar={calendar} onChange={refresh} />)}
            </ul>

            <form onSubmit={submit} noValidate className="mt-4 grid gap-3 rounded-control bg-canvas p-3 sm:grid-cols-4" data-testid="external-calendar-form">
                <Field label={t('calendars.source')}>
                    <select className={inputClass} value={source} onChange={(e) => setSource(e.target.value as ChannelSource)}>
                        {CHANNEL_SOURCES.map((s) => (
                            <option key={s} value={s}>
                                {t(`channelSource.${s}`)}
                            </option>
                        ))}
                    </select>
                </Field>
                <div className="sm:col-span-3">
                    <Field label={t('calendars.url')} errors={fieldErrors(add.error).url} hint={t('calendars.urlHint')}>
                        <TextInput value={url} onChange={setUrl} inputMode="url" placeholder="https://" />
                    </Field>
                </div>
                {needsSwitch && (
                    <label className="flex items-start gap-2 text-sm text-warning sm:col-span-4">
                        <input type="checkbox" checked={switchToRequest} onChange={(e) => setSwitchToRequest(e.target.checked)} name="switch_to_request" className="mt-0.5" />
                        {t('calendars.switchToRequest')}
                    </label>
                )}
                {add.isError && !needsSwitch && (
                    <div className="sm:col-span-4">
                        <FormError message={reason !== '' ? t(`fetchError.${reason}`, { defaultValue: errorMessage(add.error) }) : errorMessage(add.error)} />
                    </div>
                )}
                <div className="sm:col-span-4">
                    <button type="submit" className={primaryButton} disabled={add.isPending || url.trim() === ''}>
                        {add.isPending ? t('calendars.syncing') : t('calendars.add')}
                    </button>
                </div>
            </form>
        </Section>
    );
}

function CalendarRow({ calendar, onChange }: { calendar: ExternalCalendar; onChange: () => void }) {
    const { t } = useTranslation();
    const confirmation = useConfirm();
    const errorMessage = useErrorMessage();
    const [showRuns, setShowRuns] = useState(false);
    const [newUrl, setNewUrl] = useState<string | null>(null);
    const runs = useQuery({ queryKey: ['sync-runs', calendar.id], queryFn: () => calendarsApi.runs(calendar.id), enabled: showRuns });
    const syncNow = useMutation({ mutationFn: () => calendarsApi.syncNow(calendar.id), onSuccess: onChange });
    const toggle = useMutation({
        mutationFn: () => calendarsApi.updateCalendar(calendar.id, { status: calendar.status === 'DISABLED' ? 'ACTIVE' : 'DISABLED' }),
        onSuccess: onChange,
    });
    const changeUrl = useMutation({
        mutationFn: (url: string) => calendarsApi.updateCalendar(calendar.id, { url }),
        onSuccess: () => {
            setNewUrl(null);
            onChange();
        },
    });
    const remove = useMutation({ mutationFn: () => calendarsApi.removeCalendar(calendar.id), onSuccess: onChange });
    const error = syncNow.error ?? toggle.error ?? changeUrl.error ?? remove.error;
    const disabled = calendar.status === 'DISABLED';

    return (
        <li className="py-4" data-testid="external-calendar-row" data-health={calendar.health}>
            <div className="flex flex-wrap items-start justify-between gap-3 text-sm">
                <div className="space-y-1">
                    <p className="font-medium text-ink">
                        {t(`channelSource.${calendar.source}`)} · {calendar.url_host ?? '—'}
                    </p>
                    {calendar.demo && <p className="text-xs text-muted">{t('calendars.demo')}</p>}
                    <p className="text-ink">
                        {calendar.last_success_at !== null
                            ? t('calendars.lastSuccess', { date: formatDateTime(calendar.last_success_at) })
                            : t('calendars.neverSynced')}
                        {calendar.active_events !== null && ` · ${t('calendars.events', { count: calendar.active_events })}`}
                    </p>
                    {calendar.consecutive_failures > 0 && <p className="text-warning">{t('calendars.failures', { count: calendar.consecutive_failures })}</p>}
                    {calendar.health === 'UNHEALTHY' && <p className="text-danger">{t('calendars.unhealthyHint')}</p>}
                    {calendar.health === 'DEGRADED' && <p className="text-warning">{t('calendars.degradedHint')}</p>}
                </div>
                <span className={`rounded-full border px-2 py-0.5 text-xs font-medium ${healthColors[calendar.health]}`} data-testid="calendar-health">
                    {t(`calendarHealth.${calendar.health}`)}
                </span>
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
                {!disabled && (
                    <button type="button" className={secondaryButton} disabled={syncNow.isPending} onClick={() => syncNow.mutate()}>
                        {syncNow.isPending ? t('calendars.syncing') : t('calendars.syncNow')}
                    </button>
                )}
                <button type="button" className={secondaryButton} onClick={() => setNewUrl(newUrl === null ? '' : null)}>
                    {t('calendars.changeUrl')}
                </button>
                <button type="button" className={secondaryButton} disabled={toggle.isPending} onClick={() => toggle.mutate()}>
                    {disabled ? t('calendars.enable') : t('calendars.disable')}
                </button>
                <button type="button" className={secondaryButton} onClick={() => setShowRuns((v) => !v)}>
                    {showRuns ? t('calendars.hideRuns') : t('calendars.showRuns')}
                </button>
                <button
                    type="button"
                    className={dangerButton}
                    disabled={remove.isPending}
                    onClick={() => confirmation.ask({ title: t('calendars.removeConfirm'), confirmLabel: t('calendars.remove'), action: () => remove.mutate() })}
                >
                    {t('calendars.remove')}
                </button>
                {confirmation.dialog}
            </div>
            {newUrl !== null && (
                <div className="mt-3 flex flex-wrap items-end gap-2">
                    <div className="min-w-64 flex-1">
                        <Field label={t('calendars.newUrl')} hint={t('calendars.changeUrlHint')}>
                            <TextInput value={newUrl} onChange={setNewUrl} inputMode="url" placeholder="https://" />
                        </Field>
                    </div>
                    <button type="button" className={primaryButton} disabled={changeUrl.isPending || newUrl.trim() === ''} onClick={() => changeUrl.mutate(newUrl.trim())}>
                        {t('common.save')}
                    </button>
                </div>
            )}
            {showRuns && (
                <div className="mt-3 overflow-x-auto">
                    {runs.isPending && <Loading />}
                    {runs.data !== undefined && (
                        <table className="min-w-full text-left text-xs" data-testid="sync-runs">
                            <thead className="text-muted">
                                <tr>
                                    <th className="py-1 pr-3">{t('calendars.runStarted')}</th>
                                    <th className="py-1 pr-3">{t('calendars.runOutcome')}</th>
                                    <th className="py-1 pr-3">{t('calendars.runEvents')}</th>
                                </tr>
                            </thead>
                            <tbody className="text-ink">
                                {runs.data.map((run) => (
                                    <tr key={run.id} className="border-t border-slate-100">
                                        <td className="py-1 pr-3">{formatDateTime(run.started_at)}</td>
                                        <td className="py-1 pr-3">
                                            {run.outcome !== null ? t(`syncOutcome.${run.outcome}`, { defaultValue: run.outcome }) : '—'}
                                            {run.error_code !== null && ` · ${run.error_code}`}
                                        </td>
                                        <td className="py-1 pr-3">
                                            {t('calendars.runCounts', { seen: run.events_seen, added: run.events_added, removed: run.events_removed })}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            )}
            {error !== null && <FormError message={errorMessage(error)} />}
        </li>
    );
}

function ExportedFeeds({ unitId }: { unitId: string }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const feeds = useQuery({ queryKey: ['ics-feeds', unitId], queryFn: () => calendarsApi.feeds(unitId) });
    const refresh = () => {
        void queryClient.invalidateQueries({ queryKey: ['ics-feeds', unitId] });
        void queryClient.invalidateQueries({ queryKey: ['calendar-checklist', unitId] });
    };
    const create = useMutation({ mutationFn: (destination: ChannelSource) => calendarsApi.createFeed(unitId, destination), onSuccess: refresh });
    const active = new Set((feeds.data ?? []).filter((f) => f.status === 'ACTIVE').map((f) => f.destination));
    const missing = CHANNEL_SOURCES.filter((s) => !active.has(s));

    return (
        <Section title={t('calendars.exported')}>
            <p className="mb-4 text-sm text-muted">{t('calendars.exportedIntro')}</p>
            {feeds.isPending && <Loading />}
            {feeds.isError && <FormError message={errorMessage(feeds.error)} />}
            <ul className="divide-y divide-line-soft" data-testid="feed-list">
                {feeds.data?.map((feed) => <FeedRow key={feed.id} feed={feed} onChange={refresh} />)}
            </ul>
            {missing.length > 0 && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {missing.map((destination) => (
                        <button key={destination} type="button" className={secondaryButton} disabled={create.isPending} onClick={() => create.mutate(destination)}>
                            {t('calendars.createFeed', { destination: t(`channelSource.${destination}`) })}
                        </button>
                    ))}
                </div>
            )}
            {create.isError && <FormError message={errorMessage(create.error)} />}
        </Section>
    );
}

function FeedRow({ feed, onChange }: { feed: IcsFeed; onChange: () => void }) {
    const { t } = useTranslation();
    const confirmation = useConfirm();
    const errorMessage = useErrorMessage();
    const [copied, setCopied] = useState(false);
    const rotate = useMutation({ mutationFn: () => calendarsApi.rotateFeed(feed.id), onSuccess: onChange });

    const copy = async () => {
        if (feed.url === null) {
            return;
        }

        try {
            await navigator.clipboard.writeText(feed.url);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    return (
        <li className="py-4 text-sm" data-testid="feed-row" data-status={feed.status} data-destination={feed.destination}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="space-y-1">
                    <p className="font-medium text-ink">{t('calendars.feedFor', { destination: t(`channelSource.${feed.destination}`) })}</p>
                    <p className="text-ink">
                        {feed.last_fetched_at !== null ? t('calendars.lastRead', { date: formatDateTime(feed.last_fetched_at) }) : t('calendars.neverRead')}
                    </p>
                    {feed.not_read_warning && <p className="text-warning">{t('calendars.notReadWarning')}</p>}
                    {feed.retires_at !== null && <p className="text-muted">{t('calendars.retiresAt', { date: formatDateTime(feed.retires_at) })}</p>}
                    {feed.url !== null && feed.status === 'ACTIVE' && <p className="break-all text-xs text-muted">{feed.url}</p>}
                </div>
                <span className="rounded-full border border-line px-2 py-0.5 text-xs font-medium text-ink">{t(`feedStatus.${feed.status}`)}</span>
            </div>
            {feed.status === 'ACTIVE' && (
                <div className="mt-3 flex flex-wrap gap-2">
                    <button type="button" className={secondaryButton} onClick={() => void copy()}>
                        {copied ? t('calendars.copied') : t('calendars.copyFeed')}
                    </button>
                    <button
                        type="button"
                        className={dangerButton}
                        disabled={rotate.isPending}
                        onClick={() => confirmation.ask({ title: t('calendars.rotateConfirm'), confirmLabel: t('calendars.rotate'), action: () => rotate.mutate() })}
                    >
                        {t('calendars.rotate')}
                    </button>
                    {confirmation.dialog}
                </div>
            )}
            {rotate.isError && <FormError message={errorMessage(rotate.error)} />}
        </li>
    );
}

function ChecklistSection({ unitId }: { unitId: string }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const checklist = useQuery({ queryKey: ['calendar-checklist', unitId], queryFn: () => calendarsApi.checklist(unitId) });
    const confirm = useMutation({
        mutationFn: (step: string) => calendarsApi.confirmStep(unitId, step),
        onSuccess: (data) => queryClient.setQueryData(['calendar-checklist', unitId], data),
    });

    return (
        <Section title={t('calendars.checklist')}>
            <p className="mb-4 text-sm text-muted">{t('calendars.checklistIntro')}</p>
            {checklist.isPending && <Loading />}
            {checklist.data?.steps.length === 0 && <EmptyState title={t('calendars.checklistEmpty')} />}
            {checklist.data?.complete === true && <p className="mb-3 text-sm font-medium text-emerald-800">{t('calendars.checklistComplete')}</p>}
            <ul className="space-y-2" data-testid="checklist">
                {checklist.data?.steps.map((step) => {
                    const [, name] = step.key.includes('.') ? step.key.split('.') : [null, step.key];
                    const channel = step.channel !== null ? t(`channelSource.${step.channel}`) : '';

                    return (
                        <li key={step.key} className="flex flex-wrap items-center justify-between gap-2 text-sm" data-testid="checklist-step" data-done={step.done ? '1' : '0'}>
                            <span className={step.done ? 'text-emerald-800' : 'text-ink'}>
                                {step.done ? '✓ ' : '○ '}
                                {t(`checklistStep.${name}`, { channel })}
                                {step.evidence.last_fetched_at !== undefined &&
                                    ` · ${step.evidence.last_fetched_at !== null ? t('calendars.lastRead', { date: formatDateTime(step.evidence.last_fetched_at) }) : t('calendars.neverRead')}`}
                            </span>
                            {step.kind === 'manual' && !step.done && (
                                <button type="button" className={secondaryButton} disabled={confirm.isPending} onClick={() => confirm.mutate(step.key)}>
                                    {t('calendars.confirmStep')}
                                </button>
                            )}
                        </li>
                    );
                })}
            </ul>
            {confirm.isError && <FormError message={errorMessage(confirm.error)} />}
        </Section>
    );
}
