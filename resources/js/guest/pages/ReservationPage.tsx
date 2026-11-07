import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams, useSearchParams } from 'react-router';

import { ApiError, publicApi, type ReservationStatus } from '../api';
import { Notice, Shell, Spinner } from '../components';
import { formatDate, formatDateTime, formatMoney } from '../format';
import { loadBooking, saveBooking } from '../storage';

const POLL_MS = 2000;
const POLL_LIMIT_MS = 60_000;
/** Stati in cui la conferma arriva dal webhook (mai dal redirect, I6): si continua a chiedere. */
const PENDING = new Set(['AWAITING_PAYMENT', 'CAPTURING']);

/**
 * Pagina di ritorno dal pagamento (3.7). Il redirect non conferma nulla: la pagina legge lo stato dal
 * server ogni 2 secondi fino alla conferma del webhook, per al massimo un minuto, poi rimanda all'email.
 * Accesso con codice + token (sessionStorage della scheda o link dell'email).
 */
export function ReservationPage() {
    const { t } = useTranslation();
    const { code = '' } = useParams();
    const [search] = useSearchParams();
    const cancelled = search.get('cancelled') === '1';
    const stored = useMemo(() => loadBooking(code), [code]);
    const token = search.get('token') ?? stored?.token ?? null;
    const [startedAt] = useState(() => Date.now());

    if (token !== null && stored === null) {
        saveBooking(code, { token, checkoutUrl: null, checkoutExpiresAt: null });
    }

    const status = useQuery({
        queryKey: ['reservation', code, token],
        queryFn: () => publicApi.status(code, token ?? ''),
        enabled: token !== null,
        retry: (count, error) => !(error instanceof ApiError && error.status === 404) && count < 2,
        refetchInterval: (query) => {
            const current = query.state.data?.status;

            if (current === undefined || !PENDING.has(current) || (cancelled && current === 'AWAITING_PAYMENT')) {
                return false;
            }

            return Date.now() - startedAt < POLL_LIMIT_MS ? POLL_MS : false;
        },
    });

    if (token === null) {
        return (
            <Shell>
                <Notice>{t('reservation.noToken')}</Notice>
                <BackLink />
            </Shell>
        );
    }

    if (status.isPending) {
        return (
            <Shell>
                <Spinner />
            </Shell>
        );
    }

    if (status.isError) {
        return (
            <Shell>
                <Notice tone="error">
                    {status.error instanceof ApiError && status.error.status === 404 ? t('reservation.noToken') : t('common.loadError')}
                </Notice>
                <BackLink />
            </Shell>
        );
    }

    const reservation = status.data;
    const timedOut = Date.now() - startedAt >= POLL_LIMIT_MS;
    const resumeUrl = cancelled && reservation.status === 'AWAITING_PAYMENT' ? resumableCheckout(stored) : null;

    return (
        <Shell title={reservation.property.name}>
            <h1 className="text-2xl font-semibold text-ink">{t('reservation.title')}</h1>
            <p className="mt-1 text-sm text-muted">{t('reservation.code', { code: reservation.reservation_code })}</p>
            <div className="mt-6" data-testid="reservation-status" data-status={reservation.status}>
                <StatusMessage reservation={reservation} cancelled={cancelled} timedOut={timedOut} resumeUrl={resumeUrl} />
            </div>
            <Summary reservation={reservation} />
            {(reservation.property.contact_email !== null || reservation.property.contact_phone !== null) && (
                <p className="mt-6 text-sm text-muted">
                    {t('reservation.contactProperty', {
                        contact: [reservation.property.contact_email, reservation.property.contact_phone].filter(Boolean).join(' · '),
                    })}
                </p>
            )}
            <BackLink />
        </Shell>
    );
}

function resumableCheckout(stored: ReturnType<typeof loadBooking>): string | null {
    if (stored?.checkoutUrl == null) {
        return null;
    }

    if (stored.checkoutExpiresAt !== null && new Date(stored.checkoutExpiresAt).getTime() <= Date.now()) {
        return null;
    }

    return stored.checkoutUrl;
}

function StatusMessage({
    reservation,
    cancelled,
    timedOut,
    resumeUrl,
}: {
    reservation: ReservationStatus;
    cancelled: boolean;
    timedOut: boolean;
    resumeUrl: string | null;
}) {
    const { t, i18n } = useTranslation();

    switch (reservation.status) {
        case 'AWAITING_PAYMENT':
            if (cancelled) {
                return (
                    <Notice tone="warning">
                        <p className="font-medium">{t('reservation.cancelledByGuest')}</p>
                        <p className="mt-1">{t('reservation.cancelledByGuestHint')}</p>
                        {resumeUrl !== null && (
                            <a href={resumeUrl} target="_top" className="mt-3 inline-block rounded-card bg-brand-700 px-4 py-2.5 font-medium text-white">
                                {t('reservation.resumePayment')}
                            </a>
                        )}
                    </Notice>
                );
            }

            return timedOut ? (
                <Notice tone="warning">{t('reservation.stillWaiting')}</Notice>
            ) : (
                <Notice>
                    <p className="font-medium">{t('reservation.waiting')}</p>
                    <p className="mt-1">{t('reservation.waitingHint')}</p>
                </Notice>
            );
        case 'CAPTURING':
            return <Notice>{timedOut ? t('reservation.stillWaiting') : t('reservation.capturing')}</Notice>;
        case 'CONFIRMED':
            return (
                <Notice tone="success">
                    <p className="font-medium">{t('reservation.confirmed')}</p>
                    <p className="mt-1">{t('reservation.confirmedHint')}</p>
                </Notice>
            );
        case 'AWAITING_APPROVAL':
        case 'CAPTURE_FAILED':
            return (
                <Notice tone="success">
                    <p className="font-medium">{t('reservation.awaitingApproval')}</p>
                    {reservation.approval_deadline !== null && (
                        <p className="mt-1">{t('reservation.awaitingApprovalHint', { deadline: formatDateTime(reservation.approval_deadline, i18n.language) })}</p>
                    )}
                </Notice>
            );
        case 'PAYMENT_FAILED':
            return (
                <Notice tone="error">
                    <p className="font-medium">{t('reservation.paymentFailed')}</p>
                    <p className="mt-1">{t('reservation.paymentFailedHint')}</p>
                </Notice>
            );
        case 'EXPIRED':
            return (
                <Notice tone="warning">
                    <p className="font-medium">{t('reservation.expired')}</p>
                    <p className="mt-1">{t('reservation.expiredHint')}</p>
                </Notice>
            );
        case 'REJECTED':
            return (
                <Notice tone="warning">
                    <p className="font-medium">{t('reservation.rejected')}</p>
                    <p className="mt-1">{t('reservation.rejectedHint')}</p>
                </Notice>
            );
        default:
            return <Notice tone="warning">{t('reservation.cancelled')}</Notice>;
    }
}

function Summary({ reservation }: { reservation: ReservationStatus }) {
    const { t, i18n } = useTranslation();
    const money = (value: ReservationStatus['paid']) => formatMoney(value, i18n.language);

    return (
        <section className="mt-6 rounded-card border border-line-soft bg-surface p-4">
            <h2 className="font-semibold text-ink">{t('reservation.summary')}</h2>
            <p className="mt-2 text-ink">{reservation.unit.name}</p>
            <p className="text-sm text-muted">
                {t('reservation.stay', {
                    checkIn: formatDate(reservation.check_in, i18n.language),
                    checkOut: formatDate(reservation.check_out, i18n.language),
                })}{' '}
                · {t('booking.nights', { count: reservation.nights })} · {t('reservation.guests', { count: reservation.adults + reservation.children })}
            </p>
            <dl className="mt-3 space-y-1 border-t border-slate-100 pt-3 text-sm">
                <div className="flex justify-between font-semibold">
                    <dt>{t('reservation.total')}</dt>
                    <dd className="tabular-nums">{money(reservation.contract_total)}</dd>
                </div>
                <div className="flex justify-between text-ink">
                    <dt>{t('reservation.paid')}</dt>
                    <dd className="tabular-nums">{money(reservation.paid)}</dd>
                </div>
                {reservation.status === 'CONFIRMED' && reservation.outstanding.amount_cents > 0 && (
                    <div className="flex justify-between text-ink">
                        <dt>{t('reservation.outstanding')}</dt>
                        <dd className="tabular-nums">{money(reservation.outstanding)}</dd>
                    </div>
                )}
            </dl>
            {reservation.cancellation_policy !== null && (
                <p className="mt-3 text-sm text-muted">
                    {t('booking.cancellation')}: {reservation.cancellation_policy.title}
                </p>
            )}
        </section>
    );
}

function BackLink() {
    const { t } = useTranslation();

    return (
        <Link to="/" className="mt-6 inline-block text-sm text-ink underline">
            {t('reservation.backToProperty')}
        </Link>
    );
}
