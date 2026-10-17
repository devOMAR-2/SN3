import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { globalMocks, sharedProps } from '@/test/pageHarness';
import type { Team, TeamAbilities, TeamMember } from '@/types/domain';
import Members from './Members.vue';

const state = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
const http = vi.hoisted(() => ({ get: vi.fn(), patch: vi.fn(), post: vi.fn(), delete: vi.fn(), request: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
  usePage: () => ({ props: state.props, url: '/t/team-alpha/members' }),
  router: { visit: vi.fn(), reload: vi.fn(), on: () => () => undefined },
}));

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: http,
}));

const team: Team = {
  id: 'team-1',
  name: 'Alpha',
  slug: 'team-alpha',
  description: null,
  owner_user_id: 'owner',
  role: 'member',
  archived_at: null,
  created_at: null,
  updated_at: null,
};

const members: TeamMember[] = [
  { user_id: 'owner', name: 'Olivia Owner', role: 'owner', joined_at: '2026-10-01T09:00:00Z' },
  { user_id: 'me', name: 'Me', role: 'member', joined_at: '2026-10-02T09:00:00Z' },
  { user_id: 'other', name: 'Omar Other', role: 'viewer', joined_at: '2026-10-03T09:00:00Z' },
];

const none: TeamAbilities = {
  view_settings: false,
  update: false,
  create_project: false,
  invite: false,
  invite_admin: false,
  change_member_roles: false,
  remove_other_members: false,
  transfer_ownership: false,
  leave: true,
  archive: false,
  unarchive: false,
  delete: false,
  export: false,
};

function renderMembers(can: TeamAbilities, me = 'me') {
  state.props = sharedProps('en', me);

  return render(Members, { props: { team, can, lock: null }, global: { mocks: globalMocks } });
}

describe('Team members page', () => {
  beforeEach(() => {
    http.get.mockImplementation(async (url: string) =>
      url.includes('/projects') || url.includes('/invitations')
        ? { data: { data: [], meta: { next_cursor: null, has_more: false } } }
        : { data: { data: members, meta: { next_cursor: null, has_more: false } } },
    );
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('shows a Member no management controls, only Leave on their own row', async () => {
    renderMembers(none);

    const list = await screen.findByRole('list', { name: 'Members' });
    expect(within(list).queryAllByRole('combobox')).toHaveLength(0);
    expect(within(list).queryByRole('button', { name: /^Remove/ })).toBeNull();
    expect(within(list).getByRole('button', { name: 'Leave team' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Transfer ownership' })).toBeNull();
  });

  it('gives the Owner role and remove controls for everyone but the Owner row', async () => {
    renderMembers(
      { ...none, change_member_roles: true, remove_other_members: true, transfer_ownership: true, leave: false },
      'owner',
    );

    const list = await screen.findByRole('list', { name: 'Members' });
    expect(within(list).getAllByRole('combobox')).toHaveLength(2);
    expect(within(list).queryByRole('combobox', { name: 'Role of Olivia Owner' })).toBeNull();
    expect(within(list).getByRole('button', { name: 'Remove Omar Other' })).toBeTruthy();
    expect(within(list).queryByRole('button', { name: 'Remove Olivia Owner' })).toBeNull();
    expect(within(list).queryByRole('button', { name: 'Leave team' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Transfer ownership' })).toBeTruthy();
  });

  it('puts the role back and explains when the server refuses a change', async () => {
    const { apiFailure } = await import('@/test/pageHarness');
    http.patch.mockRejectedValue(apiFailure(403, 'forbidden'));
    renderMembers({ ...none, change_member_roles: true, remove_other_members: true, leave: false }, 'owner');

    const select = (await screen.findByRole('combobox', { name: 'Role of Omar Other' })) as HTMLSelectElement;
    select.value = 'admin';
    select.dispatchEvent(new Event('change'));

    await waitFor(() => expect(screen.getByText('server message for forbidden')).toBeTruthy());
    expect((screen.getByRole('combobox', { name: 'Role of Omar Other' }) as HTMLSelectElement).value).toBe('viewer');
  });

  it('offers every member as the new Owner, reading all pages of the list', async () => {
    const page2: TeamMember = { user_id: 'far', name: 'Fatima Far', role: 'member', joined_at: '2026-10-04T09:00:00Z' };
    http.get.mockImplementation(async (url: string, config?: { params?: Record<string, unknown> }) => {
      if (url.includes('/projects') || url.includes('/invitations')) {
        return { data: { data: [], meta: { next_cursor: null, has_more: false } } };
      }

      // The page's own list: one page of 50 with more behind it.
      if (config?.params?.limit === 50) {
        return { data: { data: members, meta: { next_cursor: 'c1', has_more: true } } };
      }

      // The transfer dialog reads 100 at a time until the end.
      return config?.params?.cursor === 'p2'
        ? { data: { data: [page2], meta: { next_cursor: null, has_more: false } } }
        : { data: { data: members, meta: { next_cursor: 'p2', has_more: true } } };
    });
    renderMembers(
      { ...none, change_member_roles: true, remove_other_members: true, transfer_ownership: true, leave: false },
      'owner',
    );

    await fireEvent.click(await screen.findByRole('button', { name: 'Transfer ownership' }));

    const select = await screen.findByRole('combobox', { name: 'New Owner' });
    await waitFor(() => expect(within(select).getByRole('option', { name: 'Fatima Far' })).toBeTruthy());
    expect(within(select).queryByRole('option', { name: 'Olivia Owner' })).toBeNull();
    expect(within(select).getByRole('option', { name: 'Me' })).toBeTruthy();
  });

  it('shows the invitations panel (CORE-002) only to those the server lets invite', async () => {
    const { container } = renderMembers(none);

    await screen.findByRole('list', { name: 'Members' });
    expect(container.querySelector('[data-extension-point="team-invitations"]')).toBeNull();
    expect(screen.queryByRole('heading', { name: 'Invitations' })).toBeNull();
    cleanup();

    renderMembers({ ...none, invite: true, invite_admin: true, leave: false }, 'owner');

    expect(await screen.findByRole('heading', { name: 'Invitations' })).toBeTruthy();
  });
});
