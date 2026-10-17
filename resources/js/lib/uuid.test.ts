import { afterEach, describe, expect, it, vi } from 'vitest';
import { randomUuid } from './uuid';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe('randomUuid', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('returns distinct version 4 UUIDs', () => {
    const first = randomUuid();

    expect(first).toMatch(UUID);
    expect(randomUuid()).not.toBe(first);
  });

  it('falls back to getRandomValues outside a secure context', () => {
    const real = globalThis.crypto;
    vi.stubGlobal('crypto', {
      getRandomValues: <T extends ArrayBufferView>(array: T): T => real.getRandomValues(array),
    });

    expect(randomUuid()).toMatch(UUID);
  });
});
