<?php

namespace App\Domain\Workflows;

use Illuminate\Support\Facades\DB;

/**
 * Writes a project's status order as dense ranks `0..n-1` (ADR-021 §3). Callers hold the project
 * write lock (ProjectWriteLock), so no other writer can add, remove or move a status meanwhile,
 * and pass every remaining status ID of the project in the new order.
 *
 * All ranks change in one UPDATE. `UNIQUE(project_id, position)` is DEFERRABLE INITIALLY IMMEDIATE,
 * which PostgreSQL checks at the end of each statement rather than row by row, so swapping ranks
 * within the statement is allowed while the result is still checked at once.
 */
final class StatusOrder
{
    /**
     * @param  array<int, string>  $orderedIds
     */
    public static function write(string $projectId, array $orderedIds): void
    {
        if ($orderedIds === []) {
            return;
        }

        $values = [];
        $bindings = [];

        foreach (array_values($orderedIds) as $position => $id) {
            $values[] = '(?::uuid, ?::numeric)';
            $bindings[] = $id;
            $bindings[] = $position;
        }

        DB::update(
            'UPDATE project_statuses AS s SET position = v.position, updated_at = now() '
            .'FROM (VALUES '.implode(', ', $values).') AS v(id, position) '
            .'WHERE s.project_id = ? AND s.id = v.id AND s.position <> v.position',
            [...$bindings, $projectId],
        );
    }
}
