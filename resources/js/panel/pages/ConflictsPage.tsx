import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { calendarsApi, type Conflict, type ConflictEvent, CONFLICT_RESOLUTIONS, type ConflictResolution, type ConflictStatus } from '../api/calendars';
import { fieldErrors } from '../api/client';
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
} from '../components/Form';
import { formatDate, formatDateTime } from '../dates';

const OPEN: ConflictStatus[] = ['OPEN', 'INVESTIGATING'];

/**
 * Conflitti di calendario (06, 5.8): date occupate da due fonti. Le date restano bloccate finché il
 * gestore non chiude il conflitto; nessuna azione cancella prenotazioni (I13). Un'eco sospetta si può
 * dichiarare prenotazione vera ("Non è un'eco").
 */
export function ConflictsPage() {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const [showClosed, setShowClosed] = useState(false);
    const statuses: ConflictStatus[] = showClosed ? ['RESOLVED', 'IGNORED'] : OPEN;
    const conflicts = useQuery({ queryKey: ['conflicts', statuses], queryFn: () => calendarsApi.conflicts(statuses) });

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <h1 className="text-2xl font-semibold text-ink">{t('conflicts.title')}</h1>
            <p className="text-sm text-muted">{t('conflicts.intro')}</p>
            <Section
                title={showClosed ? t('conflicts.closed') : t('conflicts.open')}
                actions={
                    <button type="button" className={secondaryButton} onClick={() => setShowClosed((v) => !v)}>
                        {showClosed ? t('conflicts.showOpen') : t('conflicts.showClosed')}
                    </button>
                }
            >
                {conflicts.isPending && <Loading />}
                {conflicts.isError && <FormError message={errorMessage(conflicts.error)} />}
                {conflicts.data?.length === 0 && <EmptyState title={showClosed ? t('conflicts.noneClosed') : t('conflicts.none')} />}
                <ul className="divide-y divide-line-soft" data-testid="conflict-list">
                    {conflicts.data?.map((conflict) => <ConflictRow key={conflict.id} conflict={conflict} />)}
                </ul>
            </Section>
        </main>
    );
}

function EventLine({ event }: { event: ConflictEvent }) {
    const { t } = useTranslation();

    return (
        <span>
            {t('conflicts.externalEvent', {
                source: t(`channelSource.${event.source ?? 'OTHER'}`),
                checkIn: formatDate(event.check_in),
                checkOut: formatDate(event.check_out),
            })}
            {event.summary !== null && ` · ${event.summary}`}
        </span>
    );
}

function ConflictRow({ conflict }: { conflict: Conflict }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const [mode, setMode] = useState<'resolve' | 'ignore' | null>(null);
    const [resolution, setResolution] = useState<ConflictResolution>('CANCELLED_EXTERNAL');
    const [notes, setNotes] = useState('');
    const refresh = () => void queryClient.invalidateQueries({ queryKey: ['conflicts'] });
    const onSuccess = () => {
        setMode(null);
        setNotes('');
        refresh();
    };

    const investigate = useMutation({ mutationFn: () => calendarsApi.investigate(conflict.id), onSuccess });
    const close = useMutation({
        mutationFn: () =>
            mode === 'ignore' ? calendarsApi.ignore(conflict.id, notes.trim()) : calendarsApi.resolve(conflict.id, resolution, notes.trim() === '' ? null : notes.trim()),
        onSuccess,
    });
    const notEcho = useMutation({ mutationFn: (eventId: string) => calendarsApi.notAnEcho(eventId), onSuccess });
    const open = conflict.status === 'OPEN' || conflict.status === 'INVESTIGATING';
    const suspected = [conflict.external_event, conflict.other_external_event].find((e) => e?.echo_kind === 'SUSPECTED_ECHO') ?? null;
    const error = investigate.error ?? close.error ?? notEcho.error;
    const errors = fieldErrors(close.error);

    return (
        <li className="py-4" data-testid="conflict-row" data-status={conflict.status}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="space-y-1 text-sm">
                    <p className="font-medium text-ink">
                        {conflict.unit.name ?? '—'} · {formatDate(conflict.check_in)} → {formatDate(conflict.check_out)}
                    </p>
                    {conflict.reservation !== null && (
                        <p>
                            <Link to={`/prenotazioni/${conflict.reservation.id}`} className="text-brand-800 hover:underline">
                                {t('conflicts.reservation', { code: conflict.reservation.code })}
                            </Link>
                        </p>
                    )}
                    {conflict.kind === 'MANUAL_BLOCK' && <p className="text-ink">{t('conflicts.manualBlock')}</p>}
                    {conflict.external_event !== null && (
                        <p className="text-ink">
                            <EventLine event={conflict.external_event} />
                        </p>
                    )}
                    {conflict.other_external_event !== null && (
                        <p className="text-ink">
                            <EventLine event={conflict.other_external_event} />
                        </p>
                    )}
                    {conflict.suspected_echo && <p className="text-warning">{t('conflicts.suspectedEcho')}</p>}
                    <p className="text-xs text-muted">
                        {t('conflicts.detectedAt', { date: formatDateTime(conflict.detected_at) })}
                        {conflict.resolved_at !== null && ` · ${t('conflicts.closedAt', { date: formatDateTime(conflict.resolved_at) })}`}
                        {conflict.resolution !== null && ` · ${t(`conflictResolution.${conflict.resolution}`)}`}
                    </p>
                </div>
                <span className="rounded-full border border-line px-2 py-0.5 text-xs font-medium text-ink" data-testid="conflict-status">
                    {t(`conflictStatus.${conflict.status}`)}
                </span>
            </div>

            {open && (
                <div className="mt-3 flex flex-wrap gap-2">
                    {conflict.status === 'OPEN' && (
                        <button type="button" className={secondaryButton} disabled={investigate.isPending} onClick={() => investigate.mutate()}>
                            {t('conflicts.investigate')}
                        </button>
                    )}
                    <button type="button" className={secondaryButton} onClick={() => setMode(mode === 'resolve' ? null : 'resolve')}>
                        {t('conflicts.resolve')}
                    </button>
                    <button type="button" className={dangerButton} onClick={() => setMode(mode === 'ignore' ? null : 'ignore')}>
                        {t('conflicts.ignore')}
                    </button>
                    {suspected !== null && (
                        <button type="button" className={secondaryButton} disabled={notEcho.isPending} onClick={() => notEcho.mutate(suspected.id)}>
                            {t('conflicts.notEcho')}
                        </button>
                    )}
                </div>
            )}

            {open && mode !== null && (
                <div className="mt-3 grid gap-3 rounded-control bg-canvas p-3 sm:grid-cols-2" data-testid="conflict-form">
                    {mode === 'resolve' && (
                        <Field label={t('conflicts.resolution')} errors={errors.resolution}>
                            <select className={inputClass} value={resolution} onChange={(e) => setResolution(e.target.value as ConflictResolution)}>
                                {CONFLICT_RESOLUTIONS.map((r) => (
                                    <option key={r} value={r}>
                                        {t(`conflictResolution.${r}`)}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                    <Field label={mode === 'ignore' ? t('conflicts.ignoreNotes') : t('conflicts.notes')} errors={errors.notes} hint={t('conflicts.notesHint')}>
                        <textarea className={inputClass} rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />
                    </Field>
                    <p className="text-xs text-muted sm:col-span-2">{t('conflicts.noCancellation')}</p>
                    <div className="sm:col-span-2">
                        <button type="button" className={primaryButton} disabled={close.isPending} onClick={() => close.mutate()}>
                            {mode === 'ignore' ? t('conflicts.confirmIgnore') : t('conflicts.confirmResolve')}
                        </button>
                    </div>
                </div>
            )}
            {error !== null && <FormError message={errorMessage(error)} />}
        </li>
    );
}
