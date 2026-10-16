<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Models\Label;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// CORE-005: the database enforces the task invariants itself (DATABASE.md §0.5, §5, §10.3, §16;
// ADR-023 §2), whatever the application does: DB-T03 (assignee in the task's team), DB-T04 (status,
// label and parent of the same project), unique task numbers, CHECK constraints and the purge rule
// for every new key (ADR-022 §5).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->project = taskProject($this->team);
    $this->other = taskProject($this->team);
    $this->task = taskIn($this->project);
});

/**
 * Runs one statement in a savepoint and reports the SQLSTATE it failed with (null when it passed).
 */
function taskSqlState(Closure $statement): ?string
{
    try {
        DB::transaction(function () use ($statement): void {
            $statement();
            // Deferrable keys are checked at the end of the statement unless deferred; force them.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? 'unknown';
    }

    return null;
}

function taskSchemaUpdate(Task $task, array $values): ?string
{
    return taskSqlState(fn () => DB::table('tasks')->where('id', $task->id)->update($values));
}

it('refuses an assignee who is not a member of the task\'s team (DB-T03)', function () {
    $stranger = Team::factory()->create()->owner;

    expect(taskSchemaUpdate($this->task, ['assignee_user_id' => $stranger->id]))->toBe('23503')
        ->and(taskSchemaUpdate($this->task, ['assignee_user_id' => $this->team->owner_user_id]))->toBeNull();
});

it('refuses a status, parent or label of another project (DB-T04)', function () {
    $foreignTask = taskIn($this->other);
    $foreignLabel = Label::factory()->for($this->other)->create();

    expect(taskSchemaUpdate($this->task, ['status_id' => taskDefaultStatusId($this->other)]))->toBe('23503')
        ->and(taskSchemaUpdate($this->task, ['parent_task_id' => $foreignTask->id]))->toBe('23503')
        ->and(taskSqlState(fn () => DB::table('task_labels')->insert(['task_id' => $this->task->id, 'project_id' => $this->project->id, 'label_id' => $foreignLabel->id])))->toBe('23503')
        ->and(taskSqlState(fn () => DB::table('task_labels')->insert(['task_id' => $this->task->id, 'project_id' => $this->other->id, 'label_id' => $foreignLabel->id])))->toBe('23503')
        // A task of one team in another team's project is impossible too.
        ->and(taskSchemaUpdate($this->task, ['team_id' => Team::factory()->create()->id]))->toBe('23503');
});

it('keeps task numbers unique per project and enforces the CHECK constraints', function (array $values, string $state) {
    $second = taskIn($this->project);

    expect(taskSchemaUpdate($second, $values))->toBe($state);
})->with([
    'duplicate number' => [['task_number' => 1], '23505'],
    'zero number' => [['task_number' => 0], '23514'],
    'unknown priority' => [['priority' => 'critical'], '23514'],
    'blank title' => [['title' => '   '], '23514'],
    'zero version' => [['version' => 0], '23514'],
    'too long title' => [['title' => str_repeat('a', 201)], '22001'],
]);

it('refuses a task that is its own parent', function () {
    expect(taskSchemaUpdate($this->task, ['parent_task_id' => $this->task->id]))->toBe('23514');
});

it('derives the sort keys from priority and due date', function () {
    DB::table('tasks')->where('id', $this->task->id)->update(['priority' => 'urgent', 'due_date' => null]);
    $row = DB::table('tasks')->where('id', $this->task->id)->first(['priority_rank', 'due_sort']);

    expect($row->priority_rank)->toBe(4)->and($row->due_sort)->toBe('infinity');

    expect(taskSchemaUpdate($this->task, ['priority_rank' => 1]))->toBe('428C9');
});

it('enforces checklist and label constraints', function () {
    $item = fn (array $values) => taskSqlState(fn () => DB::table('task_checklist_items')->insert([
        'id' => (string) Str::uuid7(), 'task_id' => $this->task->id, 'content' => 'x', 'position' => 0, 'is_done' => false,
        'created_at' => now(), 'updated_at' => now(), ...$values,
    ]));

    expect($item(['content' => ' ']))->toBe('23514')
        ->and($item(['is_done' => true]))->toBe('23514')
        ->and($item(['completed_at' => now()]))->toBe('23514')
        ->and($item(['position' => -1]))->toBe('23514')
        ->and($item([]))->toBeNull();

    Label::factory()->for($this->project)->create(['name' => 'Bug']);

    expect(taskSqlState(fn () => Label::factory()->for($this->project)->create(['name' => 'BUG'])))->toBe('23505')
        ->and(taskSqlState(fn () => Label::factory()->for($this->project)->create(['color' => '#fff'])))->toBe('23514');
});

it('removes a label\'s task links with it, and the task\'s links and checklist with the task', function () {
    $label = Label::factory()->for($this->project)->create();
    DB::table('task_labels')->insert(['task_id' => $this->task->id, 'project_id' => $this->project->id, 'label_id' => $label->id]);
    TaskChecklistItem::factory()->for($this->task)->create();

    $label->delete();
    expect(DB::table('task_labels')->count())->toBe(0);

    DB::table('task_labels')->insert(['task_id' => $this->task->id, 'project_id' => $this->project->id, 'label_id' => Label::factory()->for($this->project)->create()->id]);
    DB::table('tasks')->where('id', $this->task->id)->delete();

    expect(DB::table('task_labels')->count())->toBe(0)->and(DB::table('task_checklist_items')->count())->toBe(0);
});

it('declares every new key as the purge rule requires (ADR-022 §5)', function () {
    $keys = collect(DB::select(<<<'SQL'
        select c.conname, c.confdeltype as on_delete, c.condeferrable as deferrable, c.condeferred as deferred
        from pg_constraint c join pg_class t on t.oid = c.conrelid
        where c.contype = 'f' and t.relname in ('tasks', 'labels', 'task_labels', 'task_checklist_items')
        SQL))->keyBy('conname');

    foreach (['tasks_project_id_team_id_foreign', 'labels_project_id_team_id_foreign', 'task_labels_task_id_project_id_foreign', 'task_labels_label_id_project_id_foreign', 'task_checklist_items_task_id_foreign'] as $cascade) {
        expect($keys[$cascade]->on_delete)->toBe('c');
    }

    foreach (['tasks_project_id_status_id_foreign', 'tasks_parent_task_id_project_id_foreign', 'tasks_team_id_assignee_user_id_foreign'] as $deferrable) {
        expect($keys[$deferrable])->on_delete->toBe('a')->deferrable->toBeTrue()->deferred->toBeFalse();
    }

    expect($keys['tasks_created_by_foreign']->on_delete)->toBe('r')
        ->and($keys['task_checklist_items_completed_by_foreign']->on_delete)->toBe('n');
});
