/**
 * Board moves (CORE-007, API_SPEC.md §6.1 `POST /tasks/{id}/move`): turns "put this task at index i of that
 * column" into the endpoint's anchor form. The anchor is the task that will follow it (`before_task_id`), or
 * none for the end of the column, which the server treats as "after the last task".
 */
export interface MoveBody {
  status_id: string;
  before_task_id: string | null;
}

/**
 * @param columnIds the target column's task IDs in board order, without the moving task
 * @param index where the task goes in that list (0 = top; `columnIds.length` or more = end)
 */
export function moveBody(statusId: string, columnIds: readonly string[], index: number): MoveBody {
  const at = Math.max(0, Math.min(index, columnIds.length));

  return { status_id: statusId, before_task_id: at < columnIds.length ? (columnIds[at] ?? null) : null };
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
