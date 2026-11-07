import { cloneElement, isValidElement, useId, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import type { LocaleCode, TranslationTexts, Translations } from '../api/admin';
import { buttonClass, Card, ConfirmDialog, Skeleton } from '../../shared/ui';
import { inputClass } from './AuthCard';

export { FormError, inputClass } from './AuthCard';
export { EmptyState } from '../../shared/ui';

/** Caricamento di una sezione o di una pagina intera del pannello (8.5): annunciato agli screen reader. */
export function Loading({ page = false, lines = 3 }: { page?: boolean; lines?: number }) {
    const { t } = useTranslation();
    const skeleton = <Skeleton label={t('common.loading')} lines={lines} />;

    return page ? <div className="mx-auto max-w-5xl px-4 py-8">{skeleton}</div> : skeleton;
}

interface PendingConfirmation {
    title: string;
    confirmLabel: string;
    action: () => void;
}

/**
 * Conferma prima di un'azione irreversibile (8.5) con il dialogo condiviso, al posto di window.confirm:
 * `ask` apre il dialogo, `dialog` va messo nel markup vicino al pulsante.
 */
export function useConfirm(): { ask: (confirmation: PendingConfirmation) => void; dialog: ReactNode } {
    const { t } = useTranslation();
    const [pending, setPending] = useState<PendingConfirmation | null>(null);
    const dialog = (
        <ConfirmDialog
            open={pending !== null}
            title={pending?.title ?? ''}
            confirmLabel={pending?.confirmLabel ?? ''}
            cancelLabel={t('common.cancel')}
            onConfirm={() => {
                pending?.action();
                setPending(null);
            }}
            onCancel={() => setPending(null)}
            testId="confirm-dialog"
        />
    );

    return { ask: setPending, dialog };
}

export const primaryButton = buttonClass('primary');

export const secondaryButton = buttonClass('secondary');

export const dangerButton = buttonClass('danger');

/** Riquadro di una sezione della pagina. */
export function Section({ title, children, actions }: { title: string; children: ReactNode; actions?: ReactNode }) {
    return (
        <Card title={title} actions={actions}>
            {children}
        </Card>
    );
}

/** Campo con etichetta ed eventuali errori di validazione del server. */
export function Field({ label, errors, children, hint }: { label: string; errors?: string[]; children: ReactNode; hint?: string }) {
    const hintId = useId();
    const errorId = useId();
    const hasError = errors !== undefined && errors.length > 0;
    const describedBy = [hint !== undefined ? hintId : null, hasError ? errorId : null].filter(Boolean).join(' ') || undefined;
    // Errori e suggerimento collegati al controllo (8.2, WCAG 3.3.1 e 1.3.1).
    const control = isValidElement<{ 'aria-invalid'?: boolean; 'aria-describedby'?: string }>(children)
        ? cloneElement(children, { 'aria-invalid': hasError || undefined, 'aria-describedby': describedBy })
        : children;

    return (
        <label className="block text-sm font-medium text-ink">
            {label}
            {control}
            {hint !== undefined && (
                <span id={hintId} className="mt-1 block text-xs font-normal text-muted">
                    {hint}
                </span>
            )}
            {hasError && (
                <span id={errorId} className="mt-1 block text-xs font-normal text-danger">
                    {errors.join(' ')}
                </span>
            )}
        </label>
    );
}

export function TextInput({
    value,
    onChange,
    type = 'text',
    ...rest
}: {
    value: string;
    onChange: (value: string) => void;
    type?: string;
    placeholder?: string;
    required?: boolean;
    inputMode?: 'numeric' | 'decimal' | 'text' | 'email' | 'tel' | 'url';
    min?: number;
    max?: number;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
}) {
    return <input className={inputClass} type={type} value={value} onChange={(e) => onChange(e.target.value)} {...rest} />;
}

/** Stato del modulo delle traduzioni: testi vuoti = lingua assente. */
export type TranslationDraft = Record<LocaleCode, { name: string; description: string; house_rules: string }>;

export function translationDraft(translations?: Translations): TranslationDraft {
    const one = (t: TranslationTexts | null | undefined) => ({
        name: t?.name ?? '',
        description: t?.description ?? '',
        house_rules: t?.house_rules ?? '',
    });

    return { it: one(translations?.it), en: one(translations?.en) };
}

/** Bozza → corpo dell'API: una lingua senza nome si elimina (null). */
export function translationPayload(draft: TranslationDraft): Record<LocaleCode, TranslationTexts | null> {
    const one = (t: TranslationDraft[LocaleCode]): TranslationTexts | null =>
        t.name.trim() === '' ? null : { name: t.name, description: t.description || null, house_rules: t.house_rules || null };

    return { it: one(draft.it), en: one(draft.en) };
}

/** Testi in italiano e inglese (02: nessuna traduzione automatica). */
export function TranslationFields({
    draft,
    onChange,
    errors,
}: {
    draft: TranslationDraft;
    errors: Record<string, string[]>;
    onChange: (draft: TranslationDraft) => void;
}) {
    const { t } = useTranslation();
    const locales: LocaleCode[] = ['it', 'en'];

    return (
        <div className="grid gap-4 md:grid-cols-2">
            {locales.map((locale) => {
                const value = draft[locale];
                const set = (field: keyof typeof value, text: string) => onChange({ ...draft, [locale]: { ...value, [field]: text } });

                return (
                    <fieldset key={locale} className="space-y-3 rounded-control border border-line-soft p-3">
                        <legend className="px-1 text-sm font-semibold text-ink">{t(`translations.${locale}`)}</legend>
                        <Field label={t('translations.name')} errors={errors[`translations.${locale}.name`] ?? errors[`translations.${locale}`]}>
                            <TextInput value={value.name} onChange={(text) => set('name', text)} />
                        </Field>
                        <Field label={t('translations.description')} errors={errors[`translations.${locale}.description`]}>
                            <textarea className={inputClass} rows={4} value={value.description} onChange={(e) => set('description', e.target.value)} />
                        </Field>
                        <Field label={t('translations.houseRules')} errors={errors[`translations.${locale}.house_rules`]}>
                            <textarea className={inputClass} rows={3} value={value.house_rules} onChange={(e) => set('house_rules', e.target.value)} />
                        </Field>
                    </fieldset>
                );
            })}
        </div>
    );
}

export function SavedNotice({ show }: { show: boolean }) {
    const { t } = useTranslation();

    return show ? (
        <span className="text-sm text-success" role="status">
            {t('common.saved')}
        </span>
    ) : null;
}

/** Esito dell'ultimo salvataggio di un modulo con If-Match (07). */
export type SaveNotice = 'saved' | 'conflict' | null;

/** 412 VERSION_MISMATCH: il modulo è stato ricaricato con i dati più recenti. */
export function ConflictNotice({ show }: { show: boolean }) {
    const { t } = useTranslation();

    return show ? (
        <p className="rounded-control border border-amber-200 bg-warning-soft px-3 py-2 text-sm text-warning" role="alert">
            {t('common.conflictReloaded')}
        </p>
    ) : null;
}
