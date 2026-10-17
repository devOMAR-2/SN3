<?php

require_once __DIR__.'/CollaborationHelpers.php';

use App\Domain\Attachments\Actions\DeleteAttachment;
use App\Domain\Attachments\Actions\UploadAttachment;
use App\Domain\Comments\Actions\CreateComment;
use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Comments\Actions\UpdateComment;
use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\TaskActivity;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\Team;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Tenancy\AccessibleAttachments;
use App\Support\Tenancy\AccessibleTasks;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// CORE-008: every comment and attachment writer re-checks the actor on the locked rows (ADR-020 §7,
// ADR-023 §4): the request was authorised, then the actor's role changed while it waited for the
// lock. Still seeing the project but without the right is a 409; no longer seeing it is a 404.
// Nothing is written, no file is left behind, and no activity is recorded either way.

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->team = Team::factory()->create();
    $this->member = taskTeamMember($this->team, TeamRole::Member);
    $this->project = taskProject($this->team, 'private');
    taskProjectMember($this->project, $this->member, ProjectRole::Manager);
    $this->task = taskIn($this->project);
    $this->own = TaskComment::factory()->for($this->task)->by($this->member)->create(['body' => 'Kept']);
    $this->others = TaskComment::factory()->for($this->task)->create(['body' => 'Kept too']);
    $this->ownFile = TaskAttachment::factory()->for($this->task)->by($this->member)->create();
    $this->othersFile = TaskAttachment::factory()->for($this->task)->create();
    $this->upload = tempnam(sys_get_temp_dir(), 'sn3-recheck');
    file_put_contents($this->upload, collabFixture('text'));

    // What the route bindings resolved before the change.
    $this->resolvedTask = app(AccessibleTasks::class)->findOrFail($this->member, $this->task->id);
    $this->resolvedOwnFile = app(AccessibleAttachments::class)->findOrFail($this->member, $this->ownFile->id);
    $this->resolvedOthersFile = app(AccessibleAttachments::class)->findOrFail($this->member, $this->othersFile->id);
});

afterEach(function () {
    @unlink($this->upload);
});

$writers = [
    'add comment' => [fn () => app(CreateComment::class)->handle(test()->resolvedTask, test()->member, 'Late')],
    'edit own comment' => [fn () => app(UpdateComment::class)->handle(test()->resolvedTask, test()->own, test()->member, 'Late')],
    'delete own comment' => [fn () => app(DeleteComment::class)->handle(test()->resolvedTask, test()->own, test()->member)],
    'moderate a comment' => [fn () => app(DeleteComment::class)->handle(test()->resolvedTask, test()->others, test()->member)],
    'upload' => [fn () => app(UploadAttachment::class)->handle(test()->resolvedTask, test()->member, test()->upload, 'late.txt', 'text/plain')],
    'delete own attachment' => [fn () => app(DeleteAttachment::class)->handle(test()->resolvedOwnFile, test()->member)],
    'moderate an attachment' => [fn () => app(DeleteAttachment::class)->handle(test()->resolvedOthersFile, test()->member)],
];

function collabRecheckNothingChanged(object $test): void
{
    expect(TaskComment::query()->pluck('body')->sort()->values()->all())->toBe(['Kept', 'Kept too'])
        ->and(TaskAttachment::query()->live()->count())->toBe(2)
        ->and(collabStoredFiles())->toHaveCount(2)
        ->and(TaskActivity::query()->count())->toBe(0);
}

it('answers 409 when the actor was demoted to Team Viewer while waiting', function (Closure $write) {
    DB::table('team_memberships')->where('team_id', $this->team->id)->where('user_id', $this->member->id)->update(['role' => TeamRole::Viewer->value]);

    expect($write)->toThrow(DomainConflictException::class);
    collabRecheckNothingChanged($this);
})->with($writers);

it('answers 404 when the actor lost access to the private project while waiting', function (Closure $write) {
    DB::table('project_memberships')->where('project_id', $this->project->id)->where('user_id', $this->member->id)->delete();

    expect($write)->toThrow(ModelNotFoundException::class);
    collabRecheckNothingChanged($this);
})->with($writers);

it('answers 409 for moderation when the actor lost only the manager role', function (Closure $write) {
    DB::table('project_memberships')->where('project_id', $this->project->id)->where('user_id', $this->member->id)->update(['project_role' => ProjectRole::Contributor->value]);

    expect($write)->toThrow(DomainConflictException::class);
    collabRecheckNothingChanged($this);
})->with([
    'moderate a comment' => [fn () => app(DeleteComment::class)->handle(test()->resolvedTask, test()->others, test()->member)],
    'moderate an attachment' => [fn () => app(DeleteAttachment::class)->handle(test()->resolvedOthersFile, test()->member)],
]);
