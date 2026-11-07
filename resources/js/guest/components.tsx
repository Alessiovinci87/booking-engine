import { useQuery } from '@tanstack/react-query';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, Skeleton, SkipLink, useDocumentTitle } from '../shared/ui';
import { publicApi, type Photo } from './api';
import { guestLanguages } from './i18n';
import { LanguageSelector } from './LanguageSelector';

/**
 * Contatti della struttura come link cliccabili (8.7): email e telefono sono dati della struttura,
 * mai dell'ospite. Aree toccabili di 44 px sul telefono.
 */
export function ContactLinks({ email, phone }: { email: string | null; phone: string | null }) {
    const link = 'inline-flex min-h-tap items-center font-medium text-brand-800 underline underline-offset-2 hover:text-brand-900';

    return (
        <span className="flex flex-wrap gap-x-4" data-testid="property-contacts">
            {email !== null && (
                <a className={link} href={`mailto:${email}`}>
                    {email}
                </a>
            )}
            {phone !== null && (
                <a className={link} href={`tel:${phone.replace(/[^+\d]/g, '')}`}>
                    {phone}
                </a>
            )}
        </span>
    );
}

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
    useDocumentTitle(title ?? t('common.pageTitle'));

    return (
        <div className="mx-auto min-h-screen max-w-3xl px-4 pb-16">
            <SkipLink label={t('common.skipToContent')} />
            <header className="flex items-center justify-between gap-4 py-4">
                <p className="truncate text-sm font-medium text-muted">{title}</p>
                <LanguageSelector languages={guestLanguages} />
            </header>
            <main id="contenuto">{children}</main>
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

/**
 * Galleria adatta al telefono: una foto alla volta con frecce da 44 px e indicatore; un tocco sulla foto
 * la apre ingrandita a tutto schermo (8.3), in un <dialog> modale chiudibile con Esc o con il pulsante.
 */
export function Gallery({ photos, name }: { photos: Photo[]; name: string }) {
    const { t } = useTranslation();
    const [index, setIndex] = useState(0);
    const [zoom, setZoom] = useState(false);
    const dialog = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const element = dialog.current;

        if (element === null) {
            return;
        }

        if (zoom && !element.open) {
            element.showModal();
        } else if (!zoom && element.open) {
            element.close();
        }
    }, [zoom]);

    if (photos.length === 0) {
        return <div className="flex aspect-[4/3] items-center justify-center rounded-card bg-surface-muted text-sm text-muted">{t('unit.noPhoto')}</div>;
    }

    const current = photos[Math.min(index, photos.length - 1)] ?? photos[0];
    const move = (step: number) => setIndex((i) => (i + step + photos.length) % photos.length);
    const arrows = (variant: 'inline' | 'zoom') =>
        photos.length > 1 && (
            <>
                <button
                    type="button"
                    onClick={() => move(-1)}
                    aria-label={t('property.galleryPrevious')}
                    className={`absolute top-1/2 left-2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full text-2xl shadow-card ${variant === 'zoom' ? 'bg-white/20 text-white' : 'bg-white/90 text-ink'}`}
                >
                    ‹
                </button>
                <button
                    type="button"
                    onClick={() => move(1)}
                    aria-label={t('property.galleryNext')}
                    className={`absolute top-1/2 right-2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full text-2xl shadow-card ${variant === 'zoom' ? 'bg-white/20 text-white' : 'bg-white/90 text-ink'}`}
                >
                    ›
                </button>
            </>
        );

    return (
        <div className="relative overflow-hidden rounded-card bg-surface-muted" data-testid="gallery">
            <button type="button" className="block w-full cursor-zoom-in" onClick={() => setZoom(true)} aria-label={t('property.galleryZoom')} data-testid="gallery-zoom">
                <img
                    src={current?.urls.large}
                    alt={t('property.photoAlt', { name })}
                    className="aspect-[4/3] w-full object-cover"
                    width={current?.width ?? 1200}
                    height={current?.height ?? 900}
                    decoding="async"
                    fetchPriority="high"
                />
            </button>
            {arrows('inline')}
            {photos.length > 1 && (
                <span className="pointer-events-none absolute right-2 bottom-2 rounded bg-black/70 px-2 py-0.5 text-xs text-white" aria-live="polite">
                    {index + 1} / {photos.length}
                </span>
            )}
            <dialog
                ref={dialog}
                aria-label={t('property.photoAlt', { name })}
                className="m-0 h-full max-h-none w-full max-w-none bg-black p-0"
                onCancel={(event) => {
                    event.preventDefault();
                    setZoom(false);
                }}
                data-testid="gallery-dialog"
            >
                {zoom && (
                    <div className="relative flex h-full w-full items-center justify-center">
                        <img src={current?.urls.large} alt={t('property.photoAlt', { name })} className="max-h-full max-w-full object-contain" />
                        {arrows('zoom')}
                        <button
                            type="button"
                            onClick={() => setZoom(false)}
                            className="absolute top-3 right-3 min-h-tap rounded-full bg-white px-4 text-sm font-medium text-ink"
                            autoFocus
                        >
                            {t('common.close')}
                        </button>
                        {photos.length > 1 && (
                            <span className="absolute bottom-4 left-1/2 -translate-x-1/2 rounded bg-black/70 px-2 py-0.5 text-sm text-white">
                                {index + 1} / {photos.length}
                            </span>
                        )}
                    </div>
                )}
            </dialog>
        </div>
    );
}

export function Spinner() {
    const { t } = useTranslation();

    return <Skeleton label={t('common.loading')} lines={4} className="py-8" />;
}
