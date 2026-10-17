<?php

namespace App\Http\Controllers\Web;

use App\Domain\Notifications\TaskWatchers;
use App\Domain\Tasks\TaskAssignments;
use App\Http\Controllers\Controller;
use App\Http\Pages\Abilities;
use App\Http\Resources\ChecklistItemResource;
use App\Http\Resources\LabelResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectStatusResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\TeamResource;
use App\Models\Label;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/tasks/{task}`: task detail (CORE-006, ADR-026). The link uses the task's UUID, so renaming the
 * team or project never breaks it (DESIGN_SYSTEM.md §5.2). `{task}` resolves through AccessibleTasks:
 * a task of a hidden, trashed or foreign project is the same 404 as a random ID (AUTH-01). Edits go
 * to /api/v1 with `If-Match`.
 */
class TaskPageController extends Controller
{
    /** Subtasks listed on the page; more stay reachable through the project's task list. */
    public const SUBTASK_LIMIT = 100;

    /** Assignees offered by name (ADR-026 §5); a larger team shows the first ones and says so. */
    public const ASSIGNEE_LIMIT = 200;

    public function show(Request $request, Task $task): Response
    {
        /** @var User $user */
        $user = $request->user();
        $project = $task->project;
        $team = $project->team;

        Gate::forUser($user)->authorize('view', $project);

        // Bounded lists: 50 statuses (ADR-021 §4), 100 labels per project and 100 checklist items per
        // task (ADR-023 §6, §7).
        $statuses = $project->statuses()->orderBy('position')->orderBy('id')->get();
        $labels = $project->labels()->orderBy('name')->orderBy('id')->get();
        $checklist = $task->checklistItems()->orderBy('position')->orderBy('id')->get();

        // The parent and the children are tasks of the same, already visible project.
        $parent = $task->parent_task_id === null
            ? null
            : Task::query()->where('project_id', $project->id)->whereKey($task->parent_task_id)->first();
        $subtasks = Task::query()
            ->where('project_id', $project->id)
            ->where('parent_task_id', $task->id)
            ->orderBy('task_number')
            ->limit(self::SUBTASK_LIMIT)
            ->get();

        $projectCan = Abilities::project($user, $project);

        $assignees = TaskAssignments::eligibleUsers($project->id, self::ASSIGNEE_LIMIT + 1);
        $assigneesTruncated = count($assignees) > self::ASSIGNEE_LIMIT;
        $assignees = array_slice($assignees, 0, self::ASSIGNEE_LIMIT);

        // The current assignee is always listed, so the page shows who it is: past the cap, or no longer
        // eligible (a disabled account is not released from its tasks). An ineligible one is marked so
        // the picker shows them without offering them; the API would refuse them (ADR-023 §3).
        $assigneeId = $task->assignee_user_id;

        if ($assigneeId !== null && ! in_array($assigneeId, array_column($assignees, 'user_id'), true)) {
            $current = User::query()->whereKey($assigneeId)->first();

            if ($current instanceof User) {
                $assignees[] = [
                    'user_id' => $current->id,
                    'name' => $current->name,
                    'eligible' => TaskAssignments::eligible($project->id, $current->id),
                ];
            }
        }

        return Inertia::render('Tasks/Show', [
            'team' => TeamResource::make($team, $user),
            'project' => ProjectResource::make($project, $user),
            'task' => TaskResource::make($task, $project),
            'statuses' => $statuses->map(fn (ProjectStatus $status): array => ProjectStatusResource::make($status))->values()->all(),
            'labels' => $labels->map(fn (Label $label): array => LabelResource::make($label))->values()->all(),
            'checklist' => $checklist->map(fn (TaskChecklistItem $item): array => ChecklistItemResource::make($item))->values()->all(),
            'assignees' => $assignees,
            'assigneesTruncated' => $assigneesTruncated,
            'parent' => $parent === null ? null : $this->summary($parent, $project),
            'subtasks' => $subtasks->map(fn (Task $child): array => $this->summary($child, $project))->values()->all(),
            // CORE-009: the viewer's own watch (anyone who can see the task may watch it).
            'watching' => TaskWatchers::watching($task, $user),
            'can' => [
                'edit' => $projectCan['contribute'],
                'delete' => $projectCan['contribute'],
                'manage_labels' => $projectCan['manage_labels'],
            ],
            'teamCan' => Abilities::team($user, $team),
            'lock' => Abilities::lock($team, $project),
        ]);
    }

    /**
     * @return array{id: string, key: string, title: string, status_id: string}
     */
    private function summary(Task $task, Project $project): array
    {
        return [
            'id' => $task->id,
            'key' => $task->key($project),
            'title' => $task->title,
            'status_id' => $task->status_id,
        ];
    }
}
