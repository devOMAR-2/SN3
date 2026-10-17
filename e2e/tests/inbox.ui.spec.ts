import { randomUUID } from 'node:crypto';

import { expect, test } from '@playwright/test';

import { apiPost, apiWrite, signIn, user } from '../support/tenants';

// CORE-009: the inbox through the real UI. An Owner invites a member by email (the member sees the
// in-app invitation), the member joins, the Owner assigns them a task; the member finds it in the inbox,
// opens it (marked read, badge gone) and watches it. When the project is made private without them, the
// notification and the project's name disappear from their inbox (NOT-01). Each viewport has its own
// pair of `inbox_*` users (no seeded team), so the desktop and mobile runs never see each other's data.

test.describe('Inbox @ui', () => {
  test.use({ locale: 'en-US' });

  test('invitation, assignment, open, watch, then the project turns private (NOT-01)', async ({
    page,
    browser,
  }, testInfo) => {
    const viewport = testInfo.project.name === 'mobile' ? 'mobile' : 'desktop';
    const owner = `inbox_owner_${viewport}`;
    const member = `inbox_member_${viewport}`;
    const suffix = Date.now().toString(36);
    const teamName = `Inbox ${viewport} ${suffix}`;
    const projectName = `Secret ${suffix}`;

    // The Owner creates a team and invites the member by email.
    await signIn(page, owner);
    const team = (await (await apiPost(page, '/teams', { name: teamName })).json()).data as { id: string };
    const invited = await apiPost(
      page,
      `/teams/${team.id}/invitations`,
      { type: 'email', email: user(member).email, role: 'member' },
      { 'Idempotency-Key': randomUUID() },
    );
    expect(invited.status()).toBe(201);
    const token = ((await invited.json()).data as { token: string }).token;

    // The member sees the in-app invitation.
    const memberContext = await browser.newContext({ ...testInfo.project.use, locale: 'en-US' });
    const inbox = await memberContext.newPage();
    await signIn(inbox, member);
    await inbox.goto('/inbox');
    await expect(inbox.getByRole('heading', { level: 1, name: 'Inbox' })).toBeVisible();
    await expect(inbox.getByText(`Inbox Owner invited you to join ${teamName}`)).toBeVisible();
    await expect(inbox.getByText('To join, open the link in your invitation email.')).toBeVisible();

    const accepted = await apiPost(inbox, `/invitations/${token}/accept`, {});
    expect(accepted.status()).toBe(201);

    // The Owner creates a project and assigns the member a task.
    const project = (
      await (await apiPost(page, `/teams/${team.id}/projects`, { name: projectName, key: 'SEC' })).json()
    ).data as { id: string; version: number };
    const created = await apiPost(
      page,
      `/projects/${project.id}/tasks`,
      { title: `Plan ${suffix}`, assignee_user_id: user(member).id },
      { 'Idempotency-Key': randomUUID() },
    );
    expect(created.status()).toBe(201);
    const taskId = ((await created.json()).data as { id: string }).id;

    // The accepted invitation is gone; the assignment is there, unread.
    await inbox.reload();
    await expect(inbox.getByText('Inbox Owner assigned you a task')).toBeVisible();
    await expect(inbox.getByText(`Inbox Owner invited you to join ${teamName}`)).toHaveCount(0);
    await expect(inbox.getByRole('banner').getByRole('link', { name: 'Inbox, 1 unread' })).toBeVisible();
    await expect(inbox.locator('[data-technical-id]', { hasText: 'SEC-1' })).toHaveAttribute('dir', 'ltr');

    // Opening it marks it read and goes to the task, where the member starts watching it.
    await inbox.getByRole('link', { name: new RegExp(`SEC-1\\s*Plan ${suffix}`) }).click();
    await expect(inbox).toHaveURL(new RegExp(`/tasks/${taskId}$`));
    await expect(inbox.getByRole('heading', { level: 1, name: `Plan ${suffix}` })).toBeVisible();
    const watch = inbox.getByRole('button', { name: 'Watch', exact: true });
    await expect(watch).toHaveAttribute('aria-pressed', 'false');
    await watch.click();
    await expect(watch).toHaveAttribute('aria-pressed', 'true');

    await inbox.goto('/inbox');
    await expect(inbox.getByText('Inbox Owner assigned you a task')).toBeVisible();
    await expect(inbox.getByRole('button', { name: 'Mark as read' })).toHaveCount(0);
    await expect(inbox.getByRole('banner').getByRole('link', { name: 'Inbox', exact: true })).toBeVisible();

    // An edit reaches the watcher; "Mark all as read" clears it and says so.
    const edited = await apiWrite(page, 'patch', `/tasks/${taskId}`, { priority: 'high' }, { 'If-Match': '"1"' });
    expect(edited.status()).toBe(200);
    await inbox.reload();
    await expect(inbox.getByText('Inbox Owner updated a task you watch')).toBeVisible();
    await expect(inbox.getByText('Changed: priority')).toBeVisible();
    await inbox.getByRole('button', { name: 'Mark all as read' }).click();
    await expect(inbox.getByRole('status').filter({ hasText: 'All notifications are marked as read.' })).toBeAttached();
    await expect(inbox.getByRole('button', { name: 'Mark as read' })).toHaveCount(0);

    // The project is made private without the member: nothing of it is left in their inbox.
    const madePrivate = await apiWrite(
      page,
      'patch',
      `/projects/${project.id}`,
      { visibility: 'private' },
      { 'If-Match': `"${project.version}"` },
    );
    expect(madePrivate.status()).toBe(200);

    await inbox.reload();
    await expect(inbox.getByText('No notifications yet')).toBeVisible();
    await expect(inbox.getByText(projectName)).toHaveCount(0);
    const list = await inbox.request.get('/api/v1/notifications', { headers: { Accept: 'application/json' } });
    expect(await list.text()).not.toContain(projectName);
    expect(await list.text()).not.toContain(`Plan ${suffix}`);

    // The same inbox in Arabic, right to left.
    expect((await apiWrite(inbox, 'patch', '/me', { locale: 'ar' })).status()).toBe(200);
    await inbox.reload();
    await expect(inbox.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(inbox.getByRole('heading', { level: 1, name: 'الوارد' })).toBeVisible();
    await expect(inbox.getByText('لا توجد إشعارات بعد')).toBeVisible();

    await memberContext.close();
  });
});
