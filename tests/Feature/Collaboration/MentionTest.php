<?php

require_once __DIR__.'/CollaborationHelpers.php';

use App\Domain\Comments\CommentMentions;
use App\Domain\Comments\Events\UsersMentioned;
use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// CORE-008: mentions in comments (API_SPEC.md §6.2 "mention IDs must be explicit server-validated
// eligible users, not arbitrary free-text matches"; FR-COL-004). Only users who can see the project
// are mentioned; everyone else stays plain text, with the same answer whether they exist or not.
// The UsersMentioned event is the hook CORE-009 notifies from. TEST-FR-COL-004 (mentions).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->author = taskTeamMember($this->team, TeamRole::Member);
    $this->colleague = taskTeamMember($this->team, TeamRole::Member);
    $this->viewer = taskTeamMember($this->team, TeamRole::Viewer);
    $this->admin = taskTeamMember($this->team, TeamRole::Admin);
    $this->outsider = Team::factory()->create()->owner;
});

function mentionPost(User $author, $task, string $body)
{
    return test()->actingAs($author)->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => $body]);
}

it('mentions team members of a team-visible project, Viewers included, and announces them after commit', function () {
    Event::fake([UsersMentioned::class]);
    $task = taskIn(taskProject($this->team));

    $response = mentionPost($this->author, $task, "Thanks @{$this->colleague->id}, @".strtoupper($this->viewer->id)." and @{$this->colleague->id} again.")
        ->assertCreated();

    $expected = collect([$this->colleague->id, $this->viewer->id])->sort()->values()->all();
    expect($response->json('data.mention_user_ids'))->toBe($expected)
        ->and(DB::table('task_comment_mentions')->where('comment_id', $response->json('data.id'))->orderBy('user_id')->pluck('user_id')->all())->toBe($expected);

    Event::assertDispatched(UsersMentioned::class, fn (UsersMentioned $event) => $event->commentId === $response->json('data.id')
        && $event->taskId === $task->id
        && $event->projectId === $task->project_id
        && $event->teamId === $this->team->id
        && $event->actorId === $this->author->id
        && collect($event->userIds)->sort()->values()->all() === $expected);
});

it('treats everyone who cannot see the project as plain text, with an identical answer', function () {
    $private = taskProject($this->team, 'private');
    taskProjectMember($private, $this->author, ProjectRole::Contributor);
    $insider = taskTeamMember($this->team, TeamRole::Member);
    taskProjectMember($private, $insider, ProjectRole::Contributor);
    $disabled = taskTeamMember($this->team, TeamRole::Member);
    taskProjectMember($private, $disabled, ProjectRole::Contributor);
    $disabled->forceFill(['disabled_at' => now()])->save();
    $task = taskIn($private);

    // A non-member Admin, another team's user, a disabled account and a random ID: none is mentioned.
    $candidates = [$this->admin->id, $this->outsider->id, $disabled->id, (string) Str::uuid7()];
    $answers = [];

    foreach ($candidates as $id) {
        $answers[] = mentionPost($this->author, $task, "Hello @{$id}")->assertCreated()->json('data.mention_user_ids');
    }

    expect($answers)->toBe([[], [], [], []])
        ->and(DB::table('task_comment_mentions')->count())->toBe(0);

    // Explicit members and the Team Owner (implicit access) are mentioned.
    mentionPost($this->author, $task, "@{$insider->id} @{$this->owner->id}")
        ->assertCreated()
        ->assertJsonPath('data.mention_user_ids', collect([$insider->id, $this->owner->id])->sort()->values()->all());
});

it('never matches names or e-mail-like text, and bounds the candidates', function () {
    $task = taskIn(taskProject($this->team));
    $id = $this->colleague->id;

    expect(CommentMentions::candidates("@Sara and @{$this->colleague->name}"))->toBe([])
        ->and(CommentMentions::candidates("mail me at user@{$id}"))->toBe([])
        ->and(CommentMentions::candidates("@{$id}-suffix and @{$id}x"))->toBe([])
        ->and(CommentMentions::candidates("(@{$id}), «@{$id}»"))->toBe([$id])
        ->and(CommentMentions::candidates('@'.substr($id, 0, 35)))->toBe([]);

    // At most 50 distinct candidates per comment (ADR-027 §2).
    $many = collect(range(1, 60))->map(fn () => '@'.Str::uuid7())->implode(' ');
    expect(CommentMentions::candidates($many))->toHaveCount(50)
        ->and(CommentMentions::MAX)->toBe(50);

    mentionPost($this->author, $task, "@{$this->colleague->name} mail user@{$id}")
        ->assertCreated()
        ->assertJsonPath('data.mention_user_ids', []);
});

it('re-resolves mentions on edit: removed ones go, and only new ones are announced', function () {
    $task = taskIn(taskProject($this->team));
    $third = taskTeamMember($this->team, TeamRole::Member);
    $id = mentionPost($this->author, $task, "@{$this->colleague->id} @{$this->viewer->id}")->assertCreated()->json('data.id');

    Event::fake([UsersMentioned::class]);

    $this->actingAs($this->author)->patchJson("/api/v1/tasks/{$task->id}/comments/{$id}", ['body' => "@{$this->viewer->id} @{$third->id}"])
        ->assertOk()
        ->assertJsonPath('data.mention_user_ids', collect([$this->viewer->id, $third->id])->sort()->values()->all());

    Event::assertDispatched(UsersMentioned::class, fn (UsersMentioned $event) => $event->userIds === [$third->id]);
    Event::assertDispatchedTimes(UsersMentioned::class, 1);

    // A comment without new mentions announces nothing.
    $this->actingAs($this->author)->patchJson("/api/v1/tasks/{$task->id}/comments/{$id}", ['body' => "Only @{$third->id}"])->assertOk();
    Event::assertDispatchedTimes(UsersMentioned::class, 1);
});

it('removes the mentions of a deleted comment, so nobody is notified from it', function () {
    $task = taskIn(taskProject($this->team));
    $id = mentionPost($this->author, $task, "@{$this->colleague->id}")->assertCreated()->json('data.id');

    $this->actingAs($this->author)->deleteJson("/api/v1/tasks/{$task->id}/comments/{$id}")->assertNoContent();

    expect(DB::table('task_comment_mentions')->count())->toBe(0)
        ->and(TaskComment::onlyTrashed()->whereKey($id)->exists())->toBeTrue();
});

it('announces nothing for a comment that is refused', function () {
    Event::fake([UsersMentioned::class]);
    $project = taskProject($this->team);
    $task = taskIn($project);
    $project->forceFill(['archived_at' => now()])->save();

    mentionPost($this->author, $task, "@{$this->colleague->id}")->assertStatus(423);

    Event::assertNotDispatched(UsersMentioned::class);
    expect(DB::table('task_comment_mentions')->count())->toBe(0);
});
