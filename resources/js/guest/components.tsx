import { useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, Skeleton } from '../shared/ui';
import { publicApi, type Photo } from './api';
import { guestLanguages } from './i18n';
import { LanguageSelector } from './LanguageSelector';

/** Struttura pubblicata; ricaricata quando cambia la lingua (testi tradotti dal server). */
export function useProperty() {
    const { i18n } = useTranslation();

    return useQuery({ queryKey: ['property', i18n.language], queryFn: () => publicApi.property() });
}

/** Link della piattaforma (09 requisiti 2 e 5), dalla configurazione del server via data-attribute. */
function platformLinks(): { privacy: string; subprocessors: string } {
    const app = document.getElementById('app');

    return { privacy: app?.dataset.platformPrivacy ?? '', subprocessors: app?.dataset.subprocessors ?? '' };
}

export function Shell({ title, children }: { title?: string; children: ReactNode }) {
    const { t } = useTranslation();
    const links = platformLinks();

    return (
        <div className="mx-auto min-h-screen max-w-3xl px-4 pb-16">
            <header className="flex items-center justify-between gap-4 py-4">
                <p className="truncate text-sm font-medium text-muted">{title}</p>
                <LanguageSelector languages={guestLanguages} />
            </header>
            {children}
            {(links.privacy !== '' || links.subprocessors !== '') && (
                <footer className="mt-12 flex flex-wrap gap-4 border-t border-line-soft pt-4 text-xs text-muted" data-testid="platform-footer">
                    {links.privacy !== '' && (
                        <a href={links.privacy} target="_blank" rel="noopener noreferrer" className="underline" data-testid="platform-privacy">
                            {t('footer.platformPrivacy')}
                        </a>
                    )}
                    {links.subprocessors !== '' && (
                        <a href={links.subprocessors} target="_blank" rel="noopener noreferrer" className="underline">
                            {t('footer.subprocessors')}
                        </a>
                    )}
                </footer>
            )}
        </div>
    );
}

export function Notice({ tone = 'info', children }: { tone?: 'info' | 'error' | 'success' | 'warning'; children: ReactNode }) {
    return <Alert tone={tone === 'error' ? 'danger' : tone}>{children}</Alert>;
}

/** Galleria scorrevole, adatta al telefono: una foto alla volta con frecce e indicatore. */
export function Gallery({ photos, name }: { photos: Photo[]; name: string }) {
    const { t } = useTranslation();
    const [index, setIndex] = useState(0);

    if (photos.length === 0) {
        return <div className="flex aspect-[4/3] items-center justify-center rounded-card bg-surface-muted text-sm text-muted">{t('unit.noPhoto')}</div>;
    }

    const current = photos[Math.min(index, photos.length - 1)] ?? photos[0];
    const move = (step: number) => setIndex((i) => (i + step + photos.length) % photos.length);

    return (
        <div className="relative overflow-hidden rounded-card bg-surface-muted" data-testid="gallery">
            <img
                src={current?.urls.large}
                alt={t('property.photoAlt', { name })}
                className="aspect-[4/3] w-full object-cover"
                width={current?.width ?? undefined}
                height={current?.height ?? undefined}
            />
            {photos.length > 1 && (
                <>
                    <button
                        type="button"
                        onClick={() => move(-1)}
                        aria-label={t('property.galleryPrevious')}
                        className="absolute top-1/2 left-2 -translate-y-1/2 rounded-full bg-surface/90 px-3 py-2 text-lg shadow"
                    >
                        ‹
                    </button>
                    <button
                        type="button"
                        onClick={() => move(1)}
                        aria-label={t('property.galleryNext')}
                        className="absolute top-1/2 right-2 -translate-y-1/2 rounded-full bg-surface/90 px-3 py-2 text-lg shadow"
                    >
                        ›
                    </button>
                    <span className="absolute right-2 bottom-2 rounded bg-black/60 px-2 py-0.5 text-xs text-white">
                        {index + 1} / {photos.length}
                    </span>
                </>
            )}
        </div>
    );
}

export function Spinner() {
    const { t } = useTranslation();

    return <Skeleton label={t('common.loading')} lines={4} className="py-8" />;
}
