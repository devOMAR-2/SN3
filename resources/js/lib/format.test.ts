import { describe, expect, it } from 'vitest';
import { formatDate, suggestProjectKey } from './format';
import { classifyWriteError, createRetryKey, ifMatch, newIdempotencyKey } from './http';
import { ApiError } from './api';
import { pages } from './routes';

describe('suggestProjectKey', () => {
  it('builds a valid key from Latin names', () => {
    expect(suggestProjectKey('Website redesign')).toBe('WR');
    expect(suggestProjectKey('Payroll')).toBe('PAYROLL');
    expect(suggestProjectKey('Mobile App Version Two Plus Extra Words Here And More')).toBe('MAVTPEWHAM');
    expect(suggestProjectKey('2026 Roadmap')).toBe('ROADMAP');
    expect(suggestProjectKey('API v2')).toBe('AV');
  });

  it('leaves the key empty when the name has no usable ASCII letters', () => {
    expect(suggestProjectKey('موقع الشركة')).toBe('');
    expect(suggestProjectKey('')).toBe('');
  });

  it('only ever returns keys the API accepts', () => {
    for (const name of ['a', 'Ab', 'x y', 'Über Projekt', 'web-2', 'API v2', '___', 'Q']) {
      const key = suggestProjectKey(name);
      expect(key === '' || /^[A-Z][A-Z0-9]{1,9}$/.test(key)).toBe(true);
    }
  });
});

describe('formatDate', () => {
  it('formats calendar dates without shifting them across time zones', () => {
    expect(formatDate('en', '2026-10-20')).toBe('Oct 20, 2026');
    expect(formatDate('ar', '2026-01-01')).toContain('2026');
    expect(formatDate('ar', '2026-01-01')).not.toMatch(/[٠-٩]/);
    expect(formatDate('en', null)).toBe('');
  });
});

describe('request helpers', () => {
  it('quotes the version for If-Match', () => {
    expect(ifMatch(7)).toEqual({ 'If-Match': '"7"' });
  });

  it('creates UUID idempotency keys', () => {
    expect(newIdempotencyKey()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(newIdempotencyKey()).not.toBe(newIdempotencyKey());
  });

  it('keeps an Idempotency-Key for retries of the same request until it succeeds', () => {
    let n = 0;
    const key = createRetryKey(() => `key-${++n}`);

    expect(key.for('export')).toBe('key-1');
    // Failed, retried: the same key, so the server replays instead of creating twice.
    expect(key.for('export')).toBe('key-1');
    // A different request gets a new key.
    expect(key.for('another title')).toBe('key-2');
    key.settle();
    // After a success the next request is new.
    expect(key.for('another title')).toBe('key-3');
  });

  it('maps statuses to what the page does next', () => {
    const outcome = (status: number) => classifyWriteError(new ApiError('x', status, 'code'));

    expect([412, 423, 404, 403, 422, 409, 500, 0].map(outcome)).toEqual([
      'conflict',
      'locked',
      'gone',
      'forbidden',
      'invalid',
      'error',
      'error',
      'error',
    ]);
  });

  it('builds page URLs from slugs, keys and task IDs', () => {
    expect(pages.project('team-alpha', 'WEB')).toBe('/t/team-alpha/p/WEB');
    expect(pages.newProject('team-alpha', true)).toBe('/t/team-alpha/projects/new?onboarding=1');
    expect(pages.task('0192a000-0000-7000-8000-000000000001')).toBe('/tasks/0192a000-0000-7000-8000-000000000001');
  });
});
