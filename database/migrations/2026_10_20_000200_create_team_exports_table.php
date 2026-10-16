<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE.md §20 `team_exports` (CORE-012, ADR-024): asynchronous team exports.
 *
 * - `team_id` cascades, as every key to `teams` must (DATABASE.md §13, TrashPurgeTest), so the trash
 *   purge removes the rows with their team. The stored file is not a row: `exports:prune` deletes it
 *   at `expires_at`, and sweeps any file whose row is gone.
 * - `requested_by` cascades too: only the requesting Owner can ever read or download an export.
 * - `expires_at` is always set: 24 hours after the request while the export is pending, then 24 hours
 *   after it completes or fails (API_SPEC.md §13 "automatically removed after 24 hours").
 * - At most one export per team is in flight (`pending` or `processing`); the partial unique index
 *   backs the check RequestTeamExport makes under the team row lock.
 * - A `ready` export always has its file, size, checksum and completion time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('requested_by')->index()->constrained('users')->cascadeOnDelete();
            $table->string('status', 16);
            $table->smallInteger('format_version');
            $table->string('storage_key', 255)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();

            $table->index(['team_id', 'created_at']);
        });

        DB::statement("ALTER TABLE team_exports ADD CONSTRAINT team_exports_status_check CHECK (status IN ('pending', 'processing', 'ready', 'failed'))");
        DB::statement('ALTER TABLE team_exports ADD CONSTRAINT team_exports_format_version_check CHECK (format_version > 0)');
        DB::statement("ALTER TABLE team_exports ADD CONSTRAINT team_exports_ready_check CHECK (status <> 'ready' OR (storage_key IS NOT NULL AND size_bytes IS NOT NULL AND sha256 IS NOT NULL AND completed_at IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX team_exports_one_in_flight_unique ON team_exports (team_id) WHERE status IN ('pending', 'processing')");
    }

    public function down(): void
    {
        Schema::dropIfExists('team_exports');
    }
};
