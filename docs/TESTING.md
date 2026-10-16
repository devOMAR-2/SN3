# Testing

Test layers follow ARCHITECTURE.md §12. Every layer runs in CI on each pull request to `dev` and `main` (ADR-013).

| Layer | Tool | Where | Command |
|---|---|---|---|
| Unit, feature, architecture | Pest + PostgreSQL | `tests/` | `composer test` |
| Frontend components | Vitest + Vue Testing Library | `resources/js/**/*.test.ts` | `npm run test` |
| End-to-end | Playwright (Chromium, desktop and mobile) | `e2e/` | `npm run e2e` |

## Seeded test tenants

`e2e/fixtures/tenants.json` is the single source of the deterministic test data (FND-008). `Database\Seeders\E2ETenantSeeder` writes it to the database, and the Playwright suite reads it.

| Team | Owner | Admin | Member | Viewer |
|---|---|---|---|---|
| `alpha` (فريق ألفا) | `alpha_owner` | `alpha_admin` | `alpha_member` | `alpha_viewer`, `shared` |
| `beta` (Beta Studio) | `beta_owner` | `shared` | `beta_member` | none |

`outsider` belongs to no team. Every user, team and membership has a fixed UUIDv7, every email is verified, and all accounts share the fixture password. Names mix Arabic and English.

The password is public, so the seeder throws unless `APP_ENV` is `local`, `testing` or `e2e`. It also expects an empty database:

```bash
php artisan migrate:fresh --seed --seeder="Database\Seeders\E2ETenantSeeder"
```

Add a user or team in `tenants.json` when a later task needs one (private projects arrive with CORE-003). Tests read the fixture rather than hard-coding IDs.

Tenant isolation is proved twice:

- **`tests/Feature/Tenancy/SeededTenantIsolationTest.php` (Pest):** every user × every team through the API and the policies. Fast, and part of `composer test`.
- **`e2e/tests/tenant-isolation.spec.ts` (Playwright):** the same matrix through the running app with real sessions and CSRF. A foreign team must look exactly like a missing one.

## Running the E2E suite locally

Requirements are the same as for development: PHP 8.3+, PostgreSQL, Node 22. Then, once:

```bash
npx playwright install chromium
```

Each run:

```bash
npm run build      # or keep `npm run dev` running
npm run e2e
```

What `npm run e2e` does:

1. Starts `php artisan serve` on `http://127.0.0.1:8123`, or reuses one already listening there outside CI.
2. **Resets the `sn3_e2e` database** (`migrate:fresh` plus the seeder). Laravel creates the database if your role may create databases; otherwise create it once.
3. Runs the specs: all of them on desktop Chromium, and the `@ui` ones again on a Pixel 7 viewport.

The application under test gets its settings from environment variables, which override `.env`. They are defined in `e2e/support/env.ts`: `APP_ENV=e2e`, the `sn3_e2e` database, rate limits off, the `sync` queue and the `array` mailer. Your own database is never touched.

| Variable | Default | Purpose |
|---|---|---|
| `E2E_BASE_URL` | `http://127.0.0.1:8123` | Where the app under test listens |
| `E2E_DB_DATABASE` | `sn3_e2e` | Database to reset. The name must contain `e2e`. |
| `E2E_SKIP_DB_RESET` | unset | `1` keeps the current data, for example while iterating on one spec |
| `PLAYWRIGHT_CHROMIUM_EXECUTABLE` | unset | Use an already installed Chromium instead of Playwright's download |

Reports are written to `playwright-report/` (open it with `npx playwright show-report`); traces and screenshots are kept for failed tests in `test-results/`. Both are git-ignored. In CI, the report is uploaded as an artifact when the job fails.

## Writing E2E tests

- Prefer accessible locators (`getByRole`, `getByLabel`) with the real Arabic or English text. They double as accessibility checks.
- Tag browser-UI tests with `@ui` so they also run on the mobile project.
- Sign in with `signIn(page, 'alpha_member')` from `e2e/support/tenants.ts`. It uses the same session and CSRF flow as the login form. Drive the form itself only when the form is what you are testing.
- Tests may run in parallel against the shared seeded data, so they must not change it. A test that needs to write creates its own records.
