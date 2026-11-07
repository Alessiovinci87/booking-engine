<?php

beforeEach(fn () => $this->withoutVite());

it('serve il pannello su /app e sui suoi sottopercorsi', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<html lang="it">', escape: false)
        ->assertSee('<div id="app"></div>', escape: false);
})->with(['/app', '/app/', '/app/prenotazioni/123', '/app/pagamenti', '/app/preventivi', '/app/conflitti', '/app/calendari', '/app/metriche', '/app/ospiti/123/dati', '/app/account', '/app/password-dimenticata', '/app/reimposta-password']);

it('serve la pagina ospite su /p/{tenant}/{struttura} e sui suoi sottopercorsi', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="app" data-embed="0"', escape: false);
})->with(['/p/demo/casa-demo', '/p/demo/casa-demo/', '/p/demo/casa-demo/date']);

it('serve la pagina ospite del preventivo su /q/{token}', function () {
    $this->get('/q/'.str_repeat('a', 43))->assertOk()->assertSee('<div id="app" data-embed="0"', escape: false);
    $this->get('/q/corto')->assertNotFound();
});

it('non serve la pagina ospite senza lo slug della struttura', function () {
    $this->get('/p/demo')->assertNotFound();
});

it('le traduzioni del pannello e dell\'ospite hanno le stesse chiavi in tutte le lingue', function () {
    $keys = function (array $tree, string $prefix = '') use (&$keys): array {
        $out = [];
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $out = [...$out, ...(is_array($value) ? $keys($value, $path) : [$path])];
        }
        sort($out);

        return $out;
    };
    $load = fn (string $file): array => json_decode((string) file_get_contents(resource_path('js/'.$file)), true, flags: JSON_THROW_ON_ERROR);

    expect($keys($load('guest/locales/en.json')))->toBe($keys($load('guest/locales/it.json')))
        ->and($keys($load('panel/locales/it.json')))->not->toBeEmpty();
});

it('serve le pagine di accesso del pannello', function (string $path) {
    $this->get($path)->assertOk()->assertSee('<div id="app"></div>', escape: false);
})->with(['/app/login', '/app/mfa/setup', '/app/mfa/verify']);

it('fornisce il cookie CSRF alla SPA del pannello', function () {
    $this->withHeader('Referer', TEST_PANEL_ORIGIN.'/app/login')
        ->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});

it('il pannello traduce ogni codice di errore dell\'API', function () {
    $panel = json_decode((string) file_get_contents(resource_path('js/panel/locales/it.json')), true, flags: JSON_THROW_ON_ERROR);
    $codes = array_keys((require lang_path('it/api.php'))['errors']);

    expect($codes)->not->toBeEmpty()
        ->and(array_keys($panel['errors']))->toEqualCanonicalizing($codes);
});

it('i testi del pannello e della pagina ospite passano dalle chiavi di traduzione', function (string $app) {
    // Nessun testo scritto direttamente nel markup JSX: solo {t('...')}.
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js/'.$app))) as $file) {
        if ($file->getExtension() !== 'tsx') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match_all('/>\s*([A-Za-zÀ-ÿ][^<>{}]*[A-Za-zÀ-ÿ.!?])\s*</u', $source, $m)) {
            foreach ($m[1] as $text) {
                $offenders[] = basename($file->getPathname()).': '.trim($text);
            }
        }
    }

    expect($offenders)->toBe([]);
})->with(['panel', 'guest']);
