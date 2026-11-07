import { useMutation } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { apiDownload } from '../api/client';
import { useErrorMessage } from '../auth/session';
import { FormError, secondaryButton } from './Form';
import { ReauthPrompt, needsReauth } from './Reauth';

/**
 * Pulsante di download per gli export con dati personali (08 "export completo", 6.7): il file si
 * scarica con fetch; se il server chiede la password (REAUTH_REQUIRED) compare la richiesta e, dopo
 * la conferma, il download riparte da solo.
 */
export function ProtectedDownload({ path, fallbackName, label, testId }: { path: string; fallbackName: string; label: string; testId: string }) {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const download = useMutation({
        mutationFn: () => apiDownload(path, fallbackName),
        onSuccess: ({ blob, filename }) => {
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
        },
    });

    return (
        <div className="space-y-2">
            <button type="button" className={secondaryButton} disabled={download.isPending} onClick={() => download.mutate()} data-testid={testId}>
                {download.isPending ? t('common.wait') : label}
            </button>
            {download.isError &&
                (needsReauth(download.error) ? (
                    <ReauthPrompt onConfirmed={() => download.mutate()} onCancel={() => download.reset()} />
                ) : (
                    <FormError message={errorMessage(download.error)} />
                ))}
        </div>
    );
}
