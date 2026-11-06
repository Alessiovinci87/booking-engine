import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import type { LocaleCode, TranslationTexts, Translations } from '../api/admin';
import { inputClass } from './AuthCard';

export { FormError, inputClass } from './AuthCard';

export const primaryButton =
    'rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60';

export const secondaryButton =
    'rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60';

export const dangerButton =
    'rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60';

/** Riquadro di una sezione della pagina. */
export function Section({ title, children, actions }: { title: string; children: ReactNode; actions?: ReactNode }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div className="mb-4 flex items-center justify-between gap-4">
                <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
                {actions}
            </div>
            {children}
        </section>
    );
}

/** Campo con etichetta ed eventuali errori di validazione del server. */
export function Field({ label, errors, children, hint }: { label: string; errors?: string[]; children: ReactNode; hint?: string }) {
    return (
        <label className="block text-sm font-medium text-slate-700">
            {label}
            {children}
            {hint !== undefined && <span className="mt-1 block text-xs font-normal text-slate-500">{hint}</span>}
            {errors?.map((message) => (
                <span key={message} className="mt-1 block text-xs font-normal text-red-700">
                    {message}
                </span>
            ))}
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
                    <fieldset key={locale} className="space-y-3 rounded-md border border-slate-200 p-3">
                        <legend className="px-1 text-sm font-semibold text-slate-800">{t(`translations.${locale}`)}</legend>
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
        <span className="text-sm text-green-700" role="status">
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
        <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">
            {t('common.conflictReloaded')}
        </p>
    ) : null;
}
