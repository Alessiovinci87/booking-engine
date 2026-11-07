import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { fieldErrors } from '../api/client';
import { QUOTE_STATUSES, type Quote, type QuoteStatus, quotesApi, type SentQuote } from '../api/quotes';
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
import { addDays, formatDate, formatDateTime, today } from '../dates';
import { formatMoney, parseEuroToCents } from '../money';

const OPEN: QuoteStatus[] = ['DRAFT', 'SENT', 'IN_CHECKOUT'];

const statusColors: Record<QuoteStatus, string> = {
    DRAFT: 'bg-surface-muted text-ink border-line',
    SENT: 'bg-sky-100 text-sky-900 border-sky-300',
    IN_CHECKOUT: 'bg-amber-100 text-warning border-amber-300',
    ACCEPTED: 'bg-emerald-100 text-emerald-900 border-emerald-300',
    EXPIRED: 'bg-surface-muted text-muted border-line',
    REVOKED: 'bg-surface-muted text-muted border-line',
    EXHAUSTED: 'bg-red-100 text-danger border-red-300',
};

/**
 * Preventivi (5.7): creazione con sconto esplicito (prezzo calcolato e congelato dal server, 04), elenco
 * per stato e azioni: "Copia link", "Apri WhatsApp", invio per email, ritiro. Il link e il testo del
 * messaggio arrivano dal server; il testo WhatsApp non contiene dati dell'ospite.
 */
export function QuotesPage() {
    const { t } = useTranslation();
    const [filter, setFilter] = useState<QuoteStatus[]>(OPEN);
    const quotes = useQuery({ queryKey: ['quotes', filter], queryFn: () => quotesApi.list(filter) });
    const errorMessage = useErrorMessage();

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <h1 className="text-2xl font-semibold text-ink">{t('quotes.title')}</h1>
            <p className="text-sm text-muted">{t('quotes.intro')}</p>

            <NewQuoteForm />

            <Section
                title={t('quotes.list')}
                actions={
                    <select
                        className="rounded-control border border-line px-2 py-1 text-sm"
                        value={filter.length === OPEN.length && OPEN.every((s) => filter.includes(s)) ? 'open' : (filter[0] ?? 'all')}
                        onChange={(e) => setFilter(e.target.value === 'open' ? OPEN : e.target.value === 'all' ? [] : [e.target.value as QuoteStatus])}
                        aria-label={t('quotes.filter')}
                        data-testid="quote-filter"
                    >
                        <option value="open">{t('quotes.filterOpen')}</option>
                        <option value="all">{t('quotes.filterAll')}</option>
                        {QUOTE_STATUSES.map((s) => (
                            <option key={s} value={s}>
                                {t(`quoteStatus.${s}`)}
                            </option>
                        ))}
                    </select>
                }
            >
                {quotes.isPending && <Loading />}
                {quotes.isError && <FormError message={errorMessage(quotes.error)} />}
                {quotes.data?.length === 0 && <EmptyState title={t('quotes.empty')} />}
                <ul className="divide-y divide-line-soft" data-testid="quote-list">
                    {quotes.data?.map((quote) => <QuoteRow key={quote.id} quote={quote} />)}
                </ul>
            </Section>
        </main>
    );
}

function NewQuoteForm() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const { options, isPending } = useAllUnits();
    const [unitId, setUnitId] = useState('');
    const [checkIn, setCheckIn] = useState(addDays(today(), 7));
    const [checkOut, setCheckOut] = useState(addDays(today(), 10));
    const [adults, setAdults] = useState('2');
    const [children, setChildren] = useState('0');
    const [discount, setDiscount] = useState('');
    const [guestName, setGuestName] = useState('');
    const [guestEmail, setGuestEmail] = useState('');
    const [locale, setLocale] = useState<'it' | 'en'>('it');
    const [localErrors, setLocalErrors] = useState<Record<string, string[]>>({});
    const unit = unitId !== '' ? unitId : (options[0]?.unit.id ?? '');

    const create = useMutation({
        mutationFn: quotesApi.create,
        onSuccess: () => {
            setDiscount('');
            setGuestName('');
            setGuestEmail('');
            void queryClient.invalidateQueries({ queryKey: ['quotes'] });
        },
    });
    const errors = { ...fieldErrors(create.error), ...localErrors };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const discountCents = discount.trim() === '' ? 0 : parseEuroToCents(discount);

        if (discountCents === null) {
            setLocalErrors({ discount_cents: [t('common.invalidAmount')] });

            return;
        }

        setLocalErrors({});
        create.mutate({
            unit_id: unit,
            check_in: checkIn,
            check_out: checkOut,
            adults: Number.parseInt(adults, 10) || 1,
            children: Number.parseInt(children, 10) || 0,
            discount_cents: discountCents,
            guest_name: guestName.trim() === '' ? null : guestName.trim(),
            guest_email: guestEmail.trim() === '' ? null : guestEmail.trim(),
            guest_locale: locale,
        });
    };

    if (isPending) {
        return <Loading />;
    }

    return (
        <form onSubmit={submit} noValidate data-testid="quote-form">
            <Section title={t('quotes.new')}>
                <div className="grid gap-4 sm:grid-cols-5">
                    <Field label={t('reservation.unit')} errors={errors.unit_id}>
                        <UnitSelect options={options} value={unit} onChange={setUnitId} className={inputClass} />
                    </Field>
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
                <div className="mt-4 grid gap-4 sm:grid-cols-4">
                    <Field label={t('newReservation.discount')} errors={errors.discount_cents} hint={t('quotes.discountHint')}>
                        <TextInput value={discount} onChange={setDiscount} inputMode="decimal" placeholder="0,00" />
                    </Field>
                    <Field label={t('quotes.guestName')} errors={errors.guest_name}>
                        <TextInput value={guestName} onChange={setGuestName} />
                    </Field>
                    <Field label={t('reservation.email')} errors={errors.guest_email} hint={t('quotes.emailHint')}>
                        <TextInput type="email" value={guestEmail} onChange={setGuestEmail} inputMode="email" />
                    </Field>
                    <Field label={t('newReservation.locale')} errors={errors.guest_locale}>
                        <select className={inputClass} value={locale} onChange={(e) => setLocale(e.target.value as 'it' | 'en')}>
                            <option value="it">{t('translations.it')}</option>
                            <option value="en">{t('translations.en')}</option>
                        </select>
                    </Field>
                </div>
                <p className="mt-3 text-xs text-muted">{t('quotes.frozenNote')}</p>
                {create.isError && <FormError message={errorMessage(create.error)} />}
                <div className="mt-4">
                    <button type="submit" className={primaryButton} disabled={create.isPending || unit === ''}>
                        {create.isPending ? t('common.wait') : t('quotes.create')}
                    </button>
                </div>
            </Section>
        </form>
    );
}

function QuoteRow({ quote }: { quote: Quote }) {
    const { t } = useTranslation();
    const confirmation = useConfirm();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const [notice, setNotice] = useState<string | null>(null);
    const [link, setLink] = useState<string | null>(quote.link);
    const refresh = () => void queryClient.invalidateQueries({ queryKey: ['quotes'] });

    const send = useMutation({
        mutationFn: ({ channel }: { channel: 'email' | 'link'; whatsapp?: Window | null }) => quotesApi.send(quote.id, channel),
        onSuccess: async (sent: SentQuote, { channel, whatsapp }) => {
            setLink(sent.link);

            if (whatsapp !== undefined) {
                // La finestra si apre al clic (niente blocco dei popup) e riceve l'indirizzo dopo la risposta.
                if (whatsapp !== null) {
                    whatsapp.location.href = sent.whatsapp_url;
                } else {
                    window.open(sent.whatsapp_url, '_blank', 'noopener');
                }
            } else if (channel === 'link') {
                try {
                    await navigator.clipboard.writeText(sent.link);
                    setNotice(t('quotes.linkCopied'));
                } catch {
                    setNotice(t('quotes.linkReady'));
                }
            } else {
                setNotice(t('quotes.emailQueued'));
            }

            refresh();
        },
    });
    const revoke = useMutation({ mutationFn: () => quotesApi.revoke(quote.id), onSuccess: refresh });
    const usable = (quote.status === 'DRAFT' || quote.status === 'SENT') && !quote.expired;
    const error = send.error ?? revoke.error;

    return (
        <li className="py-4" data-testid="quote-row" data-status={quote.status}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-medium text-ink">
                        {quote.unit.name} · {formatDate(quote.check_in)} → {formatDate(quote.check_out)}
                    </p>
                    <p className="text-sm text-muted">
                        {[quote.guest_name, t('quotes.guests', { count: quote.adults + quote.children })].filter(Boolean).join(' · ')}
                    </p>
                    <p className="text-sm text-ink">
                        {formatMoney(quote.total)}
                        {quote.discount.amount_cents > 0 && <span className="text-muted"> ({t('quotes.discountApplied', { amount: formatMoney(quote.discount) })})</span>}
                    </p>
                    <p className="text-xs text-muted">
                        {quote.expired ? t('quotes.expiredAt', { date: formatDateTime(quote.expires_at) }) : t('quotes.expiresAt', { date: formatDateTime(quote.expires_at) })}
                        {' · '}
                        {t('quotes.attempts', { count: quote.checkout_attempts, max: quote.max_checkout_attempts })}
                    </p>
                    {quote.reservation !== null && (
                        <Link to={`/prenotazioni/${quote.reservation.id}`} className="text-sm text-brand-800 hover:underline">
                            {t('quotes.reservation', { code: quote.reservation.code })}
                        </Link>
                    )}
                </div>
                <span className={`inline-block rounded-full border px-2 py-0.5 text-xs font-medium ${statusColors[quote.status]}`} data-testid="quote-status">
                    {t(`quoteStatus.${quote.status}`)}
                </span>
            </div>
            {usable && (
                <div className="mt-3 flex flex-wrap gap-2">
                    <button type="button" className={secondaryButton} disabled={send.isPending} onClick={() => send.mutate({ channel: 'link' })}>
                        {t('quotes.copyLink')}
                    </button>
                    <button
                        type="button"
                        className={secondaryButton}
                        disabled={send.isPending}
                        onClick={() => {
                            const whatsapp = window.open('about:blank', '_blank');

                            if (whatsapp !== null) {
                                whatsapp.opener = null;
                            }

                            send.mutate({ channel: 'link', whatsapp });
                        }}
                    >
                        {t('quotes.openWhatsapp')}
                    </button>
                    {quote.guest_email !== null && (
                        <button type="button" className={secondaryButton} disabled={send.isPending} onClick={() => send.mutate({ channel: 'email' })}>
                            {t('quotes.sendEmail')}
                        </button>
                    )}
                </div>
            )}
            {(quote.status === 'DRAFT' || quote.status === 'SENT' || quote.status === 'IN_CHECKOUT') && (
                <div className="mt-2">
                    <button
                        type="button"
                        className={dangerButton}
                        disabled={revoke.isPending}
                        onClick={() => confirmation.ask({ title: t('quotes.revokeConfirm'), confirmLabel: t('quotes.revoke'), action: () => revoke.mutate() })}
                    >
                        {t('quotes.revoke')}
                    </button>
                    {confirmation.dialog}
                </div>
            )}
            {link !== null && usable && (
                <p className="mt-2 break-all text-xs text-muted" data-testid="quote-link">
                    {link}
                </p>
            )}
            {notice !== null && <p className="mt-2 text-sm text-emerald-800">{notice}</p>}
            {error !== null && <FormError message={errorMessage(error)} />}
        </li>
    );
}
