import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { api } from '../api/client';

export function LogoutButton({ className }: { className?: string }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();

    const logout = useMutation({
        mutationFn: api.logout,
        onSettled: () => {
            // Nessun dato del tenant resta in memoria dopo l'uscita.
            queryClient.clear();
            window.location.assign('/app/login');
        },
    });

    return (
        <button type="button" className={className} disabled={logout.isPending} onClick={() => logout.mutate()}>
            {t('layout.logout')}
        </button>
    );
}
