import { expect, test } from '@playwright/test';

import { PASSWORD, TEAMS, user } from '../support/tenants';

// Browser-level smoke of the seeded tenants: Arabic RTL and English LTR, sign-in through the
// real form, and no other tenant's data on the signed-in page. Runs on desktop and mobile.

test.describe('Arabic (default) @ui', () => {
  test.use({ locale: 'ar' });

  test('renders the sign-in page right-to-left', async ({ page }) => {
    await page.goto('/login');

    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { name: 'تسجيل الدخول' })).toBeVisible();
  });

  test('signs in through the form and greets the user in Arabic', async ({ page }) => {
    const member = user('alpha_member');
    await page.goto('/login');

    await page.getByLabel('البريد الإلكتروني').fill(member.email);
    await page.getByLabel('كلمة المرور').fill(PASSWORD);
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click();

    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(member.name);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    // No other tenant leaks into the page or its Inertia props.
    const beta = TEAMS.find((t) => t.key === 'beta')!;
    expect(await page.content()).not.toContain(beta.name);
    expect(await page.content()).not.toContain(beta.id);
  });
});

test.describe('English @ui', () => {
  test.use({ locale: 'en-US' });

  test('renders left-to-right and switches to Arabic', async ({ page }) => {
    await page.goto('/login');

    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

    await page.getByRole('button', { name: /العربية/ }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { name: 'تسجيل الدخول' })).toBeVisible();
  });

  test('rejects a wrong password without signing in', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('Email').fill(user('beta_owner').email);
    await page.getByLabel('Password').fill('wrong-password-123');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page).toHaveURL(/\/login$/);
    await expect(
      page
        .getByRole('alert')
        .or(page.getByText(/incorrect|invalid|do not match/i))
        .first(),
    ).toBeVisible();
  });
});
