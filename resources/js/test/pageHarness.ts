/**
 * Shared set-up for page component tests: jsdom gaps (matchMedia) and Inertia's head manager.
 * Each test file mocks `@inertiajs/vue3` (usePage) and `@/lib/api` itself, because vi.mock is hoisted
 * per file.
 */
import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios';

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

// jsdom has <dialog> but not showModal()/close(); a minimal stand-in opens and closes it.
if (typeof HTMLDialogElement !== 'undefined' && typeof HTMLDialogElement.prototype.showModal !== 'function') {
  HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement): void {
    this.setAttribute('open', '');
  };
  HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement): void {
    this.removeAttribute('open');
    this.dispatchEvent(new Event('close'));
  };
}

/** Inertia's <Head> needs the head manager that createInertiaApp installs. */
export const globalMocks = {
  $headManager: {
    createProvider: () => ({
      update: () => undefined,
      disconnect: () => undefined,
      preferredAttribute: () => 'inertia',
    }),
  },
};

/** A rejected /api/v1 call with the API_SPEC error envelope. */
export function apiFailure(status: number, code: string): AxiosError {
  const config = { headers: new AxiosHeaders() };
  const response = {
    status,
    data: { error: { code, message: `server message for ${code}`, request_id: 'req_test' } },
    statusText: '',
    headers: {},
    config,
  } as AxiosResponse;

  return new AxiosError('Request failed', 'ERR_BAD_REQUEST', config, null, response);
}

export function sharedProps(locale: 'ar' | 'en' = 'en', userId = 'me'): Record<string, unknown> {
  return {
    locale,
    dir: locale === 'ar' ? 'rtl' : 'ltr',
    auth: {
      user: {
        id: userId,
        name: 'Me',
        email: 'me@example.test',
        email_verified: true,
        email_verified_at: null,
        locale,
        timezone: 'UTC',
        theme: 'system',
        email_notifications: true,
        in_app_notifications: true,
        created_at: null,
      },
    },
    flash: { status: null },
    features: { registration: true },
    workspace: { teams: [], has_more: false },
    inbox: { unread_count: 0 },
  };
}
