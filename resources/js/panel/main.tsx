import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { lazy, StrictMode, Suspense } from 'react';
import { createRoot } from 'react-dom/client';
import { I18nextProvider } from 'react-i18next';
import { BrowserRouter, Route, Routes } from 'react-router';

import '../../css/panel.css';
import { Skeleton, ToastProvider, useDocumentTitle } from '../shared/ui';
import { ApiError } from './api/client';
import { AuthGate } from './auth/AuthGate';
import { sessionQueryKey } from './auth/session';
import { PanelLayout } from './components/PanelLayout';
import { i18n } from './i18n';
import { ForgotPasswordPage } from './pages/ForgotPasswordPage';
import { LoginPage } from './pages/LoginPage';
import { MfaSetupPage } from './pages/MfaSetupPage';
import { MfaVerifyPage } from './pages/MfaVerifyPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { ResetPasswordPage } from './pages/ResetPasswordPage';

/* Divisione del codice (8.4): ogni pagina del pannello dopo l'accesso si carica quando serve. */
const AccountPage = lazy(() => import('./pages/AccountPage').then((m) => ({ default: m.AccountPage })));
const CalendarPage = lazy(() => import('./pages/CalendarPage').then((m) => ({ default: m.CalendarPage })));
const CalendarsPage = lazy(() => import('./pages/CalendarsPage').then((m) => ({ default: m.CalendarsPage })));
const ConflictsPage = lazy(() => import('./pages/ConflictsPage').then((m) => ({ default: m.ConflictsPage })));
const GuestDataPage = lazy(() => import('./pages/GuestDataPage').then((m) => ({ default: m.GuestDataPage })));
const MetricsPage = lazy(() => import('./pages/MetricsPage').then((m) => ({ default: m.MetricsPage })));
const NewReservationPage = lazy(() => import('./pages/NewReservationPage').then((m) => ({ default: m.NewReservationPage })));
const OffboardingPage = lazy(() => import('./pages/OffboardingPage').then((m) => ({ default: m.OffboardingPage })));
const PaymentsPage = lazy(() => import('./pages/PaymentsPage').then((m) => ({ default: m.PaymentsPage })));
const PropertiesPage = lazy(() => import('./pages/PropertiesPage').then((m) => ({ default: m.PropertiesPage })));
const PropertyPage = lazy(() => import('./pages/PropertyPage').then((m) => ({ default: m.PropertyPage })));
const QuotesPage = lazy(() => import('./pages/QuotesPage').then((m) => ({ default: m.QuotesPage })));
const ReservationDetailPage = lazy(() => import('./pages/ReservationDetailPage').then((m) => ({ default: m.ReservationDetailPage })));
const ReservationsPage = lazy(() => import('./pages/ReservationsPage').then((m) => ({ default: m.ReservationsPage })));
const SupportAccessPage = lazy(() => import('./pages/SupportAccessPage').then((m) => ({ default: m.SupportAccessPage })));
const UnitPage = lazy(() => import('./pages/UnitPage').then((m) => ({ default: m.UnitPage })));

const queryClient: QueryClient = new QueryClient({
    defaultOptions: {
        queries: {
            // Sessione scaduta o MFA da verificare: si ricarica lo stato dell'accesso e AuthGate
            // porta alla pagina giusta.
            retry: (count, error) => !(error instanceof ApiError && error.status < 500) && count < 2,
        },
    },
});

queryClient.getQueryCache().subscribe((event) => {
    const error = event.query.state.error;

    const isSessionQuery = event.query.queryHash === JSON.stringify(sessionQueryKey);

    if (event.type === 'updated' && error instanceof ApiError && error.status === 401 && !isSessionQuery) {
        void queryClient.invalidateQueries({ queryKey: sessionQueryKey });
    }
});

const container = document.getElementById('app');

if (container === null) {
    throw new Error('Elemento #app non trovato');
}

createRoot(container).render(
    <StrictMode>
        <I18nextProvider i18n={i18n}>
            <QueryClientProvider client={queryClient}>
                <ToastProvider>
                    <DocumentTitle />
                    <Suspense fallback={<PageLoading />}>
                <BrowserRouter basename="/app">
                    <Routes>
                        <Route
                            path="login"
                            element={
                                <AuthGate stage="anonymous">
                                    <LoginPage />
                                </AuthGate>
                            }
                        />
                        <Route
                            path="password-dimenticata"
                            element={
                                <AuthGate stage="anonymous">
                                    <ForgotPasswordPage />
                                </AuthGate>
                            }
                        />
                        {/* Senza AuthGate: il link della email vale anche con una sessione aperta su questo browser. */}
                        <Route path="reimposta-password" element={<ResetPasswordPage />} />
                        <Route
                            path="mfa/setup"
                            element={
                                <AuthGate stage="mfa-setup">
                                    <MfaSetupPage />
                                </AuthGate>
                            }
                        />
                        <Route
                            path="mfa/verify"
                            element={
                                <AuthGate stage="mfa-verify">
                                    <MfaVerifyPage />
                                </AuthGate>
                            }
                        />
                        <Route
                            element={
                                <AuthGate stage="ready">
                                    <PanelLayout />
                                </AuthGate>
                            }
                        >
                            <Route index element={<PropertiesPage />} />
                            <Route path="strutture/:propertyId" element={<PropertyPage />} />
                            <Route path="unita/:unitId" element={<UnitPage />} />
                            <Route path="calendario" element={<CalendarPage />} />
                            <Route path="prenotazioni" element={<ReservationsPage />} />
                            <Route path="prenotazioni/nuova" element={<NewReservationPage />} />
                            <Route path="prenotazioni/:reservationId" element={<ReservationDetailPage />} />
                            <Route path="pagamenti" element={<PaymentsPage />} />
                            <Route path="preventivi" element={<QuotesPage />} />
                            <Route path="conflitti" element={<ConflictsPage />} />
                            <Route path="calendari" element={<CalendarsPage />} />
                            <Route path="metriche" element={<MetricsPage />} />
                            <Route path="ospiti/:guestId/dati" element={<GuestDataPage />} />
                            <Route path="account" element={<AccountPage />} />
                            <Route path="uscita" element={<OffboardingPage />} />
                            <Route path="accessi-supporto" element={<SupportAccessPage />} />
                            <Route path="*" element={<NotFoundPage />} />
                        </Route>
                    </Routes>
                </BrowserRouter>
                    </Suspense>
                </ToastProvider>
            </QueryClientProvider>
        </I18nextProvider>
    </StrictMode>,
);

/** Titolo della scheda: titolo della pagina più il nome del pannello (8.2). */
function DocumentTitle() {
    useDocumentTitle(i18n.t('app.name'));

    return null;
}

function PageLoading() {
    return (
        <main className="mx-auto max-w-5xl px-4 py-6">
            <Skeleton label={i18n.t('common.loading')} lines={5} />
        </main>
    );
}
