<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 04, first part (DATABASE.md §5, §14): `task_comments` and `task_comment_mentions`
 * (CORE-008, ADR-027 §2).
 *
 * Keys, following ADR-022 §5 (every key into teams/projects cascades; keys between two tables the
 * trash purge deletes from cascade or are DEFERRABLE):
 *
 * - `(project_id, team_id) → projects(id, team_id)` and `(task_id, project_id) → tasks(id, project_id)`
 *   cascade: a comment carries its task's project and team and nobody else's (DB-T04), and the purge
 *   removes comments with their task. `team_id`/`project_id` are not in DATABASE.md §5; they scope
 *   comments like every other descendant (§10.1, technical addition).
 * - `author_user_id → users` RESTRICT, like `tasks.created_by`.
 * - Mentions: `comment_id → task_comments` and `user_id → users` cascade; PK `(comment_id, user_id)`
 *   (one mention per user and comment), index `(user_id, created_at DESC)` for CORE-009's inbox.
 *   The table is a technical addition: API_SPEC.md §6.2 requires server-validated mention IDs and
 *   CORE-009 notifies from them.
 *
 * CHECKs: a non-blank body of at most 10,000 characters (API_SPEC.md §6.2), `edited_at` not before
 * `created_at`. The list index is `(task_id, created_at DESC, id DESC)` (DATABASE.md §12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('project_id');
            $table->uuid('task_id');
            $table->uuid('author_user_id');
            $table->text('body');
            $table->timestampTz('edited_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['project_id', 'team_id'])->references(['id', 'team_id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['task_id', 'project_id'])->references(['id', 'project_id'])->on('tasks')->cascadeOnDelete();
            $table->foreign('author_user_id')->references('id')->on('users')->restrictOnDelete();

            $table->index(['team_id', 'project_id']);
        });

        DB::statement('CREATE INDEX task_comments_task_id_created_at_index ON task_comments (task_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX task_comments_trashed_deleted_at_index ON task_comments (deleted_at) WHERE deleted_at IS NOT NULL');
        DB::statement("ALTER TABLE task_comments ADD CONSTRAINT task_comments_body_check CHECK (btrim(body) <> '' AND char_length(body) <= 10000)");
        DB::statement('ALTER TABLE task_comments ADD CONSTRAINT task_comments_edited_at_check CHECK (edited_at IS NULL OR edited_at >= created_at)');

        Schema::create('task_comment_mentions', function (Blueprint $table) {
            $table->uuid('comment_id');
            $table->uuid('user_id');
            $table->timestampTz('created_at');

            $table->primary(['comment_id', 'user_id']);
            $table->foreign('comment_id')->references('id')->on('task_comments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::statement('CREATE INDEX task_comment_mentions_user_id_created_at_index ON task_comment_mentions (user_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comment_mentions');
        Schema::dropIfExists('task_comments');
    }
};
