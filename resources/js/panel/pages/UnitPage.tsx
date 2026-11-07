import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';

import { adminApi, type BookingMode, type PaymentMode, type Unit } from '../api/admin';
import { ApiError, fieldErrors } from '../api/client';
import { useErrorMessage } from '../auth/session';
import {
    ConflictNotice,
    Field,
    FormError,
    inputClass,
    Loading,
    primaryButton,
    SavedNotice,
    secondaryButton,
    Section,
    TextInput,
    translationDraft,
    TranslationFields,
    translationPayload,
    type SaveNotice,
} from '../components/Form';
import { centsToEuroInput, parseEuroToCents } from '../money';
import { NotFoundPage } from './NotFoundPage';
import { PricePreviewSection } from './unit/PricePreviewSection';
import { UnitPhotosSection } from './unit/UnitPhotosSection';
import { UnitRatesSection } from './unit/UnitRatesSection';

/** Unità: dati, testi IT/EN, vendite, foto, tariffe, stagioni e anteprima prezzo (07). */
export function UnitPage() {
    const { unitId = '' } = useParams();
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const unit = useQuery({ queryKey: ['unit', unitId], queryFn: () => adminApi.unit(unitId) });
    const [notice, setNotice] = useState<SaveNotice>(null);

    if (unit.isPending) {
        return <Loading page />;
    }

    if (unit.isError) {
        return unit.error instanceof ApiError && unit.error.status === 404 ? (
            <NotFoundPage />
        ) : (
            <main className="mx-auto max-w-5xl px-4 py-8">
                <FormError message={errorMessage(unit.error)} />
            </main>
        );
    }

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <Link to={`/strutture/${unit.data.property_id}`} className="text-sm text-brand-800 hover:underline">
                ← {t('unit.backToProperty')}
            </Link>
            <h1 className="text-2xl font-semibold text-ink">{unit.data.name}</h1>
            <SalesSection unit={unit.data} onNotice={setNotice} />
            <UnitForm key={unit.data.version} unit={unit.data} notice={notice} onNotice={setNotice} />
            <UnitPhotosSection unitId={unit.data.id} />
            <UnitRatesSection unitId={unit.data.id} />
            <PricePreviewSection unitId={unit.data.id} />
        </main>
    );
}

function useUnitUpdated(unit: Unit, onNotice: (notice: SaveNotice) => void) {
    const queryClient = useQueryClient();

    return {
        onMutate: () => onNotice(null),
        onSuccess: (updated: Unit) => {
            onNotice('saved');
            queryClient.setQueryData(['unit', unit.id], updated);
            void queryClient.invalidateQueries({ queryKey: ['units', unit.property_id] });
        },
        onError: (error: unknown) => {
            if (error instanceof ApiError && error.code === 'VERSION_MISMATCH') {
                onNotice('conflict');
                void queryClient.invalidateQueries({ queryKey: ['unit', unit.id] });
            }
        },
    };
}

/** Interruttore delle vendite (02: sales_suspended) e stato attivo/inattivo dell'unità. */
function SalesSection({ unit, onNotice }: { unit: Unit; onNotice: (notice: SaveNotice) => void }) {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const callbacks = useUnitUpdated(unit, onNotice);
    const sales = useMutation({ mutationFn: () => adminApi.setSalesSuspended(unit.id, unit.version, !unit.sales_suspended), ...callbacks });
    const status = useMutation({
        mutationFn: () => adminApi.updateUnit(unit.id, unit.version, { status: unit.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE' }),
        ...callbacks,
    });
    const error = sales.error ?? status.error;
    // ADR-0010 punto 8: disattivare l'ultima unità attiva di una struttura pubblicata è consentito,
    // ma la pagina ospite mostrerà "Nessuna disponibilità al momento": si chiede conferma.
    const property = useQuery({ queryKey: ['property', unit.property_id], queryFn: () => adminApi.property(unit.property_id) });
    const units = useQuery({ queryKey: ['units', unit.property_id], queryFn: () => adminApi.units(unit.property_id) });
    const [confirming, setConfirming] = useState(false);
    const lastActive =
        unit.status === 'ACTIVE' &&
        property.data?.status === 'PUBLISHED' &&
        (units.data?.data ?? []).filter((u) => u.status === 'ACTIVE' && u.id !== unit.id).length === 0;
    const toggleStatus = () => {
        if (lastActive && !confirming) {
            setConfirming(true);

            return;
        }

        setConfirming(false);
        status.mutate();
    };

    return (
        <Section title={t('unit.salesTitle')}>
            <div className="flex flex-wrap items-center gap-4 text-sm text-ink">
                <span data-testid="unit-status">{t(`units.status.${unit.status}`)}</span>
                <span>{unit.sales_suspended ? t('units.salesSuspended') : t('units.salesOpen')}</span>
                <button className={secondaryButton} type="button" onClick={() => sales.mutate()} disabled={sales.isPending}>
                    {unit.sales_suspended ? t('unit.resumeSales') : t('unit.suspendSales')}
                </button>
                <button className={secondaryButton} type="button" onClick={toggleStatus} disabled={status.isPending}>
                    {unit.status === 'ACTIVE' ? t('unit.deactivate') : t('unit.activate')}
                </button>
            </div>
            {confirming && (
                <div className="mt-3 rounded-control border border-amber-200 bg-warning-soft p-3 text-sm text-warning" role="alert" data-testid="last-unit-warning">
                    <p>{t('unit.lastActiveWarning')}</p>
                    <div className="mt-2 flex gap-2">
                        <button className={primaryButton} type="button" onClick={toggleStatus}>
                            {t('unit.deactivateAnyway')}
                        </button>
                        <button className={secondaryButton} type="button" onClick={() => setConfirming(false)}>
                            {t('common.cancel')}
                        </button>
                    </div>
                </div>
            )}
            {error !== null && !(error instanceof ApiError && error.code === 'VERSION_MISMATCH') && (
                <div className="mt-3">
                    <FormError message={errorMessage(error)} />
                </div>
            )}
        </Section>
    );
}

function UnitForm({ unit, notice, onNotice }: { unit: Unit; notice: SaveNotice; onNotice: (notice: SaveNotice) => void }) {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const property = useQuery({ queryKey: ['property', unit.property_id], queryFn: () => adminApi.property(unit.property_id) });
    const [name, setName] = useState(unit.name);
    const [baseOccupancy, setBaseOccupancy] = useState(String(unit.base_occupancy));
    const [maxOccupancy, setMaxOccupancy] = useState(String(unit.max_occupancy));
    const [minNights, setMinNights] = useState(String(unit.min_nights));
    const [bookingMode, setBookingMode] = useState<BookingMode>(unit.booking_mode);
    const [paymentMode, setPaymentMode] = useState<PaymentMode>(unit.payment_mode);
    const [cleaning, setCleaning] = useState(centsToEuroInput(unit.cleaning_fee.amount_cents));
    const [extraGuest, setExtraGuest] = useState(centsToEuroInput(unit.extra_guest_fee.amount_cents));
    const [policyId, setPolicyId] = useState(unit.cancellation_policy_id ?? '');
    const [amountErrors, setAmountErrors] = useState<Record<string, string[]>>({});
    const [translations, setTranslations] = useState(() => translationDraft(unit.translations));

    const save = useMutation({
        mutationFn: (amounts: { cleaning: number; extraGuest: number }) =>
            adminApi.updateUnit(unit.id, unit.version, {
                name,
                base_occupancy: Number.parseInt(baseOccupancy, 10),
                max_occupancy: Number.parseInt(maxOccupancy, 10),
                min_nights: Number.parseInt(minNights, 10),
                booking_mode: bookingMode,
                payment_mode: paymentMode,
                cleaning_fee_cents: amounts.cleaning,
                extra_guest_fee_cents: amounts.extraGuest,
                cancellation_policy_id: policyId === '' ? null : policyId,
                translations: translationPayload(translations),
            }),
        ...useUnitUpdated(unit, onNotice),
    });
    const errors = { ...fieldErrors(save.error), ...amountErrors };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const cleaningCents = parseEuroToCents(cleaning);
        const extraGuestCents = parseEuroToCents(extraGuest);
        const invalid = [t('common.invalidAmount')];
        setAmountErrors({
            ...(cleaningCents === null ? { cleaning_fee_cents: invalid } : {}),
            ...(extraGuestCents === null ? { extra_guest_fee_cents: invalid } : {}),
        });

        if (cleaningCents !== null && extraGuestCents !== null) {
            save.mutate({ cleaning: cleaningCents, extraGuest: extraGuestCents });
        }
    };

    return (
        <Section title={t('unit.details')}>
            <form className="space-y-5" onSubmit={submit} noValidate>
                <ConflictNotice show={notice === 'conflict'} />
                <FormError message={save.isError && !(save.error instanceof ApiError && save.error.code === 'VALIDATION_FAILED') ? errorMessage(save.error) : null} />
                <div className="grid gap-4 md:grid-cols-3">
                    <Field label={t('unit.name')} errors={errors.name}>
                        <TextInput value={name} onChange={setName} required />
                    </Field>
                    <Field label={t('unit.base_occupancy')} errors={errors.base_occupancy} hint={t('unit.baseOccupancyHint')}>
                        <TextInput type="number" min={1} max={50} value={baseOccupancy} onChange={setBaseOccupancy} />
                    </Field>
                    <Field label={t('unit.max_occupancy')} errors={errors.max_occupancy}>
                        <TextInput type="number" min={1} max={50} value={maxOccupancy} onChange={setMaxOccupancy} />
                    </Field>
                    <Field label={t('unit.min_nights')} errors={errors.min_nights}>
                        <TextInput type="number" min={1} max={365} value={minNights} onChange={setMinNights} />
                    </Field>
                    <Field label={t('unit.cleaning_fee')} errors={errors.cleaning_fee_cents}>
                        <TextInput inputMode="decimal" value={cleaning} onChange={setCleaning} />
                    </Field>
                    <Field label={t('unit.extra_guest_fee')} errors={errors.extra_guest_fee_cents} hint={t('unit.extraGuestHint')}>
                        <TextInput inputMode="decimal" value={extraGuest} onChange={setExtraGuest} />
                    </Field>
                    <Field label={t('unit.booking_mode')} errors={errors.booking_mode}>
                        <select className={inputClass} value={bookingMode} onChange={(e) => setBookingMode(e.target.value as BookingMode)}>
                            <option value="REQUEST">{t('unit.bookingModes.REQUEST')}</option>
                            <option value="INSTANT">{t('unit.bookingModes.INSTANT')}</option>
                        </select>
                    </Field>
                    <Field label={t('unit.payment_mode')} errors={errors.payment_mode}>
                        <select className={inputClass} value={paymentMode} onChange={(e) => setPaymentMode(e.target.value as PaymentMode)}>
                            <option value="DEPOSIT_30">{t('unit.paymentModes.DEPOSIT_30')}</option>
                            <option value="FULL">{t('unit.paymentModes.FULL')}</option>
                        </select>
                    </Field>
                    <Field label={t('unit.policy')} errors={errors.cancellation_policy_id}>
                        <select className={inputClass} value={policyId} onChange={(e) => setPolicyId(e.target.value)}>
                            <option value="">{t('unit.propertyPolicy')}</option>
                            {property.data?.cancellation_policies.map((policy) => (
                                <option key={policy.id} value={policy.id}>
                                    {t(`policies.${policy.code}`)}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
                <TranslationFields draft={translations} onChange={setTranslations} errors={errors} />
                <div className="flex items-center gap-4">
                    <button className={primaryButton} type="submit" disabled={save.isPending}>
                        {save.isPending ? t('common.wait') : t('common.save')}
                    </button>
                    <SavedNotice show={notice === 'saved'} />
                </div>
            </form>
        </Section>
    );
}
