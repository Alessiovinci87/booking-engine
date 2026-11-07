import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { I18nextProvider } from 'react-i18next';
import { BrowserRouter, Route, Routes } from 'react-router';

import '../../css/app.css';
import { ApiError } from './api/client';
import { AuthGate } from './auth/AuthGate';
import { sessionQueryKey } from './auth/session';
import { PanelLayout } from './components/PanelLayout';
import { i18n } from './i18n';
import { CalendarPage } from './pages/CalendarPage';
import { LoginPage } from './pages/LoginPage';
import { MfaSetupPage } from './pages/MfaSetupPage';
import { MfaVerifyPage } from './pages/MfaVerifyPage';
import { NewReservationPage } from './pages/NewReservationPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { PaymentsPage } from './pages/PaymentsPage';
import { PropertiesPage } from './pages/PropertiesPage';
import { PropertyPage } from './pages/PropertyPage';
import { ReservationDetailPage } from './pages/ReservationDetailPage';
import { ReservationsPage } from './pages/ReservationsPage';
import { UnitPage } from './pages/UnitPage';

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
                            <Route path="*" element={<NotFoundPage />} />
                        </Route>
                    </Routes>
                </BrowserRouter>
            </QueryClientProvider>
        </I18nextProvider>
    </StrictMode>,
);
