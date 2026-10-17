<?php

require_once __DIR__.'/CollaborationHelpers.php';

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// CORE-008: task comments (API_SPEC.md §6.2; DATABASE.md §5 task_comments; FR-COL-001): list, add,
// edit own, delete own or moderate; tombstones in the activity log; 30-day pruning of deleted
// comments. TEST-FR-COL-001; acceptance scenarios AUTH-01, AUTH-04 (comments).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->member = taskTeamMember($this->team, TeamRole::Member);
    $this->other = taskTeamMember($this->team, TeamRole::Member);
    $this->admin = taskTeamMember($this->team, TeamRole::Admin);
    $this->viewer = taskTeamMember($this->team, TeamRole::Viewer);
    $this->project = taskProject($this->team);
    $this->task = taskIn($this->project);
    $this->url = "/api/v1/tasks/{$this->task->id}/comments";
});

it('adds a comment with author and timestamps, and lists comments newest first', function () {
    $this->freezeTime();

    $first = $this->actingAs($this->member)->postJson($this->url, ['body' => "## مراجعة\n\nPlease review **this**."])
        ->assertCreated()
        ->assertJsonPath('data.task_id', $this->task->id)
        ->assertJsonPath('data.author_user_id', $this->member->id)
        ->assertJsonPath('data.body', "## مراجعة\n\nPlease review **this**.")
        ->assertJsonPath('data.mention_user_ids', [])
        ->assertJsonPath('data.edited_at', null)
        ->assertJsonPath('data.created_at', now()->utc()->toIso8601ZuluString())
        ->json('data');

    expect(array_keys($first))->toBe(['id', 'task_id', 'author_user_id', 'body', 'mention_user_ids', 'edited_at', 'created_at', 'updated_at']);

    $this->travel(1)->minute();
    $second = $this->actingAs($this->other)->postJson($this->url, ['body' => 'Second'])->assertCreated()->json('data.id');

    $this->actingAs($this->viewer)->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('data.0.id', $second)
        ->assertJsonPath('data.1.id', $first['id'])
        ->assertJsonPath('meta.has_more', false);

    $this->actingAs($this->viewer)->getJson("{$this->url}?limit=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.has_more', true);

    // The task itself is not changed by its comments (§1.4 versions the task, not its comments).
    expect($this->task->fresh()->version)->toBe(1);
});

it('validates the body with stable codes', function (array $body, string $field, string $code) {
    $this->actingAs($this->member)->postJson($this->url, $body)
        ->assertUnprocessable()
        ->assertJsonPath("error.fields.{$field}.0", $code);

    expect(TaskComment::query()->count())->toBe(0);
})->with([
    'missing' => [[], 'body', 'required'],
    'blank' => [['body' => "  \n "], 'body', 'required'],
    'not a string' => [['body' => ['x']], 'body', 'string'],
    'too long' => [['body' => str_repeat('ب', 10_001)], 'body', 'max'],
    'unknown field' => [['body' => 'x', 'author_user_id' => '018f8c70-0000-7000-8000-000000000001'], 'author_user_id', 'unknown_field'],
]);

it('accepts exactly 10,000 characters of Markdown, Arabic included (DB-T13)', function () {
    $body = str_repeat('ك', 10_000);

    $this->actingAs($this->member)->postJson($this->url, ['body' => $body])->assertCreated()->assertJsonPath('data.body', $body);
});

it('lets only the author edit a comment, keeping the edit time and an audit entry', function () {
    $comment = TaskComment::factory()->for($this->task)->by($this->member)->create(['body' => 'Draft']);
    $this->freezeTime();

    foreach ([$this->other, $this->owner, $this->admin, $this->viewer] as $notAuthor) {
        $this->actingAs($notAuthor)->patchJson("{$this->url}/{$comment->id}", ['body' => 'Hijacked'])->assertForbidden();
    }

    $this->actingAs($this->member)->patchJson("{$this->url}/{$comment->id}", ['body' => 'Final'])
        ->assertOk()
        ->assertJsonPath('data.body', 'Final')
        ->assertJsonPath('data.edited_at', now()->utc()->toIso8601ZuluString());

    // Sending the same body again changes nothing.
    $this->travel(5)->minutes();
    $this->actingAs($this->member)->patchJson("{$this->url}/{$comment->id}", ['body' => 'Final'])->assertOk();

    expect($comment->fresh()->body)->toBe('Final')
        ->and(TaskActivity::query()->where('action', 'comment.edited')->count())->toBe(1)
        ->and(TaskActivity::query()->where('action', 'comment.edited')->sole()->changes)->toEqual(['comment_id' => $comment->id, 'mentions' => 0])
        ->and(json_encode(TaskActivity::query()->pluck('changes')))->not->toContain('Final')->not->toContain('Draft');
});

it('lets the author, the Owner and project managers delete a comment, and leaves only a tombstone', function () {
    $manager = taskTeamMember($this->team, TeamRole::Member);
    taskProjectMember($this->project, $manager, ProjectRole::Manager);
    $ownComment = TaskComment::factory()->for($this->task)->by($this->member)->create(['body' => 'Mine']);
    $moderated = TaskComment::factory()->for($this->task)->by($this->member)->create(['body' => 'Off-topic secret']);
    $byOwner = TaskComment::factory()->for($this->task)->by($this->member)->create(['body' => 'Also off-topic']);

    // Neither another writer nor a Team Admin without the manager role may moderate (ADR-027 §1).
    foreach ([$this->other, $this->admin, $this->viewer] as $user) {
        $this->actingAs($user)->deleteJson("{$this->url}/{$ownComment->id}")->assertForbidden();
    }

    $this->actingAs($this->member)->deleteJson("{$this->url}/{$ownComment->id}")->assertNoContent();
    $this->actingAs($manager)->deleteJson("{$this->url}/{$moderated->id}")->assertNoContent();
    $this->actingAs($this->owner)->deleteJson("{$this->url}/{$byOwner->id}")->assertNoContent();

    // Gone from every read and write path.
    $this->actingAs($this->member)->getJson($this->url)->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->member)->patchJson("{$this->url}/{$ownComment->id}", ['body' => 'x'])->assertNotFound();
    $this->actingAs($this->member)->deleteJson("{$this->url}/{$ownComment->id}")->assertNotFound();

    $tombstones = TaskActivity::query()->where('action', 'comment.deleted')->orderBy('id')->get();
    expect($tombstones->map(fn (TaskActivity $entry) => [$entry->actor_user_id, $entry->changes])->all())->toEqual([
        [$this->member->id, ['comment_id' => $ownComment->id, 'moderated' => false]],
        [$manager->id, ['comment_id' => $moderated->id, 'moderated' => true]],
        [$this->owner->id, ['comment_id' => $byOwner->id, 'moderated' => true]],
    ])->and(json_encode($tombstones->pluck('changes')))->not->toContain('secret');

    // Soft-deleted for the 30-day recovery window, then pruned for good (DATABASE.md §5).
    expect(TaskComment::onlyTrashed()->count())->toBe(3);
    $this->travel(29)->days();
    Artisan::call('model:prune', ['--model' => [TaskComment::class]]);
    expect(TaskComment::onlyTrashed()->count())->toBe(3);
    $this->travel(2)->days();
    Artisan::call('model:prune', ['--model' => [TaskComment::class]]);
    expect(TaskComment::withTrashed()->count())->toBe(0)
        // The tombstones stay.
        ->and(TaskActivity::query()->where('action', 'comment.deleted')->count())->toBe(3);
});

it('stops an author who lost write access from editing or deleting their comment', function () {
    $comment = TaskComment::factory()->for($this->task)->by($this->member)->create();
    $this->member->teamMemberships()->where('team_id', $this->team->id)->update(['role' => TeamRole::Viewer->value]);

    $this->actingAs($this->member)->patchJson("{$this->url}/{$comment->id}", ['body' => 'x'])->assertForbidden();
    $this->actingAs($this->member)->deleteJson("{$this->url}/{$comment->id}")->assertForbidden();
    $this->actingAs($this->member)->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');
});

it('is read-only for Team Viewers whatever the body (AUTH-04)', function (array $body) {
    $this->actingAs($this->viewer)->postJson($this->url, $body)->assertForbidden()->assertJsonPath('error.code', 'forbidden');

    expect(TaskComment::query()->count())->toBe(0);
})->with([
    'valid' => [['body' => 'Hello']],
    'invalid' => [['body' => '']],
    'unknown field' => [['nope' => true]],
]);

it('answers 404 for comments of another task, random and malformed IDs', function () {
    $otherTask = taskIn($this->project);
    $foreign = TaskComment::factory()->for($otherTask)->by($this->member)->create();

    foreach ([$foreign->id, '018f8c70-0000-7000-8000-000000000099', 'not-a-uuid'] as $id) {
        $this->actingAs($this->member)->patchJson("{$this->url}/{$id}", ['body' => 'x'])->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->actingAs($this->owner)->deleteJson("{$this->url}/{$id}")->assertNotFound();
    }

    expect($foreign->fresh()->body)->not->toBe('x');
});

it('answers 404 for a task the caller cannot see, a trashed task and another team (DB-T01, DB-T02)', function () {
    $private = taskProject($this->team, 'private');
    $privateTask = taskIn($private);
    $privateComment = TaskComment::factory()->for($privateTask)->create(['body' => 'Private words']);
    $otherTeam = Team::factory()->create();
    $foreignTask = taskIn(taskProject($otherTeam));

    foreach ([$privateTask, $foreignTask] as $task) {
        $this->actingAs($this->admin)->getJson("/api/v1/tasks/{$task->id}/comments")->assertNotFound()->assertJsonMissing(['body' => 'Private words']);
        $this->actingAs($this->admin)->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'x'])->assertNotFound();
    }
    $this->actingAs($this->admin)->deleteJson("/api/v1/tasks/{$privateTask->id}/comments/{$privateComment->id}")->assertNotFound();

    // The Team Owner sees and moderates private comments without a membership row.
    $this->actingAs($this->owner)->getJson("/api/v1/tasks/{$privateTask->id}/comments")->assertOk()->assertJsonPath('data.0.body', 'Private words');

    $this->task->delete();
    $this->actingAs($this->member)->getJson($this->url)->assertNotFound();
    $this->actingAs($this->member)->postJson($this->url, ['body' => 'x'])->assertNotFound();
});

it('refuses comment writes in an archived project or team (423), and keeps them readable', function (string $archived) {
    $comment = TaskComment::factory()->for($this->task)->by($this->member)->create();
    ($archived === 'team' ? $this->team : $this->project)->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->member)->postJson($this->url, ['body' => 'x'])->assertStatus(423)->assertJsonPath('error.code', 'resource_locked');
    $this->actingAs($this->member)->patchJson("{$this->url}/{$comment->id}", ['body' => 'x'])->assertStatus(423);
    $this->actingAs($this->member)->deleteJson("{$this->url}/{$comment->id}")->assertStatus(423);
    $this->actingAs($this->viewer)->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');

    expect(TaskComment::query()->count())->toBe(1);
})->with(['project', 'team']);

it('requires authentication', function () {
    $this->getJson($this->url)->assertUnauthorized();
    $this->postJson($this->url, ['body' => 'x'])->assertUnauthorized();
});

it('keeps a comment within its own task, project and team in the database, with a non-blank body (DB-T04)', function () {
    $otherProject = taskProject($this->team);

    expect(fn () => DB::transaction(fn () => DB::table('task_comments')->insert([
        'id' => (string) Str::uuid7(),
        'team_id' => $this->team->id,
        'project_id' => $otherProject->id,
        'task_id' => $this->task->id,
        'author_user_id' => $this->member->id,
        'body' => 'Misplaced',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'task_comments_task_id_project_id_foreign');

    expect(fn () => DB::transaction(fn () => DB::table('task_comments')->insert([
        'id' => (string) Str::uuid7(),
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'task_id' => $this->task->id,
        'author_user_id' => $this->member->id,
        'body' => '   ',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'task_comments_body_check');
});
