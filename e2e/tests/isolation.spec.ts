import { expect, test, type Page } from '@playwright/test';

import { apiGet, canSee, PROJECTS, signIn, team, USERS, type SeededProject } from '../support/tenants';

// CORE-011 acceptance through the running application: two teams with private projects, and for every
// seeded user every seeded project they may not see — by direct URL (pages), the API, file download,
// search and the inbox. Each refusal is the same bare 404, and no response carries the project's canary.
// Read-only: it changes nothing, so it runs alongside the other specs.

function project(key: string): SeededProject {
  const found = PROJECTS.find((p) => p.key === key);
  if (!found) throw new Error(`Unknown seeded project ${key}`);
  return found;
}

function hiddenFrom(userKey: string): SeededProject[] {
  return PROJECTS.filter((project) => !canSee(userKey, project));
}

async function expectRefused(page: Page, path: string, canary: string): Promise<void> {
  const response = await apiGet(page, path);
  const body = await response.text();
  expect(response.status(), path).toBe(404);
  expect(body, path).not.toContain(canary);
  expect(JSON.parse(body).error.code, path).toBe('not_found');
}

for (const user of USERS) {
  const hidden = hiddenFrom(user.key);

  test(`${user.key} reaches none of the ${hidden.length} projects hidden from them`, async ({ page }) => {
    await signIn(page, user.key);

    for (const project of hidden) {
      const slug = team(project.team).slug;

      // Direct URLs of pages: the generic unavailable page, naming nothing.
      for (const path of [`/t/${slug}/p/${project.project_key}`, `/tasks/${project.ids.task}`]) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(404);
        expect(await page.content(), path).not.toContain(project.canary);
      }

      // The API, and the file behind the attachment.
      for (const path of [
        `/projects/${project.ids.project}`,
        `/projects/${project.ids.project}/tasks`,
        `/tasks/${project.ids.task}`,
        `/tasks/${project.ids.task}/comments`,
        `/tasks/${project.ids.task}/attachments`,
        `/attachments/${project.ids.attachment}/download`,
      ]) {
        await expectRefused(page, path, project.canary);
      }
    }

    // Their assigned tasks, and a search of every project they can see.
    for (const project of hidden) {
      const mine = await apiGet(page, '/me/tasks');
      expect(mine.status()).toBe(200);
      expect(await mine.text()).not.toContain(project.canary);

      for (const visible of PROJECTS.filter((p) => canSee(user.key, p))) {
        const found = await apiGet(page, `/projects/${visible.ids.project}/tasks?q=${project.canary}`);
        expect(found.status()).toBe(200);
        expect(await found.text()).not.toContain(project.canary);
      }
    }

    // The inbox: no hidden notification, and the count agrees.
    const expected = PROJECTS.filter((p) => canSee(user.key, p))
      .flatMap((p) => p.notifications)
      .filter((note) => note.to === user.key)
      .map((note) => note.id)
      .sort();
    const inbox = await apiGet(page, '/notifications');
    const ids = ((await inbox.json()).data as { id: string }[]).map((item) => item.id).sort();
    expect(ids).toEqual(expected);
    const count = await apiGet(page, '/notifications/unread-count');
    expect((await count.json()).data.unread_count).toBe(expected.length);
  });
}

test('the same URLs answer the Team Owner, so a refusal above is never just a wrong URL', async ({ page }) => {
  await signIn(page, 'beta_owner');
  const hidden = project('beta_private');

  for (const path of [
    `/projects/${hidden.ids.project}`,
    `/projects/${hidden.ids.project}/tasks`,
    `/tasks/${hidden.ids.task}`,
    `/tasks/${hidden.ids.task}/comments`,
    `/tasks/${hidden.ids.task}/attachments`,
  ]) {
    const response = await apiGet(page, path);
    expect(response.status(), path).toBe(200);
    expect(await response.text(), path).toContain(hidden.canary);
  }

  const file = await apiGet(page, `/attachments/${hidden.ids.attachment}/download`);
  expect(file.status()).toBe(200);
  expect(await file.text()).toBe(`File content ${hidden.canary}`);

  for (const path of [`/t/${team('beta').slug}/p/${hidden.project_key}`, `/tasks/${hidden.ids.task}`]) {
    expect((await page.goto(path))?.status(), path).toBe(200);
  }
});

test.describe('the inbox and project pages in the browser @ui', () => {
  test('a team Admin without membership sees the team project, never the private one', async ({ page }) => {
    // `shared` is a beta Admin with no membership of the private beta project, and was mentioned there.
    await signIn(page, 'shared');
    const teamProject = project('beta_team');
    const privateProject = project('beta_private');

    await page.goto('/inbox');
    await expect(page.getByText(`Task ${teamProject.canary}`)).toBeVisible();
    await expect(page.getByText(privateProject.canary)).toHaveCount(0);
    expect(await page.content()).not.toContain(privateProject.canary);

    await page.goto(`/t/${team('beta').slug}/projects`);
    await expect(page.getByRole('main').getByText(`Project ${teamProject.canary}`)).toBeVisible();
    expect(await page.content()).not.toContain(privateProject.canary);

    // Searching the visible project for the private task finds nothing.
    await page.goto(`/t/${team('beta').slug}/p/${teamProject.project_key}?view=list&q=${privateProject.canary}`);
    await expect(page.getByText('No tasks match these filters.')).toBeVisible();
    expect(await page.content()).not.toContain(`Task ${privateProject.canary}`);
  });
});
