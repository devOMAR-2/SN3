<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Domain\Trash\PurgeTrash;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// CORE-005 and the trash purge through real COMMITs (ADR-022 §5, ADR-023 §2). The tasks' keys to
// statuses, parents and team memberships are DEFERRABLE NO ACTION, checked at COMMIT, which
// RefreshDatabase never reaches; so this file works on committed data and cleans up after itself,
// like PurgeCommitTest.

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

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

/**
 * A committed team with a project full of tasks: assigned, labelled, nested, with checklists, one in
 * the trash.
 *
 * @return array{team: Team, project: Project}
 */
function committedTaskTeam(object $test): array
{
    $team = Team::factory()->create();
    $test->teams[] = $team;
    $member = taskTeamMember($team);
    // Tracked here: once the team is purged, its memberships no longer name its users.
    $test->users[] = $member->id;
    $project = taskProject($team);
    $label = Label::factory()->for($project)->create();

    $parent = taskIn($project, ['assignee_user_id' => $member->id]);
    $child = Task::factory()->childOf($parent)->create(['assignee_user_id' => $team->owner_user_id]);
    DB::table('task_labels')->insert(['task_id' => $child->id, 'project_id' => $project->id, 'label_id' => $label->id]);
    TaskChecklistItem::factory()->for($child)->create();
    TaskChecklistItem::factory()->for($child)->create();
    taskIn($project, ['assignee_user_id' => $member->id])->delete();

    return ['team' => $team, 'project' => $project];
}

it('purges a trashed team with assigned, labelled and nested tasks through a real COMMIT', function () {
    ['team' => $team, 'project' => $project] = committedTaskTeam($this);
    $team->forceFill(['deleted_at' => now()->subDays(31)])->save();

    expect(DB::transactionLevel())->toBe(0)
        ->and(app(PurgeTrash::class)->handle())->toBe(['projects' => 0, 'teams' => 1, 'failed' => 0]);

    foreach (['tasks', 'labels', 'project_statuses'] as $table) {
        expect(DB::table($table)->where('project_id', $project->id)->exists())->toBeFalse();
    }

    expect(DB::table('task_labels')->where('project_id', $project->id)->exists())->toBeFalse()
        ->and(TaskChecklistItem::query()->count())->toBe(0);
});

it('purges a trashed project with its tasks and leaves the team and its other projects alone', function () {
    ['team' => $team, 'project' => $project] = committedTaskTeam($this);
    $kept = taskIn(taskProject($team));
    $project->forceFill(['deleted_at' => now()->subDays(31)])->save();

    expect(app(PurgeTrash::class)->handle())->toBe(['projects' => 1, 'teams' => 0, 'failed' => 0]);

    expect(Task::withTrashed()->where('project_id', $project->id)->exists())->toBeFalse()
        ->and(Task::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(Team::query()->whereKey($team->id)->exists())->toBeTrue();
});
