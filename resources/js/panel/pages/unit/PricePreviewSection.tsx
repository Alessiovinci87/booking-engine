import { useMutation } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { adminApi } from '../../api/admin';
import { ApiError, fieldErrors } from '../../api/client';
import { useErrorMessage } from '../../auth/session';
import { Field, FormError, primaryButton, Section, TextInput } from '../../components/Form';
import { formatMoney } from '../../money';

/**
 * Anteprima prezzo (07, POST /admin/units/{id}/price-preview): stesso calcolo della pagina ospite.
 * Il pannello mostra righe e totali del server senza ricalcolare nulla.
 */
export function PricePreviewSection({ unitId }: { unitId: string }) {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const [checkIn, setCheckIn] = useState('');
    const [checkOut, setCheckOut] = useState('');
    const [adults, setAdults] = useState('2');
    const [children, setChildren] = useState('0');

    const preview = useMutation({
        mutationFn: () =>
            adminApi.pricePreview(unitId, {
                check_in: checkIn,
                check_out: checkOut,
                adults: Number.parseInt(adults, 10),
                children: Number.parseInt(children, 10) || 0,
            }),
    });
    const errors = fieldErrors(preview.error);
    const quote = preview.data;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        preview.mutate();
    };

    const domainError = preview.error instanceof ApiError && preview.error.code !== 'VALIDATION_FAILED' ? preview.error : null;

    return (
        <Section title={t('preview.title')}>
            <form className="grid gap-4 md:grid-cols-5 md:items-end" onSubmit={submit} noValidate>
                <Field label={t('preview.checkIn')} errors={errors.check_in}>
                    <TextInput type="date" value={checkIn} onChange={setCheckIn} required />
                </Field>
                <Field label={t('preview.checkOut')} errors={errors.check_out}>
                    <TextInput type="date" value={checkOut} onChange={setCheckOut} required />
                </Field>
                <Field label={t('preview.adults')} errors={errors.adults}>
                    <TextInput type="number" min={1} max={50} value={adults} onChange={setAdults} />
                </Field>
                <Field label={t('preview.children')} errors={errors.children}>
                    <TextInput type="number" min={0} max={50} value={children} onChange={setChildren} />
                </Field>
                <div>
                    <button className={primaryButton} type="submit" disabled={preview.isPending}>
                        {preview.isPending ? t('common.wait') : t('preview.submit')}
                    </button>
                </div>
            </form>

            {domainError !== null && (
                <div className="mt-4">
                    <FormError
                        message={
                            domainError.code === 'MIN_NIGHTS_NOT_MET'
                                ? t('preview.minNights', { required: domainError.details.required_min_nights, nights: domainError.details.nights })
                                : errorMessage(domainError)
                        }
                    />
                </div>
            )}

            {quote !== undefined && (
                <div className="mt-6" data-testid="price-preview">
                    <table className="w-full text-left text-sm">
                        <thead className="text-muted">
                            <tr>
                                <th className="py-1 font-medium">{t('preview.line')}</th>
                                <th className="py-1 font-medium">{t('preview.date')}</th>
                                <th className="py-1 text-right font-medium">{t('preview.quantity')}</th>
                                <th className="py-1 text-right font-medium">{t('preview.amount')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line-soft">
                            {quote.lines.map((line, index) => (
                                <tr key={`${line.kind}-${line.date ?? index}`}>
                                    <td className="py-1.5">{t(`preview.kinds.${line.kind}`)}</td>
                                    <td className="py-1.5">{line.date ?? '—'}</td>
                                    <td className="py-1.5 text-right">{line.quantity}</td>
                                    <td className="py-1.5 text-right">{formatMoney(line.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <dl className="mt-4 grid max-w-sm grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <dt className="text-muted">{t('preview.nights')}</dt>
                        <dd className="text-right">{quote.nights}</dd>
                        <dt className="font-semibold text-ink">{t('preview.total')}</dt>
                        <dd className="text-right font-semibold" data-testid="price-total">
                            {formatMoney(quote.total)}
                        </dd>
                        <dt className="text-muted">{t('preview.deposit')}</dt>
                        <dd className="text-right" data-testid="price-deposit">
                            {formatMoney(quote.deposit)}
                        </dd>
                        <dt className="text-muted">{t('preview.balance')}</dt>
                        <dd className="text-right" data-testid="price-balance">
                            {formatMoney(quote.balance)}
                        </dd>
                        <dt className="text-muted">{t('unit.booking_mode')}</dt>
                        <dd className="text-right">{t(`unit.bookingModes.${quote.booking_mode}`)}</dd>
                    </dl>
                    {quote.cancellation_policy !== null && (
                        <p className="mt-3 text-sm text-muted">
                            <span className="font-medium">{quote.cancellation_policy.title}</span> — {quote.cancellation_policy.text}
                        </p>
                    )}
                </div>
            )}
        </Section>
    );
}
