import { useMutation, useQuery } from '@tanstack/react-query';
import { useRef, useState, type FormEvent } from 'react';
import { Trans, useTranslation } from 'react-i18next';
import { useParams } from 'react-router';

import { ApiError, fieldErrors, publicApi, type GuestInput, type PublicQuote } from '../api';
import { Notice, Shell, Spinner } from '../components';
import { COUNTRY_CODES, countryName, formatDate, formatDateTime } from '../format';
import { loadBooking, saveBooking } from '../storage';
import { BeforePayment, Checkbox, Field, PriceDetails, Section } from './BookingPage';

const PHONE = /^\+?[0-9 ().-]{6,40}$/;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Pagina ospite del preventivo `/q/{token}` (5.7): date, ospiti e prezzo congelato del preventivo (il
 * client non calcola nulla), dati dell'ospite precompilati, condizioni e informativa, poi pagamento a
 * pagina intera. Un secondo invio durante il checkout riceve lo stesso pagamento (I21). Scaduto,
 * ritirato o esaurito: messaggio dedicato (410).
 */
export function QuotePage() {
    const { t, i18n } = useTranslation();
    const { token = '' } = useParams();
    const quote = useQuery({
        queryKey: ['public-quote', token, i18n.language],
        queryFn: () => publicApi.quoteView(token),
        retry: (count, error) => !(error instanceof ApiError && (error.status === 404 || error.status === 410)) && count < 2,
    });

    if (quote.isPending) {
        return (
            <Shell>
                <Spinner />
            </Shell>
        );
    }

    if (quote.isError) {
        const code = quote.error instanceof ApiError ? quote.error.code : 'SERVER_ERROR';
        const known = ['QUOTE_EXPIRED', 'QUOTE_REVOKED', 'QUOTE_EXHAUSTED'].includes(code);

        return (
            <Shell>
                <h1 className="text-2xl font-semibold text-ink">{t('quote.title')}</h1>
                <div className="mt-4" data-testid="quote-unavailable" data-code={code}>
                    <Notice tone={known ? 'warning' : 'error'}>{known ? t(`quote.${code}`) : quote.error instanceof ApiError && quote.error.status === 404 ? t('quote.notFound') : t('common.loadError')}</Notice>
                </div>
            </Shell>
        );
    }

    return <QuoteForm token={token} quote={quote.data} />;
}

function QuoteForm({ token, quote }: { token: string; quote: PublicQuote }) {
    const { t, i18n } = useTranslation();
    const [first, ...rest] = (quote.guest.name ?? '').trim().split(/\s+/);
    const [guest, setGuest] = useState<GuestInput>({
        first_name: first ?? '',
        last_name: rest.join(' '),
        email: quote.guest.email ?? '',
        phone: '',
        country_code: '',
        notes: '',
    });
    const [acceptTerms, setAcceptTerms] = useState(false);
    const [acceptPrivacy, setAcceptPrivacy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [notice, setNotice] = useState<{ tone: 'error' | 'warning'; text: string } | null>(null);
    const idempotency = useRef<{ body: string; key: string } | null>(null);
    const property = quote.property;
    const requestMode = quote.booking_mode === 'REQUEST';

    const update = (field: keyof GuestInput, value: string) => setGuest((g) => ({ ...g, [field]: value }));

    const accept = useMutation({
        mutationFn: ({ body, key }: { body: Record<string, unknown>; key: string }) => publicApi.acceptQuote(token, body, key),
        onSuccess: (started) => {
            // Al secondo invio il token di accesso non torna (si mostra una sola volta): resta quello salvato.
            const token = started.access_token ?? loadBooking(started.reservation_code)?.token ?? null;

            if (token !== null) {
                saveBooking(started.reservation_code, { token, checkoutUrl: started.checkout_url, checkoutExpiresAt: started.checkout_expires_at });
            }

            window.location.assign(started.checkout_url);
        },
        onError: (error) => {
            if (!(error instanceof ApiError)) {
                setNotice({ tone: 'error', text: t('errors.SERVER_ERROR') });

                return;
            }

            const fields = fieldErrors(error);

            if (Object.keys(fields).length > 0) {
                const mapped: Record<string, string> = {};

                for (const [key, messages] of Object.entries(fields)) {
                    const name = key.startsWith('guest.') ? key.slice(6) : key === 'accepted_privacy_version' ? 'privacy' : key === 'accepted_terms_version' ? 'terms' : key;
                    mapped[name] = messages[0] ?? t('errors.VALIDATION_FAILED');
                }

                setErrors(mapped);
                setNotice({ tone: 'error', text: t('booking.fixErrors') });

                return;
            }

            const text = t(`quote.${error.code}`, { defaultValue: t(`errors.${error.code}`, { defaultValue: t('errors.SERVER_ERROR') }) });
            setNotice({ tone: error.status === 410 || error.code === 'DATES_UNAVAILABLE' ? 'warning' : 'error', text });
        },
    });

    const validate = (): Record<string, string> => {
        const found: Record<string, string> = {};

        for (const field of ['first_name', 'last_name', 'email', 'phone', 'country_code'] as const) {
            if (guest[field].trim() === '') {
                found[field] = t('booking.required');
            }
        }

        if (found.email === undefined && !EMAIL.test(guest.email.trim())) {
            found.email = t('booking.invalidEmail');
        }

        if (found.phone === undefined && !PHONE.test(guest.phone.trim())) {
            found.phone = t('booking.invalidPhone');
        }

        if (property.terms_url !== null && !acceptTerms) {
            found.terms = t('booking.mustAccept');
        }

        if (!acceptPrivacy) {
            found.privacy = t('booking.mustAccept');
        }

        return found;
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setNotice(null);
        const found = validate();
        setErrors(found);

        if (Object.keys(found).length > 0) {
            setNotice({ tone: 'error', text: t('booking.fixErrors') });

            return;
        }

        const body = {
            guest: {
                first_name: guest.first_name.trim(),
                last_name: guest.last_name.trim(),
                email: guest.email.trim(),
                phone: guest.phone.trim(),
                country_code: guest.country_code,
                notes: guest.notes.trim() === '' ? null : guest.notes.trim(),
            },
            locale: i18n.language,
            accepted_terms_version: property.terms_url !== null ? property.terms_version : null,
            accepted_privacy_version: property.privacy_version,
        };
        const serialized = JSON.stringify(body);

        if (idempotency.current?.body !== serialized) {
            idempotency.current = { body: serialized, key: crypto.randomUUID() };
        }

        accept.mutate({ body, key: idempotency.current.key });
    };

    const inputClass = (field: string) =>
        `mt-1 block w-full rounded-card border px-3 py-2.5 text-base ${errors[field] ? 'border-red-400 bg-danger-soft' : 'border-line bg-surface'}`;

    return (
        <Shell title={property.name}>
            <h1 className="text-2xl font-semibold text-ink">{t('quote.title')}</h1>
            <p className="mt-1 text-ink" data-testid="quote-summary">
                {t('quote.summary', {
                    unit: quote.unit.name,
                    checkIn: formatDate(quote.check_in, i18n.language),
                    checkOut: formatDate(quote.check_out, i18n.language),
                    count: quote.nights,
                })}
            </p>
            <p className="mt-1 text-sm text-muted">
                {t('quote.guests', { count: quote.adults + quote.children })} · {t('quote.validUntil', { date: formatDateTime(quote.expires_at, i18n.language) })}
            </p>

            {quote.accepted ? (
                <div className="mt-6" data-testid="quote-accepted">
                    <Notice tone="success">{t('quote.alreadyAccepted')}</Notice>
                </div>
            ) : (
                <form onSubmit={submit} noValidate className="mt-6 space-y-8">
                    <Section title={t('booking.price')}>
                        <PriceDetails quote={quote} requestMode={requestMode} />
                    </Section>

                    {quote.in_checkout && <Notice>{t('quote.inCheckout')}</Notice>}

                    <Section title={t('booking.yourDetails')}>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('booking.firstName')} error={errors.first_name}>
                                <input className={inputClass('first_name')} value={guest.first_name} onChange={(e) => update('first_name', e.target.value)} autoComplete="given-name" name="first_name" />
                            </Field>
                            <Field label={t('booking.lastName')} error={errors.last_name}>
                                <input className={inputClass('last_name')} value={guest.last_name} onChange={(e) => update('last_name', e.target.value)} autoComplete="family-name" name="last_name" />
                            </Field>
                            <Field label={t('booking.email')} error={errors.email}>
                                <input className={inputClass('email')} type="email" inputMode="email" value={guest.email} onChange={(e) => update('email', e.target.value)} autoComplete="email" name="email" />
                            </Field>
                            <Field label={t('booking.phone')} error={errors.phone}>
                                <input className={inputClass('phone')} type="tel" inputMode="tel" value={guest.phone} onChange={(e) => update('phone', e.target.value)} autoComplete="tel" name="phone" />
                            </Field>
                            <Field label={t('booking.country')} error={errors.country_code}>
                                <select className={inputClass('country_code')} value={guest.country_code} onChange={(e) => update('country_code', e.target.value)} autoComplete="country" name="country_code">
                                    <option value="">{t('booking.countryPlaceholder')}</option>
                                    {COUNTRY_CODES.map((code) => ({ code, name: countryName(code, i18n.language) }))
                                        .sort((a, b) => a.name.localeCompare(b.name, i18n.language))
                                        .map(({ code, name }) => (
                                            <option key={code} value={code}>
                                                {name}
                                            </option>
                                        ))}
                                </select>
                            </Field>
                        </div>
                        <Field label={t('booking.notes')} error={errors.notes}>
                            <textarea className={inputClass('notes')} rows={3} maxLength={2000} value={guest.notes} onChange={(e) => update('notes', e.target.value)} name="notes" />
                        </Field>
                        <p className="text-xs text-warning">{t('booking.notesWarning')}</p>
                    </Section>

                    <BeforePayment
                        policy={quote.cancellation_policy}
                        privacyUrl={property.privacy_policy_url}
                        contactEmail={property.contact_email}
                        contactPhone={property.contact_phone}
                    />

                    <section className="space-y-3">
                        {property.terms_url !== null && (
                            <Checkbox checked={acceptTerms} onChange={setAcceptTerms} error={errors.terms} name="accept_terms">
                                <Trans i18nKey="booking.acceptTerms" components={{ termsLink: <a href={property.terms_url} target="_blank" rel="noopener noreferrer" className="underline" /> }} />
                            </Checkbox>
                        )}
                        <Checkbox checked={acceptPrivacy} onChange={setAcceptPrivacy} error={errors.privacy} name="accept_privacy">
                            <Trans
                                i18nKey="booking.acceptPrivacy"
                                components={{ privacyLink: <a href={property.privacy_policy_url ?? '#'} target="_blank" rel="noopener noreferrer" className="underline" /> }}
                            />
                        </Checkbox>
                    </section>

                    {notice !== null && <Notice tone={notice.tone}>{notice.text}</Notice>}

                    <div className="space-y-2">
                        <button
                            type="submit"
                            disabled={accept.isPending}
                            className="w-full rounded-card bg-brand-700 px-4 py-3.5 text-base font-semibold text-white hover:bg-brand-800 disabled:cursor-not-allowed disabled:bg-slate-300"
                        >
                            {accept.isPending ? t('booking.sending') : requestMode ? t('booking.proceedRequest') : t('quote.accept')}
                        </button>
                        <p className="text-center text-xs text-muted">{t('booking.paymentNote')}</p>
                    </div>
                </form>
            )}

            {quote.accepted && (property.contact_email !== null || property.contact_phone !== null) && (
                <p className="mt-6 text-sm text-muted">
                    {t('reservation.contactProperty', { contact: [property.contact_email, property.contact_phone].filter(Boolean).join(' · ') })}
                </p>
            )}
        </Shell>
    );
}
