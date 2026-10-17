<?php

require_once __DIR__.'/CollaborationHelpers.php';

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\TaskActivity;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// CORE-008: who may read and write comments, attachments and activity, for every team role ×
// visibility × explicit project role (API_SPEC.md §6.2, §6.3, §12, §17; BRD §7 "Comment in accessible
// project"; DATABASE.md §19; DECIDED-003), and non-disclosure of private-project collaboration on
// every endpoint. Acceptance scenarios AUTH-02, AUTH-04; DATABASE.md §16 DB-T01, DB-T02.

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->team = Team::factory()->create();
    $this->author = taskTeamMember($this->team, TeamRole::Member);
});

/**
 * [team role, visibility, project role, can read, can write, can moderate]
 */
dataset('collaboration matrix', [
    'Owner, team, none' => ['owner', 'team', null, true, true, true],
    'Owner, private, none' => ['owner', 'private', null, true, true, true],
    'Admin, team, none' => ['admin', 'team', null, true, true, false],
    'Admin, team, manager' => ['admin', 'team', 'manager', true, true, true],
    'Admin, private, none' => ['admin', 'private', null, false, false, false],
    'Admin, private, contributor' => ['admin', 'private', 'contributor', true, true, false],
    'Admin, private, manager' => ['admin', 'private', 'manager', true, true, true],
    'Member, team, none' => ['member', 'team', null, true, true, false],
    'Member, team, manager' => ['member', 'team', 'manager', true, true, true],
    'Member, private, none' => ['member', 'private', null, false, false, false],
    'Member, private, contributor' => ['member', 'private', 'contributor', true, true, false],
    'Member, private, manager' => ['member', 'private', 'manager', true, true, true],
    'Viewer, team, none' => ['viewer', 'team', null, true, false, false],
    'Viewer, team, manager' => ['viewer', 'team', 'manager', true, false, false],
    'Viewer, private, none' => ['viewer', 'private', null, false, false, false],
    'Viewer, private, contributor' => ['viewer', 'private', 'contributor', true, false, false],
]);

function collabMatrixActor(Team $team, Project $project, string $teamRole, ?string $projectRole): User
{
    $user = $teamRole === 'owner' ? $team->owner : taskTeamMember($team, TeamRole::from($teamRole));

    if ($projectRole !== null) {
        taskProjectMember($project, $user, ProjectRole::from($projectRole));
    }

    return $user;
}

it('enforces the collaboration permission matrix on every endpoint', function (string $teamRole, string $visibility, ?string $projectRole, bool $read, bool $write, bool $moderate) {
    $project = taskProject($this->team, $visibility);
    taskProjectMember($project, $this->author, ProjectRole::Contributor);
    $user = collabMatrixActor($this->team, $project, $teamRole, $projectRole);
    $task = taskIn($project);
    $comment = TaskComment::factory()->for($task)->by($this->author)->create(['body' => 'Confidential remark']);
    $attachment = TaskAttachment::factory()->for($task)->by($this->author)->withContent(collabFixture('pdf'), 'Confidential.pdf', 'application/pdf')->create();
    TaskActivity::query()->insert(['id' => (string) Str::uuid7(), 'team_id' => $this->team->id, 'project_id' => $project->id, 'task_id' => $task->id, 'action' => 'comment.created', 'changes' => '{}', 'source' => 'user', 'created_at' => now()]);

    $hidden = fn ($response) => $response->assertNotFound()->assertJsonPath('error.code', 'not_found')->assertDontSee('Confidential');
    $this->actingAs($user);

    foreach ([
        $this->getJson("/api/v1/tasks/{$task->id}/comments"),
        $this->getJson("/api/v1/tasks/{$task->id}/attachments"),
        $this->get("/api/v1/attachments/{$attachment->id}/download", ['Accept' => 'application/json']),
        $this->getJson("/api/v1/tasks/{$task->id}/activity"),
        $this->getJson("/api/v1/projects/{$project->id}/activity"),
    ] as $response) {
        $read ? $response->assertOk() : $hidden($response);
    }

    $denied = fn ($response) => $read ? $response->assertForbidden() : $hidden($response);

    // Writes.
    $post = $this->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'Mine']);
    $write ? $post->assertCreated() : $denied($post);
    $upload = collabUploadTo($user, $task, collabUpload(collabFixture('pdf'), 'mine.pdf'));
    $write ? $upload->assertCreated() : $denied($upload);

    // Someone else's comment: never editable; deletable only by a moderator.
    $denied($this->patchJson("/api/v1/tasks/{$task->id}/comments/{$comment->id}", ['body' => 'x']));
    $deleteComment = $this->deleteJson("/api/v1/tasks/{$task->id}/comments/{$comment->id}");
    $moderate ? $deleteComment->assertNoContent() : $denied($deleteComment);
    $deleteAttachment = $this->deleteJson("/api/v1/attachments/{$attachment->id}");
    $moderate ? $deleteAttachment->assertNoContent() : $denied($deleteAttachment);

    expect(TaskComment::query()->whereKey($comment->id)->exists())->toBe(! $moderate)
        ->and(TaskComment::query()->where('body', 'x')->exists())->toBeFalse()
        ->and(TaskComment::query()->where('author_user_id', $user->id)->count())->toBe($write ? 1 : 0)
        ->and(TaskAttachment::query()->live()->where('uploaded_by', $user->id)->count())->toBe($write ? 1 : 0);
})->with('collaboration matrix');

it('keeps private-project collaboration out of reach of guessed IDs from another team (DB-T01)', function () {
    $project = taskProject($this->team, 'private');
    $task = taskIn($project);
    $comment = TaskComment::factory()->for($task)->create();
    $attachment = TaskAttachment::factory()->for($task)->create();
    $outsider = Team::factory()->create()->owner;

    $this->actingAs($outsider);
    $this->getJson("/api/v1/tasks/{$task->id}/comments")->assertNotFound();
    $this->deleteJson("/api/v1/tasks/{$task->id}/comments/{$comment->id}")->assertNotFound();
    $this->get("/api/v1/attachments/{$attachment->id}/download", ['Accept' => 'application/json'])->assertNotFound();
    $this->deleteJson("/api/v1/attachments/{$attachment->id}")->assertNotFound();
    $this->getJson("/api/v1/projects/{$project->id}/activity")->assertNotFound();
    $this->getJson('/api/v1/me/activity')->assertOk()->assertJsonCount(0, 'data');
});
