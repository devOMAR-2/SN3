<?php

namespace App\Domain\Tasks;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Filters and sort orders of the task lists (API_SPEC.md §6.1, §1.4; ADR-023 §3). The caller's query
 * is already authorization-scoped (a `{project}` resolved by AccessibleProjects, or
 * AccessibleTasks::constrain()), so filters only ever narrow what the caller may see, and counts,
 * cursors and pages never include anything else (§1.1).
 *
 * Every order ends with `id`, so keyset pages are stable. `position` is board order: the status's
 * position, then the task's rank within its status. Priority and due-date orders use the generated
 * `priority_rank` and `due_sort` columns, so tasks without a due date sort last and keyset
 * pagination never compares NULLs. Cursors are bound to the sort (TaskController, MyTasksController),
 * because their keys only fit the order they were issued for.
 */
final class TaskQuery
{
    /**
     * @param  Builder<Task>  $query
     * @param  array{q?: string, status_id?: list<string>, assignee_user_id?: string, priority?: list<string>, label_id?: list<string>, due_after?: string, due_before?: string}  $filters
     * @return Builder<Task>
     */
    public static function filter(Builder $query, Project $project, array $filters): Builder
    {
        if (isset($filters['q']) && $filters['q'] !== '') {
            self::search($query, $project, $filters['q']);
        }

        if (isset($filters['status_id'])) {
            $query->whereIn('status_id', $filters['status_id']);
        }

        if (isset($filters['assignee_user_id'])) {
            $query->where('assignee_user_id', $filters['assignee_user_id']);
        }

        if (isset($filters['priority'])) {
            $query->whereIn('priority', $filters['priority']);
        }

        if (isset($filters['label_id'])) {
            $labelIds = $filters['label_id'];
            $query->whereExists(function (QueryBuilder $links) use ($labelIds): void {
                $links->selectRaw('1')->from('task_labels')->whereColumn('task_labels.task_id', 'tasks.id')->whereIn('task_labels.label_id', $labelIds);
            });
        }

        if (isset($filters['due_after'])) {
            $query->where('due_date', '>=', $filters['due_after']);
        }

        if (isset($filters['due_before'])) {
            $query->where('due_date', '<=', $filters['due_before']);
        }

        return $query;
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public static function sort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'updated_at' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            'due_date' => $query->orderBy('due_sort')->orderBy('id'),
            'priority' => $query->orderByDesc('priority_rank')->orderByDesc('created_at')->orderByDesc('id'),
            // Board order: the status column first, then the task's rank in it.
            'position' => $query
                ->join('project_statuses', 'project_statuses.id', '=', 'tasks.status_id')
                ->select('tasks.*')
                ->addSelect('project_statuses.position as status_position')
                ->orderBy('status_position')
                ->orderBy('tasks.position')
                ->orderBy('tasks.id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * Title contains the text, ignoring case (wildcards in it are literal), or the text is the task's
     * number or key: `104` or `WEB-104` (the key of this project only).
     *
     * @param  Builder<Task>  $query
     */
    private static function search(Builder $query, Project $project, string $q): void
    {
        $pattern = '%'.addcslashes($q, '\\%_').'%';
        $number = null;

        if (preg_match('/^(?:([A-Za-z][A-Za-z0-9]{1,9})-)?([1-9][0-9]{0,17})$/', $q, $matches) === 1
            && ($matches[1] === '' || strtoupper($matches[1]) === $project->project_key)) {
            $number = (int) $matches[2];
        }

        $query->where(function (Builder $match) use ($pattern, $number): void {
            $match->where('title', 'ilike', $pattern);

            if ($number !== null) {
                $match->orWhere('task_number', $number);
            }
        });
    }
}
