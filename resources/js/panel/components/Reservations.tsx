import { useQueries, useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { adminApi, type Property, type Unit } from '../api/admin';
import type { ReservationStatus } from '../api/reservations';

/** Colori per stato: gli stessi nel calendario, nell'elenco e nel dettaglio (legenda unica). */
export const statusColors: Record<ReservationStatus | 'BLOCK' | 'EXTERNAL', string> = {
    AWAITING_PAYMENT: 'bg-amber-100 text-warning border-amber-300',
    AWAITING_APPROVAL: 'bg-sky-100 text-sky-900 border-sky-300',
    CAPTURING: 'bg-indigo-100 text-indigo-900 border-indigo-300',
    CAPTURE_FAILED: 'bg-red-100 text-danger border-red-300',
    CONFIRMED: 'bg-emerald-100 text-emerald-900 border-emerald-300',
    REJECTED: 'bg-surface-muted text-muted border-line',
    EXPIRED: 'bg-surface-muted text-muted border-line',
    PAYMENT_FAILED: 'bg-surface-muted text-muted border-line',
    CANCELLED: 'bg-surface-muted text-muted border-line',
    BLOCK: 'bg-slate-700 text-white border-slate-800',
    EXTERNAL: 'bg-violet-100 text-violet-900 border-violet-300',
};

export function StatusBadge({ status }: { status: ReservationStatus }) {
    const { t } = useTranslation();

    return (
        <span className={`inline-block rounded-full border px-2 py-0.5 text-xs font-medium ${statusColors[status]}`} data-testid="reservation-status">
            {t(`reservationStatus.${status}`)}
        </span>
    );
}

export interface UnitOption {
    unit: Unit;
    property: Property;
}

/** Tutte le unità del tenant, raggruppate per struttura (per i filtri e le scelte del pannello). */
export function useAllUnits(): { options: UnitOption[]; isPending: boolean } {
    const properties = useQuery({ queryKey: ['properties'], queryFn: adminApi.properties });
    const list = properties.data?.data ?? [];
    const units = useQueries({
        queries: list.map((property) => ({ queryKey: ['units', property.id], queryFn: () => adminApi.units(property.id) })),
    });
    const options = list.flatMap((property, index) => (units[index]?.data?.data ?? []).map((unit) => ({ unit, property })));

    return { options, isPending: properties.isPending || units.some((u) => u.isPending) };
}

export function UnitSelect({
    options,
    value,
    onChange,
    allowEmpty,
    className,
}: {
    options: UnitOption[];
    value: string;
    onChange: (unitId: string) => void;
    allowEmpty?: boolean;
    className: string;
}) {
    const { t } = useTranslation();
    const byProperty = new Map<string, UnitOption[]>();

    for (const option of options) {
        byProperty.set(option.property.id, [...(byProperty.get(option.property.id) ?? []), option]);
    }

    return (
        <select className={className} value={value} onChange={(e) => onChange(e.target.value)} name="unit_id">
            {allowEmpty === true && <option value="">{t('reservations.allUnits')}</option>}
            {[...byProperty.values()].map((group) => (
                <optgroup key={group[0]?.property.id} label={group[0]?.property.name}>
                    {group.map(({ unit }) => (
                        <option key={unit.id} value={unit.id}>
                            {unit.name}
                        </option>
                    ))}
                </optgroup>
            ))}
        </select>
    );
}
