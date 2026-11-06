import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { api, ApiError, type SessionState } from '../api/client';

export type AuthStage = 'loading' | 'anonymous' | 'mfa-setup' | 'mfa-verify' | 'ready';

export const sessionQueryKey = ['auth', 'session'] as const;

/** Stato dell'accesso: nessuna sessione, MFA da configurare, MFA da verificare, pannello pronto. */
export function useAuthStage(): AuthStage {
    const query = useQuery<SessionState | null>({
        queryKey: sessionQueryKey,
        queryFn: async () => {
            try {
                return await api.session();
            } catch (error) {
                if (error instanceof ApiError && error.status === 401) {
                    return null;
                }
                throw error;
            }
        },
        retry: false,
        staleTime: 30_000,
    });

    if (query.isPending) {
        return 'loading';
    }

    const session = query.data ?? null;

    if (session === null) {
        return 'anonymous';
    }

    if (!session.mfa_enabled) {
        return 'mfa-setup';
    }

    return session.mfa_verified ? 'ready' : 'mfa-verify';
}

/** Messaggio tradotto per un errore dell'API, a partire dal codice. */
export function useErrorMessage(): (error: unknown) => string {
    const { t } = useTranslation();

    return (error: unknown) => {
        const code = error instanceof ApiError ? error.code : 'SERVER_ERROR';

        return t(`errors.${code}`, { defaultValue: t('errors.SERVER_ERROR') });
    };
}
