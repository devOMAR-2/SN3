<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
 *   status names"), and at most one status per project is the default, always a `todo` one.
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
        // New tasks start in the default status, so it must never be a done (or in-progress) status.
        DB::statement("ALTER TABLE project_statuses ADD CONSTRAINT project_statuses_default_is_todo_check CHECK (NOT is_default OR category = 'todo')");

        $this->seedExistingProjects();
    }

    public function down(): void
    {
        Schema::dropIfExists('project_statuses');
    }

    /**
     * One set-based INSERT for every project, trashed ones included (a restored project must have a
     * workflow). Names follow the creator's locale; IDs are UUIDv7, built in SQL from the clock and
     * `gen_random_uuid()` (PostgreSQL 16 has no native v7 generator): the first 48 bits become the
     * Unix time in milliseconds and the version nibble becomes 7.
     */
    private function seedExistingProjects(): void
    {
        $values = [];
        $bindings = [];

        foreach (self::DEFAULTS['en'] as $category => $english) {
            $values[] = '(?, ?::numeric, ?, ?, ?::boolean)';
            array_push($bindings, $category, count($values) - 1, self::DEFAULTS['ar'][$category], $english, $category === 'todo' ? 'true' : 'false');
        }

        DB::insert(
            'INSERT INTO project_statuses (id, project_id, team_id, name, category, position, is_default, created_at, updated_at) '
            ."SELECT encode(set_bit(set_bit(overlay(uuid_send(gen_random_uuid()) PLACING substring(int8send(floor(extract(epoch FROM clock_timestamp()) * 1000)::bigint) FROM 3) FROM 1 FOR 6), 52, 1), 53, 1), 'hex')::uuid, "
            ."p.id, p.team_id, CASE WHEN u.locale = 'en' THEN d.name_en ELSE d.name_ar END, d.category, d.position, d.is_default, now(), now() "
            .'FROM projects p JOIN users u ON u.id = p.created_by '
            .'CROSS JOIN (VALUES '.implode(', ', $values).') AS d(category, position, name_ar, name_en, is_default)',
            $bindings,
        );
    }
};
