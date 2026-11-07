<?php

beforeEach(fn () => $this->withoutVite());

it('serve il pannello su /app e sui suoi sottopercorsi', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<html lang="it">', escape: false)
        ->assertSee('<div id="app"></div>', escape: false);
})->with(['/app', '/app/', '/app/prenotazioni/123', '/app/pagamenti', '/app/preventivi', '/app/conflitti', '/app/calendari', '/app/metriche', '/app/ospiti/123/dati', '/app/account', '/app/uscita', '/app/accessi-supporto', '/app/password-dimenticata', '/app/reimposta-password']);

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

it('ogni codice di errore di 07 ha un messaggio IT/EN nell\'API, IT nel pannello e IT/EN per l\'ospite (8.5)', function () {
    $doc = (string) file_get_contents(base_path('docs/07-API-CONTRACT.md'));
    $start = strpos($doc, '| HTTP | Codici |');
    expect($start)->not->toBeFalse();
    $table = substr($doc, (int) $start, strpos($doc, "\n\n", (int) $start) - (int) $start);
    preg_match_all('/`([A-Z][A-Z_]{3,})`/', $table, $m);
    $codes = array_values(array_unique($m[1]));

    // Codici che arrivano solo al pannello o ai client di calendario: l'ospite non li riceve mai.
    $panelOnly = [
        'MFA_REQUIRED', 'MFA_SETUP_REQUIRED', 'MFA_ALREADY_ENABLED', 'INVALID_CREDENTIALS', 'INVALID_MFA_CODE',
        'PASSWORD_RESET_INVALID', 'REAUTH_REQUIRED', 'EXTERNAL_CALENDARS_ACTIVE', 'NO_ACTIVE_UNITS',
        'TRANSLATION_IT_MISSING', 'CANCELLATION_POLICY_MISSING', 'CONFLICT_OPEN', 'PRIVACY_POLICY_MISSING',
        'DPA_NOT_SIGNED', 'GUEST_HAS_ACTIVE_RESERVATION', 'ICS_FEED_EXISTS', 'INVALID_ICAL_URL', 'UNIT_IS_INSTANT',
        'CHECKLIST_STEP_INVALID', 'TENANT_NOT_OFFBOARDING', 'OFFBOARDING_NOT_READY', 'EXPORT_EXPIRED',
        'SUPPORT_READ_ONLY', 'SUPPORT_ACCESS_INACTIVE', 'SUPPORT_ACCESS_NOT_ACTIVE', 'PAYMENT_PROVIDER_REJECTED',
        'FEED_RETIRED',
    ];
    $json = fn (string $path): array => json_decode((string) file_get_contents(resource_path($path)), true, flags: JSON_THROW_ON_ERROR);
    $panel = $json('js/panel/locales/it.json')['errors'];
    $guestIt = $json('js/guest/locales/it.json')['errors'];
    $guestEn = $json('js/guest/locales/en.json')['errors'];
    $apiIt = (require lang_path('it/api.php'))['errors'];
    $apiEn = (require lang_path('en/api.php'))['errors'];
    $missing = [];

    foreach ($codes as $code) {
        foreach (['api it' => $apiIt, 'api en' => $apiEn, 'pannello' => $panel] as $where => $messages) {
            if (! isset($messages[$code])) {
                $missing[] = "{$code} ({$where})";
            }
        }

        if (! in_array($code, $panelOnly, true)) {
            foreach (['ospite it' => $guestIt, 'ospite en' => $guestEn] as $where => $messages) {
                if (! isset($messages[$code])) {
                    $missing[] = "{$code} ({$where})";
                }
            }
        }
    }

    expect(count($codes))->toBeGreaterThan(40)
        ->and($missing)->toBe([])
        ->and(array_keys($guestIt))->toEqualCanonicalizing(array_keys($guestEn));
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
