import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

// Due applicazioni React nella stessa build (ADR-0006): pannello su /app, pagina ospite su /p/...
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/panel/main.tsx', 'resources/js/guest/main.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
