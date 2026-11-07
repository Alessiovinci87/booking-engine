import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, Outlet } from 'react-router';

import { api } from '../api/client';
import { LogoutButton } from './LogoutButton';

/** Layout base del pannello: nome del tenant, navigazione, utente e uscita. */
export function PanelLayout() {
    const { t } = useTranslation();
    const me = useQuery({ queryKey: ['me'], queryFn: api.me, staleTime: 60_000 });

    return (
        <div className="min-h-screen">
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                    <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{t('app.name')}</p>
                        <p className="font-semibold text-slate-900" data-testid="tenant-name">
                            {me.data?.tenant.name ?? '…'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-4 text-sm">
                        <nav className="flex flex-wrap items-center gap-4">
                            <Link to="/" className="font-medium text-blue-700 hover:underline">
                                {t('layout.properties')}
                            </Link>
                            <Link to="/calendario" className="font-medium text-blue-700 hover:underline">
                                {t('layout.calendar')}
                            </Link>
                            <Link to="/prenotazioni" className="font-medium text-blue-700 hover:underline">
                                {t('layout.reservations')}
                            </Link>
                            <Link to="/conflitti" className="font-medium text-blue-700 hover:underline">
                                {t('layout.conflicts')}
                            </Link>
                            <Link to="/calendari" className="font-medium text-blue-700 hover:underline">
                                {t('layout.calendars')}
                            </Link>
                            <Link to="/preventivi" className="font-medium text-blue-700 hover:underline">
                                {t('layout.quotes')}
                            </Link>
                            <Link to="/pagamenti" className="font-medium text-blue-700 hover:underline">
                                {t('layout.payments')}
                            </Link>
                        </nav>
                        {me.data && <span className="hidden text-slate-600 sm:inline">{me.data.user.email}</span>}
                        <LogoutButton className="rounded-md border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50" />
                    </div>
                </div>
            </header>
            <Outlet />
        </div>
    );
}
