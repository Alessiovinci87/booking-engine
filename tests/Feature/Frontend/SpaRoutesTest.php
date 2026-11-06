<?php

beforeEach(fn () => $this->withoutVite());

it('serve il pannello su /app e sui suoi sottopercorsi', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<html lang="it">', escape: false)
        ->assertSee('<div id="app"></div>', escape: false);
})->with(['/app', '/app/', '/app/prenotazioni/123']);

it('serve la pagina ospite su /p/{tenant}/{struttura} e sui suoi sottopercorsi', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('<div id="app"></div>', escape: false);
})->with(['/p/demo/casa-demo', '/p/demo/casa-demo/', '/p/demo/casa-demo/date']);

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
