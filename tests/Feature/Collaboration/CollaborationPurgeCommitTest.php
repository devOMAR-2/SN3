<?php

require_once __DIR__.'/CollaborationHelpers.php';

use App\Domain\Activity\Activity;
use App\Domain\Attachments\AttachmentStorage;
use App\Domain\Trash\PurgeTrash;
use App\Models\TaskActivity;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// CORE-008 and the trash purge through real COMMITs (ADR-022 §5): comments, mentions, attachments
// (rows and files) and the append-only activity log of a purged team or project all go, the
// activity guard lets the cascade through, and nothing else is touched. Works on committed data and
// cleans up after itself, like TaskPurgeCommitTest.

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Storage::fake('attachments');
    $this->teams = [];
    $this->users = [];
});

afterEach(function () {
    $teamIds = array_map(fn (Team $team) => $team->id, $this->teams);
    $userIds = TeamMembership::query()->whereIn('team_id', $teamIds)->pluck('user_id')
        ->merge(array_map(fn (Team $team) => $team->owner_user_id, $this->teams))->merge($this->users)->unique()->all();

    DB::transaction(function () use ($teamIds): void {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('teams')->whereIn('id', $teamIds)->delete();
    });
    User::query()->whereKey($userIds)->delete();
});

function committedCollaborationTeam(object $test): array
{
    $team = Team::factory()->create();
    $test->teams[] = $team;
    $member = taskTeamMember($team);
    $test->users[] = $member->id;
    $project = taskProject($team);
    $task = taskIn($project, ['assignee_user_id' => $member->id]);

    $comment = TaskComment::factory()->for($task)->by($member)->create();
    DB::table('task_comment_mentions')->insert(['comment_id' => $comment->id, 'user_id' => $team->owner_user_id, 'created_at' => now()]);
    TaskAttachment::factory()->for($task)->by($member)->create();
    Activity::task($task, $member, 'comment.created', ['comment_id' => $comment->id]);
    Activity::project($project, $member, 'project.member_added', ['user_id' => $member->id, 'role' => 'contributor']);
    Activity::team($team->id, $team->owner, 'team.member_role_changed', ['user_id' => $member->id, 'from' => 'viewer', 'to' => 'member']);

    return ['team' => $team, 'project' => $project, 'member' => $member];
}

it('purges a trashed team with comments, mentions, attachments and activity through a real COMMIT', function () {
    ['team' => $team, 'project' => $project] = committedCollaborationTeam($this);
    ['team' => $survivor] = committedCollaborationTeam($this);
    $team->forceFill(['deleted_at' => now()->subDays(31)])->save();

    expect(DB::transactionLevel())->toBe(0)
        ->and(app(PurgeTrash::class)->handle())->toBe(['projects' => 0, 'teams' => 1, 'failed' => 0]);

    foreach (['task_comments', 'task_attachments', 'task_activities'] as $table) {
        expect(DB::table($table)->where('team_id', $team->id)->exists())->toBeFalse()
            ->and(DB::table($table)->where('team_id', $survivor->id)->exists())->toBeTrue();
    }

    expect(DB::table('task_comment_mentions')->count())->toBe(1)
        ->and(Storage::disk('attachments')->allFiles(app(AttachmentStorage::class)->teamPrefix($team->id)))->toBe([])
        ->and(Storage::disk('attachments')->allFiles(app(AttachmentStorage::class)->teamPrefix($survivor->id)))->toHaveCount(1);
});

it('purges a trashed project with its collaboration and keeps the team\'s own audit entries', function () {
    ['team' => $team, 'project' => $project] = committedCollaborationTeam($this);
    $project->forceFill(['deleted_at' => now()->subDays(31)])->save();

    expect(app(PurgeTrash::class)->handle())->toBe(['projects' => 1, 'teams' => 0, 'failed' => 0]);

    expect(TaskActivity::query()->where('team_id', $team->id)->pluck('action')->all())->toBe(['team.member_role_changed'])
        ->and(TaskComment::withTrashed()->where('project_id', $project->id)->exists())->toBeFalse()
        ->and(collabStoredFiles())->toBe([]);
});
