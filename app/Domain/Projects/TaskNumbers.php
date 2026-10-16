<?php

namespace App\Domain\Projects;

use App\Models\Project;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

/**
 * Allocates project task numbers and formats task keys (DATABASE.md §5, §11; API_SPEC.md §6.1;
 * FR-TASK-003; ADR-021 §5). A task key is `{project_key}-{task_number}`, e.g. `WEB-104`.
 *
 * CORE-005 creates a task like this, in one transaction:
 *
 *     DB::transaction(function () use ($project, $actor) {
 *         $locked = ProjectWriteLock::project($project, $actor, 'contribute');
 *         $number = TaskNumbers::allocate($locked);
 *         // insert the task with task_number = $number, in this same transaction
 *     });
 *
 * - The number comes from one `UPDATE … RETURNING` on the project row, never `MAX(task_number)+1`,
 *   so concurrent creates get distinct, increasing numbers (DB-T05). A rollback returns the number
 *   with everything else; numbers that were committed are never issued again (a trigger refuses
 *   to lower `next_task_number`).
 * - The first allocation freezes the project key (PROJ-01): UpdateProject answers 409, and the same
 *   trigger refuses the change in the database.
 */
final class TaskNumbers
{
    /**
     * Issues the next task number of a project the caller has locked with ProjectWriteLock (team,
     * then project), inside the transaction that inserts the task.
     *
     * @throws LogicException outside a transaction: the number would be committed without its task
     */
    public static function allocate(Project $lockedProject): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Allocate a task number inside the transaction that inserts the task.');
        }

        $row = DB::selectOne(
            'UPDATE projects SET next_task_number = next_task_number + 1 WHERE id = ? RETURNING next_task_number - 1 AS task_number',
            [$lockedProject->id],
        );

        $number = $row instanceof stdClass ? ($row->task_number ?? null) : null;

        if (! is_int($number) && ! (is_string($number) && ctype_digit($number))) {
            throw (new ModelNotFoundException)->setModel(Project::class, [$lockedProject->id]);
        }

        $number = (int) $number;

        $lockedProject->next_task_number = $number + 1;
        $lockedProject->syncOriginalAttribute('next_task_number');

        return $number;
    }

    public static function key(Project $project, int $taskNumber): string
    {
        return $project->project_key.'-'.$taskNumber;
    }
}
