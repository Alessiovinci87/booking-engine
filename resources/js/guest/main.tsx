import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { I18nextProvider } from 'react-i18next';
import { BrowserRouter, Route, Routes } from 'react-router';

import 'react-day-picker/style.css';
import '../../css/app.css';
import { guestLocation } from './api';
import { i18n } from './i18n';
import { BookingPage } from './pages/BookingPage';
import { PropertyPage } from './pages/PropertyPage';
import { ReservationPage } from './pages/ReservationPage';

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
                    <Routes>
                        <Route path="/" element={<PropertyPage />} />
                        <Route path="/alloggi/:unitId" element={<BookingPage embed={isEmbed} />} />
                        <Route path="/prenotazione/:code" element={<ReservationPage />} />
                        <Route path="*" element={<PropertyPage />} />
                    </Routes>
                </BrowserRouter>
            </QueryClientProvider>
        </I18nextProvider>
    </StrictMode>,
);
