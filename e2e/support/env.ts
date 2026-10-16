// Environment for the application under test. Real environment variables win over .env in
// Laravel, so these settings apply to `php artisan serve` and the seeding commands without
// touching the developer's .env.

export const E2E_BASE_URL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8123';

export const E2E_DB_DATABASE = process.env.E2E_DB_DATABASE ?? 'sn3_e2e';

export function e2eEnv(): Record<string, string> {
  return {
    ...(process.env as Record<string, string>),
    APP_ENV: 'e2e',
    APP_URL: E2E_BASE_URL,
    DB_DATABASE: E2E_DB_DATABASE,
    // Many sign-ins from one address in a few seconds: keep limits out of the way.
    SN3_RATE_LIMIT_LOGIN_PER_MINUTE: '0',
    SN3_RATE_LIMIT_BURST_PER_SECOND: '0',
    SN3_RATE_LIMIT_PER_MINUTE: '0',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
  };
}
