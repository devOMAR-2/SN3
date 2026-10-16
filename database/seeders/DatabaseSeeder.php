<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * `php artisan db:seed` seeds nothing: SN3 has no demo data, and accounts are created through
 * registration. Deterministic test tenants come from E2ETenantSeeder (docs/TESTING.md).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
