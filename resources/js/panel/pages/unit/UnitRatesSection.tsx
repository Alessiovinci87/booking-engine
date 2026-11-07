import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { adminApi, type RatePlan, type Season, type SeasonWarning } from '../../api/admin';
import { ApiError, fieldErrors } from '../../api/client';
import { useErrorMessage } from '../../auth/session';
import {
    dangerButton,
    Field,
    FormError,
    primaryButton,
    SavedNotice,
    secondaryButton,
    Section,
    TextInput,
    useConfirm,
} from '../../components/Form';
import { centsToEuroInput, formatMoney, parseEuroToCents } from '../../money';

/**
 * Prezzo base e stagioni dell'unità (04, 07). Le stagioni si indicano con prima e ultima notte
 * comprese; sovrapposizioni con la stessa priorità sono ammesse ma segnalate dal server.
 */
export function UnitRatesSection({ unitId }: { unitId: string }) {
    const { t } = useTranslation();
    const plan = useQuery({ queryKey: ['rate-plan', unitId], queryFn: () => adminApi.ratePlan(unitId) });
    const seasons = useQuery({ queryKey: ['seasons', unitId], queryFn: () => adminApi.seasons(unitId) });
    const [editing, setEditing] = useState<Season | 'new' | null>(null);

    return (
        <Section title={t('rates.title')}>
            {plan.data !== undefined && <BasePriceForm key={plan.data.version} unitId={unitId} plan={plan.data} />}

            <div className="mt-6 flex items-center justify-between">
                <h3 className="text-sm font-semibold text-ink">{t('seasons.title')}</h3>
                <button className={secondaryButton} type="button" onClick={() => setEditing('new')}>
                    {t('seasons.add')}
                </button>
            </div>
            <Warnings warnings={seasons.data?.warnings ?? []} />
            {seasons.data?.data.length === 0 && <p className="mt-2 text-sm text-muted">{t('seasons.empty')}</p>}
            {(seasons.data?.data.length ?? 0) > 0 && (
                <table className="mt-3 w-full text-left text-sm">
                    <thead className="text-muted">
                        <tr>
                            <th className="py-1 font-medium">{t('seasons.name')}</th>
                            <th className="py-1 font-medium">{t('seasons.firstNight')}</th>
                            <th className="py-1 font-medium">{t('seasons.lastNight')}</th>
                            <th className="py-1 font-medium">{t('seasons.nightly')}</th>
                            <th className="py-1 font-medium">{t('seasons.priority')}</th>
                            <th className="py-1 font-medium">{t('seasons.minNights')}</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line-soft">
                        {seasons.data?.data.map((season) => (
                            <tr key={season.id}>
                                <td className="py-2">{season.name}</td>
                                <td className="py-2">{season.first_night}</td>
                                <td className="py-2">{season.last_night}</td>
                                <td className="py-2">{formatMoney(season.nightly)}</td>
                                <td className="py-2">{season.priority}</td>
                                <td className="py-2">{season.min_nights ?? '—'}</td>
                                <td className="py-2 text-right">
                                    <button className={secondaryButton} type="button" onClick={() => setEditing(season)}>
                                        {t('common.edit')}
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            {editing !== null && (
                <SeasonForm
                    key={editing === 'new' ? 'new' : `${editing.id}-${editing.version}`}
                    unitId={unitId}
                    season={editing === 'new' ? null : editing}
                    onDone={() => setEditing(null)}
                />
            )}
        </Section>
    );
}

function BasePriceForm({ unitId, plan }: { unitId: string; plan: RatePlan }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const [price, setPrice] = useState(centsToEuroInput(plan.base_nightly.amount_cents));
    const [invalid, setInvalid] = useState(false);
    const save = useMutation({
        mutationFn: (cents: number) => adminApi.updateRatePlan(unitId, plan.version, cents),
        onSuccess: (updated) => queryClient.setQueryData(['rate-plan', unitId], updated),
        onError: (error) => {
            if (error instanceof ApiError && error.code === 'VERSION_MISMATCH') {
                void queryClient.invalidateQueries({ queryKey: ['rate-plan', unitId] });
            }
        },
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const cents = parseEuroToCents(price);
        setInvalid(cents === null);

        if (cents !== null) {
            save.mutate(cents);
        }
    };

    return (
        <form className="flex flex-wrap items-end gap-4" onSubmit={submit} noValidate>
            <Field label={t('rates.baseNightly')} errors={invalid ? [t('common.invalidAmount')] : fieldErrors(save.error).base_nightly_cents}>
                <TextInput inputMode="decimal" value={price} onChange={setPrice} />
            </Field>
            <button className={primaryButton} type="submit" disabled={save.isPending}>
                {save.isPending ? t('common.wait') : t('common.save')}
            </button>
            <SavedNotice show={save.isSuccess} />
            {save.isError && !(save.error instanceof ApiError && save.error.code === 'VALIDATION_FAILED') && <FormError message={errorMessage(save.error)} />}
        </form>
    );
}

function Warnings({ warnings }: { warnings: SeasonWarning[] }) {
    const { t } = useTranslation();

    if (warnings.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 space-y-1 rounded-control bg-warning-soft p-3 text-sm text-warning" role="status">
            {warnings.map((warning) => (
                <p key={warning.seasons.map((s) => s.id).join('-')}>
                    {t('seasons.overlapWarning', { priority: warning.priority, seasons: warning.seasons.map((s) => s.name).join(', ') })}
                </p>
            ))}
        </div>
    );
}

function SeasonForm({ unitId, season, onDone }: { unitId: string; season: Season | null; onDone: () => void }) {
    const { t } = useTranslation();
    const confirmation = useConfirm();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const [name, setName] = useState(season?.name ?? '');
    const [firstNight, setFirstNight] = useState(season?.first_night ?? '');
    const [lastNight, setLastNight] = useState(season?.last_night ?? '');
    const [price, setPrice] = useState(season === null ? '' : centsToEuroInput(season.nightly.amount_cents));
    const [priority, setPriority] = useState(String(season?.priority ?? 0));
    const [minNights, setMinNights] = useState(season?.min_nights === null || season === null ? '' : String(season.min_nights));
    const [invalid, setInvalid] = useState(false);
    const refresh = () => queryClient.invalidateQueries({ queryKey: ['seasons', unitId] });

    const save = useMutation({
        mutationFn: (cents: number) => {
            const input = {
                name,
                first_night: firstNight,
                last_night: lastNight,
                nightly_cents: cents,
                priority: Number.parseInt(priority, 10) || 0,
                min_nights: minNights.trim() === '' ? null : Number.parseInt(minNights, 10),
            };

            return season === null ? adminApi.createSeason(unitId, input) : adminApi.updateSeason(unitId, season, input);
        },
        onSuccess: () => {
            void refresh();
            onDone();
        },
    });
    const remove = useMutation({
        mutationFn: () => adminApi.deleteSeason(unitId, season?.id ?? ''),
        onSuccess: () => {
            void refresh();
            onDone();
        },
    });
    const errors = fieldErrors(save.error);
    const error = save.error ?? remove.error;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const cents = parseEuroToCents(price);
        setInvalid(cents === null);

        if (cents !== null) {
            save.mutate(cents);
        }
    };

    return (
        <form className="mt-4 space-y-4 rounded-control border border-line-soft p-4" onSubmit={submit} noValidate>
            <h4 className="text-sm font-semibold text-ink">{season === null ? t('seasons.add') : t('seasons.edit')}</h4>
            {error !== null && !(error instanceof ApiError && error.code === 'VALIDATION_FAILED') && <FormError message={errorMessage(error)} />}
            <div className="grid gap-4 md:grid-cols-3">
                <Field label={t('seasons.name')} errors={errors.name}>
                    <TextInput value={name} onChange={setName} required />
                </Field>
                <Field label={t('seasons.firstNight')} errors={errors.first_night}>
                    <TextInput type="date" value={firstNight} onChange={setFirstNight} required />
                </Field>
                <Field label={t('seasons.lastNight')} errors={errors.last_night} hint={t('seasons.lastNightHint')}>
                    <TextInput type="date" value={lastNight} onChange={setLastNight} required />
                </Field>
                <Field label={t('seasons.nightly')} errors={invalid ? [t('common.invalidAmount')] : errors.nightly_cents}>
                    <TextInput inputMode="decimal" value={price} onChange={setPrice} />
                </Field>
                <Field label={t('seasons.priority')} errors={errors.priority} hint={t('seasons.priorityHint')}>
                    <TextInput type="number" min={0} max={1000} value={priority} onChange={setPriority} />
                </Field>
                <Field label={t('seasons.minNights')} errors={errors.min_nights} hint={t('seasons.minNightsHint')}>
                    <TextInput type="number" min={1} max={365} value={minNights} onChange={setMinNights} />
                </Field>
            </div>
            <div className="flex flex-wrap gap-3">
                <button className={primaryButton} type="submit" disabled={save.isPending}>
                    {save.isPending ? t('common.wait') : t('common.save')}
                </button>
                <button className={secondaryButton} type="button" onClick={onDone}>
                    {t('common.cancel')}
                </button>
                {season !== null && (
                    <button
                        className={dangerButton}
                        type="button"
                        disabled={remove.isPending}
                        onClick={() => confirmation.ask({ title: t('seasons.confirmRemove'), confirmLabel: t('seasons.remove'), action: () => remove.mutate() })}
                    >
                        {t('seasons.remove')}
                    </button>
                )}
                {confirmation.dialog}
            </div>
        </form>
    );
}
