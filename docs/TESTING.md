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

`outsider` belongs to no team. `journey_users` (`journey_en`, CORE-006; `inbox_owner_*` and `inbox_member_*`, CORE-009) are also in no seeded team: `e2e/tests/core-journey.spec.ts` and `e2e/tests/inbox.ui.spec.ts` (one pair of users per viewport) sign in with them to create their own teams, projects and tasks, so their writes never reach the data the other specs read. Every user and team has a fixed UUIDv7 (membership IDs are generated), every email is verified, and all accounts share the fixture password. Names mix Arabic and English.

The password is public, so the seeder throws unless `APP_ENV` is `local`, `testing` or `e2e`. It also expects an empty database:

```bash
php artisan migrate:fresh --seed --seeder="Database\Seeders\E2ETenantSeeder"
```

Add a user, team or project in `tenants.json` when a later task needs one. Tests read the fixture rather than hard-coding IDs.

`projects` (CORE-011) seeds, per team, a team-visible and a private project, each with a task, label, checklist item, comment with mentions, attachment, extra status and inbox notifications. Every text field carries the project's **canary** (`CNRY-ALPHA-PRIV`, …), so a test can assert that a response holds no canary its viewer may not see. In both private projects the Team Admin has no membership (`alpha_admin`, and `shared` in beta), and was mentioned there, so they hold notifications they must never see.

Tenant isolation is proved at three levels:

- **`tests/Feature/Tenancy/SeededTenantIsolationTest.php` (Pest):** every user × every team through the API and the policies. Fast, and part of `composer test`.
- **`e2e/tests/tenant-isolation.spec.ts` (Playwright):** the same matrix (`/me/teams`, `/teams`, `/teams/{id}`) through the running app with real sessions and CSRF. A foreign team must look exactly like a missing one. Both of these fail when the membership filter is removed from `AccessibleTeams`.
- **`tests/Feature/Isolation/` (Pest, CORE-011):** the whole application against the seeded world. What a user may not see is a list of *needles*: the canaries and row IDs of projects hidden from them, the name, slug and IDs of teams they are not in, their teams' private trashed project unless they own the team, and the name, email and ID of every person they share no team with. Needles are matched as written and as JSON and HTML encode them (`\u0641…` for Arabic), after removing the echo of the request's own URL.
  - `RouteInventoryTest`: every registered route is listed in `IsolationRoutes`, as `SCOPED` (it names a tenant resource) or `OPEN` with its kind. A new route fails the suite until it is classified, and every parameter of a scoped route must have a value in the world.
  - `CrossTenantMatrixTest`: every scoped route, for every seeded resource, by every user who may not see it: `404`, no needle in the body, and no change to the application's tables, the stored attachment and export files or the queued jobs. The same URL then reaches the resource for a user who may see it (any answer but 404, 405 or 5xx), so a 404 is never just a wrong URL; guests get `401` or the sign-in redirect.
  - `GuessedIdentifierTest`: another tenant's real ID (and, for a Team Admin, a private project of their own team), a random UUID, and uppercased, suffixed, malformed and injection-shaped IDs get the same status, headers (apart from date, cookies, request ID and rate-limit counters) and body.
  - `ListingLeakTest`: every page, list, activity feed and search (by text, task number and key) each user can open holds no needle; searches find exactly the visible tasks; the inbox and unread count list exactly the visible notifications. The world adds a stale assignment and a stale activity entry of each private project's Admin, rows the API cannot create today, so `/me/tasks` and `/me/activity` are proved to filter by visibility.
  - Checked by mutation, each failing the suite: a Team Admin seeing private projects; unscoped teams, tasks, comments, attachments, `/me/tasks` or `/me/activity`; the inbox project check removed; search by number escaping its project; cross-team assignee names; a project or team name on the 404 page; Inertia props listing hidden projects; a header that differs for an ID that exists.
- **`e2e/tests/isolation.spec.ts` (Playwright, CORE-011):** for every seeded user and every project hidden from them, the page URLs, API, attachment download, assigned tasks, project search and inbox through the running app; the same URLs answer the Team Owner (a positive control). A browser walk (`@ui`) shows a non-member Team Admin the team project and never the private one.

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

The application under test gets its settings from environment variables, which override `.env`. They are defined in `e2e/support/env.ts`: `APP_ENV=e2e`, the `sn3_e2e` database (with `DB_URL` cleared), rate limits off, the `sync` queue and the `array` mailer.

Before resetting anything, global setup asks the app which database and environment it actually resolves to, and stops unless the answer is `sn3_e2e` and `e2e`. A cached config (`php artisan config:cache` or `optimize`) ignores environment variables, so it makes the run stop instead of resetting your own database. Clear it with `php artisan config:clear`.

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
