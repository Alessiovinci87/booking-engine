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
            <header className="border-b border-line-soft bg-surface">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                    <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-muted">{t('app.name')}</p>
                        <p className="font-semibold text-ink" data-testid="tenant-name">
                            {me.data?.tenant.name ?? '…'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-4 text-sm">
                        <nav className="flex flex-wrap items-center gap-4">
                            <Link to="/" className="font-medium text-brand-800 hover:underline">
                                {t('layout.properties')}
                            </Link>
                            <Link to="/calendario" className="font-medium text-brand-800 hover:underline">
                                {t('layout.calendar')}
                            </Link>
                            <Link to="/prenotazioni" className="font-medium text-brand-800 hover:underline">
                                {t('layout.reservations')}
                            </Link>
                            <Link to="/conflitti" className="font-medium text-brand-800 hover:underline">
                                {t('layout.conflicts')}
                            </Link>
                            <Link to="/calendari" className="font-medium text-brand-800 hover:underline">
                                {t('layout.calendars')}
                            </Link>
                            <Link to="/preventivi" className="font-medium text-brand-800 hover:underline">
                                {t('layout.quotes')}
                            </Link>
                            <Link to="/pagamenti" className="font-medium text-brand-800 hover:underline">
                                {t('layout.payments')}
                            </Link>
                            <Link to="/metriche" className="font-medium text-brand-800 hover:underline">
                                {t('layout.metrics')}
                            </Link>
                        </nav>
                        {me.data && (
                            <Link to="/account" className="hidden text-muted hover:underline sm:inline" title={t('layout.account')}>
                                {me.data.user.email}
                            </Link>
                        )}
                        <Link to="/account" className="font-medium text-brand-800 hover:underline sm:hidden">
                            {t('layout.account')}
                        </Link>
                        <LogoutButton className="rounded-control border border-line px-3 py-1.5 text-ink hover:bg-canvas" />
                    </div>
                </div>
            </header>
            {me.data?.support && (
                <p className="border-b border-violet-300 bg-violet-50 px-4 py-2 text-center text-sm font-medium text-violet-900" role="status" data-testid="support-banner">
                    {t('support.banner', {
                        time: new Date(me.data.support.expires_at).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }),
                        reason: me.data.support.reason,
                    })}
                </p>
            )}
            {me.data !== undefined && me.data.tenant.dpa_signed_at === null && (
                <p className="border-b border-amber-200 bg-warning-soft px-4 py-2 text-center text-sm text-warning" data-testid="dpa-missing">
                    {t('privacy.dpaMissing')}
                </p>
            )}
            {me.data !== undefined && (me.data.tenant.status === 'OFFBOARDING' || me.data.tenant.status === 'CLOSED') && (
                <p className="border-b border-red-200 bg-danger-soft px-4 py-2 text-center text-sm text-danger" data-testid="offboarding-banner">
                    {t(me.data.tenant.status === 'CLOSED' ? 'offboarding.bannerClosed' : 'offboarding.banner')}{' '}
                    <Link to="/uscita" className="font-medium underline">
                        {t('offboarding.bannerLink')}
                    </Link>
                </p>
            )}
            <Outlet />
        </div>
    );
}
