/**
 * Password-reset links carry the token and email in the URL fragment
 * (`/reset-password#token=…&email=…`). Browsers never send a fragment to the server, so the
 * token cannot end up in access logs, proxy logs or Referer headers.
 */
export interface ResetLink {
  token: string;
  email: string;
}

export function readResetLink(hash: string): ResetLink {
  const params = new URLSearchParams(hash.startsWith('#') ? hash.slice(1) : hash);

  return { token: params.get('token') ?? '', email: params.get('email') ?? '' };
}
