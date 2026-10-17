import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { emptyFilters } from '@/lib/taskFilters';
import { apiFailure, globalMocks, sharedProps } from '@/test/pageHarness';
import type { ProjectStatus, Task } from '@/types/domain';
import TaskBoard from './TaskBoard.vue';

const state = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
  usePage: () => ({ props: state.props, url: '/t/alpha/p/WEB' }),
  router: { visit: vi.fn(), reload: vi.fn(), on: () => () => undefined },
}));

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: http,
}));

const statuses: ProjectStatus[] = [
  {
    id: 's1',
    project_id: 'p1',
    name: 'To do',
    category: 'todo',
    color: null,
    position: 0,
    is_default: true,
    version: 1,
  },
  {
    id: 's2',
    project_id: 'p1',
    name: 'Done',
    category: 'done',
    color: null,
    position: 1,
    is_default: false,
    version: 1,
  },
];

function task(n: number, statusId = 's1'): Task {
  return {
    id: `task-${n}`,
    project_id: 'p1',
    key: `WEB-${n}`,
    number: n,
    title: `Task ${n}`,
    description: null,
    status_id: statusId,
    priority: 'none',
    assignee_user_id: null,
    due_date: null,
    label_ids: [],
    parent_task_id: null,
    created_by: 'o',
    version: 1,
    created_at: null,
    updated_at: null,
  };
}

/** The To do column holds three loaded tasks and more pages; Done is empty. */
function renderBoard(canWrite = true) {
  state.props = sharedProps('en');
  http.get.mockImplementation((_url: string, config: { params: Record<string, unknown> }) => {
    const status = (config.params['status_id[]'] as string[])[0];
    const data = status === 's1' ? [task(1), task(2), task(3)] : [];

    return Promise.resolve({
      data: { data, meta: { next_cursor: status === 's1' ? 'c' : null, has_more: status === 's1' } },
    });
  });

  return render(TaskBoard, {
    props: {
      projectId: 'p1',
      statuses,
      filters: emptyFilters(),
      labelsById: new Map(),
      assigneeNames: new Map(),
      canWrite,
    },
    global: { mocks: globalMocks },
  });
}

async function openMove(key: string) {
  await fireEvent.click(await screen.findByRole('button', { name: `Move ${key}…` }));

  return within(screen.getByRole('dialog', { hidden: true }));
}

function titles(): string[] {
  return screen.getAllByRole('link').map((link) => link.textContent?.trim() ?? '');
}

describe('Task board', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('offers no moves or quick add without write access', async () => {
    renderBoard(false);

    await screen.findByText('Task 1');
    expect(screen.queryByRole('button', { name: /^Move WEB-/ })).toBeNull();
    expect(screen.queryByLabelText('Add task to To do')).toBeNull();
  });

  it('moves a card up with the dialog, anchored before the card it now precedes, and keeps focus on it', async () => {
    renderBoard();
    http.post.mockResolvedValue({ data: { data: { ...task(2), version: 2 } } });

    const dialog = await openMove('WEB-2');
    await fireEvent.update(dialog.getByLabelText('Position'), 'up');
    await fireEvent.click(dialog.getByRole('button', { name: 'Move' }));

    expect(http.post).toHaveBeenCalledWith(
      '/tasks/task-2/move',
      { status_id: 's1', before_task_id: 'task-1' },
      { headers: { 'If-Match': '"1"' } },
    );
    expect(titles()).toEqual(['Task 2', 'Task 1', 'Task 3']);
    await waitFor(() => expect(screen.getByText('WEB-2 moved to To do, position 1.')).toBeTruthy());
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Move WEB-2…' }));
  });

  it('sends "Bottom" to the end of the whole column, past the pages not loaded yet', async () => {
    renderBoard();
    http.post.mockResolvedValue({ data: { data: { ...task(1), version: 2 } } });

    const dialog = await openMove('WEB-1');
    await fireEvent.update(dialog.getByLabelText('Position'), 'bottom');
    await fireEvent.click(dialog.getByRole('button', { name: 'Move' }));

    expect(http.post).toHaveBeenCalledWith(
      '/tasks/task-1/move',
      { status_id: 's1', before_task_id: null },
      expect.anything(),
    );
    // Not shown again until "Load more" reaches it, so it is never listed twice.
    expect(titles()).toEqual(['Task 2', 'Task 3']);
    await waitFor(() => expect(screen.getByText('WEB-1 moved to the bottom of To do.')).toBeTruthy());
  });

  it('puts the card back and refreshes the board when the task changed meanwhile', async () => {
    renderBoard();
    http.post.mockRejectedValue(apiFailure(412, 'precondition_failed'));

    const dialog = await openMove('WEB-3');
    await fireEvent.update(dialog.getByLabelText('Position'), 'top');
    const reads = http.get.mock.calls.length;
    await fireEvent.click(dialog.getByRole('button', { name: 'Move' }));

    await waitFor(() => expect(http.get.mock.calls.length).toBeGreaterThan(reads));
    await waitFor(() => expect(titles()).toEqual(['Task 1', 'Task 2', 'Task 3']));
    expect(
      screen.getAllByText('WEB-3 changed meanwhile. The board has been refreshed; try the move again.').length,
    ).toBeGreaterThan(0);
  });

  it('stops offering moves once the server says the project is read-only', async () => {
    const view = renderBoard();
    http.post.mockRejectedValue(apiFailure(423, 'locked'));

    const dialog = await openMove('WEB-1');
    await fireEvent.update(dialog.getByLabelText('Position'), 'bottom');
    await fireEvent.click(dialog.getByRole('button', { name: 'Move' }));

    await waitFor(() => expect(view.emitted('locked')).toHaveLength(1));
    expect(titles()).toEqual(['Task 1', 'Task 2', 'Task 3']);
  });
});
