<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Solo in locale: dati demo inventati (1.8). In staging e produzione i tenant si creano con
     * tenant:create.
     */
    public function run(): void
    {
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
