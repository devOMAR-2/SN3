import { expect, test } from '@playwright/test';

import { signIn, team as seededTeam } from '../support/tenants';

// CORE-006 main path through the real UI: sign in, create a team, create its first project, add a task,
// open it, edit it (title, priority, checklist), and see the edit persist; then the same task in Arabic,
// right-to-left, with the task key still left-to-right. Writes only with the journey user, who belongs to
// no seeded team, so the read-only specs running in parallel are unaffected.

test.describe('core journey', () => {
  test.use({ locale: 'en-US' });

  test('team → project → task → edit, then Arabic RTL', async ({ page }) => {
    const suffix = Date.now().toString(36);
    const teamName = `Journey ${suffix}`;

    await signIn(page, 'journey_en');
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Journey Writer');

    // Create the team (wizard step 1).
    await page.getByRole('main').getByRole('link', { name: 'Create team' }).click();
    await page.getByLabel('Team name').fill(teamName);
    await page.getByRole('button', { name: 'Create team' }).click();

    // First project (wizard step 2).
    await expect(page.getByRole('heading', { level: 1, name: 'Create your first project' })).toBeVisible();
    await page.getByLabel('Project name').fill('Website');
    await expect(page.getByLabel('Project key')).toHaveValue('WEBSITE');
    await page.getByLabel('Project key').fill('WEB');
    await page.getByRole('button', { name: 'Create project' }).click();

    await expect(page).toHaveURL(/\/t\/journey-[a-z0-9-]+\/p\/WEB(\?view=board)?$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Website' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Board', selected: true })).toBeVisible();
    await expect(page.getByText('No tasks').first()).toBeVisible();

    // Quick add in the To do column: title, then Enter.
    const quickAdd = page.getByLabel('Add task to To do');
    await quickAdd.fill('Fix login validation');
    await quickAdd.press('Enter');
    const taskLink = page.getByRole('link', { name: 'Fix login validation' });
    await expect(taskLink).toBeVisible();

    // Open and edit it.
    await taskLink.click();
    await expect(page).toHaveURL(/\/tasks\/[0-9a-f-]{36}$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Fix login validation' })).toBeVisible();

    await page.getByRole('button', { name: 'Edit title' }).click();
    await page.getByLabel('Title').fill('Fix login validation errors');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Fix login validation errors' })).toBeVisible();

    const saved = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().includes('/api/v1/tasks/'));
    await page.getByLabel('Priority').selectOption('high');
    expect((await saved).status()).toBe(200);

    await page.getByLabel('New checklist item').fill('Write the test');
    await page.getByLabel('New checklist item').press('Enter');
    await expect(page.getByText('Write the test')).toBeVisible();

    // Persisted on the server, not just on screen.
    await page.reload();
    await expect(page.getByRole('heading', { level: 1, name: 'Fix login validation errors' })).toBeVisible();
    await expect(page.getByLabel('Priority')).toHaveValue('high');
    await expect(page.getByText('0 of 1 done')).toBeVisible();

    // The same page in Arabic: right-to-left, translated, with the task key isolated left-to-right.
    await page.getByRole('button', { name: /العربية/ }).click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.getByLabel('الأولوية')).toHaveValue('high');
    await expect(page.getByRole('button', { name: 'تعديل العنوان' })).toBeVisible();
    await expect(page.locator('[data-technical-id]', { hasText: 'WEB-1' }).first()).toHaveAttribute('dir', 'ltr');
  });
});

test.describe('workspace navigation @ui', () => {
  test.use({ locale: 'ar' });

  test('reaches a team from the navigation, right-to-left, without horizontal scrolling', async ({
    page,
    isMobile,
  }) => {
    const alpha = seededTeam('alpha');
    await signIn(page, 'alpha_owner');
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    if (isMobile) {
      await page.getByRole('button', { name: 'فتح قائمة التنقل' }).click();
    }

    const nav = page
      .getByRole(isMobile ? 'dialog' : 'complementary')
      .getByRole('navigation', { name: 'التنقل الرئيسي' });
    await nav.getByRole('button', { name: /اختر فريقًا|تبديل الفريق|الفريق/ }).click();
    await nav.getByRole('link', { name: alpha.name }).click();

    await expect(page).toHaveURL(new RegExp(`/t/${alpha.slug}$`));
    await expect(page.getByRole('heading', { level: 1, name: alpha.name })).toBeVisible();
    // Keyboard reachable: the projects card is a real link.
    await expect(page.getByRole('main').getByRole('link', { name: /المشاريع/ })).toBeVisible();

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);

    // Another team's page is the generic unavailable page, naming nothing.
    const beta = seededTeam('beta');
    const response = await page.goto(`/t/${beta.slug}`);
    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1, name: 'هذه الصفحة غير متاحة' })).toBeVisible();
    expect(await page.content()).not.toContain(beta.name);
  });
});
