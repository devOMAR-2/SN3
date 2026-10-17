<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 04, last part (DATABASE.md §8, §12, §14): `task_watchers` and `notifications`
 * (CORE-009, ADR-029).
 *
 * `task_watchers` (technical addition: API_SPEC.md §6.1 defines `/tasks/{id}/watchers`, DECIDED-006
 * "watchers are separate entities if implemented"): PK `(task_id, user_id)`, one row per watcher.
 *
 * `notifications` as in DATABASE.md §8, plus technical columns:
 *
 * - `task_id`, `comment_id`, `invitation_id`: what the notification is about, as keys, so rendering
 *   re-reads the live rows (and re-checks access) instead of copying titles into `payload`
 *   (DATABASE.md §8 "Payload must not cache … unrestricted private content"). `payload` only ever
 *   holds field names (`{"fields": [...]}`).
 * - `event_count`, `updated_at`: noisy events are collapsed into one unread notification per
 *   recipient, task and type (API_SPEC.md §9 "batch/dedupe noisy events"), enforced by the partial
 *   unique index `notifications_collapse_unique`. `updated_at` is the latest event, the inbox order.
 * - `email_due_at`, `emailed_at`: the queued email (ARCHITECTURE.md ADR-006/007, API_SPEC.md §9).
 *
 * Keys (ADR-022 §5): `team_id → teams`, `(project_id, team_id) → projects(id, team_id)`,
 * `(task_id, project_id) → tasks(id, project_id)`, `comment_id → task_comments`, `invitation_id →
 * team_invitations` and both user keys of the watchers cascade, so a notification goes with whatever
 * it names, and the trash purge removes it with its team or project; `recipient_user_id → users`
 * cascades, `actor_user_id → users` sets NULL.
 */
return new class extends Migration
{
    /** The types whose unread notifications are collapsed per recipient and task. */
    private const COLLAPSED = "'task.assigned', 'task.updated', 'task.commented'";

    public function up(): void
    {
        Schema::create('task_watchers', function (Blueprint $table) {
            $table->uuid('task_id');
            $table->uuid('user_id');
            $table->uuid('team_id');
            $table->uuid('project_id');
            $table->timestampTz('created_at');

            $table->primary(['task_id', 'user_id']);
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign(['project_id', 'team_id'])->references(['id', 'team_id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['task_id', 'project_id'])->references(['id', 'project_id'])->on('tasks')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->index(['user_id']);
            $table->index(['project_id', 'team_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipient_user_id');
            $table->uuid('team_id');
            $table->uuid('project_id')->nullable();
            $table->uuid('task_id')->nullable();
            $table->uuid('comment_id')->nullable();
            $table->uuid('invitation_id')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->string('type', 80);
            $table->jsonb('payload')->default('{}');
            $table->integer('event_count')->default(1);
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('email_due_at')->nullable();
            $table->timestampTz('emailed_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->foreign('recipient_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign(['project_id', 'team_id'])->references(['id', 'team_id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['task_id', 'project_id'])->references(['id', 'project_id'])->on('tasks')->cascadeOnDelete();
            $table->foreign('comment_id')->references('id')->on('task_comments')->cascadeOnDelete();
            $table->foreign('invitation_id')->references('id')->on('team_invitations')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['team_id']);
            $table->index(['project_id', 'team_id']);
            $table->index(['task_id']);
        });

        // DATABASE.md §12 (the unread count) and the inbox order (latest event first, ties by id).
        DB::statement('CREATE INDEX notifications_recipient_read_at_created_at_index ON notifications (recipient_user_id, read_at, created_at DESC)');
        DB::statement('CREATE INDEX notifications_recipient_updated_at_index ON notifications (recipient_user_id, updated_at DESC, id DESC)');
        DB::statement('CREATE INDEX notifications_comment_id_index ON notifications (comment_id) WHERE comment_id IS NOT NULL');
        DB::statement('CREATE INDEX notifications_invitation_id_index ON notifications (invitation_id) WHERE invitation_id IS NOT NULL');
        DB::statement('CREATE INDEX notifications_email_due_at_index ON notifications (email_due_at) WHERE email_due_at IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX notifications_collapse_unique ON notifications (recipient_user_id, task_id, type) WHERE read_at IS NULL AND type IN ('.self::COLLAPSED.')');
        // One mention notification per comment and user, one invitation notification per invitation.
        DB::statement("CREATE UNIQUE INDEX notifications_mention_unique ON notifications (recipient_user_id, comment_id) WHERE type = 'comment.mentioned'");
        DB::statement("CREATE UNIQUE INDEX notifications_invitation_unique ON notifications (recipient_user_id, invitation_id) WHERE type = 'team.invitation'");

        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ('task.assigned', 'task.updated', 'task.commented', 'comment.mentioned', 'team.invitation'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_subject_check CHECK (CASE WHEN type = 'team.invitation' THEN invitation_id IS NOT NULL AND project_id IS NULL AND task_id IS NULL AND comment_id IS NULL WHEN type = 'comment.mentioned' THEN project_id IS NOT NULL AND task_id IS NOT NULL AND comment_id IS NOT NULL AND invitation_id IS NULL ELSE project_id IS NOT NULL AND task_id IS NOT NULL AND comment_id IS NULL AND invitation_id IS NULL END)");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_payload_check CHECK (jsonb_typeof(payload) = 'object' AND octet_length(payload::text) <= 1024)");
        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_event_count_check CHECK (event_count >= 1)');
        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_updated_at_check CHECK (updated_at >= created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('task_watchers');
    }
};
