import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Navigate } from 'react-router';

import { type AuthStage, useAuthStage } from './session';

const routeFor: Record<Exclude<AuthStage, 'loading'>, string> = {
    anonymous: '/login',
    'mfa-setup': '/mfa/setup',
    'mfa-verify': '/mfa/verify',
    ready: '/',
};

/**
 * Mostra i figli solo nella fase di accesso indicata; altrimenti porta alla pagina giusta.
 * Il controllo vero è sul server (EnsureMfaVerified): qui si guida soltanto la navigazione.
 */
export function AuthGate({ stage, children }: { stage: Exclude<AuthStage, 'loading'>; children: ReactNode }) {
    const { t } = useTranslation();
    const current = useAuthStage();

    if (current === 'loading') {
        return (
            <p className="p-10 text-center text-muted" role="status">
                {t('common.loading')}
            </p>
        );
    }

    if (current !== stage) {
        return <Navigate to={routeFor[current]} replace />;
    }

    return <>{children}</>;
}
