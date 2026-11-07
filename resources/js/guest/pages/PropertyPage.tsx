import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import type { PublicUnit } from '../api';
import { Gallery, Notice, Shell, Spinner, useProperty } from '../components';

/**
 * Pagina della struttura (3.7): galleria, descrizione, orari, alloggi prenotabili. Struttura senza
 * unità attive → "Nessuna disponibilità al momento" (ADR-0010).
 */
export function PropertyPage() {
    const { t } = useTranslation();
    const property = useProperty();

    if (property.isPending) {
        return (
            <Shell>
                <Spinner />
            </Shell>
        );
    }

    if (property.isError) {
        return (
            <Shell>
                <Notice tone="error">{t('common.loadError')}</Notice>
            </Shell>
        );
    }

    const p = property.data;
    const photos = p.units.flatMap((unit) => unit.photos);

    return (
        <Shell title={p.name}>
            <main className="space-y-6">
                <Gallery photos={photos} name={p.name} />
                <section>
                    <h1 className="text-2xl font-semibold text-ink">{p.name}</h1>
                    {p.address.city !== null && (
                        <p className="mt-1 text-sm text-muted">{[p.address.line1, p.address.city].filter(Boolean).join(', ')}</p>
                    )}
                    {p.description !== null && <p className="mt-3 whitespace-pre-line text-ink">{p.description}</p>}
                    <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted">
                        {p.checkin_time !== null && <span>{t('property.checkin', { time: p.checkin_time })}</span>}
                        {p.checkout_time !== null && <span>{t('property.checkout', { time: p.checkout_time })}</span>}
                    </div>
                </section>

                <section aria-labelledby="units-title">
                    <h2 id="units-title" className="text-lg font-semibold text-ink">
                        {t('property.units')}
                    </h2>
                    {p.units.length === 0 ? (
                        <div className="mt-3" data-testid="no-availability">
                            <Notice tone="warning">
                                <p className="font-medium">{t('property.noAvailability')}</p>
                                <p className="mt-1">{t('property.noAvailabilityHint')}</p>
                            </Notice>
                        </div>
                    ) : (
                        <ul className="mt-3 grid gap-4 sm:grid-cols-2">
                            {p.units.map((unit) => (
                                <UnitCard key={unit.id} unit={unit} />
                            ))}
                        </ul>
                    )}
                </section>

                {p.house_rules !== null && (
                    <section>
                        <h2 className="text-lg font-semibold text-ink">{t('property.houseRules')}</h2>
                        <p className="mt-2 whitespace-pre-line text-sm text-ink">{p.house_rules}</p>
                    </section>
                )}

                {(p.contact_email !== null || p.contact_phone !== null) && (
                    <section className="text-sm text-muted">
                        <h2 className="text-lg font-semibold text-ink">{t('property.contact')}</h2>
                        <p className="mt-2">{[p.contact_email, p.contact_phone].filter(Boolean).join(' · ')}</p>
                    </section>
                )}
            </main>
        </Shell>
    );
}

function UnitCard({ unit }: { unit: PublicUnit }) {
    const { t } = useTranslation();
    const cover = unit.photos[0];

    return (
        <li className="overflow-hidden rounded-card border border-line-soft bg-surface shadow-card" data-testid="unit-card">
            {cover !== undefined ? (
                <img src={cover.urls.thumbnail} alt={t('property.photoAlt', { name: unit.name })} className="aspect-[4/3] w-full object-cover" />
            ) : (
                <div className="flex aspect-[4/3] items-center justify-center bg-surface-muted text-sm text-muted">{t('unit.noPhoto')}</div>
            )}
            <div className="space-y-2 p-4">
                <h3 className="font-semibold text-ink">{unit.name}</h3>
                {unit.description !== null && <p className="line-clamp-3 text-sm text-muted">{unit.description}</p>}
                <ul className="flex flex-wrap gap-2 text-xs text-ink">
                    <li className="rounded-full bg-surface-muted px-2 py-0.5">{t('unit.guests', { count: unit.max_occupancy })}</li>
                    <li className="rounded-full bg-surface-muted px-2 py-0.5">{t('unit.minNights', { count: unit.min_nights })}</li>
                    <li className={`rounded-full px-2 py-0.5 ${unit.booking_mode === 'INSTANT' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-warning'}`}>
                        {unit.booking_mode === 'INSTANT' ? t('unit.instant') : t('unit.request')}
                    </li>
                </ul>
                <Link
                    to={`/alloggi/${unit.id}`}
                    className="mt-2 block rounded-card bg-slate-900 px-4 py-3 text-center text-sm font-medium text-white hover:bg-slate-800"
                >
                    {t('unit.choose')}
                </Link>
            </div>
        </li>
    );
}
