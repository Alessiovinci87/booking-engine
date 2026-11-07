import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';

import { fieldErrors } from '../api/client';
import { type CalendarData, type CalendarOccupancy, reservationsApi } from '../api/reservations';
import { useErrorMessage } from '../auth/session';
import { dangerButton, Field, FormError, inputClass, primaryButton, secondaryButton, Section, TextInput } from '../components/Form';
import { statusColors, UnitSelect, useAllUnits } from '../components/Reservations';
import { addDays, coversNight, formatDate, formatMonth, fromIsoDate, monthStart, today, weekdayNames } from '../dates';

/**
 * Calendario mensile per unità (3.8): ogni notte mostra l'occupazione che la copre, colorata per tipo
 * e stato (prenotazioni, blocchi manuali), più eventi esterni e conflitti aperti. Clic su una notte
 * libera: nuovo blocco a partire da quella data. Clic su un blocco: rimozione.
 */
export function CalendarPage() {
    const { t } = useTranslation();
    const [params, setParams] = useSearchParams();
    const { options, isPending } = useAllUnits();
    const unitId = params.get('unit') ?? options[0]?.unit.id ?? '';
    const month = monthStart(params.get('month') ?? today());
    const [blockStart, setBlockStart] = useState<string | null>(null);
    const [selectedBlock, setSelectedBlock] = useState<CalendarOccupancy | null>(null);

    // Griglia da lunedì: 6 settimane coprono qualsiasi mese.
    const offset = (fromIsoDate(month).getDay() + 6) % 7;
    const gridStart = addDays(month, -offset);
    const gridEnd = addDays(gridStart, 42);
    const calendar = useQuery({
        queryKey: ['calendar', unitId, gridStart, gridEnd],
        queryFn: () => reservationsApi.calendar(unitId, gridStart, gridEnd),
        enabled: unitId !== '',
    });

    const setMonth = (value: string) => {
        const next = new URLSearchParams(params);
        next.set('month', value.slice(0, 7));
        setParams(next);
    };

    const setUnit = (value: string) => {
        const next = new URLSearchParams(params);
        next.set('unit', value);
        setParams(next);
        setBlockStart(null);
        setSelectedBlock(null);
    };

    useEffect(() => {
        setSelectedBlock(null);
    }, [month]);

    if (isPending) {
        return <p className="mx-auto max-w-5xl px-4 py-8 text-slate-600">{t('common.loading')}</p>;
    }

    if (options.length === 0) {
        return (
            <main className="mx-auto max-w-5xl px-4 py-8">
                <p className="text-slate-600">{t('calendar.noUnits')}</p>
            </main>
        );
    }

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <h1 className="text-2xl font-semibold text-slate-900">{t('calendar.title')}</h1>
                <label className="text-sm font-medium text-slate-700">
                    {t('calendar.unit')}
                    <UnitSelect options={options} value={unitId} onChange={setUnit} className={`${inputClass} min-w-56`} />
                </label>
            </div>

            <Section
                title={formatMonth(month)}
                actions={
                    <div className="flex gap-2">
                        <button type="button" className={secondaryButton} onClick={() => setMonth(monthStart(month, -1))} aria-label={t('calendar.previous')}>
                            ‹
                        </button>
                        <button type="button" className={secondaryButton} onClick={() => setMonth(today())}>
                            {t('calendar.today')}
                        </button>
                        <button type="button" className={secondaryButton} onClick={() => setMonth(monthStart(month, 1))} aria-label={t('calendar.next')}>
                            ›
                        </button>
                    </div>
                }
            >
                {calendar.isError ? (
                    <FormError message={t('common.loadError')} />
                ) : (
                    <MonthGrid
                        data={calendar.data}
                        gridStart={gridStart}
                        month={month}
                        onFreeNight={(night) => {
                            setSelectedBlock(null);
                            setBlockStart(night);
                        }}
                        onBlock={(block) => {
                            setBlockStart(null);
                            setSelectedBlock(block);
                        }}
                    />
                )}
                <Legend />
            </Section>

            {selectedBlock !== null && <BlockDetails unitId={unitId} block={selectedBlock} onDone={() => setSelectedBlock(null)} />}
            <NewBlockForm key={`${unitId}-${blockStart ?? ''}`} unitId={unitId} initialStart={blockStart} />
            {calendar.data !== undefined && calendar.data.conflicts.length > 0 && (
                <Section title={t('calendar.conflicts')}>
                    <ul className="space-y-1 text-sm text-red-800">
                        {calendar.data.conflicts.map((c) => (
                            <li key={c.id}>
                                {t('calendar.conflictLine', { from: formatDate(c.check_in), to: formatDate(c.check_out) })}
                            </li>
                        ))}
                    </ul>
                </Section>
            )}
        </main>
    );
}

function MonthGrid({
    data,
    gridStart,
    month,
    onFreeNight,
    onBlock,
}: {
    data: CalendarData | undefined;
    gridStart: string;
    month: string;
    onFreeNight: (night: string) => void;
    onBlock: (block: CalendarOccupancy) => void;
}) {
    const { t } = useTranslation();
    const days = Array.from({ length: 42 }, (_, i) => addDays(gridStart, i));
    const current = month.slice(0, 7);
    const now = today();

    return (
        <div className="overflow-x-auto">
            <div className="grid min-w-[42rem] grid-cols-7 gap-px rounded-md border border-slate-200 bg-slate-200 text-xs" data-testid="calendar-grid">
                {weekdayNames().map((name) => (
                    <div key={name} className="bg-slate-50 px-2 py-1 text-center font-medium uppercase text-slate-500">
                        {name}
                    </div>
                ))}
                {days.map((night) => {
                    const occupancy = data?.occupancies.find((o) => coversNight(o.check_in, o.check_out, night));
                    const external = data?.external_events.find((e) => coversNight(e.check_in, e.check_out, night));
                    const conflict = data?.conflicts.some((c) => coversNight(c.check_in, c.check_out, night)) ?? false;
                    const inMonth = night.slice(0, 7) === current;

                    return (
                        <div
                            key={night}
                            className={`min-h-20 bg-white p-1 ${inMonth ? '' : 'bg-slate-50 text-slate-400'} ${conflict ? 'ring-2 ring-inset ring-red-500' : ''}`}
                            data-night={night}
                        >
                            <div className={`mb-1 text-right ${night === now ? 'font-bold text-blue-700' : ''}`}>{fromIsoDate(night).getDate()}</div>
                            {occupancy !== undefined ? (
                                <OccupancyChip occupancy={occupancy} night={night} onBlock={onBlock} />
                            ) : external !== undefined ? (
                                <div className={`truncate rounded border px-1 py-0.5 ${statusColors.EXTERNAL}`} title={external.summary ?? undefined}>
                                    {t('calendar.external', { source: external.source })}
                                </div>
                            ) : night >= now ? (
                                <button
                                    type="button"
                                    className="h-8 w-full rounded text-slate-300 hover:bg-slate-100 hover:text-slate-600"
                                    onClick={() => onFreeNight(night)}
                                    aria-label={t('calendar.blockFrom', { date: formatDate(night) })}
                                >
                                    +
                                </button>
                            ) : null}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function OccupancyChip({ occupancy, night, onBlock }: { occupancy: CalendarOccupancy; night: string; onBlock: (block: CalendarOccupancy) => void }) {
    const { t } = useTranslation();
    const first = occupancy.check_in === night || fromIsoDate(night).getDay() === 1;
    const temporary = occupancy.phase === 'TEMPORARY' ? 'border-dashed' : '';

    if (occupancy.kind === 'MANUAL_BLOCK' || occupancy.reservation === null) {
        return (
            <button
                type="button"
                onClick={() => onBlock(occupancy)}
                className={`block w-full truncate rounded border px-1 py-0.5 text-left ${statusColors.BLOCK}`}
                data-testid="calendar-block"
                title={occupancy.note ?? undefined}
            >
                {first ? t('calendar.block') : ' '}
            </button>
        );
    }

    const r = occupancy.reservation;

    return (
        <Link
            to={`/prenotazioni/${r.id}`}
            className={`block truncate rounded border px-1 py-0.5 ${statusColors[r.status]} ${temporary}`}
            data-testid="calendar-reservation"
            title={`${r.code} · ${r.guest_name} · ${t(`reservationStatus.${r.status}`)}`}
        >
            {first ? r.guest_name : ' '}
        </Link>
    );
}

function Legend() {
    const { t } = useTranslation();
    const items: [keyof typeof statusColors, string][] = [
        ['CONFIRMED', t('reservationStatus.CONFIRMED')],
        ['AWAITING_PAYMENT', t('reservationStatus.AWAITING_PAYMENT')],
        ['AWAITING_APPROVAL', t('reservationStatus.AWAITING_APPROVAL')],
        ['CAPTURING', t('reservationStatus.CAPTURING')],
        ['CAPTURE_FAILED', t('reservationStatus.CAPTURE_FAILED')],
        ['BLOCK', t('calendar.block')],
        ['EXTERNAL', t('calendar.externalLegend')],
    ];

    return (
        <div className="mt-3 space-y-1 text-xs text-slate-600">
            <ul className="flex flex-wrap gap-2">
                {items.map(([key, label]) => (
                    <li key={key} className={`rounded border px-2 py-0.5 ${statusColors[key]}`}>
                        {label}
                    </li>
                ))}
            </ul>
            <p>{t('calendar.legendHint')}</p>
        </div>
    );
}

function BlockDetails({ unitId, block, onDone }: { unitId: string; block: CalendarOccupancy; onDone: () => void }) {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const queryClient = useQueryClient();
    const remove = useMutation({
        mutationFn: () => reservationsApi.deleteBlock(unitId, block.id),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['calendar', unitId] });
            onDone();
        },
    });

    return (
        <Section title={t('calendar.blockTitle')}>
            <p className="text-sm text-slate-700">{t('calendar.blockRange', { from: formatDate(block.check_in), to: formatDate(block.check_out) })}</p>
            {block.note !== null && <p className="mt-1 text-sm text-slate-600">{block.note}</p>}
            <div className="mt-3 flex gap-2">
                <button type="button" className={dangerButton} onClick={() => remove.mutate()} disabled={remove.isPending} data-testid="remove-block">
                    {t('calendar.removeBlock')}
                </button>
                <button type="button" className={secondaryButton} onClick={onDone}>
                    {t('common.close')}
                </button>
            </div>
            {remove.isError && (
                <div className="mt-3">
                    <FormError message={errorMessage(remove.error)} />
                </div>
            )}
        </Section>
    );
}

function NewBlockForm({ unitId, initialStart }: { unitId: string; initialStart: string | null }) {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const queryClient = useQueryClient();
    const [checkIn, setCheckIn] = useState(initialStart ?? '');
    const [checkOut, setCheckOut] = useState(initialStart !== null ? addDays(initialStart, 1) : '');
    const [note, setNote] = useState('');
    const [created, setCreated] = useState(false);
    const create = useMutation({
        mutationFn: () => reservationsApi.createBlock(unitId, { check_in: checkIn, check_out: checkOut, note: note.trim() === '' ? null : note.trim() }),
        onMutate: () => setCreated(false),
        onSuccess: () => {
            setCreated(true);
            setNote('');
            void queryClient.invalidateQueries({ queryKey: ['calendar', unitId] });
        },
    });
    const errors = fieldErrors(create.error);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        create.mutate();
    };

    return (
        <Section title={t('calendar.newBlock')}>
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3" data-testid="block-form">
                <Field label={t('calendar.blockCheckIn')} errors={errors.check_in}>
                    <TextInput type="date" value={checkIn} onChange={setCheckIn} required />
                </Field>
                <Field label={t('calendar.blockCheckOut')} errors={errors.check_out} hint={t('calendar.blockCheckOutHint')}>
                    <TextInput type="date" value={checkOut} onChange={setCheckOut} required />
                </Field>
                <Field label={t('calendar.blockNote')} errors={errors.note} hint={t('calendar.blockNoteHint')}>
                    <TextInput value={note} onChange={setNote} />
                </Field>
                <div className="flex items-center gap-3 sm:col-span-3">
                    <button type="submit" className={primaryButton} disabled={create.isPending || checkIn === '' || checkOut === ''}>
                        {t('calendar.createBlock')}
                    </button>
                    {created && (
                        <span className="text-sm text-green-700" role="status">
                            {t('calendar.blockCreated')}
                        </span>
                    )}
                </div>
                {create.isError && Object.keys(errors).length === 0 && (
                    <div className="sm:col-span-3">
                        <FormError message={errorMessage(create.error)} />
                    </div>
                )}
            </form>
        </Section>
    );
}
