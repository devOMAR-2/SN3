import { cleanup, fireEvent, render, screen } from '@testing-library/vue';
import { AxiosError, AxiosHeaders } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Accept from './Accept.vue';

window.matchMedia ??= (query: string) =>
  ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    addListener: () => undefined,
    removeListener: () => undefined,
    dispatchEvent: () => false,
  }) as MediaQueryList;

const token = 'Ab3_-'.repeat(8) + 'xyz';
const state = vi.hoisted(() => ({ user: null as null | { email_verified: boolean } }));
const visit = vi.hoisted(() => vi.fn());
const replace = vi.hoisted(() => vi.fn());
const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
  usePage: () => ({
    props: {
      locale: 'en',
      dir: 'ltr',
      auth: { user: state.user },
      flash: { status: null },
      features: { registration: true },
    },
  }),
  router: { visit, replace },
}));

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api,
}));

const headManager = {
  createProvider: () => ({ update: () => undefined, disconnect: () => undefined, preferredAttribute: () => 'inertia' }),
};
const renderPage = () => render(Accept, { global: { mocks: { $headManager: headManager } } });

function apiError(status: number, code: string): AxiosError {
  const headers = new AxiosHeaders();

  return new AxiosError('failed', String(status), undefined, undefined, {
    status,
    statusText: code,
    headers,
    config: { headers },
    data: { error: { code, message: code, request_id: 'r' } },
  });
}

const preview = { team: { name: 'Website' }, role: 'member', type: 'link', expires_at: '2026-10-24T12:00:00Z' };

describe('Invitation page', () => {
  beforeEach(() => {
    window.location.hash = `#${token}`;
    state.user = null;
    api.get.mockResolvedValue({ data: { data: preview } });
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    window.sessionStorage.clear();
    window.location.hash = '';
  });

  it('explains an incomplete link without calling the API', async () => {
    window.location.hash = '#short';
    renderPage();

    expect(await screen.findByText(/incomplete/)).toBeTruthy();
    expect(api.get).not.toHaveBeenCalled();
  });

  it('shows the team and role to a guest, drops the token from the URL and keeps it for the sign-in round trip', async () => {
    renderPage();

    expect(await screen.findByText('You are invited to join Website')).toBeTruthy();
    expect(screen.getByText('Role: Member')).toBeTruthy();
    expect(api.get).toHaveBeenCalledWith(`/invitations/${token}/preview`);
    expect(replace).toHaveBeenCalled();

    await fireEvent.click(screen.getByRole('button', { name: 'Sign in to accept' }));

    expect(window.sessionStorage.getItem('sn3.pendingInvitation')).toBe(token);
    expect(visit).toHaveBeenCalledWith('/login');
  });

  it('asks an unverified user to verify first', async () => {
    state.user = { email_verified: false };
    renderPage();

    expect(await screen.findByText(/Verify your email address before/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Join the team' })).toBeNull();
  });

  it('joins a verified user', async () => {
    state.user = { email_verified: true };
    api.post.mockResolvedValue({ status: 201, data: { data: {} } });
    renderPage();

    await fireEvent.click(await screen.findByRole('button', { name: 'Join the team' }));

    expect(api.post).toHaveBeenCalledWith(`/invitations/${token}/accept`);
    expect(await screen.findByText('You are now a member of Website.')).toBeTruthy();
  });

  it('reads the token back from the sign-in round trip when the URL has none', async () => {
    window.location.hash = '';
    window.sessionStorage.setItem('sn3.pendingInvitation', token);
    renderPage();

    expect(await screen.findByText('You are invited to join Website')).toBeTruthy();
    expect(window.sessionStorage.getItem('sn3.pendingInvitation')).toBeNull();
  });

  it.each([
    [404, 'not_found', /does not exist/],
    [410, 'expired', /no longer valid/],
  ])('explains a %i preview', async (status, code, text) => {
    api.get.mockRejectedValue(apiError(status, code));
    renderPage();

    expect(await screen.findByText(text)).toBeTruthy();
  });

  it.each([
    [403, 'forbidden', /different email address/],
    [423, 'resource_locked', /archived/],
  ])('explains a %i on accept', async (status, code, text) => {
    state.user = { email_verified: true };
    api.post.mockRejectedValue(apiError(status, code));
    renderPage();

    await fireEvent.click(await screen.findByRole('button', { name: 'Join the team' }));

    expect(await screen.findByText(text)).toBeTruthy();
  });
});
