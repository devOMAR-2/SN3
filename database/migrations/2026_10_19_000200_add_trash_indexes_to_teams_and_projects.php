<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CORE-010 (ADR-022 §5): the daily trash purge looks for teams and projects with
 * `deleted_at <= now - 30 days`. Partial indexes over trashed rows only keep that lookup cheap
 * without indexing the (overwhelmingly) live rows. Expand-only; rollback drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX teams_trashed_deleted_at_index ON teams (deleted_at) WHERE deleted_at IS NOT NULL');
        DB::statement('CREATE INDEX projects_trashed_deleted_at_index ON projects (deleted_at) WHERE deleted_at IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS projects_trashed_deleted_at_index');
        DB::statement('DROP INDEX IF EXISTS teams_trashed_deleted_at_index');
    }
};
