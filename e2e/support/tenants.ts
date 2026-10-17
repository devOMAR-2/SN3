import { expect, type APIResponse, type Page } from '@playwright/test';

import fixture from '../fixtures/tenants.json' with { type: 'json' };

// Typed access to the deterministic tenants seeded by Database\Seeders\E2ETenantSeeder.

export type Role = 'owner' | 'admin' | 'member' | 'viewer';

export interface SeededUser {
  key: string;
  id: string;
  name: string;
  email: string;
  locale: 'ar' | 'en';
}

export interface SeededTeam {
  key: string;
  id: string;
  name: string;
  slug: string;
  owner: string;
  members: Record<string, Role>;
}

export const PASSWORD: string = fixture.password;
export const USERS = fixture.users as unknown as SeededUser[];
export const TEAMS = fixture.teams as unknown as SeededTeam[];
/** Seeded users in no team, for flows that change data (they never appear in USERS' isolation checks). */
export const WORKFLOW_USERS = fixture.workflow_users as unknown as SeededUser[];

export function user(key: string): SeededUser {
  const found = [...USERS, ...WORKFLOW_USERS].find((u) => u.key === key);
  if (!found) throw new Error(`Unknown seeded user "${key}"`);
  return found;
}

export function team(key: string): SeededTeam {
  const found = TEAMS.find((t) => t.key === key);
  if (!found) throw new Error(`Unknown seeded team "${key}"`);
  return found;
}

/** The team used where any seeded team will do. */
export const FIRST_TEAM: SeededTeam = team('alpha');

/** The user's role in each team they belong to, keyed by team ID. */
export function expectedRoles(userKey: string): Record<string, Role> {
  const roles: Record<string, Role> = {};
  for (const t of TEAMS) {
    const role: Role | undefined = t.owner === userKey ? 'owner' : t.members[userKey];
    if (role) roles[t.id] = role;
  }
  return roles;
}

async function xsrfToken(page: Page): Promise<string> {
  const cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
  if (!cookie) throw new Error('No XSRF-TOKEN cookie; visit a page first.');
  return decodeURIComponent(cookie.value);
}

/**
 * Signs in through POST /api/v1/auth/login on the page's own cookie jar, exactly as the
 * login form does (session cookie + CSRF header).
 */
export async function signIn(page: Page, userKey: string): Promise<void> {
  await page.goto('/login');
  const response = await page.request.post('/api/v1/auth/login', {
    headers: { 'X-XSRF-TOKEN': await xsrfToken(page), Accept: 'application/json' },
    data: { email: user(userKey).email, password: PASSWORD },
  });
  expect(response.status(), `sign-in of ${userKey}`).toBe(200);
}

export async function apiGet(page: Page, path: string): Promise<APIResponse> {
  return page.request.get(`/api/v1${path}`, { headers: { Accept: 'application/json' } });
}

/** A JSON write to /api/v1 with the page's session and CSRF token, as the application's own forms send it. */
export async function apiPost(
  page: Page,
  path: string,
  data: unknown,
  headers: Record<string, string> = {},
): Promise<APIResponse> {
  return page.request.post(`/api/v1${path}`, {
    headers: { 'X-XSRF-TOKEN': await xsrfToken(page), Accept: 'application/json', ...headers },
    data,
  });
}
