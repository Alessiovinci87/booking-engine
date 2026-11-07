import { useMutation, useQuery } from '@tanstack/react-query';
import { type FormEvent, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';

import { adminApi } from '../api/admin';
import { fieldErrors } from '../api/client';
import { OFFLINE_METHODS, type OfflineMethod, reservationsApi } from '../api/reservations';
import { useErrorMessage } from '../auth/session';
import { Field, FormError, inputClass, Loading, primaryButton, Section, TextInput } from '../components/Form';
import { UnitSelect, useAllUnits } from '../components/Reservations';
import { addDays, today } from '../dates';
import { formatMoney, parseEuroToCents } from '../money';
import { COUNTRY_CODES } from '../../shared/countries';

/**
 * Prenotazione manuale (3.8, T2): confermata subito, anche senza pagamento (stato economico UNPAID).
 * Il prezzo lo calcola il server: qui si mostra l'anteprima e si indica lo sconto facoltativo.
 * Facoltativo anche un primo pagamento offline (bonifico, contanti…).
 */
export function NewReservationPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const errorMessage = useErrorMessage();
    const [params] = useSearchParams();
    const { options, isPending } = useAllUnits();
    const [unitId, setUnitId] = useState(params.get('unit') ?? '');
    const [checkIn, setCheckIn] = useState(params.get('check_in') ?? today());
    const [checkOut, setCheckOut] = useState(params.get('check_out') ?? addDays(today(), 3));
    const [adults, setAdults] = useState('2');
    const [children, setChildren] = useState('0');
    const [discount, setDiscount] = useState('');
    const [guest, setGuest] = useState({ first_name: '', last_name: '', email: '', phone: '', country_code: 'IT', notes: '' });
    const [locale, setLocale] = useState<'it' | 'en'>('it');
    const [payNow, setPayNow] = useState(false);
    const [payAmount, setPayAmount] = useState('');
    const [payMethod, setPayMethod] = useState<OfflineMethod>('BANK_TRANSFER');
    const [localErrors, setLocalErrors] = useState<Record<string, string[]>>({});
    const unit = unitId !== '' ? unitId : (options[0]?.unit.id ?? '');
    const validStay = checkIn !== '' && checkOut !== '' && checkIn < checkOut;
    const countries = useMemo(() => {
        const names = new Intl.DisplayNames(['it-IT'], { type: 'region' });

        return COUNTRY_CODES.map((code) => ({ code, name: names.of(code) ?? code })).sort((a, b) => a.name.localeCompare(b.name, 'it'));
    }, []);

    const preview = useQuery({
        queryKey: ['price-preview', unit, checkIn, checkOut, adults, children],
        queryFn: () =>
            adminApi.pricePreview(unit, { check_in: checkIn, check_out: checkOut, adults: Number.parseInt(adults, 10) || 1, children: Number.parseInt(children, 10) || 0 }),
        enabled: unit !== '' && validStay,
        retry: false,
    });

    const create = useMutation({
        mutationFn: reservationsApi.create,
        onSuccess: (created) => void navigate(`/prenotazioni/${created.id}`),
    });
    const errors = { ...fieldErrors(create.error), ...localErrors };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const found: Record<string, string[]> = {};
        const discountCents = discount.trim() === '' ? 0 : parseEuroToCents(discount);
        const payCents = payNow ? parseEuroToCents(payAmount) : null;

        if (discountCents === null) {
            found.discount_cents = [t('common.invalidAmount')];
        }

        if (payNow && payCents === null) {
            found['offline_payment.amount_cents'] = [t('common.invalidAmount')];
        }

        setLocalErrors(found);

        if (Object.keys(found).length > 0) {
            return;
        }

        create.mutate({
            unit_id: unit,
            check_in: checkIn,
            check_out: checkOut,
            adults: Number.parseInt(adults, 10),
            children: Number.parseInt(children, 10) || 0,
            discount_cents: discountCents ?? 0,
            offline_payment: payNow && payCents !== null ? { amount_cents: payCents, method: payMethod } : null,
            guest: {
                first_name: guest.first_name.trim(),
                last_name: guest.last_name.trim(),
                email: guest.email.trim(),
                phone: guest.phone.trim(),
                country_code: guest.country_code,
                notes: guest.notes.trim() === '' ? null : guest.notes.trim(),
            },
            locale,
        });
    };

    const setGuestField = (field: keyof typeof guest) => (value: string) => setGuest((g) => ({ ...g, [field]: value }));

    if (isPending) {
        return <Loading page />;
    }

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <Link to="/prenotazioni" className="text-sm text-brand-800 hover:underline">
                {`← ${t('reservation.back')}`}
            </Link>
            <h1 className="text-2xl font-semibold text-ink">{t('newReservation.title')}</h1>
            <p className="text-sm text-muted">{t('newReservation.intro')}</p>

            <form onSubmit={submit} className="space-y-6" data-testid="manual-reservation-form" noValidate>
                <Section title={t('newReservation.stay')}>
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
                    <div className="mt-4 rounded-control bg-canvas p-3 text-sm" data-testid="manual-price">
                        {!validStay ? (
                            <p className="text-muted">{t('newReservation.chooseDates')}</p>
                        ) : preview.isFetching ? (
                            <Loading />
                        ) : preview.isError ? (
                            <p className="text-danger">{errorMessage(preview.error)}</p>
                        ) : preview.data !== undefined ? (
                            <p className="text-ink">
                                {t('newReservation.priceSummary', { nights: preview.data.nights, total: formatMoney(preview.data.total) })}
                            </p>
                        ) : null}
                        <p className="mt-1 text-xs text-muted">{t('newReservation.availabilityNote')}</p>
                    </div>
                    <div className="mt-4 max-w-xs">
                        <Field label={t('newReservation.discount')} errors={errors.discount_cents} hint={t('newReservation.discountHint')}>
                            <TextInput value={discount} onChange={setDiscount} inputMode="decimal" placeholder="0,00" />
                        </Field>
                    </div>
                </Section>

                <Section title={t('reservation.guest')}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('newReservation.firstName')} errors={errors['guest.first_name']}>
                            <TextInput value={guest.first_name} onChange={setGuestField('first_name')} />
                        </Field>
                        <Field label={t('newReservation.lastName')} errors={errors['guest.last_name']}>
                            <TextInput value={guest.last_name} onChange={setGuestField('last_name')} />
                        </Field>
                        <Field label={t('reservation.email')} errors={errors['guest.email']}>
                            <TextInput type="email" value={guest.email} onChange={setGuestField('email')} inputMode="email" />
                        </Field>
                        <Field label={t('reservation.phone')} errors={errors['guest.phone']}>
                            <TextInput type="tel" value={guest.phone} onChange={setGuestField('phone')} inputMode="tel" />
                        </Field>
                        <Field label={t('reservation.country')} errors={errors['guest.country_code']}>
                            <select className={inputClass} value={guest.country_code} onChange={(e) => setGuestField('country_code')(e.target.value)}>
                                {countries.map(({ code, name }) => (
                                    <option key={code} value={code}>
                                        {name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t('newReservation.locale')} errors={errors.locale} hint={t('newReservation.localeHint')}>
                            <select className={inputClass} value={locale} onChange={(e) => setLocale(e.target.value as 'it' | 'en')}>
                                <option value="it">{t('translations.it')}</option>
                                <option value="en">{t('translations.en')}</option>
                            </select>
                        </Field>
                    </div>
                    <div className="mt-4">
                        <Field label={t('newReservation.notes')} errors={errors['guest.notes']}>
                            <textarea className={inputClass} rows={3} value={guest.notes} onChange={(e) => setGuestField('notes')(e.target.value)} maxLength={2000} />
                        </Field>
                    </div>
                </Section>

                <Section title={t('newReservation.payment')}>
                    <label className="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" checked={payNow} onChange={(e) => setPayNow(e.target.checked)} name="pay_now" />
                        {t('newReservation.payNow')}
                    </label>
                    {payNow && (
                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <Field label={t('reservation.amount')} errors={errors['offline_payment.amount_cents']}>
                                <TextInput value={payAmount} onChange={setPayAmount} inputMode="decimal" />
                            </Field>
                            <Field label={t('reservation.method')} errors={errors['offline_payment.method']}>
                                <select className={inputClass} value={payMethod} onChange={(e) => setPayMethod(e.target.value as OfflineMethod)}>
                                    {OFFLINE_METHODS.map((m) => (
                                        <option key={m} value={m}>
                                            {t(`offlineMethod.${m}`)}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>
                    )}
                    <p className="mt-3 text-xs text-muted">{t('newReservation.unpaidNote')}</p>
                </Section>

                {create.isError && <FormError message={errorMessage(create.error)} />}
                <button type="submit" className={primaryButton} disabled={create.isPending || unit === ''}>
                    {create.isPending ? t('common.wait') : t('newReservation.submit')}
                </button>
            </form>
        </main>
    );
}
