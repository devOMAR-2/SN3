import { afterEach, describe, expect, it } from 'vitest';
import {
  hasPendingInvitation,
  readInvitationToken,
  rememberPendingInvitation,
  takePendingInvitation,
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

  it('keeps a pending invitation for one round trip in this tab', () => {
    expect(hasPendingInvitation()).toBe(false);

    rememberPendingInvitation(token);

    expect(hasPendingInvitation()).toBe(true);
    expect(takePendingInvitation()).toBe(token);
    expect(hasPendingInvitation()).toBe(false);
    expect(takePendingInvitation()).toBeNull();
  });

  it('ignores a tampered pending value', () => {
    window.sessionStorage.setItem('sn3.pendingInvitation', '<script>');

    expect(hasPendingInvitation()).toBe(false);
    expect(takePendingInvitation()).toBeNull();
  });
});
