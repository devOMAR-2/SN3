import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { globalMocks, sharedProps } from '@/test/pageHarness';
import Profile from './Profile.vue';

const state = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), request: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
  usePage: () => ({ props: state.props, url: '/settings' }),
  router: { visit: vi.fn(), reload: vi.fn(), on: () => () => undefined },
}));

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: http,
}));

describe('Profile settings', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('saves the notification preferences with the profile (CORE-009)', async () => {
    state.props = sharedProps('en');
    const user = (state.props.auth as { user: Record<string, unknown> }).user;
    http.request.mockResolvedValue({ data: { data: { ...user, email_notifications: false } } });
    render(Profile, { global: { mocks: globalMocks } });

    const email = screen.getByRole('checkbox', { name: 'Email me about notifications' }) as HTMLInputElement;
    const inApp = screen.getByRole('checkbox', { name: 'Show notifications in my inbox' }) as HTMLInputElement;
    expect(email.checked).toBe(true);
    expect(inApp.checked).toBe(true);
    expect(screen.getByRole('group', { name: 'Notifications' })).toBeTruthy();

    await fireEvent.click(email);
    await fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(http.request).toHaveBeenCalled());
    expect(http.request.mock.calls[0]?.[0]).toMatchObject({
      method: 'patch',
      url: '/me',
      data: { email_notifications: false, in_app_notifications: true },
    });
  });

  it('labels the preferences in Arabic', () => {
    state.props = sharedProps('ar');
    render(Profile, { global: { mocks: globalMocks } });

    expect(screen.getByRole('checkbox', { name: 'أرسل إليّ الإشعارات بالبريد' })).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: 'اعرض الإشعارات في صندوق الوارد' })).toBeTruthy();
  });
});
