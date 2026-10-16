<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Domain\Projects\ProjectRole;
use App\Domain\Tasks\Actions\CreateLabel;
use App\Domain\Teams\TeamRole;
use App\Models\Label;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// CORE-005: project labels (API_SPEC.md §5 labels rows; DATABASE.md §5 labels, task_labels) and
// task ↔ label links through `label_ids`. TEST-FR-TASK-001 (optional labels); AGENTS.md §8
// "Customization: labels … no cross-project leaks"; DATABASE.md §16 DB-T04.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->project = taskProject($this->team);
    $this->manager = taskTeamMember($this->team, TeamRole::Member);
    taskProjectMember($this->project, $this->manager, ProjectRole::Manager);
    $this->member = taskTeamMember($this->team, TeamRole::Member);
    $this->url = "/api/v1/projects/{$this->project->id}/labels";
});

it('creates, lists, renames and recolours labels', function () {
    $id = $this->actingAs($this->manager)->postJson($this->url, ['name' => '  خطأ  ', 'color' => ' #abcdef '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'خطأ')
        ->assertJsonPath('data.color', '#ABCDEF')
        ->assertJsonPath('data.project_id', $this->project->id)
        ->json('data.id');

    $this->actingAs($this->owner)->postJson($this->url, ['name' => 'Bug'])->assertCreated()->assertJsonPath('data.color', null);

    $this->actingAs($this->member)->getJson($this->url)->assertOk()->assertJsonPath('data.*.name', ['Bug', 'خطأ']);

    $this->actingAs($this->manager)->patchJson("{$this->url}/{$id}", ['name' => 'Defect', 'color' => null])
        ->assertOk()
        ->assertJsonPath('data.name', 'Defect')
        ->assertJsonPath('data.color', null);
});

it('keeps names unique per project ignoring case, but not across projects (409)', function () {
    Label::factory()->for($this->project)->create(['name' => 'Bug']);
    $other = Label::factory()->for($this->project)->create(['name' => 'UI']);

    $this->actingAs($this->owner)->postJson($this->url, ['name' => 'bug'])->assertStatus(409)->assertJsonPath('error.code', 'conflict');
    $this->actingAs($this->owner)->patchJson("{$this->url}/{$other->id}", ['name' => 'BUG'])->assertStatus(409);

    $elsewhere = taskProject($this->team);
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$elsewhere->id}/labels", ['name' => 'Bug'])->assertCreated();
});

it('validates label bodies with stable codes', function (array $body, string $field, string $code) {
    $this->actingAs($this->owner)->postJson($this->url, [...['name' => 'Ok'], ...$body])
        ->assertUnprocessable()
        ->assertJsonPath("error.fields.{$field}.0", $code);
})->with([
    'missing name' => [['name' => null], 'name', 'required'],
    'name too long' => [['name' => str_repeat('ل', 51)], 'name', 'max'],
    'short colour' => [['color' => '#FFF'], 'color', 'regex'],
    'named colour' => [['color' => 'red'], 'color', 'regex'],
    'unknown field' => [['position' => 1], 'position', 'unknown_field'],
]);

it('limits a project to 100 labels (409)', function () {
    Label::factory()->for($this->project)->count(CreateLabel::MAX_PER_PROJECT)->create();

    $this->actingAs($this->owner)->postJson($this->url, ['name' => 'One more'])->assertStatus(409);
});

it('answers a label of another project like a random one (404)', function () {
    $foreign = Label::factory()->for(taskProject($this->team))->create(['name' => 'Kept']);

    foreach ([$foreign->id, (string) Str::uuid(), 'x'] as $id) {
        $this->actingAs($this->owner)->patchJson("{$this->url}/{$id}", ['name' => 'Taken'])->assertNotFound();
        $this->actingAs($this->owner)->deleteJson("{$this->url}/{$id}")->assertNotFound();
    }

    expect($foreign->fresh()->name)->toBe('Kept');
});

it('deletes a label with its task links and moves the version of the tasks that carried it', function () {
    $label = Label::factory()->for($this->project)->create();
    $keep = Label::factory()->for($this->project)->create();
    $tagged = taskIn($this->project);
    $trashed = taskIn($this->project);
    $plain = taskIn($this->project);

    foreach ([$tagged, $trashed] as $task) {
        $this->actingAs($this->member)->patchJson("/api/v1/tasks/{$task->id}", ['label_ids' => [$label->id, $keep->id]], taskIfMatch(1))->assertOk();
    }
    $trashed->delete();

    $this->actingAs($this->manager)->deleteJson("{$this->url}/{$label->id}", ['junk' => 1])->assertUnprocessable();
    $this->actingAs($this->manager)->deleteJson("{$this->url}/{$label->id}")->assertNoContent();

    $this->actingAs($this->member)->getJson("/api/v1/tasks/{$tagged->id}")
        ->assertJsonPath('data.label_ids', [$keep->id])
        ->assertJsonPath('data.version', 3);

    expect(DB::table('task_labels')->where('label_id', $label->id)->count())->toBe(0)
        ->and(Task::withTrashed()->findOrFail($trashed->id)->version)->toBe(3)
        ->and($plain->fresh()->version)->toBe(1);
});

it('lets contributors attach and detach labels but not manage them (403 whatever the body)', function () {
    $label = Label::factory()->for($this->project)->create();
    $task = taskIn($this->project);

    $this->actingAs($this->member)->patchJson("/api/v1/tasks/{$task->id}", ['label_ids' => [$label->id]], taskIfMatch(1))->assertOk();
    $this->actingAs($this->member)->patchJson("/api/v1/tasks/{$task->id}", ['label_ids' => []], taskIfMatch(2))->assertOk();

    $this->actingAs($this->member)->postJson($this->url, ['name' => 'Mine'])->assertForbidden();
    $this->actingAs($this->member)->postJson($this->url, ['junk' => true])->assertForbidden();
    $this->actingAs($this->member)->patchJson("{$this->url}/{$label->id}", ['name' => 'Mine'])->assertForbidden();
    $this->actingAs($this->member)->deleteJson("{$this->url}/{$label->id}")->assertForbidden();

    expect(Label::query()->count())->toBe(1);
});

it('answers 423 on label writes in an archived project', function () {
    $label = Label::factory()->for($this->project)->create();
    $this->project->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->owner)->postJson($this->url, ['name' => 'New'])->assertStatus(423);
    $this->actingAs($this->owner)->patchJson("{$this->url}/{$label->id}", ['name' => 'New'])->assertStatus(423);
    $this->actingAs($this->owner)->deleteJson("{$this->url}/{$label->id}")->assertStatus(423);
    $this->actingAs($this->member)->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');
});
