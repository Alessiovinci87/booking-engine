import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { privacyApi } from '../api/privacy';
import { useErrorMessage } from '../auth/session';
import { dangerButton, FormError, Section, secondaryButton } from './Form';
import { ProtectedDownload } from './ProtectedDownload';
import { ReauthPrompt, needsReauth } from './Reauth';

/**
 * Privacy dell'ospite nel dettaglio della prenotazione (6.3, 09 "Diritti degli interessati"):
 * download dei dati in JSON, versione stampabile (PDF dal browser), anonimizzazione con conferma e
 * ri-autenticazione.
 */
export function GuestPrivacy({ guestId, reservationId, anonymized }: { guestId: string; reservationId: string; anonymized: boolean }) {
    const { t } = useTranslation();
    const errorMessage = useErrorMessage();
    const queryClient = useQueryClient();
    const [confirming, setConfirming] = useState(false);
    const erase = useMutation({
        mutationFn: () => privacyApi.eraseGuest(guestId),
        onSuccess: () => {
            setConfirming(false);
            void queryClient.invalidateQueries({ queryKey: ['reservation', reservationId] });
        },
    });

    return (
        <Section title={t('privacy.title')}>
            <div className="space-y-3 text-sm" data-testid="guest-privacy">
                {anonymized ? (
                    <p className="text-slate-600">{t('privacy.anonymized')}</p>
                ) : (
                    <>
                        <p className="text-slate-600">{t('privacy.hint')}</p>
                        <div className="flex flex-wrap items-start gap-3">
                            <ProtectedDownload path={privacyApi.exportPath(guestId)} fallbackName={`ospite-${guestId}.json`} label={t('privacy.export')} testId="guest-export" />
                            <Link className={secondaryButton} to={`/ospiti/${guestId}/dati`} data-testid="guest-print">
                                {t('privacy.print')}
                            </Link>
                            {!confirming && (
                                <button type="button" className={dangerButton} onClick={() => setConfirming(true)} data-testid="guest-erase">
                                    {t('privacy.erase')}
                                </button>
                            )}
                        </div>
                        {confirming && (
                            <div className="space-y-3 rounded-md border border-red-200 bg-red-50 p-3">
                                <p className="text-red-900">{t('privacy.eraseConfirm')}</p>
                                <div className="flex gap-3">
                                    <button type="button" className={dangerButton} disabled={erase.isPending} onClick={() => erase.mutate()} data-testid="guest-erase-confirm">
                                        {t('privacy.erase')}
                                    </button>
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() => {
                                            setConfirming(false);
                                            erase.reset();
                                        }}
                                    >
                                        {t('common.cancel')}
                                    </button>
                                </div>
                            </div>
                        )}
                        {erase.isError &&
                            (needsReauth(erase.error) ? (
                                <ReauthPrompt onConfirmed={() => erase.mutate()} onCancel={() => erase.reset()} />
                            ) : (
                                <FormError message={errorMessage(erase.error)} />
                            ))}
                    </>
                )}
                {erase.isSuccess && <p className="text-emerald-700">{t('privacy.eraseDone')}</p>}
            </div>
        </Section>
    );
}
