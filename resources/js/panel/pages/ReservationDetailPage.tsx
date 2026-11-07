import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';

import type { Money } from '../api/admin';
import { ApiError, fieldErrors } from '../api/client';
import {
    type ModifyInput,
    OFFLINE_METHODS,
    type OfflineMethod,
    type ReservationAction,
    type ReservationDetail,
    reservationsApi,
    type StayPrice,
} from '../api/reservations';
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
} from '../components/Form';
import { GuestPrivacy } from '../components/GuestPrivacy';
import { ReauthPrompt, needsReauth } from '../components/Reauth';
import { StatusBadge } from '../components/Reservations';
import { formatDate, formatDateTime } from '../dates';
import { centsToEuroInput, formatMoney, parseEuroToCents } from '../money';
import { NotFoundPage } from './NotFoundPage';

/** Ogni azione risponde con il dettaglio aggiornato. */
type DetailRequest = Promise<ReservationDetail>;

/**
 * Dettaglio della prenotazione (3.8): soggiorno, ospite, importi, righe di prezzo congelate,
 * pagamenti con eventi, rimborsi, modifiche e storico. Le azioni sono quelle che il server elenca in
 * `actions` per lo stato corrente (03); ogni esito arriva dalla risposta del server.
 */
export function ReservationDetailPage() {
    const { reservationId = '' } = useParams();
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const reservation = useQuery({ queryKey: ['reservation', reservationId], queryFn: () => reservationsApi.get(reservationId) });

    if (reservation.isPending) {
        return <Loading page />;
    }

    if (reservation.isError) {
        return reservation.error instanceof ApiError && reservation.error.status === 404 ? (
            <NotFoundPage />
        ) : (
            <main className="mx-auto max-w-5xl px-4 py-8">
                <FormError message={errorMessage(reservation.error)} />
            </main>
        );
    }

    const r = reservation.data;

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <Link to="/prenotazioni" className="text-sm text-brand-800 hover:underline">
                {`← ${t('reservation.back')}`}
            </Link>
            <div className="flex flex-wrap items-center gap-3">
                <h1 className="font-mono text-2xl font-semibold text-ink" data-testid="reservation-code">
                    {r.code}
                </h1>
                <StatusBadge status={r.status} />
                <span className="text-sm text-muted">{t(`channel.${r.channel}`)}</span>
            </div>

            <Actions reservation={r} />

            <div className="grid gap-6 md:grid-cols-2">
                <Section title={t('reservation.stay')}>
                    <Rows
                        rows={[
                            [t('reservation.unit'), r.unit.name],
                            [t('reservation.dates'), `${formatDate(r.check_in)} → ${formatDate(r.check_out)}`],
                            [t('reservation.nights'), String(r.nights)],
                            [t('reservation.guests'), t('reservation.guestsValue', { adults: r.adults, children: r.children })],
                            [t('reservation.bookingMode'), t(`unit.bookingModes.${r.booking_mode}`)],
                            [t('reservation.confirmationKind'), r.confirmation_kind === null ? '—' : t(`confirmationKind.${r.confirmation_kind}`)],
                            [t('reservation.createdAt'), r.created_at === null ? '—' : formatDateTime(r.created_at)],
                            ...deadlines(r, t),
                        ]}
                    />
                    {r.cancel_reason !== null && <p className="mt-3 text-sm text-ink">{t('reservation.reason', { reason: r.cancel_reason })}</p>}
                </Section>
                <Section title={t('reservation.guest')}>
                    {r.guest.anonymized ? (
                        <p className="text-sm text-muted">{t('reservation.anonymized')}</p>
                    ) : (
                        <Rows
                            rows={[
                                [t('reservation.guestName'), `${r.guest.first_name} ${r.guest.last_name}`],
                                [t('reservation.email'), r.guest.email ?? '—'],
                                [t('reservation.phone'), r.guest.phone ?? '—'],
                                [t('reservation.country'), r.guest.country_code ?? '—'],
                                [t('reservation.language'), r.guest.locale.toUpperCase()],
                            ]}
                        />
                    )}
                    {r.guest.notes !== null && <p className="mt-3 whitespace-pre-line rounded-control bg-canvas p-3 text-sm text-ink">{r.guest.notes}</p>}
                </Section>
            </div>

            <GuestPrivacy guestId={r.guest.id} reservationId={r.id} anonymized={r.guest.anonymized} />

            <Section title={t('reservation.amounts')}>
                <div className="grid gap-6 md:grid-cols-2">
                    <Rows
                        rows={[
                            [t('reservation.total'), formatMoney(r.contract_total)],
                            [t('reservation.deposit'), formatMoney(r.deposit_due)],
                            [t('reservation.balance'), formatMoney(r.balance_due)],
                            [t('reservation.collected'), formatMoney(r.collected)],
                            [t('reservation.refunded'), formatMoney(r.refunded)],
                            [t('reservation.outstanding'), formatMoney(r.outstanding)],
                            [t('reservation.economicStatus'), t(`economicStatus.${r.economic_status}`)],
                            [t('reservation.refundStatus'), t(`refundStatus.${r.refund_status}`)],
                        ]}
                    />
                    <div>
                        <h3 className="mb-2 text-sm font-semibold text-ink">{t('reservation.priceLines')}</h3>
                        <ul className="divide-y divide-slate-100 text-sm" data-testid="price-lines">
                            {r.price_lines.map((line, index) => (
                                <li key={`${line.kind}-${line.date ?? ''}-${index}`} className="flex justify-between gap-4 py-1">
                                    <span className="text-ink">
                                        {t(`priceLines.${line.kind}`, { defaultValue: line.kind })}
                                        {line.date !== null && ` · ${formatDate(line.date)}`}
                                        {line.quantity > 1 && ` × ${line.quantity}`}
                                    </span>
                                    <span className="tabular-nums">{formatMoney(line.amount)}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </Section>

            <Section title={t('reservation.payments')}>
                {r.payments.length === 0 ? (
                    <EmptyState title={t('reservation.noPayments')} />
                ) : (
                    <ul className="space-y-3" data-testid="payments">
                        {r.payments.map((p) => (
                            <li key={p.id} className="rounded-control border border-line-soft p-3 text-sm">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium text-ink">
                                        {p.method_offline !== null ? t(`offlineMethod.${p.method_offline}`) : t(`paymentPurpose.${p.purpose}`, { defaultValue: p.purpose })}
                                    </span>
                                    <span className="text-ink">{`${t(`paymentStatus.${p.status}`, { defaultValue: p.status })} · ${formatMoney(p.amount)}`}</span>
                                </div>
                                {p.events.length > 0 && (
                                    <ul className="mt-2 space-y-0.5 text-xs text-muted">
                                        {p.events.map((e, index) => (
                                            <li key={`${e.type}-${index}`}>
                                                {`${formatDateTime(e.occurred_at)} · ${e.type}${e.amount !== null ? ` · ${formatMoney(e.amount)}` : ''}`}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {r.refunds.length > 0 && (
                    <div className="mt-4">
                        <h3 className="mb-2 text-sm font-semibold text-ink">{t('reservation.refunds')}</h3>
                        <ul className="space-y-1 text-sm text-ink">
                            {r.refunds.map((f) => (
                                <li key={f.id}>
                                    {`${formatMoney(f.amount)} · ${t(`refundRowStatus.${f.status}`, { defaultValue: f.status })} · ${t('reservation.attempts', { count: f.attempts })}`}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </Section>

            {r.changes.length > 0 && (
                <Section title={t('reservation.changes')}>
                    <ul className="space-y-2 text-sm text-ink">
                        {r.changes.map((c) => (
                            <li key={c.occurred_at}>
                                <span className="text-muted">{formatDateTime(c.occurred_at)}</span>
                                {` · ${formatDate(c.previous.check_in)} → ${formatDate(c.previous.check_out)} (${formatMoney(c.previous.contract_total)})`}
                                {` ⇒ ${formatDate(c.new.check_in)} → ${formatDate(c.new.check_out)} (${formatMoney(c.new.contract_total)})`}
                                {c.note !== null && <span className="block text-muted">{c.note}</span>}
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <Section title={t('reservation.history')}>
                <ul className="space-y-1 text-sm text-ink" data-testid="history">
                    {r.history.map((h, index) => (
                        <li key={`${h.action}-${index}`}>
                            <span className="text-muted">{formatDateTime(h.occurred_at)}</span>
                            {` · ${t(`history.${h.action.replace(/\./g, '_')}`, { defaultValue: h.action })} · ${t(`actor.${h.actor_type}`, { defaultValue: h.actor_type })}`}
                        </li>
                    ))}
                </ul>
            </Section>
        </main>
    );
}

function deadlines(r: ReservationDetail, t: (key: string) => string): [string, string][] {
    const rows: [string, string][] = [];

    if (r.status === 'AWAITING_APPROVAL' && r.approval_deadline !== null) {
        rows.push([t('reservation.approvalDeadline'), formatDateTime(r.approval_deadline)]);
    }

    if (r.status === 'CAPTURE_FAILED' && r.decision_deadline !== null) {
        rows.push([t('reservation.decisionDeadline'), formatDateTime(r.decision_deadline)]);
    }

    if (r.recovery_deadline !== null && r.status === 'AWAITING_PAYMENT') {
        rows.push([t('reservation.recoveryDeadline'), formatDateTime(r.recovery_deadline)]);
    }

    if (r.confirmed_at !== null) {
        rows.push([t('reservation.confirmedAt'), formatDateTime(r.confirmed_at)]);
    }

    if (r.cancelled_at !== null) {
        rows.push([t('reservation.cancelledAt'), formatDateTime(r.cancelled_at)]);
    }

    return rows;
}

function Rows({ rows }: { rows: [string, string][] }) {
    return (
        <dl className="space-y-1 text-sm">
            {rows.map(([label, value]) => (
                <div key={label} className="flex justify-between gap-4">
                    <dt className="text-muted">{label}</dt>
                    <dd className="text-right text-ink">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

/** Azioni ammesse dallo stato; un solo modulo aperto alla volta. */
function Actions({ reservation }: { reservation: ReservationDetail }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState<ReservationAction | null>(null);
    const [recovery, setRecovery] = useState<ReservationDetail['recovery'] | null>(null);

    if (reservation.actions.length === 0) {
        return null;
    }

    const close = () => setOpen(null);

    return (
        <Section title={t('reservation.actions')}>
            <div className="flex flex-wrap gap-2" data-testid="reservation-actions">
                {reservation.actions.map((action) => (
                    <button
                        key={action}
                        type="button"
                        className={open === action ? primaryButton : action === 'cancel' || action === 'reject' ? dangerButton : secondaryButton}
                        onClick={() => setOpen(open === action ? null : action)}
                        data-testid={`action-${action}`}
                    >
                        {t(`reservationActions.${action}`)}
                    </button>
                ))}
            </div>
            <div className="mt-4">
                {open === 'approve' && <ConfirmAction reservation={reservation} text={t('reservation.approveHint')} run={() => reservationsApi.approve(reservation.id)} onDone={close} />}
                {open === 'release_dates' && (
                    <ConfirmAction reservation={reservation} text={t('reservation.releaseHint')} run={() => reservationsApi.releaseDates(reservation.id)} onDone={close} />
                )}
                {open === 'reject' && <RejectForm reservation={reservation} onDone={close} />}
                {open === 'cancel' && <CancelForm reservation={reservation} onDone={close} />}
                {open === 'modify' && <ModifyForm reservation={reservation} onDone={close} />}
                {open === 'offline_payment' && <OfflinePaymentForm reservation={reservation} onDone={close} />}
                {open === 'recovery_link' && <RecoveryForm reservation={reservation} onDone={(link) => {
                    setRecovery(link);
                    close();
                }} />}
                {recovery != null && (
                    <div className="rounded-control bg-emerald-50 p-3 text-sm text-emerald-900" role="status">
                        <p>{t('reservation.recoveryCreated', { expires: formatDateTime(recovery.expires_at) })}</p>
                        <input readOnly className={`${inputClass} font-mono text-xs`} value={recovery.checkout_url} onFocus={(e) => e.target.select()} />
                    </div>
                )}
            </div>
        </Section>
    );
}

function useAction<TVars>(reservation: ReservationDetail, run: (vars: TVars) => DetailRequest, onDone: (result: ReservationDetail) => void) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: run,
        onSuccess: (updated) => {
            queryClient.setQueryData(['reservation', reservation.id], updated);
            void queryClient.invalidateQueries({ queryKey: ['reservation', reservation.id] });
            void queryClient.invalidateQueries({ queryKey: ['reservations'] });
            void queryClient.invalidateQueries({ queryKey: ['calendar'] });
            onDone(updated);
        },
        onError: (error) => {
            if (error instanceof ApiError && (error.code === 'VERSION_MISMATCH' || error.code === 'ILLEGAL_TRANSITION')) {
                void queryClient.invalidateQueries({ queryKey: ['reservation', reservation.id] });
            }
        },
    });
}

function ActionError({ error }: { error: unknown }) {
    const errorMessage = useErrorMessage();

    return error === null || Object.keys(fieldErrors(error)).length > 0 ? null : (
        <div className="mt-3">
            <FormError message={errorMessage(error)} />
        </div>
    );
}

function ActionForm({ onSubmit, children, pending, submitLabel, onCancel }: { onSubmit: () => void; children?: ReactNode; pending: boolean; submitLabel: string; onCancel: () => void }) {
    const { t } = useTranslation();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSubmit();
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-control border border-line-soft bg-canvas p-4" data-testid="action-form">
            {children}
            <div className="flex gap-2">
                <button type="submit" className={primaryButton} disabled={pending}>
                    {pending ? t('common.wait') : submitLabel}
                </button>
                <button type="button" className={secondaryButton} onClick={onCancel}>
                    {t('common.cancel')}
                </button>
            </div>
        </form>
    );
}

function ConfirmAction({ reservation, text, run, onDone }: { reservation: ReservationDetail; text: string; run: () => DetailRequest; onDone: () => void }) {
    const { t } = useTranslation();
    const action = useAction(reservation, run, onDone);

    return (
        <>
            <ActionForm onSubmit={() => action.mutate(undefined)} pending={action.isPending} submitLabel={t('reservation.confirm')} onCancel={onDone}>
                <p className="text-sm text-ink">{text}</p>
            </ActionForm>
            <ActionError error={action.error} />
        </>
    );
}

function RejectForm({ reservation, onDone }: { reservation: ReservationDetail; onDone: () => void }) {
    const { t } = useTranslation();
    const [reason, setReason] = useState('');
    const action = useAction(reservation, () => reservationsApi.reject(reservation.id, reason.trim() === '' ? null : reason.trim()), onDone);

    return (
        <>
            <ActionForm onSubmit={() => action.mutate(undefined)} pending={action.isPending} submitLabel={t('reservationActions.reject')} onCancel={onDone}>
                <p className="text-sm text-ink">{t('reservation.rejectHint')}</p>
                <Field label={t('reservation.reasonLabel')} errors={fieldErrors(action.error).reason}>
                    <textarea className={inputClass} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
                </Field>
            </ActionForm>
            <ActionError error={action.error} />
        </>
    );
}

function CancelForm({ reservation, onDone }: { reservation: ReservationDetail; onDone: () => void }) {
    const { t } = useTranslation();
    const proposed = reservation.cancellation_refund?.proposed ?? { amount_cents: 0, currency: reservation.contract_total.currency };
    const max: Money = reservation.cancellation_refund?.max ?? proposed;
    const [refund, setRefund] = useState(centsToEuroInput(proposed.amount_cents));
    const [reason, setReason] = useState('');
    const [invalid, setInvalid] = useState(false);
    const action = useAction(reservation, (cents: number) => reservationsApi.cancel(reservation.id, cents, reason.trim() === '' ? null : reason.trim()), onDone);
    const errors = fieldErrors(action.error);

    const submit = () => {
        const cents = parseEuroToCents(refund);
        setInvalid(cents === null);

        if (cents !== null) {
            action.mutate(cents);
        }
    };

    return (
        <>
            <ActionForm onSubmit={submit} pending={action.isPending} submitLabel={t('reservationActions.cancel')} onCancel={onDone}>
                <p className="text-sm text-ink">{t('reservation.cancelHint', { proposed: formatMoney(proposed), max: formatMoney(max) })}</p>
                <Field label={t('reservation.refundLabel')} errors={invalid ? [t('common.invalidAmount')] : errors.refund_cents} hint={t('reservation.refundHint')}>
                    <TextInput value={refund} onChange={setRefund} inputMode="decimal" />
                </Field>
                <Field label={t('reservation.reasonLabel')} errors={errors.reason}>
                    <textarea className={inputClass} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
                </Field>
            </ActionForm>
            {/* Rimborso > 0: password richiesta (08, ADR-0012 punto 24); dopo la conferma la cancellazione riparte. */}
            {needsReauth(action.error) ? <ReauthPrompt onConfirmed={submit} onCancel={() => action.reset()} /> : <ActionError error={action.error} />}
        </>
    );
}

function ModifyForm({ reservation, onDone }: { reservation: ReservationDetail; onDone: () => void }) {
    const { t } = useTranslation();
    const [checkIn, setCheckIn] = useState(reservation.check_in);
    const [checkOut, setCheckOut] = useState(reservation.check_out);
    const [adults, setAdults] = useState(String(reservation.adults));
    const [children, setChildren] = useState(String(reservation.children));
    const [note, setNote] = useState('');
    const input = (): ModifyInput => ({
        check_in: checkIn,
        check_out: checkOut,
        adults: Number.parseInt(adults, 10),
        children: Number.parseInt(children, 10),
        note: note.trim() === '' ? null : note.trim(),
    });
    // Prima il server calcola l'anteprima (nulla viene salvato), poi il gestore conferma (ADR-0011 punto 28).
    const preview = useMutation({ mutationFn: () => reservationsApi.previewModify(reservation.id, input()) });
    const action = useAction(
        reservation,
        (expected: number) => reservationsApi.modify(reservation.id, reservation.version, { ...input(), expected_total_cents: expected }),
        onDone,
    );
    const errors = fieldErrors(preview.error ?? action.error);
    const back = () => {
        preview.reset();
    };

    if (preview.data !== undefined) {
        const { current, proposed, difference } = preview.data;

        return (
            <>
                <ActionForm
                    onSubmit={() => action.mutate(proposed.contract_total.amount_cents)}
                    pending={action.isPending}
                    submitLabel={t('reservation.confirmModify')}
                    onCancel={back}
                >
                    <p className="text-sm text-ink">{t('reservation.modifyPreviewHint')}</p>
                    <div className="grid gap-4 sm:grid-cols-2" data-testid="modify-preview">
                        <StayPriceColumn title={t('reservation.before')} price={current} />
                        <StayPriceColumn title={t('reservation.after')} price={proposed} />
                    </div>
                    <p className="text-sm font-medium text-ink" data-testid="modify-difference">
                        {t('reservation.difference', { amount: formatMoney(difference) })}
                    </p>
                </ActionForm>
                <ActionError error={action.error} />
            </>
        );
    }

    return (
        <>
            <ActionForm onSubmit={() => preview.mutate()} pending={preview.isPending} submitLabel={t('reservation.previewModify')} onCancel={onDone}>
                <p className="text-sm text-ink">{t('reservation.modifyHint')}</p>
                <div className="grid gap-3 sm:grid-cols-4">
                    <Field label={t('reservation.checkIn')} errors={errors.check_in}>
                        <TextInput type="date" value={checkIn} onChange={setCheckIn} />
                    </Field>
                    <Field label={t('reservation.checkOut')} errors={errors.check_out}>
                        <TextInput type="date" value={checkOut} onChange={setCheckOut} />
                    </Field>
                    <Field label={t('reservation.adults')} errors={errors.adults}>
                        <TextInput type="number" value={adults} onChange={setAdults} min={1} />
                    </Field>
                    <Field label={t('reservation.children')} errors={errors.children}>
                        <TextInput type="number" value={children} onChange={setChildren} min={0} />
                    </Field>
                </div>
                <Field label={t('reservation.changeNote')} errors={errors.note}>
                    <TextInput value={note} onChange={setNote} />
                </Field>
            </ActionForm>
            <ActionError error={preview.error ?? action.error} />
        </>
    );
}

function StayPriceColumn({ title, price }: { title: string; price: StayPrice }) {
    const { t } = useTranslation();

    return (
        <div className="rounded-control border border-line-soft p-3 text-sm">
            <p className="font-medium text-ink">{title}</p>
            <p className="text-ink">
                {t('reservation.previewStay', {
                    checkIn: formatDate(price.check_in),
                    checkOut: formatDate(price.check_out),
                    count: price.nights,
                    adults: price.adults,
                    children: price.children,
                })}
            </p>
            <ul className="mt-2 divide-y divide-slate-100">
                {price.price_lines.map((line, index) => (
                    <li key={`${line.kind}-${line.date ?? ''}-${index}`} className="flex justify-between gap-4 py-1">
                        <span className="text-ink">
                            {t(`priceLines.${line.kind}`, { defaultValue: line.kind })}
                            {line.date !== null && ` · ${formatDate(line.date)}`}
                            {line.quantity > 1 && ` × ${line.quantity}`}
                        </span>
                        <span className="tabular-nums">{formatMoney(line.amount)}</span>
                    </li>
                ))}
            </ul>
            <p className="mt-2 flex justify-between font-medium text-ink">
                <span>{t('reservation.total')}</span>
                <span className="tabular-nums">{formatMoney(price.contract_total)}</span>
            </p>
        </div>
    );
}

function OfflinePaymentForm({ reservation, onDone }: { reservation: ReservationDetail; onDone: () => void }) {
    const { t } = useTranslation();
    const [amount, setAmount] = useState(centsToEuroInput(reservation.outstanding.amount_cents));
    const [method, setMethod] = useState<OfflineMethod>('BANK_TRANSFER');
    const [invalid, setInvalid] = useState(false);
    const action = useAction(reservation, (cents: number) => reservationsApi.offlinePayment(reservation.id, cents, method), onDone);
    const errors = fieldErrors(action.error);

    const submit = () => {
        const cents = parseEuroToCents(amount);
        setInvalid(cents === null);

        if (cents !== null) {
            action.mutate(cents);
        }
    };

    return (
        <>
            <ActionForm onSubmit={submit} pending={action.isPending} submitLabel={t('reservationActions.offline_payment')} onCancel={onDone}>
                <p className="text-sm text-ink">{t('reservation.offlineHint', { outstanding: formatMoney(reservation.outstanding) })}</p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label={t('reservation.amount')} errors={invalid ? [t('common.invalidAmount')] : errors.amount_cents}>
                        <TextInput value={amount} onChange={setAmount} inputMode="decimal" />
                    </Field>
                    <Field label={t('reservation.method')} errors={errors.method}>
                        <select className={inputClass} value={method} onChange={(e) => setMethod(e.target.value as OfflineMethod)}>
                            {OFFLINE_METHODS.map((m) => (
                                <option key={m} value={m}>
                                    {t(`offlineMethod.${m}`)}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
            </ActionForm>
            <ActionError error={action.error} />
        </>
    );
}

function RecoveryForm({ reservation, onDone }: { reservation: ReservationDetail; onDone: (link: ReservationDetail['recovery']) => void }) {
    const { t } = useTranslation();
    const [hours, setHours] = useState('12');
    const action = useAction(reservation, () => reservationsApi.recoveryLink(reservation.id, Number.parseInt(hours, 10)), (updated) => onDone(updated.recovery));

    return (
        <>
            <ActionForm onSubmit={() => action.mutate(undefined)} pending={action.isPending} submitLabel={t('reservationActions.recovery_link')} onCancel={() => onDone(undefined)}>
                <p className="text-sm text-ink">{t('reservation.recoveryHint')}</p>
                <Field label={t('reservation.recoveryHours')} errors={fieldErrors(action.error).deadline_hours}>
                    <TextInput type="number" value={hours} onChange={setHours} min={1} max={24} />
                </Field>
            </ActionForm>
            <ActionError error={action.error} />
        </>
    );
}
