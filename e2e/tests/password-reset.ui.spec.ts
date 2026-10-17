import { expect, test } from '@playwright/test';

import { user } from '../support/tenants';

// Reset links carry the token in the URL fragment, which never reaches the server or its logs.
// The page reads it, removes it from the address bar and submits it to the API.

test.describe('Password reset link @ui', () => {
  test.use({ locale: 'en-US' });

  test('reads the token from the fragment and clears it from the address bar', async ({ page }) => {
    const email = user('alpha_viewer').email;
    const attempt = page.waitForRequest((r) => r.url().endsWith('/api/v1/auth/password/reset'));

    await page.goto(`/reset-password#token=not-a-real-token&email=${encodeURIComponent(email)}`);

    await expect(page.getByLabel('Email')).toHaveValue(email);
    // The fragment is dropped right after mount (router.replace); on a busy runner that can land a moment
    // after the field shows the address, so wait for it rather than reading the URL once.
    await expect.poll(() => new URL(page.url()).hash).toBe('');

    await page.getByLabel('Password').fill('a-brand-new-password-123');
    await page.getByRole('button', { name: 'Save new password' }).click();

    const request = await attempt;
    expect(request.postDataJSON()).toMatchObject({ token: 'not-a-real-token', email });
    await expect(page.getByText('This reset link is invalid or has expired. Request a new one.')).toBeVisible();
  });

  test('explains a link without a token', async ({ page }) => {
    await page.goto('/reset-password');

    await expect(page.getByText('This reset link is invalid or has expired. Request a new one.')).toBeVisible();
  });
});
