import { expect, test } from '@playwright/test';

import { apiGet, expectedRoles, FIRST_TEAM, signIn, TEAMS, USERS } from '../support/tenants';

// FND-008 acceptance: deterministic proof, through the running application with real sessions,
// that no user reaches a team outside their own tenants. Every seeded user × every seeded team.

const RANDOM_TEAM_ID = '0192a000-0000-7000-8000-00000000ffff';

for (const u of USERS) {
  test(`${u.key} sees exactly their own teams`, async ({ page }) => {
    await signIn(page, u.key);
    const expected = expectedRoles(u.key);

    const list = await apiGet(page, '/me/teams');
    expect(list.status()).toBe(200);
    const listed = (await list.json()).data as { id: string; role: string }[];
    expect(Object.fromEntries(listed.map((t) => [t.id, t.role]))).toEqual(expected);

    const missing = await apiGet(page, `/teams/${RANDOM_TEAM_ID}`);
    expect(missing.status()).toBe(404);
    const missingError = (await missing.json()).error;

    for (const t of TEAMS) {
      const response = await apiGet(page, `/teams/${t.id}`);

      if (t.id in expected) {
        expect(response.status(), `${u.key} → ${t.key}`).toBe(200);
        expect((await response.json()).data.role).toBe(expected[t.id]);
      } else {
        // A foreign team is indistinguishable from one that does not exist.
        expect(response.status(), `${u.key} → ${t.key}`).toBe(404);
        const body = await response.text();
        expect(body).not.toContain(t.name);
        expect(body).not.toContain(t.id);
        const error = JSON.parse(body).error;
        expect({ code: error.code, message: error.message }).toEqual({
          code: missingError.code,
          message: missingError.message,
        });
      }
    }
  });
}

test('guests reach no team data', async ({ request }) => {
  for (const path of ['/me/teams', '/teams', `/teams/${FIRST_TEAM.id}`]) {
    const response = await request.get(`/api/v1${path}`, { headers: { Accept: 'application/json' } });
    expect(response.status(), path).toBe(401);
    expect(await response.text()).not.toContain(FIRST_TEAM.name);
  }
});

test('guessed and malformed team IDs never reach the database', async ({ page }) => {
  await signIn(page, 'outsider');

  for (const id of ['not-a-uuid', '1', "'%20OR%201=1--", 'team-alpha', FIRST_TEAM.slug]) {
    const response = await apiGet(page, `/teams/${id}`);
    expect(response.status(), id).toBe(404);
    expect((await response.json()).error.code).toBe('not_found');
  }
});
