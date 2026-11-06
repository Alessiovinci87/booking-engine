<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Le migration dei test girano come app_migrator (proprietario delle tabelle), come in produzione.
     * I test usano invece la connessione predefinita "pgsql" (app_runtime, soggetto a RLS), dentro la
     * transazione di RefreshDatabase.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => 'pgsql_migrator',
            '--drop-views' => true,
            '--seed' => false,
        ];
    }
}
