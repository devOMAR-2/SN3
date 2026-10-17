import { afterEach, describe, expect, it } from 'vitest';
import {
  forgetPendingInvitation,
  hasPendingInvitation,
  peekPendingInvitation,
  readInvitationToken,
  rememberPendingInvitation,
} from './invitationLink';

const token = 'Ab3_-'.repeat(8) + 'xyz';

describe('invitation links', () => {
  afterEach(() => {
    window.sessionStorage.clear();
  });

  it('reads a well-formed token from the fragment', () => {
    expect(readInvitationToken(`#${token}`)).toBe(token);
    expect(readInvitationToken(token)).toBe(token);
  });

  it('rejects missing or malformed tokens', () => {
    expect(readInvitationToken('')).toBeNull();
    expect(readInvitationToken('#')).toBeNull();
    expect(readInvitationToken(`#${token}x`)).toBeNull();
    expect(readInvitationToken(`#${token.slice(1)}`)).toBeNull();
    expect(readInvitationToken(`#${token.slice(1)}=`)).toBeNull();
  });

  it('keeps a pending invitation in this tab until it is forgotten', () => {
    expect(hasPendingInvitation()).toBe(false);

    rememberPendingInvitation(token);

    expect(hasPendingInvitation()).toBe(true);
    expect(peekPendingInvitation()).toBe(token);
    expect(peekPendingInvitation()).toBe(token);

    forgetPendingInvitation();

    expect(hasPendingInvitation()).toBe(false);
    expect(peekPendingInvitation()).toBeNull();
  });

  it('ignores a tampered pending value', () => {
    window.sessionStorage.setItem('sn3.pendingInvitation', '<script>');

    expect(hasPendingInvitation()).toBe(false);
    expect(peekPendingInvitation()).toBeNull();
  });
});
