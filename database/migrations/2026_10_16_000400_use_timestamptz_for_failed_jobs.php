<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `failed_jobs.failed_at` came from Laravel's skeleton as `timestamp` without a time zone, the only
 * such column in the schema. Stored values are UTC (the pgsql session time zone is pinned to UTC).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE failed_jobs ALTER COLUMN failed_at TYPE timestamptz USING failed_at AT TIME ZONE 'UTC'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE failed_jobs ALTER COLUMN failed_at TYPE timestamp USING failed_at AT TIME ZONE 'UTC'");
    }
};
