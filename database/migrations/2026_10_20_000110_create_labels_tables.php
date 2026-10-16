<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 03, third part (DATABASE.md §5): project labels and the task ↔ label links
 * (CORE-005, ADR-023 §6).
 *
 * - `labels (project_id, team_id) → projects(id, team_id)` cascades (ADR-022 §5); `UNIQUE(id,
 *   project_id)` is the target of the links' composite key, and names are unique per project
 *   ignoring case (`UNIQUE(project_id, lower(name))`).
 * - `task_labels` has `PRIMARY KEY (task_id, label_id)`. Both composite keys carry `project_id`, so a
 *   task can only carry labels of its own project (DB-T04). Both cascade: deleting a label removes
 *   its associations (API_SPEC §5 "Remove label and associations"), and the purge removes them with
 *   their tasks and labels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->uuid('project_id');
            $table->string('name', 50);
            $table->string('color', 16)->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'project_id']);
            $table->index(['team_id', 'project_id']);

            $table->foreign(['project_id', 'team_id'])
                ->references(['id', 'team_id'])
                ->on('projects')
                ->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE labels ADD CONSTRAINT labels_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE labels ADD CONSTRAINT labels_color_format_check CHECK (color IS NULL OR color ~ '^#[0-9A-F]{6}$')");
        DB::statement('CREATE UNIQUE INDEX labels_project_id_lower_name_unique ON labels (project_id, lower(name))');

        Schema::create('task_labels', function (Blueprint $table) {
            $table->uuid('task_id');
            $table->uuid('project_id');
            $table->uuid('label_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['task_id', 'label_id']);
            $table->index(['label_id', 'task_id']);

            $table->foreign(['task_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('tasks')
                ->cascadeOnDelete();

            $table->foreign(['label_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('labels')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_labels');
        Schema::dropIfExists('labels');
    }
};
