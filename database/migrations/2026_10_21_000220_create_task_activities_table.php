<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 04, third part (DATABASE.md §5, §12): `task_activities`, the append-only activity
 * and audit log (CORE-008, ADR-027 §3).
 *
 * Technical correction: DATABASE.md §5 gives the table `team_id`, `project_id` and `task_id`. It also
 * holds project-level entries (`task_id` NULL: project membership and visibility changes) and
 * team-level entries (`project_id` NULL: team role changes, removals, ownership transfers), which
 * NFR-SEC-005 and ARCHITECTURE.md §11.2 require to be audited and BRD §10 calls `audit_events`. One
 * table keeps one authorization rule per level instead of a second log.
 *
 * - Keys (ADR-022 §5): `team_id → teams`, `(project_id, team_id) → projects(id, team_id)` and
 *   `(task_id, project_id) → tasks(id, project_id)` cascade, so an entry always belongs to its own
 *   project and team, and the purge removes it with them. `actor_user_id → users` sets NULL.
 * - CHECKs: a task entry has a project; `action` is `<domain>.<event>`; `source` is `user`, `system` or
 *   `integration`; `changes` is a JSON object of at most 4 KiB (a bounded, sanitised allowlist,
 *   DATABASE.md §5).
 * - Indexes: `(task_id, created_at DESC, id DESC)`, `(project_id, …)`, `(team_id, …)` and
 *   `(actor_user_id, …)` for the task, project and own-activity feeds.
 * - **Append-only:** triggers refuse UPDATE, DELETE and TRUNCATE issued directly. Only the actions of
 *   foreign keys pass (a cascade from a purged team, project or task, or the SET NULL of a deleted
 *   user), recognised by `pg_trigger_depth() > 1`: they run inside the referential trigger.
 * - `sn3_uuid_v7()` builds UUIDv7 IDs in SQL (as the CORE-004 status seed does inline), for
 *   set-based inserts such as the assignment release of TaskAssignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sn3_uuid_v7() RETURNS uuid LANGUAGE sql VOLATILE AS $$
                SELECT encode(set_bit(set_bit(overlay(uuid_send(gen_random_uuid()) PLACING substring(int8send(floor(extract(epoch FROM clock_timestamp()) * 1000)::bigint) FROM 3) FROM 1 FOR 6), 52, 1), 53, 1), 'hex')::uuid
            $$
            SQL);

        Schema::create('task_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('project_id')->nullable();
            $table->uuid('task_id')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->string('action', 80);
            $table->jsonb('changes')->default('{}');
            $table->string('source', 20)->default('user');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign(['project_id', 'team_id'])->references(['id', 'team_id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['task_id', 'project_id'])->references(['id', 'project_id'])->on('tasks')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });

        foreach (['task_id', 'project_id', 'team_id', 'actor_user_id'] as $column) {
            DB::statement("CREATE INDEX task_activities_{$column}_created_at_index ON task_activities ({$column}, created_at DESC, id DESC)");
        }

        DB::statement('ALTER TABLE task_activities ADD CONSTRAINT task_activities_task_needs_project_check CHECK (task_id IS NULL OR project_id IS NOT NULL)');
        DB::statement("ALTER TABLE task_activities ADD CONSTRAINT task_activities_action_check CHECK (action ~ '^[a-z][a-z_]*\\.[a-z][a-z_]*$')");
        DB::statement("ALTER TABLE task_activities ADD CONSTRAINT task_activities_source_check CHECK (source IN ('user', 'system', 'integration'))");
        DB::statement("ALTER TABLE task_activities ADD CONSTRAINT task_activities_changes_check CHECK (jsonb_typeof(changes) = 'object' AND octet_length(changes::text) <= 4096)");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sn3_task_activities_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- Foreign-key actions (a purge cascade, the SET NULL of a deleted user) run inside the
                -- referential trigger, one level down. Everything issued directly is refused.
                IF pg_trigger_depth() > 1 THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;

                RAISE EXCEPTION 'task_activities is append-only' USING ERRCODE = 'insufficient_privilege';
            END
            $$
            SQL);

        DB::statement('CREATE TRIGGER task_activities_append_only BEFORE UPDATE OR DELETE ON task_activities FOR EACH ROW EXECUTE FUNCTION sn3_task_activities_append_only()');
        DB::statement('CREATE TRIGGER task_activities_no_truncate BEFORE TRUNCATE ON task_activities FOR EACH STATEMENT EXECUTE FUNCTION sn3_task_activities_append_only()');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
        DB::statement('DROP FUNCTION IF EXISTS sn3_task_activities_append_only()');
        DB::statement('DROP FUNCTION IF EXISTS sn3_uuid_v7()');
    }
};
