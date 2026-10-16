import { cleanup, render, screen } from '@testing-library/vue';
import { afterEach, describe, expect, it } from 'vitest';

import Welcome from './Welcome.vue';

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
    const arabic = screen.getByText('سنع');
    const english = screen.getByText('SN3');

    expect(heading.contains(arabic)).toBe(true);
    expect(heading.contains(english)).toBe(true);
    expect(arabic.getAttribute('lang')).toBe('ar');
    expect(arabic.getAttribute('dir')).toBe('rtl');
    expect(english.getAttribute('lang')).toBe('en');
    expect(english.getAttribute('dir')).toBe('ltr');
  });

  it('hides the decorative separator from assistive technology', () => {
    renderWelcome();

    const separator = screen.getByText('·');

    expect(separator.getAttribute('aria-hidden')).toBe('true');
  });
});
