import { describe, expect, it } from 'vitest';

import { readResetLink } from './resetLink';

describe('readResetLink', () => {
  it('reads the token and the percent-encoded email from the fragment', () => {
    expect(readResetLink('#token=abc123&email=sara%2Btest%40example.com')).toEqual({
      token: 'abc123',
      email: 'sara+test@example.com',
    });
  });

  it('returns empty values when the fragment is missing or incomplete', () => {
    expect(readResetLink('')).toEqual({ token: '', email: '' });
    expect(readResetLink('#email=a%40b.c')).toEqual({ token: '', email: 'a@b.c' });
  });
});
