/**
 * Board and List filters (CORE-007, DESIGN_SYSTEM.md §5.3 "filtering state is URL-serializable"; API_SPEC.md
 * §6.1). The state lives in the page's query string, so a filtered view can be bookmarked or shared; the
 * server applies every filter inside the caller's access, so a shared link never shows more than the
 * recipient may see.
 */
import type { Priority } from '@/types/domain';

export const priorities: readonly Priority[] = ['urgent', 'high', 'medium', 'low', 'none'];
export const listSorts = ['position', 'created_at', 'updated_at', 'due_date', 'priority'] as const;
export type ListSort = (typeof listSorts)[number];
export type ProjectView = 'board' | 'list';

export interface TaskFilters {
  q: string;
  /** A user ID, `none` for unassigned tasks is not an API filter, so only IDs. Empty: anyone. */
  assignee: string;
  priority: Priority[];
  label: string[];
  dueAfter: string;
  dueBefore: string;
  /** List view only: the board shows every status as a column. */
  status: string[];
}

export function emptyFilters(): TaskFilters {
  return { q: '', assignee: '', priority: [], label: [], dueAfter: '', dueBefore: '', status: [] };
}

/** API limits (ListTasksRequest): at most 20 labels and 50 statuses per request. */
export const MAX_LABELS = 20;
const MAX_STATUSES = 50;
const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/** A real calendar date from 1000-01-01 on, as the API accepts (`date_format:Y-m-d`). */
export function isDate(value: string): boolean {
  const match = DATE.exec(value);

  if (match === null) {
    return false;
  }

  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
  const date = new Date(Date.UTC(year, month - 1, day));

  return (
    year >= 1000 && date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day
  );
}
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function ids(values: string[]): string[] {
  return [...new Set(values.filter((value) => UUID.test(value)))];
}

/** Reads the filters, view and sort from a query string; anything malformed is dropped, not sent. */
export function readQuery(search: string): { filters: TaskFilters; view: ProjectView | null; sort: ListSort } {
  const query = new URLSearchParams(search);
  const priority = query
    .getAll('priority')
    .filter((value): value is Priority => (priorities as string[]).includes(value));
  const view = query.get('view');
  const sort = query.get('sort');
  const dueAfter = isDate(query.get('due_after') ?? '') ? (query.get('due_after') as string) : '';
  const dueBefore = isDate(query.get('due_before') ?? '') ? (query.get('due_before') as string) : '';

  return {
    filters: {
      q: (query.get('q') ?? '').slice(0, 200),
      assignee: ids([query.get('assignee') ?? ''])[0] ?? '',
      priority: [...new Set(priority)],
      label: ids(query.getAll('label')).slice(0, MAX_LABELS),
      dueAfter,
      // An empty range is refused by the API (`after_or_equal`): keep the start, drop the end.
      dueBefore: dueAfter !== '' && dueBefore !== '' && dueBefore < dueAfter ? '' : dueBefore,
      status: ids(query.getAll('status')).slice(0, MAX_STATUSES),
    },
    view: view === 'board' || view === 'list' ? view : null,
    sort: (listSorts as readonly string[]).includes(sort ?? '') ? (sort as ListSort) : 'position',
  };
}

/** The query string for the current state (only non-default values), without the leading `?`. */
export function writeQuery(filters: TaskFilters, view: ProjectView, sort: ListSort): string {
  const query = new URLSearchParams();
  query.set('view', view);

  if (view === 'list' && sort !== 'position') {
    query.set('sort', sort);
  }

  if (filters.q.trim() !== '') {
    query.set('q', filters.q.trim());
  }

  if (filters.assignee !== '') {
    query.set('assignee', filters.assignee);
  }

  filters.priority.forEach((value) => query.append('priority', value));
  filters.label.forEach((value) => query.append('label', value));

  if (filters.dueAfter !== '') {
    query.set('due_after', filters.dueAfter);
  }

  if (filters.dueBefore !== '') {
    query.set('due_before', filters.dueBefore);
  }

  if (view === 'list') {
    filters.status.forEach((value) => query.append('status', value));
  }

  return query.toString();
}

/** The `GET /projects/{id}/tasks` parameters for these filters (API_SPEC.md §6.1). */
export function apiParams(filters: TaskFilters, withStatus: boolean): Record<string, string | string[]> {
  const params: Record<string, string | string[]> = {};

  if (filters.q.trim() !== '') {
    params.q = filters.q.trim();
  }

  if (filters.assignee !== '') {
    params.assignee_user_id = filters.assignee;
  }

  if (filters.priority.length > 0) {
    params['priority[]'] = filters.priority;
  }

  if (filters.label.length > 0) {
    params['label_id[]'] = filters.label;
  }

  if (filters.dueAfter !== '') {
    params.due_after = filters.dueAfter;
  }

  // An end before the start would be a 422 for every column; it is left out until the range is valid.
  if (filters.dueBefore !== '' && (filters.dueAfter === '' || filters.dueBefore >= filters.dueAfter)) {
    params.due_before = filters.dueBefore;
  }

  if (withStatus && filters.status.length > 0) {
    params['status_id[]'] = filters.status;
  }

  return params;
}

export function activeFilterCount(filters: TaskFilters, withStatus: boolean): number {
  return (
    (filters.q.trim() !== '' ? 1 : 0) +
    (filters.assignee !== '' ? 1 : 0) +
    filters.priority.length +
    filters.label.length +
    (filters.dueAfter !== '' ? 1 : 0) +
    (filters.dueBefore !== '' ? 1 : 0) +
    (withStatus ? filters.status.length : 0)
  );
}
