import { useId } from 'react';
import { useTranslation } from 'react-i18next';

import type { GuestLanguage } from './i18n';

export function LanguageSelector({ languages }: { languages: readonly GuestLanguage[] }) {
    const { t, i18n } = useTranslation();
    const id = useId();

    return (
        <div className="flex items-center gap-2 text-sm">
            <label htmlFor={id} className="text-muted">
                {t('language.label')}
            </label>
            <select
                id={id}
                value={i18n.resolvedLanguage}
                onChange={(event) => void i18n.changeLanguage(event.target.value)}
                className="min-h-tap rounded-control border border-line bg-surface px-3 py-2"
            >
                {languages.map((language) => (
                    <option key={language} value={language}>
                        {t(`language.${language}`)}
                    </option>
                ))}
            </select>
        </div>
    );
}
