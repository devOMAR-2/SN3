<?php

namespace App\Domain\Activity;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\AccessibleProjects;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads the activity log (API_SPEC.md §4, §6.1, §9; CORE-008, ADR-027 §4). Every feed is
 * authorization-scoped before it is ordered and paginated, newest first (`created_at desc, id desc`):
 *
 * - **task feed:** the task's entries, for anyone who can see the task (the `{task}` binding);
 * - **project feed:** the project's own entries and those of its tasks, for anyone who can see the
 *   project (the `{project}` binding). Private-project entries therefore reach only its members and
 *   the Team Owner;
 * - **own feed** (`/me/activity`): the caller's own entries, and only those they may still see now:
 *   project and task entries of projects AccessibleProjects grants them, team entries of teams they
 *   still belong to. Activity in a private project they were removed from disappears, and no entry of
 *   a project they cannot see is ever counted or listed.
 *
 * There is no team-wide feed (API_SPEC.md defines none), so team and private-project entries never
 * reach anyone else through a broader list.
 */
final class ActivityFeed
{
    public function __construct(private readonly AccessibleProjects $projects) {}

    /**
     * @return Builder<TaskActivity>
     */
    public function forTask(Task $task): Builder
    {
        return self::ordered(TaskActivity::query()->where('task_id', $task->id));
    }

    /**
     * @return Builder<TaskActivity>
     */
    public function forProject(Project $project): Builder
    {
        return self::ordered(TaskActivity::query()->where('project_id', $project->id));
    }

    /**
     * @return Builder<TaskActivity>
     */
    public function forActor(User $user): Builder
    {
        $visibleProjects = $this->projects->constrain(Project::query(), $user)->select('projects.id');
        $teams = Team::query()->whereRelation('memberships', 'user_id', $user->id)->select('teams.id');

        return self::ordered(
            TaskActivity::query()
                ->where('actor_user_id', $user->id)
                ->where(function (Builder $visible) use ($visibleProjects, $teams): void {
                    $visible
                        ->whereIn('project_id', $visibleProjects)
                        ->orWhere(function (Builder $team) use ($teams): void {
                            $team->whereNull('project_id')->whereIn('team_id', $teams);
                        });
                }),
        );
    }

    /**
     * @param  Builder<TaskActivity>  $query
     * @return Builder<TaskActivity>
     */
    private static function ordered(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
