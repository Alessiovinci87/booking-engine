<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_timestamps_are_utc_and_locale_is_italian(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame('it', config('app.locale'));
    }

    public function test_root_redirects_to_the_panel(): void
    {
        $this->get('/')->assertRedirect('/app');
    }

    public function test_health_check_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
