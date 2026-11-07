import { useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

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
                <p className="truncate text-sm font-medium text-slate-500">{title}</p>
                <LanguageSelector languages={guestLanguages} />
            </header>
            {children}
            {(links.privacy !== '' || links.subprocessors !== '') && (
                <footer className="mt-12 flex flex-wrap gap-4 border-t border-slate-200 pt-4 text-xs text-slate-500" data-testid="platform-footer">
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
    const tones = {
        info: 'border-sky-200 bg-sky-50 text-sky-900',
        error: 'border-red-200 bg-red-50 text-red-900',
        success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
        warning: 'border-amber-200 bg-amber-50 text-amber-900',
    };

    return (
        <div role={tone === 'error' ? 'alert' : 'status'} className={`rounded-lg border px-4 py-3 text-sm ${tones[tone]}`}>
            {children}
        </div>
    );
}

/** Galleria scorrevole, adatta al telefono: una foto alla volta con frecce e indicatore. */
export function Gallery({ photos, name }: { photos: Photo[]; name: string }) {
    const { t } = useTranslation();
    const [index, setIndex] = useState(0);

    if (photos.length === 0) {
        return <div className="flex aspect-[4/3] items-center justify-center rounded-xl bg-slate-100 text-sm text-slate-500">{t('unit.noPhoto')}</div>;
    }

    const current = photos[Math.min(index, photos.length - 1)] ?? photos[0];
    const move = (step: number) => setIndex((i) => (i + step + photos.length) % photos.length);

    return (
        <div className="relative overflow-hidden rounded-xl bg-slate-100" data-testid="gallery">
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
                        className="absolute top-1/2 left-2 -translate-y-1/2 rounded-full bg-white/90 px-3 py-2 text-lg shadow"
                    >
                        ‹
                    </button>
                    <button
                        type="button"
                        onClick={() => move(1)}
                        aria-label={t('property.galleryNext')}
                        className="absolute top-1/2 right-2 -translate-y-1/2 rounded-full bg-white/90 px-3 py-2 text-lg shadow"
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

    return (
        <p className="py-12 text-center text-sm text-slate-500" role="status">
            {t('common.loading')}
        </p>
    );
}
