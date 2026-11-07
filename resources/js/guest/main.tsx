import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { lazy, StrictMode, Suspense } from 'react';
import { createRoot } from 'react-dom/client';
import { I18nextProvider } from 'react-i18next';
import { BrowserRouter, Route, Routes } from 'react-router';

import '../../css/guest.css';
import { guestLocation } from './api';
import { i18n } from './i18n';
import { Spinner } from './components';
import { PropertyPage } from './pages/PropertyPage';

/*
 * Divisione del codice (8.4): la pagina della struttura è nel pacchetto iniziale; prenotazione (con il
 * calendario), stato della prenotazione e preventivo si caricano quando servono.
 */
const BookingPage = lazy(() => import('./pages/BookingPage').then((m) => ({ default: m.BookingPage })));
const ReservationPage = lazy(() => import('./pages/ReservationPage').then((m) => ({ default: m.ReservationPage })));
const QuotePage = lazy(() => import('./pages/QuotePage').then((m) => ({ default: m.QuotePage })));

const queryClient = new QueryClient({ defaultOptions: { queries: { retry: 1, refetchOnWindowFocus: false } } });

const container = document.getElementById('app');

if (container === null) {
    throw new Error('Elemento #app non trovato');
}

/** Vista embed (/e/…): il pagamento si apre sempre fuori dall'iframe (08, ADR-0009). */
export const isEmbed = container.dataset.embed === '1';

createRoot(container).render(
    <StrictMode>
        <I18nextProvider i18n={i18n}>
            <QueryClientProvider client={queryClient}>
                <BrowserRouter basename={guestLocation()?.basename ?? '/'}>
                    <Suspense fallback={<Spinner />}>
                    <Routes>
                        <Route path="/" element={<PropertyPage />} />
                        <Route path="/alloggi/:unitId" element={<BookingPage embed={isEmbed} />} />
                        <Route path="/prenotazione/:code" element={<ReservationPage />} />
                        <Route path="/q/:token" element={<QuotePage />} />
                        <Route path="*" element={<PropertyPage />} />
                    </Routes>
                    </Suspense>
                </BrowserRouter>
            </QueryClientProvider>
        </I18nextProvider>
    </StrictMode>,
);
