<?php

use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

// CORE-004: the project_statuses constraints of DATABASE.md §5 and ADR-021, enforced by PostgreSQL
// itself (direct SQL bypasses the application on purpose), and the upgrade path for projects that
// existed before the table.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->project = Project::factory()->for($this->team)->withDefaultStatuses()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function wfInsertStatusRow(Project $project, array $overrides = []): void
{
    DB::table('project_statuses')->insert([
        'id' => (string) Str::uuid7(),
        'project_id' => $project->id,
        'team_id' => $project->team_id,
        'name' => 'Direct '.Str::random(6),
        'category' => 'todo',
        'position' => 10,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

/** Runs $statement in a savepoint so the failure does not abort the test transaction. */
function wfRejected(Closure $statement): string
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        return $e->getMessage();
    }

    return 'accepted';
}

it('generates UUIDv7 keys and starts the version at 1', function () {
    $status = $this->project->statuses()->firstOrFail();

    expect(Uuid::fromString($status->id)->getFields()->getVersion())->toBe(7)
        ->and($status->version)->toBe(1);
});

it('enforces the category, name, colour, position and version checks', function (array $overrides, string $constraint) {
    expect(wfRejected(fn () => wfInsertStatusRow($this->project, $overrides)))->toContain($constraint);
})->with([
    'unknown category' => [['category' => 'blocked'], 'project_statuses_category_check'],
    'blank name' => [['name' => '  '], 'project_statuses_name_not_blank_check'],
    'lower-case colour' => [['color' => '#abcdef'], 'project_statuses_color_format_check'],
    'short colour' => [['color' => '#FFF'], 'project_statuses_color_format_check'],
    'negative position' => [['position' => -1], 'project_statuses_position_check'],
    'zero version' => [['version' => 0], 'project_statuses_version_check'],
]);

it('accepts a valid row', function () {
    expect(wfRejected(fn () => wfInsertStatusRow($this->project, ['color' => '#145A38'])))->toBe('accepted');
});

it('keeps positions, names (ignoring case) and the default unique per project', function () {
    expect(wfRejected(fn () => wfInsertStatusRow($this->project, ['position' => 1])))->toContain('project_statuses_project_id_position_unique')
        ->and(wfRejected(fn () => wfInsertStatusRow($this->project, ['name' => 'DONE'])))->toContain('project_statuses_project_id_lower_name_unique')
        ->and(wfRejected(fn () => wfInsertStatusRow($this->project, ['is_default' => true])))->toContain('project_statuses_project_id_default_unique');

    // Another project may use the same position, name and default flag.
    $other = Project::factory()->for($this->team)->create();
    expect(wfRejected(fn () => wfInsertStatusRow($other, ['position' => 1, 'name' => 'Done', 'is_default' => true])))->toBe('accepted');
});

it('refuses a status whose team is not its project\'s team', function () {
    $otherTeam = Team::factory()->create();

    expect(wfRejected(fn () => wfInsertStatusRow($this->project, ['team_id' => $otherTeam->id])))
        ->toContain('project_statuses_project_id_team_id_foreign');
});

it('checks the position key at once outside a reorder, even though it is deferrable', function () {
    $done = $this->project->statuses()->where('category', 'done')->firstOrFail();

    expect(wfRejected(fn () => DB::table('project_statuses')->where('id', $done->id)->update(['position' => 0])))
        ->toContain('project_statuses_project_id_position_unique');
});

it('removes statuses with their project when it is purged', function () {
    $other = Project::factory()->for($this->team)->withDefaultStatuses()->create();

    DB::table('projects')->where('id', $this->project->id)->delete();

    expect(ProjectStatus::query()->pluck('project_id')->unique()->values()->all())->toBe([$other->id]);
});

it('has the keys and indexes DATABASE.md §5 requires', function () {
    $indexes = collect(DB::select("select indexdef from pg_indexes where tablename = 'project_statuses'"))
        ->pluck('indexdef')
        ->map(fn (string $def) => preg_replace('/^.* USING btree /', '', $def))
        ->all();

    expect($indexes)->toContain('(project_id, id)')
        ->toContain('(project_id, "position")')
        ->toContain('(project_id, lower((name)::text))')
        ->toContain('(project_id) WHERE is_default')
        ->toContain('(team_id, project_id)');
});

it('gives every existing project the default workflow on upgrade, in its creator\'s language', function () {
    // Back to the schema before CORE-004, with projects created then.
    Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);

    $english = User::factory()->create(['locale' => 'en']);
    $team = Team::factory()->ownedBy($english)->create();
    $legacy = Project::factory()->for($team)->create();
    $trashed = Project::factory()->for($team)->create();
    $trashed->delete();
    $arabic = Project::factory()->for($this->team)->create();

    Artisan::call('migrate', ['--force' => true]);

    $board = fn (Project $project) => ProjectStatus::query()->where('project_id', $project->id)->orderBy('position')
        ->get()->map(fn (ProjectStatus $s) => [$s->name, $s->category->value, $s->position, $s->is_default, $s->team_id])->all();

    expect($board($legacy))->toBe([
        ['To do', 'todo', 0, true, $team->id],
        ['In progress', 'in_progress', 1, false, $team->id],
        ['Done', 'done', 2, false, $team->id],
    ])
        ->and($board($trashed))->toHaveCount(3)
        ->and(array_column($board($arabic), 0))->toBe(['للإنجاز', 'قيد التنفيذ', 'مكتمل'])
        ->and(Uuid::fromString(ProjectStatus::query()->where('project_id', $legacy->id)->value('id'))->getFields()->getVersion())->toBe(7);
});
