import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiFailure, globalMocks, sharedProps } from '@/test/pageHarness';
import type { InboxNotification } from '@/types/domain';
import Index from './Index.vue';

const state = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
const http = vi.hoisted(() => ({
  get: vi.fn(),
  patch: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
  put: vi.fn(),
  request: vi.fn(),
}));
const visit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
  usePage: () => ({ props: state.props, url: '/inbox' }),
  router: { visit, reload: vi.fn(), on: () => () => undefined },
}));

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: http,
}));

function notification(overrides: Partial<InboxNotification>): InboxNotification {
  return {
    id: 'n1',
    type: 'task.assigned',
    team: { id: 'team-1', name: 'Alpha' },
    project: { id: 'p1', key: 'WEB', name: 'Website' },
    task: { id: 'task-1', key: 'WEB-7', title: 'Fix login' },
    comment_id: null,
    actor: { id: 'u2', name: 'Sara' },
    fields: [],
    event_count: 1,
    invitation: null,
    read_at: null,
    created_at: '2026-10-17T10:00:00Z',
    updated_at: '2026-10-17T10:00:00Z',
    ...overrides,
  };
}

function page(items: InboxNotification[]) {
  return { data: { data: items, meta: { next_cursor: null, has_more: false, limit: 25, request_id: 'r' } } };
}

const items = [
  notification({}),
  notification({ id: 'n2', type: 'task.updated', fields: ['priority', 'status_id'], event_count: 3, actor: null }),
  notification({
    id: 'n3',
    type: 'team.invitation',
    team: { id: 'team-2', name: 'Beta' },
    project: null,
    task: null,
    invitation: { role: 'member', expires_at: '2026-10-24T10:00:00Z' },
    read_at: '2026-10-17T11:00:00Z',
  }),
];

function renderInbox(locale: 'ar' | 'en' = 'en', unread = 2) {
  state.props = { ...sharedProps(locale), inbox: { unread_count: unread } };
  http.get.mockImplementation((url: string) => {
    if (url === '/notifications') {
      return Promise.resolve(page(items));
    }
    if (url === '/notifications/unread-count') {
      return Promise.resolve({ data: { data: { unread_count: 0 } } });
    }

    return Promise.resolve(page([]));
  });

  return render(Index, { global: { mocks: globalMocks } });
}

describe('Inbox', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('lists notifications with what happened, the task key as a technical ID, and unread state', async () => {
    renderInbox();

    await waitFor(() => expect(screen.getByText('Sara assigned you a task')).toBeTruthy());
    expect(screen.getByText('3 updates to a task you watch')).toBeTruthy();
    expect(screen.getByText('Changed: priority, status')).toBeTruthy();
    expect(screen.getByText('Sara invited you to join Beta')).toBeTruthy();
    expect(screen.getByText('To join, open the link in your invitation email.')).toBeTruthy();
    expect(screen.getAllByText('WEB-7')[0]?.closest('[dir="ltr"]')).not.toBeNull();
    expect(screen.getAllByText('Fix login')[0]?.getAttribute('dir')).toBe('auto');
    expect(document.querySelectorAll('[data-unread="true"]')).toHaveLength(2);
    // Two unread rows offer "Mark as read"; the read invitation does not.
    expect(screen.getAllByRole('button', { name: 'Mark as read' })).toHaveLength(2);
    // The badges say how many are unread.
    expect(screen.getAllByRole('link', { name: 'Inbox, 2 unread' }).length).toBeGreaterThan(0);
  });

  it('marks one read, announces it politely and lowers the count', async () => {
    http.post.mockResolvedValue({ data: { data: { ...items[0], read_at: '2026-10-17T12:00:00Z' } } });
    renderInbox();

    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Mark as read' })).toHaveLength(2));
    await fireEvent.click(screen.getAllByRole('button', { name: 'Mark as read' })[0]!);

    await waitFor(() => expect(screen.getByRole('status').textContent).toBe('Marked as read.'));
    expect(http.post).toHaveBeenCalledWith('/notifications/n1/read');
    expect(screen.getAllByRole('button', { name: 'Mark as read' })).toHaveLength(1);
    expect(screen.getAllByRole('link', { name: 'Inbox, 1 unread' }).length).toBeGreaterThan(0);
  });

  it('opens the task through its route after marking the notification read', async () => {
    http.post.mockResolvedValue({ data: { data: { ...items[0], read_at: '2026-10-17T12:00:00Z' } } });
    renderInbox();

    await waitFor(() => expect(screen.getByText('Sara assigned you a task')).toBeTruthy());
    const row = screen.getByText('Sara assigned you a task').closest('li')!;
    await fireEvent.click(within(row).getByRole('link'));

    await waitFor(() => expect(visit).toHaveBeenCalledWith('/tasks/task-1'));
    expect(http.post).toHaveBeenCalledWith('/notifications/n1/read');
  });

  it('drops a notification the server no longer shows (404) instead of failing', async () => {
    http.post.mockRejectedValue(apiFailure(404, 'not_found'));
    renderInbox();

    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Mark as read' })).toHaveLength(2));
    await fireEvent.click(screen.getAllByRole('button', { name: 'Mark as read' })[0]!);

    await waitFor(() => expect(screen.queryByText('Sara assigned you a task')).toBeNull());
    expect(screen.queryByRole('alert')).toBeNull();
    expect(http.get).toHaveBeenCalledWith('/notifications/unread-count');
  });

  it('marks all read, reloads the list and the count', async () => {
    http.post.mockResolvedValue({ data: { data: { marked_read: 2 } } });
    renderInbox();

    await waitFor(() => expect(screen.getByText('Sara assigned you a task')).toBeTruthy());
    await fireEvent.click(screen.getByRole('button', { name: 'Mark all as read' }));

    await waitFor(() => expect(screen.getByRole('status').textContent).toBe('All notifications are marked as read.'));
    expect(http.post).toHaveBeenCalledWith('/notifications/read-all');
    await waitFor(() => expect(screen.getAllByRole('link', { name: 'Inbox' }).length).toBeGreaterThan(0));
  });

  it('filters unread notifications and says when there are none', async () => {
    renderInbox();
    await waitFor(() => expect(screen.getByText('Sara assigned you a task')).toBeTruthy());

    http.get.mockResolvedValue(page([]));
    await fireEvent.click(screen.getByRole('button', { name: 'Unread' }));

    await waitFor(() => expect(screen.getByText('You are all caught up')).toBeTruthy());
    expect(http.get).toHaveBeenLastCalledWith('/notifications', { params: { status: 'unread', limit: 25 } });
    expect(screen.getByRole('button', { name: 'Unread' }).getAttribute('aria-pressed')).toBe('true');
  });

  it('renders in Arabic, right to left', async () => {
    renderInbox('ar');

    await waitFor(() => expect(screen.getByText('أسند إليك Sara مهمة')).toBeTruthy());
    expect(screen.getByRole('button', { name: 'تعليم الكل كمقروء' })).toBeTruthy();
    expect(screen.getByText('ما تغيّر: الأولوية، الحالة')).toBeTruthy();
  });
});
