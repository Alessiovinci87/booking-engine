import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, NavLink, Outlet } from 'react-router';

import { SkipLink } from '../../shared/ui';
import { api } from '../api/client';
import { LogoutButton } from './LogoutButton';

/** Voce del menu: area toccabile di 44 px, pagina corrente evidenziata (aria-current da NavLink). */
const navClass = ({ isActive }: { isActive: boolean }) =>
    `inline-flex min-h-tap items-center border-b-2 font-medium ${isActive ? 'border-brand-700 text-ink' : 'border-transparent text-brand-800 hover:border-brand-200'}`;

/** Layout base del pannello: nome del tenant, navigazione, utente e uscita. */
export function PanelLayout() {
    const { t } = useTranslation();
    const me = useQuery({ queryKey: ['me'], queryFn: api.me, staleTime: 60_000 });

    return (
        <div className="min-h-screen">
            <SkipLink label={t('common.skipToContent')} />
            <header className="border-b border-line-soft bg-surface">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                    <div>
                        <p className="text-xs font-medium uppercase tracking-wide text-muted">{t('app.name')}</p>
                        <p className="font-semibold text-ink" data-testid="tenant-name">
                            {me.data?.tenant.name ?? '…'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-4 text-sm">
                        <nav aria-label={t('layout.mainNav')} className="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <NavLink to="/" end={true} className={navClass}>
                                {t('layout.properties')}
                            </NavLink>
                            <NavLink to="/calendario" end={false} className={navClass}>
                                {t('layout.calendar')}
                            </NavLink>
                            <NavLink to="/prenotazioni" end={false} className={navClass}>
                                {t('layout.reservations')}
                            </NavLink>
                            <NavLink to="/conflitti" end={false} className={navClass}>
                                {t('layout.conflicts')}
                            </NavLink>
                            <NavLink to="/calendari" end={false} className={navClass}>
                                {t('layout.calendars')}
                            </NavLink>
                            <NavLink to="/preventivi" end={false} className={navClass}>
                                {t('layout.quotes')}
                            </NavLink>
                            <NavLink to="/pagamenti" end={false} className={navClass}>
                                {t('layout.payments')}
                            </NavLink>
                            <NavLink to="/metriche" end={false} className={navClass}>
                                {t('layout.metrics')}
                            </NavLink>
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
