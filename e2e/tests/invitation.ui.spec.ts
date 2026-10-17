import { expect, test } from '@playwright/test';

import { apiPost, PASSWORD, signIn, user } from '../support/tenants';

// CORE-002: an Owner creates a share link in the invitations panel; a guest opens it, signs in, comes
// back to the invitation and joins. The token stays in the URL fragment and is cleared from the
// address bar. Uses `workflow_users`, which belong to no seeded team.

test.describe('Team invitation link @ui', () => {
  test.use({ locale: 'en-US' });

  test('an Owner shares a link and a guest signs in and joins the team', async ({ page, browser }, testInfo) => {
    const teamName = `Invites ${testInfo.project.name} ${Date.now()}`;

    await signIn(page, 'invite_owner');
    const created = await apiPost(page, '/teams', { name: teamName });
    expect(created.status()).toBe(201);
    const teamId = (await created.json()).data.id as string;

    await page.goto(`/teams/${teamId}/invitations`);
    await page.getByLabel('Share link').check();
    await page.getByLabel('Role').selectOption('viewer');
    await page.getByRole('button', { name: 'Create link' }).click();

    const linkField = page.getByLabel('Invitation link');
    await expect(linkField).toHaveValue(/\/invite#[A-Za-z0-9_-]{43}$/);
    const link = await linkField.inputValue();
    await expect(page.getByText('0 of 20 uses')).toBeVisible();

    // A separate browser context: the invitee is a guest.
    const guest = await browser.newContext({ locale: 'en-US' });
    const invitee = await guest.newPage();

    await invitee.goto(link);
    await expect(invitee.getByRole('heading', { name: `You are invited to join ${teamName}` })).toBeVisible();
    await expect(invitee.getByText('Role: Viewer')).toBeVisible();
    expect(new URL(invitee.url()).hash).toBe('');

    await invitee.getByRole('button', { name: 'Sign in to accept' }).click();
    await invitee.getByLabel('Email').fill(user('invite_joiner').email);
    await invitee.getByLabel('Password').fill(PASSWORD);
    await invitee.getByRole('button', { name: 'Sign in' }).click();

    await expect(invitee).toHaveURL(/\/invite$/);
    await invitee.getByRole('button', { name: 'Join the team' }).click();
    await expect(invitee.getByText(`You are now a member of ${teamName}.`)).toBeVisible();

    const teams = await invitee.request.get('/api/v1/teams', { headers: { Accept: 'application/json' } });
    expect((await teams.json()).data).toContainEqual(expect.objectContaining({ id: teamId, role: 'viewer' }));

    await guest.close();

    await page.reload();
    await expect(page.getByText('1 of 20 uses')).toBeVisible();
  });
});
