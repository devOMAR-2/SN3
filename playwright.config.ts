import { defineConfig, devices } from '@playwright/test';

import { e2eEnv, E2E_BASE_URL } from './e2e/support/env';

// End-to-end suite (ARCHITECTURE.md §12, FND-008). See docs/TESTING.md.
// Runs against `php artisan serve` on a dedicated database that global-setup resets and seeds
// with the deterministic tenants in e2e/fixtures/tenants.json. Assets come from `npm run build`
// (or a running `npm run dev`).
export default defineConfig({
  testDir: './e2e/tests',
  globalSetup: './e2e/support/global-setup.ts',
  // Tests share one seeded database and only read from it, so they may run in parallel.
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: E2E_BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE
      ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE }
      : {},
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    // UI flows again at phone size (ARCHITECTURE.md §12: mobile viewport).
    { name: 'mobile', use: { ...devices['Pixel 7'] }, grep: /@ui/ },
  ],
  webServer: {
    command: `php artisan serve --host=127.0.0.1 --port=${new URL(E2E_BASE_URL).port}`,
    // Liveness, not readiness: global setup creates and seeds the database after the server starts.
    url: `${E2E_BASE_URL}/health/live`,
    env: e2eEnv(),
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
    stdout: 'ignore',
    stderr: 'pipe',
  },
});
