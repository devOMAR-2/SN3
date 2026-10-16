<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 03, second part (DATABASE.md §5, §14, §20): `tasks` (CORE-005, ADR-023).
 *
 * Keys, each one following ADR-022 §5 (every key into teams/projects cascades; every other key
 * between two tables the trash purge deletes from is DEFERRABLE NO ACTION):
 *
 * - `(project_id, team_id) → projects(id, team_id)` ON DELETE CASCADE: a task carries its project's
 *   team and nobody else's, and the purge removes tasks with their project.
 * - `(project_id, status_id) → project_statuses(project_id, id)`, DEFERRABLE INITIALLY IMMEDIATE NO
 *   ACTION (ADR-021 §4): a task can only use a status of its own project (DB-T04), the database
 *   refuses to delete a status that tasks still use, and a purge removing a project's tasks and
 *   statuses together passes at COMMIT.
 * - `(parent_task_id, project_id) → tasks(id, project_id)`, DEFERRABLE INITIALLY IMMEDIATE: a parent
 *   is always a task of the same project.
 * - `(team_id, assignee_user_id) → team_memberships(team_id, user_id)`, DEFERRABLE INITIALLY
 *   IMMEDIATE (DB-T03): the single assignee (DECIDED-006) is a member of the task's team. Removing a
 *   team member unassigns them in the same transaction (TaskAssignments); the database refuses a
 *   removal that would leave an assignment behind.
 * - `created_by → users` RESTRICT, like `projects.created_by`.
 *
 * Columns that settle what DATABASE.md §5 leaves open (technical corrections, ADR-023 §2):
 *
 * - `due_date date` instead of `due_at timestamptz`: API_SPEC §6.1 makes the due date a calendar
 *   date (`YYYY-MM-DD`) with no time, and a timestamp would shift it across time zones.
 * - `deleted_at`: API_SPEC §6.1 soft-deletes tasks and restores them (`POST /tasks/{id}/restore`).
 * - `version` (DATABASE.md §20) backs `If-Match`.
 * - `priority_rank` and `due_sort` are generated, stored sort keys, so the list can sort by priority
 *   (urgent first) and by due date (no due date last) with keyset pagination.
 * - `start_at`, `completed_at`, `archived_at` and `story_points` have no API field yet; the tasks that
 *   use them (FLEX-005, FLEX-007) add them with expand-only migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('project_id');
            $table->bigInteger('task_number');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->uuid('status_id');
            $table->uuid('assignee_user_id')->nullable();
            $table->uuid('created_by');
            $table->string('priority', 16)->default('none');
            $table->date('due_date')->nullable();
            $table->decimal('position', 20, 10);
            $table->uuid('parent_task_id')->nullable();
            $table->bigInteger('version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['project_id', 'task_number']);
            $table->unique(['id', 'project_id']);

            $table->foreign(['project_id', 'team_id'])
                ->references(['id', 'team_id'])
                ->on('projects')
                ->cascadeOnDelete();

            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_project_id_status_id_foreign FOREIGN KEY (project_id, status_id) REFERENCES project_statuses (project_id, id) DEFERRABLE INITIALLY IMMEDIATE');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_parent_task_id_project_id_foreign FOREIGN KEY (parent_task_id, project_id) REFERENCES tasks (id, project_id) DEFERRABLE INITIALLY IMMEDIATE');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_team_id_assignee_user_id_foreign FOREIGN KEY (team_id, assignee_user_id) REFERENCES team_memberships (team_id, user_id) DEFERRABLE INITIALLY IMMEDIATE');

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('none', 'low', 'medium', 'high', 'urgent'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_title_not_blank_check CHECK (btrim(title) <> '')");
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_task_number_check CHECK (task_number > 0)');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_version_check CHECK (version > 0)');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_parent_not_self_check CHECK (parent_task_id IS NULL OR parent_task_id <> id)');

        DB::statement("ALTER TABLE tasks ADD COLUMN priority_rank smallint GENERATED ALWAYS AS (CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END) STORED");
        DB::statement("ALTER TABLE tasks ADD COLUMN due_sort date GENERATED ALWAYS AS (COALESCE(due_date, 'infinity'::date)) STORED");

        // Access paths (DATABASE.md §5, §12): board columns, assignee filters and "my tasks", the
        // team-wide unassign on membership changes, recent activity, due dates and subtasks.
        DB::statement('CREATE INDEX tasks_project_id_status_id_position_index ON tasks (project_id, status_id, position)');
        DB::statement('CREATE INDEX tasks_project_id_assignee_user_id_index ON tasks (project_id, assignee_user_id)');
        DB::statement('CREATE INDEX tasks_team_id_assignee_user_id_index ON tasks (team_id, assignee_user_id) WHERE assignee_user_id IS NOT NULL');
        DB::statement('CREATE INDEX tasks_assignee_user_id_created_at_index ON tasks (assignee_user_id, created_at DESC, id DESC) WHERE assignee_user_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE INDEX tasks_team_id_project_id_updated_at_index ON tasks (team_id, project_id, updated_at DESC)');
        DB::statement('CREATE INDEX tasks_project_id_created_at_index ON tasks (project_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX tasks_project_id_due_date_index ON tasks (project_id, due_date)');
        DB::statement('CREATE INDEX tasks_parent_task_id_index ON tasks (parent_task_id) WHERE parent_task_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
