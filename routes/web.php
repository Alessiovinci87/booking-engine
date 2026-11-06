<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

// SPA del pannello (solo italiano): React Router gestisce i percorsi sotto /app.
Route::view('/app/{any?}', 'panel')->where('any', '.*')->name('panel');

// SPA della pagina ospite (italiano e inglese), servita per struttura.
Route::view('/p/{tenantSlug}/{propertySlug}/{any?}', 'guest')
    ->where('any', '.*')
    ->name('guest');
