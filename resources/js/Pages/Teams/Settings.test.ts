import { router } from '@inertiajs/vue3';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiFailure, globalMocks, sharedProps } from '@/test/pageHarness';
import type { Team, TeamAbilities } from '@/types/domain';
import Settings from './Settings.vue';

const state = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
const http = vi.hoisted(() => ({ get: vi.fn(), patch: vi.fn(), post: vi.fn(), delete: vi.fn(), request: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
  usePage: () => ({ props: state.props, url: '/t/team-alpha/settings' }),
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
  owner_user_id: 'me',
  role: 'owner',
  archived_at: null,
  created_at: null,
  updated_at: null,
};

const can: TeamAbilities = {
  view_settings: true,
  update: true,
  create_project: true,
  invite: true,
  invite_admin: true,
  change_member_roles: true,
  remove_other_members: true,
  transfer_ownership: true,
  leave: false,
  archive: true,
  unarchive: false,
  delete: true,
  export: false,
};

describe('Team settings', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('switches to the read-only banner and reloads the abilities when a save answers 423', async () => {
    state.props = sharedProps('en', 'me');
    http.get.mockResolvedValue({ data: { data: [], meta: { next_cursor: null, has_more: false } } });
    http.request.mockRejectedValue(apiFailure(423, 'resource_locked'));
    render(Settings, { props: { team, can, lock: null }, global: { mocks: globalMocks } });

    await fireEvent.update(screen.getByLabelText(/Team name/), 'Renamed');
    await fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(screen.getByText('This is read-only now')).toBeTruthy());
    expect(router.reload).toHaveBeenCalled();
  });
});
