import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { I18nextProvider } from 'react-i18next';
import { BrowserRouter, Route, Routes } from 'react-router';

import '../../css/app.css';
import { i18n } from './i18n';
import { HomePage } from './pages/HomePage';

const queryClient = new QueryClient();

/** Base delle rotte: /p/{tenantSlug}/{propertySlug} (ADR-0006). */
function guestBasename(pathname: string): string {
    const [, prefix, tenantSlug, propertySlug] = pathname.split('/');

    return prefix === 'p' && tenantSlug && propertySlug ? `/p/${tenantSlug}/${propertySlug}` : '/';
}

const container = document.getElementById('app');

if (container === null) {
    throw new Error('Elemento #app non trovato');
}

createRoot(container).render(
    <StrictMode>
        <I18nextProvider i18n={i18n}>
            <QueryClientProvider client={queryClient}>
                <BrowserRouter basename={guestBasename(window.location.pathname)}>
                    <Routes>
                        <Route path="*" element={<HomePage />} />
                    </Routes>
                </BrowserRouter>
            </QueryClientProvider>
        </I18nextProvider>
    </StrictMode>,
);
