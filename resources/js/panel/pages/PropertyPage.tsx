import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';

import { adminApi, type Property } from '../api/admin';
import { ApiError, fieldErrors } from '../api/client';
import { useErrorMessage } from '../auth/session';
import {
    ConflictNotice,
    Field,
    FormError,
    inputClass,
    primaryButton,
    SavedNotice,
    type SaveNotice,
    Section,
    TextInput,
    TranslationFields,
    translationDraft,
    translationPayload,
} from '../components/Form';
import { parseEuroToCents } from '../money';
import { NotFoundPage } from './NotFoundPage';

/** Struttura: dati, testi IT/EN, politica di cancellazione predefinita, pubblicazione e unità (07). */

/** "15" o "12,5" → basis point (1500, 1250). Se non è un numero: NaN, che in JSON diventa null e il server segnala il campo. */
function otaBps(value: string): number {
    const parsed = Number.parseFloat(value.trim().replace(',', '.'));

    return Number.isFinite(parsed) ? Math.round(parsed * 100) : Number.NaN;
}
export function PropertyPage() {
    const { propertyId = '' } = useParams();
    const { t } = useTranslation();
    const property = useQuery({ queryKey: ['property', propertyId], queryFn: () => adminApi.property(propertyId) });
    const [notice, setNotice] = useState<SaveNotice>(null);

    if (property.isPending) {
        return <p className="mx-auto max-w-5xl px-4 py-8 text-slate-600">{t('common.loading')}</p>;
    }

    if (property.isError) {
        return property.error instanceof ApiError && property.error.status === 404 ? <NotFoundPage /> : <ErrorPage error={property.error} />;
    }

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <Link to="/" className="text-sm text-blue-700 hover:underline">
                ← {t('properties.title')}
            </Link>
            <div className="flex flex-wrap items-center justify-between gap-4">
                <h1 className="text-2xl font-semibold text-slate-900">{property.data.name}</h1>
                <span className="rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700" data-testid="property-status">
                    {t(`properties.status.${property.data.status}`)}
                </span>
            </div>
            <PublishSection property={property.data} />
            {/* key: dopo un salvataggio o un 412 il modulo riparte dai dati aggiornati; l'avviso resta qui. */}
            <PropertyForm key={property.data.version} property={property.data} notice={notice} onNotice={setNotice} />
            <UnitsSection property={property.data} />
        </main>
    );
}

function ErrorPage({ error }: { error: unknown }) {
    const errorMessage = useErrorMessage();

    return (
        <main className="mx-auto max-w-5xl px-4 py-8">
            <FormError message={errorMessage(error)} />
        </main>
    );
}

function PublishSection({ property }: { property: Property }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const publish = useMutation({
        mutationFn: () => adminApi.publishProperty(property.id),
        onSuccess: (updated) => {
            queryClient.setQueryData(['property', property.id], updated);
            void queryClient.invalidateQueries({ queryKey: ['properties'] });
        },
    });

    if (property.status !== 'DRAFT') {
        return null;
    }

    const missing = publish.error instanceof ApiError && Array.isArray(publish.error.details.missing) ? (publish.error.details.missing as string[]) : [];

    return (
        <Section
            title={t('publish.title')}
            actions={
                <button className={primaryButton} type="button" onClick={() => publish.mutate()} disabled={publish.isPending}>
                    {publish.isPending ? t('common.wait') : t('publish.submit')}
                </button>
            }
        >
            <p className="text-sm text-slate-700">{t('publish.intro')}</p>
            {missing.length > 0 ? (
                <div className="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-900" role="alert">
                    <p className="font-medium">{t('publish.missing')}</p>
                    <ul className="mt-1 list-disc pl-5">
                        {missing.map((code) => (
                            <li key={code}>{t(`errors.${code}`)}</li>
                        ))}
                    </ul>
                </div>
            ) : (
                <div className="mt-3">
                    <FormError message={publish.isError ? errorMessage(publish.error) : null} />
                </div>
            )}
        </Section>
    );
}

const PROPERTY_FIELDS = [
    'address_line1',
    'address_line2',
    'postal_code',
    'city',
    'province',
    'country_code',
    'cin_code',
    'timezone',
    'contact_email',
    'contact_phone',
    'checkin_time',
    'checkout_time',
    'privacy_policy_url',
    'terms_url',
] as const;

type PropertyField = (typeof PROPERTY_FIELDS)[number];

/** Link dei documenti legali: la versione accettata dall'ospite deriva dall'URL (ADR-0011 punto 16). */
const LEGAL_FIELDS: ReadonlySet<PropertyField> = new Set<PropertyField>(['privacy_policy_url', 'terms_url']);

const FIELD_TYPES: Partial<Record<PropertyField, string>> = {
    contact_email: 'email',
    contact_phone: 'tel',
    checkin_time: 'time',
    checkout_time: 'time',
    privacy_policy_url: 'url',
    terms_url: 'url',
};

function PropertyForm({ property, notice, onNotice }: { property: Property; notice: SaveNotice; onNotice: (notice: SaveNotice) => void }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const [name, setName] = useState(property.name);
    const [slug, setSlug] = useState(property.slug);
    const [fields, setFields] = useState<Record<PropertyField, string>>(
        () => Object.fromEntries(PROPERTY_FIELDS.map((key) => [key, property[key] ?? ''])) as Record<PropertyField, string>,
    );
    const [policyId, setPolicyId] = useState(property.default_cancellation_policy_id ?? '');
    const [approvalHours, setApprovalHours] = useState(String(property.approval_window_hours));
    // Percentuale OTA usata dalle metriche (6.2), in basis point nell'API: 1500 → "15".
    const [otaPercent, setOtaPercent] = useState((property.ota_commission_bps / 100).toLocaleString('it-IT', { maximumFractionDigits: 2 }));
    const [translations, setTranslations] = useState(() => translationDraft(property.translations));

    const save = useMutation({
        mutationFn: () => {
            const values = Object.fromEntries(
                PROPERTY_FIELDS.map((key) => [key, fields[key].trim() === '' && key !== 'country_code' && key !== 'timezone' ? null : fields[key].trim()]),
            );

            return adminApi.updateProperty(property.id, property.version, {
                name,
                slug,
                ...values,
                default_cancellation_policy_id: policyId === '' ? null : policyId,
                approval_window_hours: Number.parseInt(approvalHours, 10),
                ota_commission_bps: otaBps(otaPercent),
                translations: translationPayload(translations),
            });
        },
        onMutate: () => onNotice(null),
        onSuccess: (updated) => {
            onNotice('saved');
            queryClient.setQueryData(['property', property.id], updated);
            void queryClient.invalidateQueries({ queryKey: ['properties'] });
        },
        onError: (error) => {
            if (error instanceof ApiError && error.code === 'VERSION_MISMATCH') {
                onNotice('conflict');
                void queryClient.invalidateQueries({ queryKey: ['property', property.id] });
            }
        },
    });
    const errors = fieldErrors(save.error);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        save.mutate();
    };

    return (
        <Section title={t('property.details')}>
            <form className="space-y-5" onSubmit={submit} noValidate>
                <ConflictNotice show={notice === 'conflict'} />
                <FormError message={save.isError ? errorMessage(save.error) : null} />
                <div className="grid gap-4 md:grid-cols-2">
                    <Field label={t('property.name')} errors={errors.name}>
                        <TextInput value={name} onChange={setName} required />
                    </Field>
                    <Field label={t('property.slug')} errors={errors.slug} hint={t('property.slugHint')}>
                        <TextInput value={slug} onChange={(value) => setSlug(value.toLowerCase())} required />
                    </Field>
                    {PROPERTY_FIELDS.map((key) => (
                        <Field key={key} label={t(`property.${key}`)} errors={errors[key]} hint={LEGAL_FIELDS.has(key) ? t('property.legalUrlHint') : undefined}>
                            <TextInput type={FIELD_TYPES[key] ?? 'text'} value={fields[key]} onChange={(value) => setFields({ ...fields, [key]: value })} />
                        </Field>
                    ))}
                    <Field label={t('property.approvalWindow')} errors={errors.approval_window_hours} hint={t('property.approvalWindowHint')}>
                        <TextInput type="number" value={approvalHours} onChange={setApprovalHours} min={1} max={48} />
                    </Field>
                    <Field label={t('property.otaCommission')} errors={errors.ota_commission_bps} hint={t('property.otaCommissionHint')}>
                        <TextInput value={otaPercent} onChange={setOtaPercent} inputMode="decimal" />
                    </Field>
                    <Field label={t('property.defaultPolicy')} errors={errors.default_cancellation_policy_id} hint={t('property.defaultPolicyHint')}>
                        <select className={inputClass} value={policyId} onChange={(e) => setPolicyId(e.target.value)}>
                            <option value="">{t('property.noPolicy')}</option>
                            {property.cancellation_policies.map((policy) => (
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

function UnitsSection({ property }: { property: Property }) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const units = useQuery({ queryKey: ['units', property.id], queryFn: () => adminApi.units(property.id) });
    const [name, setName] = useState('');
    const [baseOccupancy, setBaseOccupancy] = useState('2');
    const [maxOccupancy, setMaxOccupancy] = useState('4');
    const [price, setPrice] = useState('');
    const [priceError, setPriceError] = useState(false);

    const create = useMutation({
        mutationFn: (baseNightlyCents: number) =>
            adminApi.createUnit(property.id, {
                name,
                base_occupancy: Number.parseInt(baseOccupancy, 10),
                max_occupancy: Number.parseInt(maxOccupancy, 10),
                base_nightly_cents: baseNightlyCents,
                translations: { it: { name, description: null, house_rules: null } },
            }),
        onSuccess: (unit) => {
            void queryClient.invalidateQueries({ queryKey: ['units', property.id] });
            void navigate(`/unita/${unit.id}`);
        },
    });
    const errors = fieldErrors(create.error);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const cents = parseEuroToCents(price);
        setPriceError(cents === null);

        if (cents !== null) {
            create.mutate(cents);
        }
    };

    return (
        <Section title={t('units.title')}>
            {units.isError && <FormError message={errorMessage(units.error)} />}
            {units.data?.data.length === 0 && <p className="text-slate-600">{t('units.empty')}</p>}
            <ul className="divide-y divide-slate-200">
                {units.data?.data.map((unit) => (
                    <li key={unit.id} className="flex items-center justify-between py-3">
                        <Link to={`/unita/${unit.id}`} className="font-medium text-blue-700 hover:underline">
                            {unit.name}
                        </Link>
                        <span className="text-sm text-slate-600">
                            {t(`units.status.${unit.status}`)}
                            {unit.sales_suspended && ` · ${t('units.salesSuspended')}`}
                        </span>
                    </li>
                ))}
            </ul>

            <form className="mt-6 grid gap-4 border-t border-slate-200 pt-4 md:grid-cols-5 md:items-end" onSubmit={submit} noValidate>
                <h3 className="text-sm font-semibold text-slate-800 md:col-span-5">{t('units.create')}</h3>
                <div className="md:col-span-2">
                    <Field label={t('unit.name')} errors={errors.name}>
                        <TextInput value={name} onChange={setName} required />
                    </Field>
                </div>
                <Field label={t('unit.base_occupancy')} errors={errors.base_occupancy}>
                    <TextInput type="number" min={1} max={50} value={baseOccupancy} onChange={setBaseOccupancy} />
                </Field>
                <Field label={t('unit.max_occupancy')} errors={errors.max_occupancy}>
                    <TextInput type="number" min={1} max={50} value={maxOccupancy} onChange={setMaxOccupancy} />
                </Field>
                <Field label={t('rates.baseNightly')} errors={priceError ? [t('common.invalidAmount')] : errors.base_nightly_cents}>
                    <TextInput inputMode="decimal" value={price} onChange={setPrice} placeholder="120,00" />
                </Field>
                <div className="md:col-span-5">
                    <FormError message={create.isError && Object.keys(errors).length === 0 ? errorMessage(create.error) : null} />
                    <button className={primaryButton} type="submit" disabled={create.isPending}>
                        {create.isPending ? t('common.wait') : t('units.createSubmit')}
                    </button>
                </div>
            </form>
        </Section>
    );
}
