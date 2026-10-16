<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Domain\Tasks\Checklist;
use App\Domain\Teams\TeamRole;
use App\Models\TaskChecklistItem;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// CORE-005: task checklists (API_SPEC.md §6.2; DATABASE.md §5 task_checklist_items): create, edit,
// toggle, reorder and delete; at most 100 items of at most 300 characters. TEST-FR-TASK-007
// (checklists).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->member = taskTeamMember($this->team, TeamRole::Member);
    $this->viewer = taskTeamMember($this->team, TeamRole::Viewer);
    $this->project = taskProject($this->team);
    $this->task = taskIn($this->project);
    $this->url = "/api/v1/tasks/{$this->task->id}/checklist";
});

function taskChecklistTexts($test): array
{
    return collect($test->actingAs($test->viewer)->getJson($test->url)->assertOk()->json('data'))
        ->map(fn (array $item) => $item['position'].':'.$item['text'])
        ->all();
}

it('creates items at the end and lists them in order', function () {
    $this->actingAs($this->member)->postJson($this->url, ['text' => '  اكتب الاختبارات  '])
        ->assertCreated()
        ->assertJsonPath('data.text', 'اكتب الاختبارات')
        ->assertJsonPath('data.checked', false)
        ->assertJsonPath('data.position', 0)
        ->assertJsonPath('data.completed_by', null)
        ->assertJsonPath('data.task_id', $this->task->id);

    $this->actingAs($this->member)->postJson($this->url, ['text' => 'Ship'])->assertCreated()->assertJsonPath('data.position', 1);

    expect(taskChecklistTexts($this))->toBe(['0:اكتب الاختبارات', '1:Ship']);
});

it('toggles an item, recording who completed it and when, and keeps a repeated toggle harmless', function () {
    $item = TaskChecklistItem::factory()->for($this->task)->create();

    $this->actingAs($this->member)->patchJson("{$this->url}/{$item->id}", ['checked' => true])
        ->assertOk()
        ->assertJsonPath('data.checked', true)
        ->assertJsonPath('data.completed_by', $this->member->id)
        ->assertJsonPath('data.completed_at', fn ($at) => is_string($at));

    $completedAt = $item->fresh()->completed_at;
    $this->travel(5)->minutes();

    $this->actingAs($this->team->owner)->patchJson("{$this->url}/{$item->id}", ['checked' => true])
        ->assertOk()
        ->assertJsonPath('data.completed_by', $this->member->id);
    expect($item->fresh()->completed_at->equalTo($completedAt))->toBeTrue();

    $this->actingAs($this->member)->patchJson("{$this->url}/{$item->id}", ['checked' => false, 'text' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.checked', false)
        ->assertJsonPath('data.completed_by', null)
        ->assertJsonPath('data.completed_at', null)
        ->assertJsonPath('data.text', 'Renamed');

    // The task's own version is not affected by its checklist (ADR-023 §7).
    expect($this->task->fresh()->version)->toBe(1);
});

it('moves an item to another index and closes up the others', function () {
    $items = collect(['a', 'b', 'c', 'd'])->map(fn (string $text) => TaskChecklistItem::factory()->for($this->task)->create(['content' => $text]));

    $this->actingAs($this->member)->patchJson("{$this->url}/{$items[3]->id}", ['position' => 0])->assertOk()->assertJsonPath('data.position', 0);
    expect(taskChecklistTexts($this))->toBe(['0:d', '1:a', '2:b', '3:c']);

    $this->actingAs($this->member)->patchJson("{$this->url}/{$items[3]->id}", ['position' => 3])->assertOk();
    expect(taskChecklistTexts($this))->toBe(['0:a', '1:b', '2:c', '3:d']);

    $this->actingAs($this->member)->patchJson("{$this->url}/{$items[0]->id}", ['position' => 4])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.position', ['max']);

    $this->actingAs($this->member)->deleteJson("{$this->url}/{$items[1]->id}")->assertNoContent();
    expect(taskChecklistTexts($this))->toBe(['0:a', '1:c', '2:d']);
});

it('validates checklist bodies with stable codes', function (string $method, array $body, string $field, string $code) {
    $item = TaskChecklistItem::factory()->for($this->task)->create();
    $url = $method === 'post' ? $this->url : "{$this->url}/{$item->id}";

    $this->actingAs($this->member)->json($method, $url, $body)
        ->assertUnprocessable()
        ->assertJsonPath("error.fields.{$field}.0", $code);
})->with([
    'missing text' => ['post', [], 'text', 'required'],
    'blank text' => ['post', ['text' => '  '], 'text', 'required'],
    'text too long' => ['post', ['text' => str_repeat('a', 301)], 'text', 'max'],
    'unknown field' => ['post', ['text' => 'x', 'checked' => true], 'checked', 'unknown_field'],
    'loose boolean' => ['patch', ['checked' => 'yes'], 'checked', 'boolean'],
    'numeric boolean' => ['patch', ['checked' => 1], 'checked', 'boolean'],
    'negative position' => ['patch', ['position' => -1], 'position', 'min'],
    'fractional position' => ['patch', ['position' => 1.5], 'position', 'integer'],
    'null text' => ['patch', ['text' => null], 'text', 'required'],
]);

it('limits a task to 100 checklist items (409)', function () {
    $rows = collect(range(0, Checklist::MAX_ITEMS - 1))->map(fn (int $i) => [
        'id' => (string) Str::uuid7(), 'task_id' => $this->task->id, 'content' => "Item {$i}", 'position' => $i,
        'is_done' => false, 'created_at' => now(), 'updated_at' => now(),
    ])->all();
    DB::table('task_checklist_items')->insert($rows);

    $this->actingAs($this->member)->postJson($this->url, ['text' => 'One more'])->assertStatus(409);
    expect(TaskChecklistItem::query()->count())->toBe(Checklist::MAX_ITEMS);
});

it('is read-only for Team Viewers and gone with a trashed task', function () {
    $item = TaskChecklistItem::factory()->for($this->task)->create();

    $this->actingAs($this->viewer)->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($this->viewer)->postJson($this->url, ['text' => 'x'])->assertForbidden();
    $this->actingAs($this->viewer)->patchJson("{$this->url}/{$item->id}", ['checked' => true])->assertForbidden();
    $this->actingAs($this->viewer)->deleteJson("{$this->url}/{$item->id}")->assertForbidden();

    $this->task->delete();

    $this->actingAs($this->member)->getJson($this->url)->assertNotFound();
    $this->actingAs($this->member)->postJson($this->url, ['text' => 'x'])->assertNotFound();
    $this->actingAs($this->member)->patchJson("{$this->url}/{$item->id}", ['checked' => true])->assertNotFound();
    expect($item->fresh()->is_done)->toBeFalse();
});
