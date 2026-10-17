<?php

namespace App\Domain\Tasks;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Task ranks within a status column (`tasks.position`, numeric(20,10), DATABASE.md §5). A new task, or
 * a task whose status changes through PATCH, goes to the end of its column. The move endpoint places a
 * task between two neighbours at the midpoint of their ranks, and renumbers the column when the gap
 * has run out (API_SPEC.md §6.1 `/move`; CORE-007, ADR-028 §2).
 *
 * Callers hold the project write lock, which every task writer takes first, so two writers cannot
 * compute the same rank. Trashed tasks keep their rank and count for appends and renumbering, so a
 * restored task does not tie; only live tasks are neighbours for a move.
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
     * The rank for `$taskId` in `$statusId`: just before `$beforeId`, just after `$afterId`, or at the
     * end of the column. Null when the anchor is not a live task of the project in that status.
     * Returns `$currentPosition` unchanged when the task already sits there (same status, between the
     * same neighbours). Renumbers the column first when the neighbours' gap is exhausted.
     */
    public static function place(string $projectId, string $statusId, string $taskId, ?string $beforeId, ?string $afterId, ?string $currentPosition): ?string
    {
        $neighbours = self::neighbours($projectId, $statusId, $taskId, $beforeId, $afterId);

        if ($neighbours === null) {
            return null;
        }

        [$previous, $next] = $neighbours;

        if ($currentPosition !== null && self::strictlyBetween($currentPosition, $previous, $next)) {
            return $currentPosition;
        }

        $rank = self::between($previous, $next);

        if ($rank === null) {
            self::renumber($projectId, $statusId, $taskId);
            $neighbours = self::neighbours($projectId, $statusId, $taskId, $beforeId, $afterId);
            $rank = $neighbours === null ? null : self::between($neighbours[0], $neighbours[1]);
        }

        return $rank;
    }

    /** Whether two ranks are the same number (`'3'` and `'3.0000000000'` are). */
    public static function sameRank(string $a, string $b): bool
    {
        $row = DB::selectOne('SELECT (?::numeric = ?::numeric) AS same', [$a, $b]);

        return $row instanceof stdClass && ($row->same ?? false) === true;
    }

    /**
     * The live neighbours (ranks as strings, null for an open end) between which the task goes.
     *
     * @return array{0: string|null, 1: string|null}|null
     */
    private static function neighbours(string $projectId, string $statusId, string $taskId, ?string $beforeId, ?string $afterId): ?array
    {
        $anchorId = $beforeId ?? $afterId;

        if ($anchorId === null) {
            // The end of the column: after every other task, trashed ones included (like an append).
            $row = DB::selectOne(
                'SELECT max(position)::text AS previous FROM tasks WHERE project_id = ? AND status_id = ? AND id <> ?',
                [$projectId, $statusId, $taskId],
            );
            $previous = $row instanceof stdClass && is_string($row->previous ?? null) ? $row->previous : null;

            return [$previous, null];
        }

        $anchor = DB::selectOne(
            'SELECT position::text AS position FROM tasks WHERE id = ? AND project_id = ? AND status_id = ? AND deleted_at IS NULL AND id <> ?',
            [$anchorId, $projectId, $statusId, $taskId],
        );

        if (! $anchor instanceof stdClass || ! is_string($anchor->position ?? null)) {
            return null;
        }

        // The live task on the other side of the anchor, in board order (position, then id).
        $other = $beforeId !== null
            ? DB::selectOne(
                'SELECT position::text AS position FROM tasks WHERE project_id = ? AND status_id = ? AND deleted_at IS NULL AND id <> ? '
                .'AND (position, id) < (?::numeric, ?::uuid) ORDER BY position DESC, id DESC LIMIT 1',
                [$projectId, $statusId, $taskId, $anchor->position, $anchorId],
            )
            : DB::selectOne(
                'SELECT position::text AS position FROM tasks WHERE project_id = ? AND status_id = ? AND deleted_at IS NULL AND id <> ? '
                .'AND (position, id) > (?::numeric, ?::uuid) ORDER BY position, id LIMIT 1',
                [$projectId, $statusId, $taskId, $anchor->position, $anchorId],
            );
        $otherPosition = $other instanceof stdClass && is_string($other->position ?? null) ? $other->position : null;

        return $beforeId !== null ? [$otherPosition, $anchor->position] : [$anchor->position, $otherPosition];
    }

    private static function strictlyBetween(string $rank, ?string $previous, ?string $next): bool
    {
        $row = DB::selectOne(
            'SELECT ((?::numeric IS NULL OR ?::numeric > ?::numeric) AND (?::numeric IS NULL OR ?::numeric < ?::numeric)) AS inside',
            [$previous, $rank, $previous, $next, $rank, $next],
        );

        return $row instanceof stdClass && ($row->inside ?? false) === true;
    }

    /**
     * A rank strictly between two ranks (an open end is one whole step away), or null when the
     * numeric(20,10) column cannot hold one.
     */
    private static function between(?string $previous, ?string $next): ?string
    {
        $row = DB::selectOne(
            'SELECT r::text AS rank, (r > COALESCE(?::numeric, r - 1) AND r < COALESCE(?::numeric, r + 1)) AS fits FROM ('
            .'SELECT round(CASE '
            .'WHEN ?::numeric IS NULL AND ?::numeric IS NULL THEN 0 '
            .'WHEN ?::numeric IS NULL THEN floor(?::numeric) - 1 '
            .'WHEN ?::numeric IS NULL THEN floor(?::numeric) + 1 '
            .'ELSE (?::numeric + ?::numeric) / 2 END, 10) AS r) AS candidate',
            [$previous, $next, $previous, $next, $previous, $next, $next, $previous, $previous, $next],
        );

        return $row instanceof stdClass && ($row->fits ?? false) === true && is_string($row->rank ?? null) ? $row->rank : null;
    }

    /**
     * Renumbers a column to whole ranks 0..n-1 in board order (trashed tasks included, the moving
     * task excluded), which reopens room between every pair. Ranks are not part of the task's
     * representation, so versions do not change.
     */
    private static function renumber(string $projectId, string $statusId, string $taskId): void
    {
        DB::update(
            'UPDATE tasks AS t SET position = s.rank FROM (SELECT id, row_number() OVER (ORDER BY position, id) - 1 AS rank '
            .'FROM tasks WHERE project_id = ? AND status_id = ? AND id <> ?) AS s WHERE t.id = s.id',
            [$projectId, $statusId, $taskId],
        );
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
