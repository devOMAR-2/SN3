<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 04, second part (DATABASE.md §5): `task_attachments` (CORE-008, ADR-027 §5).
 *
 * - `(project_id, team_id) → projects` and `(task_id, project_id) → tasks` cascade (ADR-022 §5). The
 *   cascade removes rows, not files: the trash purge also deletes the purged project's or team's
 *   stored objects (DeletePurgedAttachmentFiles), and `attachments:sweep` catches anything left.
 * - `uploaded_by → users` RESTRICT, like `tasks.created_by`.
 * - `storage_key` is opaque, random and unique; `original_name` is metadata only (1–255 characters,
 *   sanitised by the application); `mime_type` is the sniffed type, never the client's;
 *   `sha256` is the 32-byte digest of the stored content.
 * - `deleted_at` marks an attachment whose file is being removed: it is invisible everywhere and out
 *   of the team quota at once, and `attachments:sweep` finishes the removal if the request could not.
 * - DATABASE.md §5 lists `created_at` only; there is no `updated_at` (attachments are immutable).
 *
 * Indexes: `(task_id, created_at DESC, id DESC)` for the list; `(team_id) INCLUDE (size_bytes)` over
 * live rows for the quota sum (DECIDED-004); `deleted_at` over pending removals for the sweeper;
 * `storage_disk` for the disks the purge and the sweep clean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('project_id');
            $table->uuid('task_id');
            $table->uuid('uploaded_by');
            $table->string('storage_disk', 50);
            $table->text('storage_key')->unique();
            $table->text('original_name');
            $table->string('mime_type', 255);
            $table->bigInteger('size_bytes');
            $table->binary('sha256')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign(['project_id', 'team_id'])->references(['id', 'team_id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['task_id', 'project_id'])->references(['id', 'project_id'])->on('tasks')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement('CREATE INDEX task_attachments_task_id_created_at_index ON task_attachments (task_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX task_attachments_team_quota_index ON task_attachments (team_id) INCLUDE (size_bytes) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX task_attachments_pending_deletion_index ON task_attachments (deleted_at) WHERE deleted_at IS NOT NULL');
        // The disks in use, read by the purge and the sweep as a loose index scan (AttachmentStorage).
        DB::statement('CREATE INDEX task_attachments_storage_disk_index ON task_attachments (storage_disk)');
        DB::statement('ALTER TABLE task_attachments ADD CONSTRAINT task_attachments_size_bytes_check CHECK (size_bytes >= 0)');
        DB::statement('ALTER TABLE task_attachments ADD CONSTRAINT task_attachments_original_name_check CHECK (char_length(original_name) BETWEEN 1 AND 255)');
        DB::statement('ALTER TABLE task_attachments ADD CONSTRAINT task_attachments_sha256_check CHECK (sha256 IS NULL OR octet_length(sha256) = 32)');
        DB::statement("ALTER TABLE task_attachments ADD CONSTRAINT task_attachments_storage_key_check CHECK (storage_key ~ '^objects/[0-9a-f-]{36}/[0-9a-f-]{36}/[0-9a-f]{32}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
    }
};
