import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiFailure, globalMocks, sharedProps } from '@/test/pageHarness';
import type { Project, ProjectStatus, Task, TaskAbilities, Team, TeamAbilities } from '@/types/domain';
import Show from './Show.vue';

const state = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
const http = vi.hoisted(() => ({
  get: vi.fn(),
  patch: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
  put: vi.fn(),
  request: vi.fn(),
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
  usePage: () => ({ props: state.props, url: '/tasks/task-1' }),
  router: { visit: vi.fn(), reload: vi.fn(), on: () => () => undefined },
}));

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: http,
}));

const team = {
  id: 'team-1',
  name: 'Alpha',
  slug: 'team-alpha',
  description: null,
  owner_user_id: 'o',
  role: 'member',
  archived_at: null,
  created_at: null,
  updated_at: null,
} as Team;
const project = {
  id: 'p1',
  team_id: 'team-1',
  name: 'Website',
  key: 'WEB',
  description: null,
  visibility: 'team',
  role: null,
  created_by: 'o',
  version: 1,
  archived_at: null,
  created_at: null,
  updated_at: null,
} as Project;
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
const task: Task = {
  id: 'task-1',
  project_id: 'p1',
  key: 'WEB-7',
  number: 7,
  title: 'Fix login',
  description: 'Line one\nLine two',
  status_id: 's1',
  priority: 'none',
  assignee_user_id: null,
  due_date: null,
  label_ids: [],
  parent_task_id: null,
  created_by: 'o',
  version: 2,
  created_at: null,
  updated_at: '2026-10-17T10:00:00Z',
};
const teamCan = { view_settings: false } as TeamAbilities;

function renderTask(can: TaskAbilities, locale: 'ar' | 'en' = 'en') {
  state.props = sharedProps(locale);
  // The sidebar's project list.
  http.get.mockResolvedValue({ data: { data: [], meta: { next_cursor: null, has_more: false } } });

  return render(Show, {
    props: {
      team,
      project,
      task,
      statuses,
      labels: [],
      checklist: [],
      assignees: [],
      assigneesTruncated: false,
      parent: null,
      subtasks: [],
      can,
      teamCan,
      lock: null,
    },
    global: { mocks: globalMocks },
  });
}

describe('Task detail', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('is read-only without the edit ability', () => {
    renderTask({ edit: false, delete: false, manage_labels: false });

    expect(screen.getByRole('heading', { level: 1, name: 'Fix login' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Edit title' })).toBeNull();
    expect(screen.queryByRole('combobox', { name: 'Status' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete task' })).toBeNull();
    expect(screen.getByText('Unassigned')).toBeTruthy();
    // The key is an LTR technical identifier.
    for (const key of screen.getAllByText('WEB-7')) {
      expect(key.closest('[dir="ltr"]')).not.toBeNull();
    }
  });

  it('saves a field with If-Match and shows the new state', async () => {
    http.patch.mockResolvedValue({ data: { data: { ...task, priority: 'high', version: 3 } } });
    renderTask({ edit: true, delete: true, manage_labels: false });

    await fireEvent.update(screen.getByRole('combobox', { name: 'Priority' }), 'high');

    await waitFor(() => expect(screen.getAllByText('All changes saved.').length).toBeGreaterThan(0));
    expect(http.patch).toHaveBeenCalledWith('/tasks/task-1', { priority: 'high' }, { headers: { 'If-Match': '"2"' } });
  });

  it('on 412 shows the reload prompt, rolls the field back and keeps the title draft', async () => {
    http.patch.mockRejectedValue(apiFailure(412, 'version_mismatch'));
    renderTask({ edit: true, delete: true, manage_labels: false });

    await fireEvent.click(screen.getByRole('button', { name: 'Edit title' }));
    await fireEvent.update(screen.getByLabelText(/Title/), 'My unsaved title');
    await fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(screen.getByText('Changed by someone else')).toBeTruthy());
    expect((screen.getByLabelText(/Title/) as HTMLInputElement).value).toBe('My unsaved title');

    await fireEvent.update(screen.getByRole('combobox', { name: 'Priority' }), 'urgent');
    await waitFor(() =>
      expect((screen.getByRole('combobox', { name: 'Priority' }) as HTMLSelectElement).value).toBe('none'),
    );

    http.get.mockResolvedValue({ data: { data: { ...task, title: 'Their title', version: 5 } } });
    await fireEvent.click(screen.getByRole('button', { name: 'Reload' }));

    await waitFor(() => expect(screen.queryByText('Changed by someone else')).toBeNull());
    expect((screen.getByLabelText(/Title/) as HTMLInputElement).value).toBe('My unsaved title');
  });

  it('on 423 shows the read-only banner and hides the editors', async () => {
    http.patch.mockRejectedValue(apiFailure(423, 'resource_locked'));
    renderTask({ edit: true, delete: true, manage_labels: false });

    await fireEvent.update(screen.getByRole('combobox', { name: 'Status' }), 's2');

    await waitFor(() => expect(screen.getByText('This is read-only now')).toBeTruthy());
    expect(screen.queryByRole('combobox', { name: 'Status' })).toBeNull();
  });

  it('renders in Arabic, right to left, with the description as plain text', () => {
    renderTask({ edit: true, delete: true, manage_labels: false }, 'ar');

    expect(screen.getByRole('combobox', { name: 'الحالة' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'تعديل العنوان' })).toBeTruthy();
    const description = screen.getByText(/Line one/);
    expect(description.innerHTML).not.toContain('<br');
    expect(description.textContent).toBe('Line one\nLine two');
  });

  it('marks where comments and activity (CORE-008) plug in', () => {
    const { container } = renderTask({ edit: false, delete: false, manage_labels: false });

    expect(container.querySelector('[data-extension-point="task-collaboration"]')).not.toBeNull();
  });
});
