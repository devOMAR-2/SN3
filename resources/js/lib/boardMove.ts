/**
 * Board moves (CORE-007, API_SPEC.md §6.1 `POST /tasks/{id}/move`): turns "put this task at index i of that
 * column" into the endpoint's anchor form. The anchor is the task that will follow it (`before_task_id`); past
 * the last loaded card of a column that has more pages, it is the last loaded card (`after_task_id`), since
 * the tasks not loaded yet come after it; no anchor is the end of the whole column.
 */
export type MoveBody =
  { status_id: string; before_task_id: string | null } | { status_id: string; after_task_id: string };

/**
 * @param columnIds the target column's loaded task IDs in board order, without the moving task
 * @param index where the task goes in that list (0 = top; `columnIds.length` or more = after the last one)
 * @param hasMore whether the column has tasks beyond the loaded ones
 */
export function moveBody(statusId: string, columnIds: readonly string[], index: number, hasMore = false): MoveBody {
  const at = Math.max(0, Math.min(index, columnIds.length));
  const last = columnIds[columnIds.length - 1];

  if (at < columnIds.length) {
    return { status_id: statusId, before_task_id: columnIds[at] ?? null };
  }

  return hasMore && last !== undefined
    ? { status_id: statusId, after_task_id: last }
    : { status_id: statusId, before_task_id: null };
}

/** The end of the whole column, loaded or not ("Bottom of the column"). */
export function endOfColumn(statusId: string): MoveBody {
  return { status_id: statusId, before_task_id: null };
}

/**
 * The index a pointer at `pointerY` drops at, given the vertical midpoints of the column's cards (other
 * than the dragged one), top to bottom.
 */
export function dropIndex(midpoints: readonly number[], pointerY: number): number {
  const index = midpoints.findIndex((middle) => pointerY < middle);

  return index === -1 ? midpoints.length : index;
}

/** Moves `id` to `index` within a list (used for optimistic updates). */
export function placeAt<T extends { id: string }>(items: readonly T[], item: T, index: number): T[] {
  const without = items.filter((other) => other.id !== item.id);
  const at = Math.max(0, Math.min(index, without.length));

  return [...without.slice(0, at), item, ...without.slice(at)];
}
