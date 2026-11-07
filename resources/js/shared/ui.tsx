/**
 * Componenti interni condivisi da pannello e pagina ospite (8.1, ADR-0017): usano solo i token di
 * resources/css/theme.css. Nessun testo fisso: etichette e messaggi arrivano già tradotti.
 */
import { createContext, useCallback, useContext, useEffect, useId, useMemo, useRef, useState, type ButtonHTMLAttributes, type ReactNode } from 'react';

export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost';
export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'brand';

const buttonBase =
    'inline-flex min-h-tap items-center justify-center gap-2 rounded-control px-4 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60';

const buttonVariants: Record<ButtonVariant, string> = {
    primary: 'bg-brand-700 text-white shadow-card hover:bg-brand-800',
    secondary: 'border border-line bg-surface text-ink hover:bg-surface-muted',
    danger: 'border border-danger/40 bg-surface text-danger hover:bg-danger-soft',
    ghost: 'text-brand-800 underline-offset-2 hover:underline',
};

/** Classi di un pulsante (anche per i link che sembrano pulsanti). */
export function buttonClass(variant: ButtonVariant = 'primary', options: { block?: boolean; strong?: boolean } = {}): string {
    const danger = variant === 'danger' && options.strong ? 'bg-danger text-white hover:bg-red-800 border-transparent' : '';

    return [buttonBase, buttonVariants[variant], danger, options.block ? 'w-full' : ''].filter(Boolean).join(' ');
}

export function Button({ variant = 'primary', block, strong, className, type = 'button', ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant; block?: boolean; strong?: boolean }) {
    return <button type={type} className={[buttonClass(variant, { block, strong }), className ?? ''].join(' ')} {...rest} />;
}

/** Campo di testo, select e area di testo: stessa altezza minima delle aree toccabili. */
export const inputClass =
    'mt-1 block min-h-tap w-full rounded-control border border-line bg-surface px-3 py-2 text-base text-ink placeholder:text-muted focus:border-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-600/40 aria-[invalid=true]:border-danger sm:text-sm';

export const labelClass = 'block text-sm font-medium text-ink';

export const linkClass = 'font-medium text-brand-800 underline-offset-2 hover:underline';

/** Riquadro con titolo facoltativo e azioni. */
export function Card({ title, actions, children, className, testId }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string; testId?: string }) {
    return (
        <section className={`rounded-card border border-line-soft bg-surface p-5 shadow-card ${className ?? ''}`} data-testid={testId}>
            {(title !== undefined || actions !== undefined) && (
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    {title !== undefined && <h2 className="text-lg font-semibold text-ink">{title}</h2>}
                    {actions}
                </div>
            )}
            {children}
        </section>
    );
}

const badgeTones: Record<Tone, string> = {
    neutral: 'border-line bg-surface-muted text-muted',
    info: 'border-sky-300 bg-info-soft text-info',
    success: 'border-emerald-300 bg-success-soft text-success',
    warning: 'border-amber-300 bg-warning-soft text-warning',
    danger: 'border-red-300 bg-danger-soft text-danger',
    brand: 'border-brand-200 bg-brand-50 text-brand-800',
};

export function Badge({ tone = 'neutral', children, testId }: { tone?: Tone; children: ReactNode; testId?: string }) {
    return (
        <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${badgeTones[tone]}`} data-testid={testId}>
            {children}
        </span>
    );
}

const alertTones: Record<Exclude<Tone, 'neutral' | 'brand'>, string> = {
    info: 'border-sky-200 bg-info-soft text-info',
    success: 'border-emerald-200 bg-success-soft text-success',
    warning: 'border-amber-200 bg-warning-soft text-warning',
    danger: 'border-red-200 bg-danger-soft text-danger',
};

/** Messaggio in evidenza; gli errori sono annunciati subito dagli screen reader (role="alert"). */
export function Alert({ tone = 'info', children, testId }: { tone?: keyof typeof alertTones; children: ReactNode; testId?: string }) {
    return (
        <div role={tone === 'danger' ? 'alert' : 'status'} className={`rounded-card border px-4 py-3 text-sm ${alertTones[tone]}`} data-testid={testId}>
            {children}
        </div>
    );
}

/** Segnaposto di caricamento: annunciato una volta con l'etichetta, le barre sono decorative. */
export function Skeleton({ label, lines = 3, className }: { label: string; lines?: number; className?: string }) {
    return (
        <div role="status" aria-live="polite" className={`space-y-3 py-4 ${className ?? ''}`}>
            <span className="sr-only">{label}</span>
            {Array.from({ length: lines }, (_, i) => (
                <div key={i} aria-hidden="true" className={`h-4 animate-pulse rounded bg-surface-muted ${i === lines - 1 ? 'w-2/3' : 'w-full'}`} />
            ))}
        </div>
    );
}

/** Stato vuoto di un elenco: cosa manca e, se c'è, cosa fare. */
export function EmptyState({ title, body, action, testId }: { title: string; body?: ReactNode; action?: ReactNode; testId?: string }) {
    return (
        <div className="rounded-card border border-dashed border-line px-6 py-8 text-center" data-testid={testId}>
            <p className="font-medium text-ink">{title}</p>
            {body !== undefined && <p className="mt-1 text-sm text-muted">{body}</p>}
            {action !== undefined && <div className="mt-4">{action}</div>}
        </div>
    );
}

/** Tabella con scorrimento orizzontale nel suo riquadro (mai della pagina) e didascalia per gli screen reader. */
export function DataTable({ caption, children, testId }: { caption: string; children: ReactNode; testId?: string }) {
    return (
        <div className="overflow-x-auto rounded-card border border-line-soft">
            <table className="min-w-full divide-y divide-line-soft bg-surface text-left text-sm" data-testid={testId}>
                <caption className="sr-only">{caption}</caption>
                {children}
            </table>
        </div>
    );
}

export const thClass = 'px-3 py-2 text-xs font-semibold tracking-wide text-muted uppercase';
export const tdClass = 'px-3 py-2 align-top';

/**
 * Conferma prima di un'azione irreversibile (8.5): <dialog> modale nativo, quindi focus trattenuto,
 * Esc per annullare e focus restituito al pulsante che l'ha aperta.
 */
export function ConfirmDialog({
    open,
    title,
    children,
    confirmLabel,
    cancelLabel,
    onConfirm,
    onCancel,
    pending = false,
    danger = true,
    testId,
}: {
    open: boolean;
    title: string;
    children?: ReactNode;
    confirmLabel: string;
    cancelLabel: string;
    onConfirm: () => void;
    onCancel: () => void;
    pending?: boolean;
    danger?: boolean;
    testId?: string;
}) {
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();

    useEffect(() => {
        const dialog = ref.current;

        if (dialog === null) {
            return;
        }

        if (open && !dialog.open) {
            dialog.showModal();
        } else if (!open && dialog.open) {
            dialog.close();
        }
    }, [open]);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            className="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-card border border-line-soft bg-surface p-0 text-ink shadow-overlay"
            onCancel={(event) => {
                event.preventDefault();
                onCancel();
            }}
            data-testid={testId}
        >
            {open && (
                <div className="space-y-4 p-6">
                    <h2 id={titleId} className="text-lg font-semibold">
                        {title}
                    </h2>
                    {children !== undefined && <div className="text-sm text-muted">{children}</div>}
                    <div className="flex flex-wrap justify-end gap-3">
                        <Button variant="secondary" onClick={onCancel} autoFocus>
                            {cancelLabel}
                        </Button>
                        <Button variant={danger ? 'danger' : 'primary'} strong={danger} onClick={onConfirm} disabled={pending} data-testid={testId === undefined ? undefined : `${testId}-confirm`}>
                            {confirmLabel}
                        </Button>
                    </div>
                </div>
            )}
        </dialog>
    );
}

interface ToastItem {
    id: number;
    message: string;
    tone: 'success' | 'danger' | 'info';
}

const ToastContext = createContext<(message: string, tone?: ToastItem['tone']) => void>(() => undefined);

/** Avvisi brevi dopo un'azione (salvato, revocato…): regione live sempre presente, chiusura dopo 5 secondi. */
export function ToastProvider({ children }: { children: ReactNode }) {
    const [items, setItems] = useState<ToastItem[]>([]);
    const next = useRef(0);
    const push = useCallback((message: string, tone: ToastItem['tone'] = 'success') => {
        next.current += 1;
        const id = next.current;
        setItems((list) => [...list, { id, message, tone }]);
        window.setTimeout(() => setItems((list) => list.filter((item) => item.id !== id)), 5000);
    }, []);
    const value = useMemo(() => push, [push]);
    const tones = { success: 'bg-success', danger: 'bg-danger', info: 'bg-ink' };

    return (
        <ToastContext.Provider value={value}>
            {children}
            <div aria-live="polite" role="status" className="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex flex-col items-center gap-2 px-4" data-testid="toasts">
                {items.map((item) => (
                    <p key={item.id} className={`pointer-events-auto rounded-control px-4 py-2 text-sm font-medium text-white shadow-overlay ${tones[item.tone]}`}>
                        {item.message}
                    </p>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    return useContext(ToastContext);
}

/**
 * Collegamento "Vai al contenuto" (8.2, WCAG 2.4.1): primo elemento raggiungibile con Tab, visibile solo
 * quando ha il focus; porta il focus sull'elemento <main> della pagina.
 */
export function SkipLink({ label }: { label: string }) {
    return (
        <a
            href="#contenuto"
            className="sr-only z-50 rounded-control bg-surface px-4 py-3 font-medium text-brand-800 shadow-overlay focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
            onClick={(event) => {
                const main = document.querySelector('main');

                if (main !== null) {
                    event.preventDefault();
                    main.setAttribute('tabindex', '-1');
                    main.focus();
                }
            }}
        >
            {label}
        </a>
    );
}

/**
 * Titolo della scheda dal titolo della pagina (<h1>) più un suffisso (8.2, WCAG 2.4.2): segue le
 * navigazioni della SPA senza toccare ogni pagina.
 */
export function useDocumentTitle(suffix: string) {
    useEffect(() => {
        const apply = () => {
            const heading = document.querySelector('h1')?.textContent?.trim() ?? '';
            const title = heading !== '' && heading !== suffix ? `${heading} · ${suffix}` : suffix;

            if (document.title !== title) {
                document.title = title;
            }
        };
        apply();
        const observer = new MutationObserver(apply);
        observer.observe(document.body, { childList: true, subtree: true, characterData: true });

        return () => observer.disconnect();
    }, [suffix]);
}
