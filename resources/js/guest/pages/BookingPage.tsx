import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { cloneElement, isValidElement, useEffect, useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { DayPicker, type DateRange } from 'react-day-picker';
import { enGB, it } from 'react-day-picker/locale';
import { Trans, useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';

import { ApiError, fieldErrors, publicApi, type GuestInput, type PriceQuote, type PublicProperty, type PublicUnit } from '../api';
import { Gallery, Notice, Shell, Spinner, useProperty } from '../components';
import { addDays, COUNTRY_CODES, countryName, formatDate, formatMoney, toIsoDate } from '../format';
import { saveBooking } from '../storage';

const MAX_NIGHTS = 365;
const AVAILABILITY_MONTHS = 12;
const PHONE = /^\+?[0-9 ().-]{6,40}$/;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Prenotazione di un alloggio (3.7): calendario di due mesi con le notti non disponibili e il minimo
 * di notti, scelta degli ospiti, prezzo con le righe dell'API (il client non calcola nulla), dati
 * dell'ospite, accettazione di condizioni e informativa, poi "Prosegui al pagamento": crea la
 * prenotazione e apre il pagamento a pagina intera (fuori dall'iframe nella vista embed).
 */
export function BookingPage({ embed }: { embed: boolean }) {
    const { t } = useTranslation();
    const { unitId } = useParams();
    const property = useProperty();

    if (property.isPending) {
        return (
            <Shell>
                <Spinner />
            </Shell>
        );
    }

    const unit = property.data?.units.find((u) => u.id === unitId);

    if (property.isError || property.data === undefined || unit === undefined) {
        return (
            <Shell>
                <Notice tone="error">{property.isError ? t('common.loadError') : t('common.notFound')}</Notice>
                <Link to="/" className="mt-4 inline-block text-sm text-ink underline">
                    {t('common.back')}
                </Link>
            </Shell>
        );
    }

    return <BookingForm property={property.data} unit={unit} embed={embed} />;
}

function BookingForm({ property, unit, embed }: { property: PublicProperty; unit: PublicUnit; embed: boolean }) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const today = useMemo(() => {
        const now = new Date();

        return new Date(now.getFullYear(), now.getMonth(), now.getDate());
    }, []);
    const [range, setRange] = useState<DateRange | undefined>();
    const [adults, setAdults] = useState(Math.min(2, unit.max_occupancy));
    const [children, setChildren] = useState(0);
    const [guest, setGuest] = useState<GuestInput>({ first_name: '', last_name: '', email: '', phone: '', country_code: '', notes: '' });
    const [acceptTerms, setAcceptTerms] = useState(false);
    const [acceptPrivacy, setAcceptPrivacy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [notice, setNotice] = useState<{ tone: 'error' | 'warning'; text: string } | null>(null);
    const [pendingCheckout, setPendingCheckout] = useState<string | null>(null);
    const idempotency = useRef<{ body: string; key: string } | null>(null);

    const from = toIsoDate(today);
    const to = toIsoDate(new Date(today.getFullYear(), today.getMonth() + AVAILABILITY_MONTHS, today.getDate()));
    const availability = useQuery({
        queryKey: ['availability', unit.id, from, to],
        queryFn: () => publicApi.availability(unit.id, from, to),
    });
    const unavailable = useMemo(() => new Set(availability.data?.unavailable_nights ?? []), [availability.data]);
    const minNightsFor = (date: Date): number => availability.data?.min_nights_by_night[toIsoDate(date)] ?? unit.min_nights;

    const checkIn = range?.from;
    const checkOut = range?.to;
    const stay = checkIn !== undefined && checkOut !== undefined ? { check_in: toIsoDate(checkIn), check_out: toIsoDate(checkOut) } : null;

    const quote = useQuery({
        queryKey: ['quote', unit.id, stay?.check_in, stay?.check_out, adults, children, i18n.language],
        queryFn: () => publicApi.quote({ unit_id: unit.id, check_in: stay?.check_in ?? '', check_out: stay?.check_out ?? '', adults, children }),
        enabled: stay !== null,
        retry: false,
    });

    /** Ultima partenza possibile dopo un arrivo: la prima notte non disponibile chiude l'intervallo. */
    const lastCheckOut = (start: Date): Date => {
        for (let i = 0; i < MAX_NIGHTS; i++) {
            const night = addDays(start, i);

            if (unavailable.has(toIsoDate(night))) {
                return night;
            }
        }

        return addDays(start, MAX_NIGHTS);
    };

    const isDisabled = (date: Date): boolean => {
        if (date < today) {
            return true;
        }

        if (checkIn !== undefined && checkOut === undefined && date > checkIn) {
            return date > lastCheckOut(checkIn);
        }

        return unavailable.has(toIsoDate(date));
    };

    const onDayClick = (day: Date, modifiers: { disabled?: boolean }) => {
        if (modifiers.disabled) {
            return;
        }

        setNotice(null);

        if (checkIn === undefined || checkOut !== undefined || day <= checkIn) {
            setRange({ from: day, to: undefined });

            return;
        }

        setRange({ from: checkIn, to: day });
    };

    const update = (field: keyof GuestInput, value: string) => {
        setGuest((g) => ({ ...g, [field]: value }));
        setErrors((e) => ({ ...e, [field]: '' }));
    };

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

    const reserve = useMutation({
        mutationFn: ({ body, key }: { body: Record<string, unknown>; key: string }) => publicApi.reserve(body, key),
        onSuccess: (started) => {
            saveBooking(started.reservation_code, {
                token: started.access_token,
                checkoutUrl: started.checkout_url,
                checkoutExpiresAt: started.checkout_expires_at,
            });
            openCheckout(started.checkout_url);
        },
        onError: (error) => {
            if (!(error instanceof ApiError)) {
                setNotice({ tone: 'error', text: t('errors.SERVER_ERROR') });

                return;
            }

            if (error.code === 'PRICE_CHANGED') {
                const price = error.details.price as PriceQuote | undefined;

                if (price !== undefined) {
                    queryClient.setQueryData(['quote', unit.id, stay?.check_in, stay?.check_out, adults, children, i18n.language], price);
                }

                setNotice({ tone: 'warning', text: t('booking.priceChanged') });

                return;
            }

            if (error.code === 'DATES_UNAVAILABLE') {
                setRange(undefined);
                void availability.refetch();
                setNotice({ tone: 'error', text: t('booking.datesTaken') });

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
                focusFirstError();

                return;
            }

            setNotice({ tone: 'error', text: t(`errors.${error.code}`, { defaultValue: t('errors.SERVER_ERROR') }) });
        },
    });

    /** Pagamento sempre a pagina intera: nella vista embed si esce dall'iframe (08). */
    const openCheckout = (url: string) => {
        if (!embed) {
            window.location.assign(url);

            return;
        }

        try {
            if (window.top !== null) {
                window.top.location.href = url;

                return;
            }
        } catch {
            // Navigazione della pagina che incorpora non consentita: nuova scheda.
        }

        const opened = window.open(url, '_blank', 'noopener');

        if (opened === null) {
            setPendingCheckout(url);
            setNotice({ tone: 'warning', text: t('booking.popupBlocked') });
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setNotice(null);
        const found = validate();
        setErrors(found);

        if (Object.keys(found).length > 0 || stay === null || quote.data === undefined) {
            setNotice({ tone: 'error', text: t('booking.fixErrors') });
            focusFirstError();

            return;
        }

        const body = {
            unit_id: unit.id,
            ...stay,
            adults,
            children,
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
            expected_total_cents: quote.data.total.amount_cents,
        };
        // Stesso corpo → stessa Idempotency-Key (nuovo invio sicuro); corpo diverso → chiave nuova.
        const serialized = JSON.stringify(body);

        if (idempotency.current?.body !== serialized) {
            idempotency.current = { body: serialized, key: crypto.randomUUID() };
        }

        reserve.mutate({ body, key: idempotency.current.key });
    };

    useEffect(() => {
        if (adults + children > unit.max_occupancy) {
            setChildren(Math.max(0, unit.max_occupancy - adults));
        }
    }, [adults, children, unit.max_occupancy]);

    const nights = stay !== null && checkIn !== undefined && checkOut !== undefined ? Math.round((checkOut.getTime() - checkIn.getTime()) / 86_400_000) : 0;
    const requestMode = unit.booking_mode === 'REQUEST';
    const inputClass = (field: string) =>
        `mt-1 block w-full rounded-card border px-3 py-2.5 text-base ${errors[field] ? 'border-red-400 bg-danger-soft' : 'border-line bg-surface'}`;

    return (
        <Shell title={property.name}>
            <Link to="/" className="inline-flex min-h-tap items-center text-sm text-muted underline">
                {t('common.back')}
            </Link>
            <h1 className="mt-3 text-2xl font-semibold text-ink">{t('booking.title', { name: unit.name })}</h1>
            <div className="mt-4">
                <Gallery photos={unit.photos} name={unit.name} />
            </div>

            <form onSubmit={submit} noValidate className="mt-6 space-y-8">
                <Section title={t('booking.dates')}>
                    <p className="text-sm text-muted">{t('booking.datesHint')}</p>
                    {availability.isPending ? (
                        <Spinner />
                    ) : (
                        <div className="mt-3 overflow-x-auto" data-testid="calendar">
                            <DayPicker
                                mode="range"
                                numberOfMonths={2}
                                pagedNavigation
                                startMonth={today}
                                endMonth={new Date(today.getFullYear(), today.getMonth() + AVAILABILITY_MONTHS - 1, 1)}
                                selected={range}
                                onDayClick={onDayClick}
                                disabled={isDisabled}
                                excludeDisabled={false}
                                modifiers={{ unavailable: (date: Date) => unavailable.has(toIsoDate(date)) }}
                                modifiersClassNames={{ unavailable: 'guest-unavailable' }}
                                locale={i18n.language === 'en' ? enGB : it}
                                weekStartsOn={1}
                                labels={{
                                    labelDayButton: (date, modifiers) =>
                                        [
                                            modifiers.today ? t('calendar.today') : null,
                                            new Intl.DateTimeFormat(i18n.language, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(date),
                                            modifiers.unavailable || modifiers.disabled ? t('calendar.unavailable') : null,
                                            modifiers.range_start ? t('calendar.checkIn') : modifiers.range_end ? t('calendar.checkOut') : modifiers.selected ? t('calendar.selected') : null,
                                        ]
                                            .filter(Boolean)
                                            .join(', '),
                                    labelNext: () => t('calendar.next'),
                                    labelPrevious: () => t('calendar.previous'),
                                }}
                            />
                        </div>
                    )}
                    <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-ink" aria-live="polite" data-testid="stay-summary">
                        <span>
                            {t('booking.checkIn')}: <strong>{checkIn !== undefined ? formatDate(toIsoDate(checkIn), i18n.language) : '—'}</strong>
                        </span>
                        <span>
                            {t('booking.checkOut')}: <strong>{checkOut !== undefined ? formatDate(toIsoDate(checkOut), i18n.language) : '—'}</strong>
                        </span>
                        {nights > 0 && <span>{t('booking.nights', { count: nights })}</span>}
                        {range !== undefined && (
                            <button type="button" className="min-h-tap text-muted underline" onClick={() => setRange(undefined)}>
                                {t('booking.clearDates')}
                            </button>
                        )}
                    </div>
                    {checkIn !== undefined && checkOut === undefined && minNightsFor(checkIn) > 1 && (
                        <p className="mt-2 text-sm text-muted">{t('booking.minNightsFor', { count: minNightsFor(checkIn) })}</p>
                    )}
                    <p className="mt-1 text-xs text-muted">
                        <span className="guest-unavailable-legend mr-1 inline-block">31</span>
                        {t('booking.unavailableLegend')}
                    </p>
                </Section>

                <Section title={t('booking.guests')}>
                    <Counter label={t('booking.adults')} value={adults} min={1} max={unit.max_occupancy - children} onChange={setAdults} />
                    <Counter label={t('booking.children')} value={children} min={0} max={unit.max_occupancy - adults} onChange={setChildren} />
                    <p className="text-xs text-muted">{t('booking.maxGuests', { count: unit.max_occupancy })}</p>
                </Section>

                <Section title={t('booking.price')}>
                    {stay === null && <p className="text-sm text-muted">{t('booking.priceHint')}</p>}
                    {stay !== null && quote.isFetching && <p className="text-sm text-muted">{t('booking.priceLoading')}</p>}
                    {stay !== null && quote.isError && (
                        <Notice tone="error">
                            {quote.error instanceof ApiError
                                ? t(`errors.${quote.error.code}`, { defaultValue: t('errors.SERVER_ERROR') })
                                : t('errors.SERVER_ERROR')}
                        </Notice>
                    )}
                    {quote.data !== undefined && stay !== null && !quote.isError && <PriceDetails quote={quote.data} requestMode={requestMode} />}
                </Section>

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
                    <p className="text-xs text-warning" data-testid="notes-warning">
                        {t('booking.notesWarning')}
                    </p>
                </Section>

                <section className="space-y-3">
                    {property.terms_url !== null && (
                        <Checkbox checked={acceptTerms} onChange={setAcceptTerms} error={errors.terms} name="accept_terms">
                            <Trans
                                i18nKey="booking.acceptTerms"
                                components={{ termsLink: <a href={property.terms_url} target="_blank" rel="noopener noreferrer" className="underline" /> }}
                            />
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
                {pendingCheckout !== null && (
                    <a href={pendingCheckout} target="_top" className="block rounded-card bg-brand-700 px-4 py-3 text-center font-medium text-white">
                        {t('booking.openPayment')}
                    </a>
                )}

                <div className="space-y-2">
                    <button
                        type="submit"
                        disabled={reserve.isPending || quote.data === undefined || stay === null}
                        className="w-full rounded-card bg-brand-700 px-4 py-3.5 text-base font-semibold text-white hover:bg-brand-800 disabled:cursor-not-allowed disabled:bg-slate-300"
                    >
                        {reserve.isPending ? t('booking.sending') : requestMode ? t('booking.proceedRequest') : t('booking.proceed')}
                    </button>
                    <p className="text-center text-xs text-muted">{t('booking.paymentNote')}</p>
                </div>
            </form>
        </Shell>
    );
}

/** Dopo un invio con errori il focus va sul primo campo non valido (8.2). */
export function focusFirstError() {
    window.requestAnimationFrame(() => document.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
}

export function PriceDetails({ quote, requestMode }: { quote: PriceQuote; requestMode: boolean }) {
    const { t, i18n } = useTranslation();
    const money = (value: PriceQuote['total']) => formatMoney(value, i18n.language);

    return (
        <div className="space-y-3" data-testid="price">
            <ul className="divide-y divide-slate-100 text-sm">
                {quote.lines.map((line, index) => (
                    <li key={`${line.kind}-${line.date ?? ''}-${index}`} className="flex justify-between gap-4 py-1.5">
                        <span className="text-ink">
                            {t(`lines.${line.kind}`, { date: line.date !== null ? formatDate(line.date, i18n.language) : '', defaultValue: line.kind })}
                            {line.quantity > 1 && ` × ${line.quantity}`}
                        </span>
                        <span className="tabular-nums text-ink">{money(line.amount)}</span>
                    </li>
                ))}
            </ul>
            <dl className="space-y-1 border-t border-line-soft pt-3 text-sm">
                <div className="flex justify-between font-semibold text-ink">
                    <dt>{t('booking.total')}</dt>
                    <dd className="tabular-nums" data-testid="price-total">
                        {money(quote.total)}
                    </dd>
                </div>
                <div className="flex justify-between text-ink">
                    <dt>{requestMode ? t('booking.depositRequest') : t('booking.deposit')}</dt>
                    <dd className="tabular-nums">{money(quote.deposit)}</dd>
                </div>
                {quote.balance.amount_cents > 0 && (
                    <div className="flex justify-between text-ink">
                        <dt>{t('booking.balance')}</dt>
                        <dd className="tabular-nums">{money(quote.balance)}</dd>
                    </div>
                )}
            </dl>
            {requestMode && <Notice>{t('booking.requestNote')}</Notice>}
            {quote.cancellation_policy !== null && (
                <div className="rounded-card bg-canvas p-3 text-sm text-ink">
                    <p className="font-medium">
                        {t('booking.cancellation')}: {quote.cancellation_policy.title}
                    </p>
                    {quote.cancellation_policy.text !== null && <p className="mt-1">{quote.cancellation_policy.text}</p>}
                </div>
            )}
        </div>
    );
}

export function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-3">
            <h2 className="text-lg font-semibold text-ink">{title}</h2>
            {children}
        </section>
    );
}

/**
 * Campo con etichetta: con un errore il controllo riceve aria-invalid e aria-describedby verso il
 * messaggio, che gli screen reader leggono insieme al campo (8.2, WCAG 3.3.1).
 */
export function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    const errorId = useId();
    const hasError = error !== undefined && error !== '';
    const control = isValidElement<{ 'aria-invalid'?: boolean; 'aria-describedby'?: string }>(children)
        ? cloneElement(children, { 'aria-invalid': hasError || undefined, 'aria-describedby': hasError ? errorId : undefined })
        : children;

    return (
        <label className="block text-sm font-medium text-ink">
            {label}
            {control}
            {hasError && (
                <span id={errorId} className="mt-1 block text-xs font-normal text-danger">
                    {error}
                </span>
            )}
        </label>
    );
}

export function Checkbox({ checked, onChange, error, name, children }: { checked: boolean; onChange: (value: boolean) => void; error?: string; name: string; children: ReactNode }) {
    const errorId = useId();
    const hasError = error !== undefined && error !== '';

    return (
        <div>
            <label className="flex min-h-tap items-start gap-3 py-2 text-sm text-ink">
                <input
                    type="checkbox"
                    name={name}
                    checked={checked}
                    onChange={(e) => onChange(e.target.checked)}
                    aria-invalid={hasError || undefined}
                    aria-describedby={hasError ? errorId : undefined}
                    className="mt-0.5 h-5 w-5 shrink-0 accent-brand-700"
                />
                <span>{children}</span>
            </label>
            {hasError && (
                <p id={errorId} className="mt-1 ml-8 text-xs text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}

function Counter({ label, value, min, max, onChange }: { label: string; value: number; min: number; max: number; onChange: (value: number) => void }) {
    const { t } = useTranslation();

    return (
        <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-ink">{label}</span>
            <div className="flex items-center gap-3">
                <button
                    type="button"
                    aria-label={t('booking.decrease', { what: label })}
                    onClick={() => onChange(Math.max(min, value - 1))}
                    disabled={value <= min}
                    className="h-11 w-11 rounded-full border border-line text-lg disabled:opacity-40"
                >
                    −
                </button>
                <span className="w-6 text-center tabular-nums" aria-live="polite">
                    {value}
                </span>
                <button
                    type="button"
                    aria-label={t('booking.increase', { what: label })}
                    onClick={() => onChange(Math.min(max, value + 1))}
                    disabled={value >= max}
                    className="h-11 w-11 rounded-full border border-line text-lg disabled:opacity-40"
                >
                    +
                </button>
            </div>
        </div>
    );
}


