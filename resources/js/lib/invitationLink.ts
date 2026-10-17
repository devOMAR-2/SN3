/**
 * Invitation links (CORE-002, ADR-025 §2) are `/invite#<token>`. The token travels in the URL fragment,
 * which browsers never send to the server, so it stays out of access logs, proxies and Referer headers.
 *
 * A guest who opens the link must sign in first; the token is kept in this tab's sessionStorage for that
 * round trip only, and cleared once the invitation is settled: joined, or found unusable.
 */
const TOKEN_PATTERN = /^[A-Za-z0-9_-]{43}$/;
const PENDING_KEY = 'sn3.pendingInvitation';

/** The token from a location hash such as `#abc…`, or null when it is missing or malformed. */
export function readInvitationToken(hash: string): string | null {
  const token = hash.startsWith('#') ? hash.slice(1) : hash;

  return TOKEN_PATTERN.test(token) ? token : null;
}

/** Remembers the token in this tab while the guest signs in or registers. */
export function rememberPendingInvitation(token: string): void {
  try {
    window.sessionStorage.setItem(PENDING_KEY, token);
  } catch {
    // Storage blocked (private mode, policy): the guest opens the link again after signing in.
  }
}

/** Whether a guest left an invitation in this tab to come back to. */
export function hasPendingInvitation(): boolean {
  try {
    return readInvitationToken(window.sessionStorage.getItem(PENDING_KEY) ?? '') !== null;
  } catch {
    return false;
  }
}

/** The remembered token, kept until the invitation is settled (joined, or no longer usable). */
export function peekPendingInvitation(): string | null {
  try {
    return readInvitationToken(window.sessionStorage.getItem(PENDING_KEY) ?? '');
  } catch {
    return null;
  }
}

/** Forgets the remembered token. */
export function forgetPendingInvitation(): void {
  try {
    window.sessionStorage.removeItem(PENDING_KEY);
  } catch {
    // Storage blocked: nothing was kept.
  }
}
