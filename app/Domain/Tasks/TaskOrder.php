<?php

namespace App\Domain\Tasks;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Task ranks within a status column (`tasks.position`, DATABASE.md §5). CORE-005 only appends: a new
 * task, or a task whose status changes, goes to the end of its column. Moving between anchors and
 * rebalancing is the move endpoint's job (API_SPEC.md §6.1 `/move`, CORE-007).
 *
 * Callers hold the project write lock, which every task writer takes first, so two appends cannot
 * compute the same rank. Trashed tasks keep their rank and count, so a restored task does not tie.
 */
final class TaskOrder
{
    /**
     * The rank after the last task of the status (0 for an empty column), as a decimal string.
     */
    public static function next(string $projectId, string $statusId): string
    {
        $row = DB::selectOne(
            'SELECT COALESCE(floor(max(position)) + 1, 0)::text AS position FROM tasks WHERE project_id = ? AND status_id = ?',
            [$projectId, $statusId],
        );

        return $row instanceof stdClass && is_string($row->position ?? null) ? $row->position : '0';
    }

    /**
     * Moves every task of one status (trashed ones included) to the end of another status of the same
     * project, keeping their relative order, and increments their versions (the status is part of
     * the task's representation). Used when a status is deleted with a replacement (WF-01).
     *
     * @return int how many tasks moved
     */
    public static function moveAll(string $projectId, string $fromStatusId, string $toStatusId): int
    {
        $base = self::next($projectId, $toStatusId);

        return DB::update(
            'UPDATE tasks AS t SET status_id = ?, position = ?::numeric + s.rank - 1, version = t.version + 1, updated_at = now() '
            .'FROM (SELECT id, row_number() OVER (ORDER BY position, id) AS rank FROM tasks WHERE project_id = ? AND status_id = ?) AS s '
            .'WHERE t.id = s.id',
            [$toStatusId, $base, $projectId, $fromStatusId],
        );
    }
}
