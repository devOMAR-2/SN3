import { describe, expect, it } from 'vitest';
import { activeFilterCount, apiParams, emptyFilters, readQuery, writeQuery } from './taskFilters';

const label = '0192f3a0-1111-7000-8000-000000000001';
const user = '0192f3a0-1111-7000-8000-000000000002';
const status = '0192f3a0-1111-7000-8000-000000000003';

describe('task filters', () => {
  it('round-trips through the query string', () => {
    const filters = {
      ...emptyFilters(),
      q: 'login',
      assignee: user,
      priority: ['high' as const],
      label: [label],
      dueAfter: '2026-10-01',
      status: [status],
    };
    const query = writeQuery(filters, 'list', 'priority');

    expect(readQuery(`?${query}`)).toEqual({ filters, view: 'list', sort: 'priority' });
  });

  it('keeps status filters and sort out of the board URL', () => {
    const query = writeQuery({ ...emptyFilters(), status: [status] }, 'board', 'priority');

    expect(query).toBe('view=board');
  });

  it('drops malformed values instead of sending them', () => {
    const read = readQuery(
      '?view=calendar&sort=title&assignee=bob&priority=extreme&priority=low&label=x&due_after=yesterday&status=1',
    );

    expect(read.view).toBeNull();
    expect(read.sort).toBe('position');
    expect(read.filters).toEqual({ ...emptyFilters(), priority: ['low'] });
  });

  it('maps filters to the API parameters, with statuses only where asked', () => {
    const filters = {
      ...emptyFilters(),
      q: ' x ',
      assignee: user,
      priority: ['urgent' as const, 'none' as const],
      label: [label],
      dueBefore: '2026-12-31',
      status: [status],
    };

    expect(apiParams(filters, false)).toEqual({
      q: 'x',
      assignee_user_id: user,
      'priority[]': ['urgent', 'none'],
      'label_id[]': [label],
      due_before: '2026-12-31',
    });
    expect(apiParams(filters, true)['status_id[]']).toEqual([status]);
    expect(activeFilterCount(filters, false)).toBe(6);
    expect(activeFilterCount(filters, true)).toBe(7);
  });
});
