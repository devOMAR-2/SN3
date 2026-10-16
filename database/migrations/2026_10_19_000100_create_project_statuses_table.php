<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Migration group 03, first part (DATABASE.md §5, §14, §20): `project_statuses` (CORE-004, ADR-021).
 *
 * - A status belongs to one project and carries its team: `(project_id, team_id)` references
 *   `projects(id, team_id)` and cascades, as ADR-020 §2 requires of every project descendant, so
 *   the trash purge (CORE-010) removes statuses with their project.
 * - `UNIQUE(project_id, id)` is the target of the tasks' composite key `(status_id, project_id)`
 *   (CORE-005), so a task can never point at another project's status (DB-T04).
 * - `position` is the stored rank (`numeric(20,10)`). CORE-004 keeps it dense, `0..n-1` in board
 *   order, and `UNIQUE(project_id, position)` makes the order total. That key is DEFERRABLE
 *   INITIALLY IMMEDIATE so PostgreSQL checks it at the end of each statement instead of row by row:
 *   a reorder rewrites every rank in one UPDATE. Nothing defers it further.
 * - Names are unique per project, ignoring case (DATABASE.md §5 "consider unique case-insensitive
 *   status names"), and at most one status per project is the default.
 * - `version` backs `If-Match` on status changes (API_SPEC.md §1.4, §18).
 *
 * Existing projects get the default workflow (To do, In progress, Done) in their creator's
 * language, exactly as CreateProject seeds new ones, so every project has statuses after upgrade.
 */
return new class extends Migration
{
    /** Default workflow per locale (DESIGN_SYSTEM.md §7.1 `task.status.*`), frozen for this migration. */
    private const DEFAULTS = [
        'ar' => ['todo' => 'للإنجاز', 'in_progress' => 'قيد التنفيذ', 'done' => 'مكتمل'],
        'en' => ['todo' => 'To do', 'in_progress' => 'In progress', 'done' => 'Done'],
    ];

    public function up(): void
    {
        Schema::create('project_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('team_id');
            $table->string('name', 100);
            $table->string('category', 16);
            $table->decimal('position', 20, 10);
            $table->string('color', 16)->nullable();
            $table->boolean('is_default')->default(false);
            $table->bigInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['project_id', 'id']);
            $table->index(['team_id', 'project_id']);

            $table->foreign(['project_id', 'team_id'])
                ->references(['id', 'team_id'])
                ->on('projects')
                ->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE project_statuses ADD CONSTRAINT project_statuses_category_check CHECK (category IN ('todo', 'in_progress', 'done'))");
        DB::statement("ALTER TABLE project_statuses ADD CONSTRAINT project_statuses_name_not_blank_check CHECK (btrim(name) <> '')");
        // Hex RGB (API_SPEC.md §5), stored upper-case by the application.
        DB::statement("ALTER TABLE project_statuses ADD CONSTRAINT project_statuses_color_format_check CHECK (color IS NULL OR color ~ '^#[0-9A-F]{6}$')");
        DB::statement('ALTER TABLE project_statuses ADD CONSTRAINT project_statuses_position_check CHECK (position >= 0)');
        DB::statement('ALTER TABLE project_statuses ADD CONSTRAINT project_statuses_version_check CHECK (version > 0)');
        DB::statement('ALTER TABLE project_statuses ADD CONSTRAINT project_statuses_project_id_position_unique UNIQUE (project_id, position) DEFERRABLE INITIALLY IMMEDIATE');
        DB::statement('CREATE UNIQUE INDEX project_statuses_project_id_lower_name_unique ON project_statuses (project_id, lower(name))');
        DB::statement('CREATE UNIQUE INDEX project_statuses_project_id_default_unique ON project_statuses (project_id) WHERE is_default');

        $this->seedExistingProjects();
    }

    public function down(): void
    {
        Schema::dropIfExists('project_statuses');
    }

    /**
     * Trashed projects too: a restored project must have a workflow.
     */
    private function seedExistingProjects(): void
    {
        $projects = DB::table('projects')
            ->join('users', 'users.id', '=', 'projects.created_by')
            ->select(['projects.id', 'projects.team_id', 'users.locale'])
            ->orderBy('projects.id');

        foreach ($projects->lazy() as $project) {
            $locale = $project->locale;
            $names = is_string($locale) && array_key_exists($locale, self::DEFAULTS) ? self::DEFAULTS[$locale] : self::DEFAULTS['ar'];
            $now = now();
            $rows = [];

            foreach (array_keys($names) as $position => $category) {
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'project_id' => $project->id,
                    'team_id' => $project->team_id,
                    'name' => $names[$category],
                    'category' => $category,
                    'position' => $position,
                    'is_default' => $category === 'todo',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('project_statuses')->insert($rows);
        }
    }
};
