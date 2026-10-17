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

/** A seeded project (CORE-011): every text of it carries `canary`. */
export interface SeededProject {
  key: string;
  team: string;
  project_key: string;
  visibility: 'team' | 'private';
  canary: string;
  members: Record<string, 'manager' | 'contributor'>;
  ids: Record<'project' | 'task' | 'comment' | 'attachment' | 'label' | 'checklist_item' | 'status', string>;
  notifications: { to: string; type: string; actor: string; id: string }[];
}

export const PASSWORD: string = fixture.password;
export const PROJECTS = fixture.projects as unknown as SeededProject[];
export const USERS = fixture.users as unknown as SeededUser[];
export const TEAMS = fixture.teams as unknown as SeededTeam[];
/** Seeded users in no team, for flows that change data (they never appear in USERS' isolation checks). */
export const WORKFLOW_USERS = fixture.workflow_users as unknown as SeededUser[];
/** Verified accounts outside every seeded team, for tests that create data (CORE-006 journey). */
export const JOURNEY_USERS = fixture.journey_users as unknown as SeededUser[];

export function user(key: string): SeededUser {
  const found = [...USERS, ...WORKFLOW_USERS, ...JOURNEY_USERS].find((u) => u.key === key);
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

/** Any JSON write to /api/v1 (PATCH, PUT, DELETE…), like apiPost(). */
export async function apiWrite(
  page: Page,
  method: 'patch' | 'put' | 'delete',
  path: string,
  data: unknown,
  headers: Record<string, string> = {},
): Promise<APIResponse> {
  return page.request.fetch(`/api/v1${path}`, {
    method,
    headers: { 'X-XSRF-TOKEN': await xsrfToken(page), Accept: 'application/json', ...headers },
    data,
  });
}

/** Whether the seeded user may see the seeded project (team project: any team member; private: Owner and members). */
export function canSee(userKey: string, project: SeededProject): boolean {
  const role = expectedRoles(userKey)[team(project.team).id];

  if (role === undefined) return false;
  if (project.visibility === 'team') return true;

  return role === 'owner' || userKey in project.members;
}
