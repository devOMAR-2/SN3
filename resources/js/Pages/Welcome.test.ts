import { cleanup, render, screen, within } from '@testing-library/vue';
import { afterEach, describe, expect, it, vi } from 'vitest';

import Welcome from './Welcome.vue';

// jsdom has no matchMedia; the theme composable listens to prefers-color-scheme.
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

// The page reads the shared `locale` prop (useI18n) and the layout's switcher uses the router.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
  usePage: () => ({ props: { locale: 'en', dir: 'ltr', auth: { user: null }, flash: { status: null } } }),
  router: { visit: vi.fn() },
}));

// Inertia's <Head> needs the head manager that createInertiaApp installs; a no-op provider stands in for it.
const headManager = { createProvider: () => ({ update: () => undefined, disconnect: () => undefined }) };
const renderWelcome = () => render(Welcome, { global: { mocks: { $headManager: headManager } } });

describe('Welcome page', () => {
  afterEach(() => {
    cleanup();
  });

  it('shows the product name in Arabic (RTL) and English (LTR) in a single heading', () => {
    renderWelcome();

    const heading = screen.getByRole('heading', { level: 1 });
    const arabic = within(heading).getByText('سنع');
    const english = within(heading).getByText('SN3');

    expect(arabic.getAttribute('lang')).toBe('ar');
    expect(arabic.getAttribute('dir')).toBe('rtl');
    expect(english.getAttribute('lang')).toBe('en');
    expect(english.getAttribute('dir')).toBe('ltr');
  });

  it('hides the decorative separator from assistive technology', () => {
    renderWelcome();

    const separator = within(screen.getByRole('heading', { level: 1 })).getByText('·');

    expect(separator.getAttribute('aria-hidden')).toBe('true');
  });
});
