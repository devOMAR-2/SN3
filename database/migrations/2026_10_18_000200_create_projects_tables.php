<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 02 (DATABASE.md §4, §14, §19, §20): `projects` and `project_memberships`.
 *
 * - A project belongs to exactly one team. `UNIQUE(id, team_id)` lets descendants carry the team
 *   and reference the pair, so a child can never point at another team's project.
 * - Project keys are unique per team, projects in the trash included, so a key stays reserved
 *   during the 30-day recovery window (DECIDED-011), like team slugs (ADR-018 §2).
 * - A project membership references its project *and team* (`(project_id, team_id)`) and the
 *   user's team membership (`(team_id, user_id)`): only an existing member of the project's own
 *   team can be a project member. The second key has no ON DELETE action, so a team membership
 *   cannot be deleted while project memberships still hang off it; removing someone from a team
 *   revokes their project memberships explicitly, in the same transaction (ADR-018 §6, ADR-020).
 * - That key is DEFERRABLE INITIALLY IMMEDIATE: checked immediately, except in a transaction that
 *   defers it. The trash purge (CORE-010) must do so (or delete project memberships first),
 *   because PostgreSQL checks it while cascading the team to its memberships, before the cascade
 *   to projects has removed the project memberships.
 *
 * The Team Owner's access to every project is an authorization rule, never a synthetic membership
 * row (DATABASE.md §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 180);
            $table->string('project_key', 20);
            $table->text('description')->nullable();
            $table->string('visibility', 16)->default('team');
            $table->foreignUuid('created_by')->index()->constrained('users')->restrictOnDelete();
            $table->boolean('sprints_enabled')->default(false);
            $table->bigInteger('next_task_number')->default(1);
            $table->bigInteger('version')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();

            $table->unique(['team_id', 'project_key']);
            $table->unique(['id', 'team_id']);
            $table->index(['team_id', 'visibility', 'archived_at']);
        });

        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_visibility_check CHECK (visibility IN ('team', 'private'))");
        // Canonical key format from API_SPEC.md §4; the application upper-cases input first.
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_project_key_format_check CHECK (project_key ~ '^[A-Z][A-Z0-9]{1,9}$')");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_next_task_number_check CHECK (next_task_number > 0)');
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_version_check CHECK (version > 0)');

        Schema::create('project_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->string('project_role', 24);
            $table->foreignUuid('added_by')->index()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['project_id', 'user_id']);
            $table->index(['user_id', 'project_id']);
            $table->index(['team_id', 'project_id']);

            $table->foreign(['project_id', 'team_id'])
                ->references(['id', 'team_id'])
                ->on('projects')
                ->cascadeOnDelete();

            // No ON DELETE action: a team membership that still has project memberships cannot be
            // deleted. Deferrable only for the team purge (see above).
            $table->foreign(['team_id', 'user_id'])
                ->references(['team_id', 'user_id'])
                ->on('team_memberships')
                ->deferrable()
                ->initiallyImmediate();
        });

        DB::statement("ALTER TABLE project_memberships ADD CONSTRAINT project_memberships_project_role_check CHECK (project_role IN ('manager', 'contributor'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('project_memberships');
        Schema::dropIfExists('projects');
    }
};
