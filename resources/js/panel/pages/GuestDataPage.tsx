import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router';

import { privacyApi } from '../api/privacy';
import { useErrorMessage } from '../auth/session';
import { FormError, primaryButton } from '../components/Form';
import { ReauthPrompt, needsReauth } from '../components/Reauth';
import { formatDate, formatDateTime } from '../dates';
import { formatMoney } from '../money';

/**
 * Versione stampabile dei dati dell'ospite (6.3, 09 "Accesso / portabilità"): stessi dati dell'export
 * JSON; il PDF si ottiene con "Stampa → Salva come PDF" del browser (ADR-0014).
 */
export function GuestDataPage() {
    const { guestId = '' } = useParams();
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const exported = useQuery({ queryKey: ['guest-export', guestId], queryFn: () => privacyApi.exportGuest(guestId), staleTime: Infinity });

    if (exported.isPending) {
        return <p className="mx-auto max-w-3xl px-4 py-8 text-slate-600">{t('common.loading')}</p>;
    }

    if (exported.isError) {
        return (
            <main className="mx-auto max-w-3xl px-4 py-8">
                {needsReauth(exported.error) ? (
                    <ReauthPrompt onConfirmed={() => void exported.refetch()} onCancel={() => window.history.back()} />
                ) : (
                    <FormError message={errorMessage(exported.error)} />
                )}
            </main>
        );
    }

    const data = exported.data.data;
    const g = data.guest;

    return (
        <main className="mx-auto max-w-3xl space-y-6 px-4 py-8 text-sm" data-testid="guest-data">
            <div className="flex items-center justify-between gap-4">
                <h1 className="text-2xl font-semibold text-slate-900">{t('privacy.printTitle')}</h1>
                <button type="button" className={`${primaryButton} print:hidden`} onClick={() => window.print()}>
                    {t('privacy.print')}
                </button>
            </div>
            <p className="text-slate-600">{t('privacy.printGenerated', { date: formatDateTime(data.generated_at) })}</p>
            <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1">
                <dt className="font-medium">{t('reservation.guestName')}</dt>
                <dd>{`${g.first_name} ${g.last_name}`}</dd>
                <dt className="font-medium">{t('reservation.email')}</dt>
                <dd>{g.email}</dd>
                <dt className="font-medium">{t('reservation.phone')}</dt>
                <dd>{g.phone}</dd>
                <dt className="font-medium">{t('reservation.country')}</dt>
                <dd>{g.country_code}</dd>
                <dt className="font-medium">{t('reservation.language')}</dt>
                <dd>{g.locale.toUpperCase()}</dd>
            </dl>
            {g.notes !== null && <p className="whitespace-pre-line rounded-md bg-slate-50 p-3">{g.notes}</p>}
            {data.reservations.map((r) => (
                <section key={r.code} className="space-y-2 border-t border-slate-200 pt-4">
                    <h2 className="font-mono text-lg font-semibold">{r.code}</h2>
                    <p>{`${r.property ?? ''} · ${r.unit ?? ''} · ${formatDate(r.check_in)} → ${formatDate(r.check_out)} · ${t(`reservationStatus.${r.status}`)}`}</p>
                    <p>{`${t('reservation.amounts')}: ${formatMoney({ amount_cents: r.contract_total_cents, currency: r.currency })}`}</p>
                    <ul className="list-disc pl-5">
                        {r.payments.map((p, i) => (
                            <li key={`p${i}`}>{`${t('reservation.payments')}: ${formatMoney({ amount_cents: p.captured_cents, currency: r.currency })} (${p.status})`}</li>
                        ))}
                        {r.refunds.map((f, i) => (
                            <li key={`r${i}`}>{`${t('reservation.refunded')}: ${formatMoney({ amount_cents: f.amount_cents, currency: r.currency })} (${f.status})`}</li>
                        ))}
                    </ul>
                </section>
            ))}
            <p className="text-slate-600">{t('privacy.printCard')}</p>
        </main>
    );
}
