<?php

namespace App\Domain\Tasks;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A task's label set (API_SPEC.md §6.1 `label_ids`, ADR-023 §6). The request carries the whole set;
 * attaching and detaching are changes to it. Every label must belong to the task's project: a label
 * of another project is answered like a random ID (422 `exists`), and the composite keys of
 * `task_labels` refuse it in the database too (DB-T04).
 *
 * Callers hold the project write lock, which label deletion takes as well.
 */
final class TaskLabels
{
    /** At most this many labels on one task (ADR-023 §6). */
    public const MAX_PER_TASK = 20;

    /**
     * @param  list<string>  $labelIds  lower-case UUIDs, without duplicates
     *
     * @throws ValidationException
     */
    public static function assertInProject(Project $lockedProject, array $labelIds): void
    {
        if ($labelIds === []) {
            return;
        }

        if ($lockedProject->labels()->whereKey($labelIds)->count() !== count($labelIds)) {
            throw ValidationException::withMessages(['label_ids' => 'exists']);
        }
    }

    /**
     * Replaces the task's labels with the given set.
     *
     * @param  list<string>  $labelIds  lower-case UUIDs of labels of the task's project, without duplicates
     * @return bool whether the set changed
     */
    public static function sync(Task $task, array $labelIds): bool
    {
        /** @var list<string> $current */
        $current = DB::table('task_labels')->where('task_id', $task->id)->pluck('label_id')->all();

        $removed = array_values(array_diff($current, $labelIds));
        $added = array_values(array_diff($labelIds, $current));

        if ($removed !== []) {
            DB::table('task_labels')->where('task_id', $task->id)->whereIn('label_id', $removed)->delete();
        }

        if ($added !== []) {
            DB::table('task_labels')->insert(array_map(fn (string $labelId): array => [
                'task_id' => $task->id,
                'project_id' => $task->project_id,
                'label_id' => $labelId,
            ], $added));
        }

        return $removed !== [] || $added !== [];
    }
}
