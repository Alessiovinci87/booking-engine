<?php

use Illuminate\Support\Facades\DB;

/*
 * 09 requisiti 2 e 5 (6.3): informativa della piattaforma ed elenco dei sub-responsabili collegati
 * nel piè di pagina della pagina ospite, dalla configurazione.
 */

it('la pagina ospite passa i link della piattaforma al piè di pagina', function () {
    $this->withoutVite();
    $fx = bookableTenant('INSTANT');
    config(['privacy.platform_notice_url' => 'https://piattaforma.example/privacy', 'privacy.subprocessors_url' => 'https://piattaforma.example/sub-responsabili']);
    $slugs = inTenant($fx['tenant'], fn () => [
        DB::table('tenants')->value('slug'),
        DB::table('properties')->value('slug'),
    ]);

    $this->get("/p/{$slugs[0]}/{$slugs[1]}")->assertOk()
        ->assertSee('data-platform-privacy="https://piattaforma.example/privacy"', escape: false)
        ->assertSee('data-subprocessors="https://piattaforma.example/sub-responsabili"', escape: false);
});
