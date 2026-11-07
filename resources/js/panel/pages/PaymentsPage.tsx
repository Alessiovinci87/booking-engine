import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';

import { type RefundRow, paymentsApi } from '../api/payments';
import { reservationsApi, type ReservationSummary } from '../api/reservations';
import { useErrorMessage } from '../auth/session';
import { FormError, Section, dangerButton, inputClass, primaryButton, secondaryButton } from '../components/Form';
import { ReauthPrompt, needsReauth } from '../components/Reauth';
import { formatDateTime } from '../dates';
import { formatMoney } from '../money';

/**
 * Pagamenti (4.10): collegamento Stripe con onboarding ospitato, richieste da approvare con il tempo
 * rimasto, pagamenti da recuperare, rimborsi da gestire (Riprova / Segna come rimborsato fuori
 * piattaforma) e avvisi di contestazione. Al ritorno dall'onboarding (?stripe=return) lo stato si rilegge
 * da Stripe.
 */
export function PaymentsPage() {
    const { t } = useTranslation();

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <h1 className="text-2xl font-semibold text-slate-900">{t('payments.title')}</h1>
            <DisputesNotice />
            <StripeAccountSection />
            <ApprovalRequestsSection />
            <CaptureFailedSection />
            <RefundsSection />
        </main>
    );
}

function StripeAccountSection() {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const [params] = useSearchParams();
    const returned = params.get('stripe') === 'return';
    const account = useQuery({ queryKey: ['stripe-account', returned], queryFn: () => paymentsApi.stripeAccount(returned) });
    const onboarding = useMutation({
        mutationFn: paymentsApi.onboardingLink,
        onSuccess: (response) => window.location.assign(response.data.url),
    });
    const info = account.data?.data;

    return (
        <Section title={t('payments.stripe.title')}>
            {account.isError && <FormError message={errorMessage(account.error)} />}
            {info === undefined ? (
                !account.isError && <p className="text-slate-600">{t('common.loading')}</p>
            ) : (
                <div className="space-y-3 text-sm" data-testid="stripe-account" data-status={info.status}>
                    <p className="font-medium text-slate-900">{t(`payments.stripe.status.${info.status}`)}</p>
                    <p className="text-slate-600">{t(`payments.stripe.hint.${info.status}`)}</p>
                    {info.demo === true && <p className="text-amber-700">{t('payments.stripe.demo')}</p>}
                    {params.get('stripe') === 'refresh' && <p className="text-amber-700">{t('payments.stripe.linkExpired')}</p>}
                    {info.status !== 'ENABLED' && (
                        <button type="button" className={primaryButton} disabled={onboarding.isPending} onClick={() => onboarding.mutate()} data-testid="stripe-onboarding">
                            {info.connected ? t('payments.stripe.continue') : t('payments.stripe.connect')}
                        </button>
                    )}
                    {onboarding.isError &&
                        (needsReauth(onboarding.error) ? (
                            <ReauthPrompt onConfirmed={() => onboarding.mutate()} onCancel={() => onboarding.reset()} />
                        ) : (
                            <FormError message={errorMessage(onboarding.error)} />
                        ))}
                </div>
            )}
        </Section>
    );
}

/** Tempo rimasto prima di una scadenza, aggiornato ogni minuto. */
function useRemaining(deadline: string | null): string | null {
    const { t } = useTranslation();
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 60_000);

        return () => window.clearInterval(timer);
    }, []);

    if (deadline === null) {
        return null;
    }

    const minutes = Math.floor((new Date(deadline).getTime() - now) / 60_000);

    if (minutes <= 0) {
        return t('payments.remaining.expired');
    }

    return t('payments.remaining.value', { hours: Math.floor(minutes / 60), minutes: minutes % 60 });
}

function DeadlineRow({ reservation, deadline }: { reservation: ReservationSummary; deadline: string | null }) {
    const { t } = useTranslation();
    const remaining = useRemaining(deadline);

    return (
        <li className="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
            <span>
                <Link to={`/prenotazioni/${reservation.id}`} className="font-mono text-blue-700 hover:underline">
                    {reservation.code}
                </Link>
                {` · ${reservation.unit.name} · ${formatMoney(reservation.contract_total)}`}
            </span>
            {deadline !== null && (
                <span className="text-slate-700" title={formatDateTime(deadline)}>
                    {t('payments.remaining.label', { remaining })}
                </span>
            )}
        </li>
    );
}

function ApprovalRequestsSection() {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const list = useQuery({ queryKey: ['reservations', { status: ['AWAITING_APPROVAL'] }], queryFn: () => reservationsApi.list({ status: ['AWAITING_APPROVAL'] }) });
    const rows = list.data?.data ?? [];

    return (
        <Section title={t('payments.approvals.title')}>
            <p className="mb-3 rounded-md bg-amber-50 p-3 text-sm text-amber-900" data-testid="approval-reminder">
                {t('payments.approvals.reminder')}
            </p>
            {list.isError && <FormError message={errorMessage(list.error)} />}
            {rows.length === 0 ? (
                <p className="text-sm text-slate-600">{list.isPending ? t('common.loading') : t('payments.approvals.empty')}</p>
            ) : (
                <ul className="divide-y divide-slate-100" data-testid="approval-requests">
                    {rows.map((r) => (
                        <DeadlineRow key={r.id} reservation={r} deadline={r.approval_deadline} />
                    ))}
                </ul>
            )}
        </Section>
    );
}

function CaptureFailedSection() {
    const { t } = useTranslation();
    const list = useQuery({ queryKey: ['reservations', { status: ['CAPTURE_FAILED'] }], queryFn: () => reservationsApi.list({ status: ['CAPTURE_FAILED'] }) });
    const rows = list.data?.data ?? [];

    if (rows.length === 0) {
        return null;
    }

    return (
        <Section title={t('payments.recovery.title')}>
            <p className="mb-3 text-sm text-slate-600">{t('payments.recovery.hint')}</p>
            <ul className="divide-y divide-slate-100" data-testid="capture-failed">
                {rows.map((r) => (
                    <DeadlineRow key={r.id} reservation={r} deadline={r.decision_deadline} />
                ))}
            </ul>
        </Section>
    );
}

function RefundsSection() {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const list = useQuery({ queryKey: ['refunds', 'open'], queryFn: () => paymentsApi.refunds(['FAILED', 'PENDING']) });
    const rows = list.data?.data ?? [];

    return (
        <Section title={t('payments.refunds.title')}>
            {list.isError && <FormError message={errorMessage(list.error)} />}
            {rows.length === 0 ? (
                <p className="text-sm text-slate-600">{list.isPending ? t('common.loading') : t('payments.refunds.empty')}</p>
            ) : (
                <ul className="divide-y divide-slate-100" data-testid="refunds">
                    {rows.map((refund) => (
                        <RefundItem key={refund.id} refund={refund} />
                    ))}
                </ul>
            )}
        </Section>
    );
}

function RefundItem({ refund }: { refund: RefundRow }) {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const queryClient = useQueryClient();
    const [offline, setOffline] = useState(false);
    const [note, setNote] = useState('');
    const done = () => {
        setOffline(false);
        void queryClient.invalidateQueries({ queryKey: ['refunds'] });
        void queryClient.invalidateQueries({ queryKey: ['reservations'] });
    };
    const retry = useMutation({ mutationFn: () => paymentsApi.retryRefund(refund.id), onSuccess: done });
    const markOffline = useMutation({ mutationFn: () => paymentsApi.markRefundOffline(refund.id, note.trim()), onSuccess: done });
    const amount = formatMoney({ amount_cents: refund.amount_cents, currency: 'EUR' });

    return (
        <li className="space-y-2 py-3 text-sm" data-testid="refund-row" data-status={refund.status}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <span>
                    {refund.reservation !== null && (
                        <Link to={`/prenotazioni/${refund.reservation.id}`} className="font-mono text-blue-700 hover:underline">
                            {refund.reservation.code}
                        </Link>
                    )}
                    {` · ${amount} · ${t(`refundRowStatus.${refund.status}`, { defaultValue: refund.status })} · ${t('reservation.attempts', { count: refund.attempts })}`}
                    {refund.next_attempt_at !== null && (
                        <span className="block text-xs text-slate-500">{t('payments.refunds.nextAttempt', { at: formatDateTime(refund.next_attempt_at) })}</span>
                    )}
                </span>
                {refund.status === 'FAILED' && (
                    <span className="flex gap-2">
                        <button type="button" className={secondaryButton} disabled={retry.isPending} onClick={() => retry.mutate()}>
                            {t('payments.refunds.retry')}
                        </button>
                        <button type="button" className={dangerButton} onClick={() => setOffline((value) => !value)}>
                            {t('payments.refunds.markOffline')}
                        </button>
                    </span>
                )}
            </div>
            {offline && (
                <form
                    className="space-y-2 rounded-md bg-slate-50 p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        markOffline.mutate();
                    }}
                >
                    <label className="block text-slate-700">
                        {t('payments.refunds.offlineNote')}
                        <textarea className={`${inputClass} mt-1`} rows={2} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} required minLength={3} />
                    </label>
                    <p className="text-xs text-slate-500">{t('payments.refunds.offlineHint', { amount })}</p>
                    <button type="submit" className={primaryButton} disabled={markOffline.isPending || note.trim().length < 3}>
                        {t('payments.refunds.confirmOffline')}
                    </button>
                </form>
            )}
            {retry.isError &&
                (needsReauth(retry.error) ? (
                    <ReauthPrompt onConfirmed={() => retry.mutate()} onCancel={() => retry.reset()} />
                ) : (
                    <FormError message={errorMessage(retry.error)} />
                ))}
            {markOffline.isError &&
                (needsReauth(markOffline.error) ? (
                    <ReauthPrompt onConfirmed={() => markOffline.mutate()} onCancel={() => markOffline.reset()} />
                ) : (
                    <FormError message={errorMessage(markOffline.error)} />
                ))}
        </li>
    );
}

function DisputesNotice() {
    const { t } = useTranslation();
    const disputes = useQuery({ queryKey: ['disputes'], queryFn: paymentsApi.disputes });
    const rows = disputes.data?.data ?? [];

    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900" role="alert" data-testid="disputes">
            <p className="font-semibold">{t('payments.disputes.title', { count: rows.length })}</p>
            <p className="mt-1">{t('payments.disputes.hint')}</p>
            <ul className="mt-2 list-disc pl-5">
                {rows.map((d) => (
                    <li key={d.dispute_id}>
                        {d.reservation !== null ? (
                            <Link to={`/prenotazioni/${d.reservation.id}`} className="font-mono underline">
                                {d.reservation.code}
                            </Link>
                        ) : (
                            d.payment_id
                        )}
                        {d.amount_cents !== null && ` · ${formatMoney({ amount_cents: d.amount_cents, currency: 'EUR' })}`}
                        {` · ${formatDateTime(d.opened_at)}`}
                    </li>
                ))}
            </ul>
        </div>
    );
}
