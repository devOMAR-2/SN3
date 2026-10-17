import { expect, test, type Page } from '@playwright/test';

import { apiPost, signIn } from '../support/tenants';

// CORE-007: the Board and the List. Moves made by dragging and with the keyboard (Move dialog) persist
// across a reload; filters live in the URL; the List sorts and filters on the server. Writes only with the
// `board_en` journey user, who belongs to no seeded team.

async function project(page: Page): Promise<{ slug: string; projectId: string }> {
  const suffix = Date.now().toString(36);
  const team = await apiPost(page, '/teams', { name: `Board ${suffix}` });
  expect(team.status()).toBe(201);
  const { id: teamId, slug } = (await team.json()).data as { id: string; slug: string };

  const created = await apiPost(page, `/teams/${teamId}/projects`, { name: 'Board', key: 'BRD' });
  expect(created.status()).toBe(201);
  const projectId = (await created.json()).data.id as string;

  for (const [title, priority] of [
    ['Alpha', 'high'],
    ['Bravo', 'none'],
    ['Charlie', 'low'],
  ]) {
    const task = await apiPost(
      page,
      `/projects/${projectId}/tasks`,
      { title, priority },
      { 'Idempotency-Key': crypto.randomUUID() },
    );
    expect(task.status()).toBe(201);
  }

  return { slug, projectId };
}

function column(page: Page, name: string) {
  return page.getByRole('region', { name });
}

async function titles(page: Page, name: string): Promise<string[]> {
  return column(page, name).getByRole('listitem').getByRole('link').allInnerTexts();
}

test.describe('board and list', () => {
  test.use({ locale: 'en-US', viewport: { width: 1440, height: 900 } });

  test('moves tasks by keyboard and by drag, and the moves persist', async ({ page }) => {
    await signIn(page, 'board_en');
    const { slug } = await project(page);

    await page.goto(`/t/${slug}/p/BRD?view=board`);
    await expect.poll(() => titles(page, 'To do')).toEqual(['Alpha', 'Bravo', 'Charlie']);

    // Keyboard: the card's Move button, then status and place.
    await column(page, 'To do').getByRole('button', { name: 'Move BRD-3…' }).click();
    const dialog = page.getByRole('dialog', { name: 'Move BRD-3' });
    await dialog.getByLabel('Status').selectOption({ label: 'Done' });
    await dialog.getByRole('button', { name: 'Move' }).click();
    await expect.poll(() => titles(page, 'Done')).toEqual(['Charlie']);
    await expect(page.getByText('BRD-3 moved to Done, position 1 of 1.')).toBeAttached();

    await column(page, 'To do').getByRole('button', { name: 'Move BRD-2…' }).click();
    await page.getByRole('dialog', { name: 'Move BRD-2' }).getByLabel('Position').selectOption({ label: 'Move up' });
    await page.getByRole('dialog', { name: 'Move BRD-2' }).getByRole('button', { name: 'Move' }).click();
    await expect.poll(() => titles(page, 'To do')).toEqual(['Bravo', 'Alpha']);

    // Drag Alpha onto the In progress column.
    const alpha = column(page, 'To do').locator('article', { hasText: 'Alpha' });
    await alpha.dragTo(column(page, 'In progress'));
    await expect.poll(() => titles(page, 'In progress')).toEqual(['Alpha']);

    // Persisted on the server.
    await page.reload();
    await expect.poll(() => titles(page, 'To do')).toEqual(['Bravo']);
    await expect.poll(() => titles(page, 'In progress')).toEqual(['Alpha']);
    await expect.poll(() => titles(page, 'Done')).toEqual(['Charlie']);
  });

  test('filters live in the URL, and the list sorts on the server', async ({ page }) => {
    await signIn(page, 'board_en');
    const { slug } = await project(page);

    await page.goto(`/t/${slug}/p/BRD?view=board`);
    await page.getByRole('group', { name: 'Priority' }).getByText('High').click();
    await expect(page).toHaveURL(/priority=high/);
    await expect.poll(() => titles(page, 'To do')).toEqual(['Alpha']);

    // The filtered view survives a reload and switches to the List with its sort.
    await page.reload();
    await expect.poll(() => titles(page, 'To do')).toEqual(['Alpha']);
    await page.getByRole('tab', { name: 'List' }).click();
    await expect(page).toHaveURL(/view=list/);
    await page.getByRole('group', { name: 'Priority' }).getByText('High').click();
    await page.getByLabel('Sort by').selectOption('priority');
    await expect(page).toHaveURL(/sort=priority/);
    await expect
      .poll(() => page.getByRole('table').getByRole('link').allInnerTexts())
      .toEqual(['Alpha', 'Charlie', 'Bravo']);
  });
});

test.describe('board on a phone @ui', () => {
  test.use({ locale: 'en-US' });

  test('shows one column at a time and moves with the dialog, without horizontal scrolling', async ({
    page,
    isMobile,
  }) => {
    test.skip(!isMobile, 'Phone layout only.');
    await signIn(page, 'board_en');
    const { slug } = await project(page);

    await page.goto(`/t/${slug}/p/BRD?view=board`);
    await expect.poll(() => titles(page, 'To do')).toEqual(['Alpha', 'Bravo', 'Charlie']);
    await expect(column(page, 'Done')).toHaveCount(0);

    await column(page, 'To do').getByRole('button', { name: 'Move BRD-1…' }).click();
    const dialog = page.getByRole('dialog', { name: 'Move BRD-1' });
    await dialog.getByLabel('Status').selectOption({ label: 'Done' });
    await dialog.getByRole('button', { name: 'Move' }).click();
    await expect.poll(() => titles(page, 'To do')).toEqual(['Bravo', 'Charlie']);

    await page.getByLabel('Column').selectOption({ label: 'Done' });
    await expect.poll(() => titles(page, 'Done')).toEqual(['Alpha']);

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
});
