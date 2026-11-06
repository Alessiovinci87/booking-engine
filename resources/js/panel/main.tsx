import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { I18nextProvider } from 'react-i18next';
import { BrowserRouter, Route, Routes } from 'react-router';

import '../../css/app.css';
import { i18n } from './i18n';
import { HomePage } from './pages/HomePage';
import { NotFoundPage } from './pages/NotFoundPage';

const queryClient = new QueryClient();

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
                        <Route index element={<HomePage />} />
                        <Route path="*" element={<NotFoundPage />} />
                    </Routes>
                </BrowserRouter>
            </QueryClientProvider>
        </I18nextProvider>
    </StrictMode>,
);
