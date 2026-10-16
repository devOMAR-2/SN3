# ADR-013 — Continuous integration and merge protection

- **Status:** Accepted
- **Date:** 2026-10-16
- **Task:** FND-003 (#4)
- **Decision inputs:** DEVELOPMENT_PLAN.md FND-003 ("PR blocked on failure; no secrets in logs"); ARCHITECTURE.md §12 (test layers and CI gates) and DECIDED-010; AGENTS.md §5–§7; ADR-011 (pinned toolchain, accepted dev-only advisory); ADR-012 (container image)

## Context

ARCHITECTURE.md §12 lists the CI gates: formatter/linter, static analysis, unit/feature tests, frontend tests, dependency vulnerability review, migration validation and a Docker smoke test. FND-003 adds the missing tools (PHP static analysis, frontend tests) and the pipeline itself. A failing pipeline must block the merge, and no secret may appear in CI logs.

## Decision

### 1. One workflow, six jobs

`.github/workflows/ci.yml` runs on `pull_request` and `push` for `dev` and `main`. The job names are the required checks:

| Check (job name) | What it runs |
|---|---|
| `PHP (Pint, Larastan, migrations, Pest)` | PHP 8.3 (`shivammathur/setup-php`, `intl`, `mbstring`, `pdo_pgsql`); `composer validate --strict`; `composer install` from the lock; `pint --test`; PHPStan/Larastan; `migrate`, `migrate:rollback`, `migrate` again; Pest against a `postgres:16.15-trixie` service container |
| `Frontend (ESLint, Prettier, vue-tsc, Vitest, build)` | Node from `.nvmrc` (22); `npm ci`; `npm run lint`, `format:check`, `typecheck`, `test`, `build` |
| `E2E (Playwright, seeded tenants)` | Added by FND-008 (`docs/TESTING.md`). PHP 8.3 and Node 22; `npm run build`; Playwright's Chromium; `npm run e2e` against a `postgres:16.15-trixie` service: `migrate:fresh` plus `E2ETenantSeeder`, `php artisan serve`, desktop and mobile projects. The HTML report is uploaded only when the job fails |
| `Dependency audit (Composer, npm)` | `composer audit --locked`; `npm audit --omit=dev --audit-level=high` (blocking); full `npm audit` (report only, see §4) |
| `Dependency review` | `actions/dependency-review-action` on pull requests only: fails if the PR adds or changes a runtime dependency with a known vulnerability of any severity |
| `Docker image (build, health smoke test)` | Buildx build of `deploy/docker/Dockerfile` (not pushed, GitHub Actions layer cache), then `docker run` without a database: `/health/live` must return `200 {"status":"ok"}` and `/health/ready` must return `503 {"status":"unavailable"}` |

The jobs run in parallel. PHP tests do not need `npm run build` (`Tests\TestCase` calls `withoutVite()`).

### 2. Tool choices

- **PHP static analysis: Larastan 3.13 (PHPStan 2.3, MIT) at level 9, no baseline.** Level 9 was the strictest level that passes on the code base. It needed one fix: a `(string)` cast on `APP_URL` in `config/filesystems.php`. Level 6 is the strictest that passes with no code change at all. Level 10 is not used: it reports `Route::inertia(...)->name(...)`, because Laravel macros return `mixed`, and idiomatic code would then need casts or ignores. `phpVersion: 80300` pins the analysis to the supported floor whatever PHP runs it. `tests/` is excluded: Pest binds `$this` inside closures at runtime. Run locally with `composer analyse`.
- **Frontend tests: Vitest 5.0.3 + Vue Testing Library (`@testing-library/vue` 8.1.0, which brings `@vue/test-utils` 2.5.1) + jsdom 29.1.1**, all MIT, exact-pinned. This is the ARCHITECTURE.md §12 stack. jsdom 29 was chosen over 30 because jsdom 30 requires Node ≥ 22.22.2, which is newer than the `engines` floor (`^22.12.0`). `vitest.config.ts` is separate from `vite.config.ts`, so the Laravel and Tailwind plugins are not loaded in tests. Tests sit next to the code as `resources/js/**/*.test.ts`. They are type-checked by `vue-tsc` and linted by ESLint. They cannot enter the production bundle, because `app.ts` only globs `Pages/**/*.vue`, and `.dockerignore` keeps them out of the image.
- The workflow uses `ubuntu-24.04`, not `ubuntu-latest`, so runner upgrades show up as deliberate changes.

### 3. Supply-chain hardening

- Every action is pinned to a full commit SHA, with the release in a trailing comment. At selection time, each pinned release was at least two weeks old. The existing Dependabot `github-actions` ecosystem proposes updates against `dev`.
- The top-level `permissions` are `contents: read`, and no job needs more. `actions/checkout` runs with `persist-credentials: false`, so later steps cannot reuse the token.
- `concurrency` cancels superseded runs of the same PR. Pushes to `dev`/`main` always run to completion.
- Composer downloads are cached with `actions/cache`, keyed on `composer.lock`. npm uses the `actions/setup-node` cache, keyed on `package-lock.json`. Docker layers use the `gha` Buildx cache.

### 4. Dependency advisories

- **Composer:** `composer audit --locked` fails on any advisory in any locked package (production and dev).
- **npm, production dependencies:** `npm audit --omit=dev --audit-level=high` fails on high or critical advisories. These are the packages that reach the browser bundle.
- **npm, development dependencies:** a full `npm audit` runs in report-only mode and emits a workflow warning. It does not fail the job, because ADR-011 accepts GHSA-vfj7-8cjw-p6xm (`braces`, via `@vue/eslint-config-typescript`): it has no upstream fix and its input never comes from users. Dev tooling never ships in the image (ADR-012). If a dev advisory becomes exploitable in CI, it must be fixed or recorded in an ADR.
- **New dependencies:** the Dependency review job blocks PRs that introduce or change a vulnerable runtime dependency. Dependabot security updates are enabled for the repository.

### 5. No secrets in logs

- The pipeline needs **no repository secrets**. `GITHUB_TOKEN` is read-only and is not persisted in the checkout.
- PostgreSQL in CI is a job-private service container with `POSTGRES_HOST_AUTH_METHOD=trust`, so no database password exists.
- `APP_KEY` for the PHP job is created by `php artisan key:generate`, which writes it to `.env` and prints only a confirmation. No step prints the environment, `.env` or the key.
- The Docker smoke test generates a throwaway key with `openssl rand`, registers it with `::add-mask::` before using it, and passes it to `docker run` by name (`-e APP_KEY`), so it is not on the command line. Container logs are printed only on failure, and they cannot contain the masked value.
- Rule for future jobs: secrets come only from `secrets.*` (GitHub masks them automatically), any derived sensitive value is masked with `::add-mask::` before first use, and `env`, `printenv`, `set -x` and `cat .env` are never used.

### 6. Merge protection ("PR blocked on failure")

The repository ruleset **"CI required (dev, main)"** (`refs/heads/dev`, `refs/heads/main`, enforcement active) enforces:

- **Required status checks:** the job names above, with GitHub Actions as the source (`integration_id` 15368, so another app cannot satisfy a check by posting a status with the same name). A PR cannot merge until each check has passed on its head commit. Failed, cancelled and pending checks all block. Dependency review runs only for PRs, which is the only way into these branches.
- **Pull request required, zero approvals.** Changes reach `dev`/`main` only through a PR, so the checks always run before a merge. No review is required: the maintainer opens and merges PRs from a single account, so a required approval could never be given. "Extra approval for unattributed changes" is off for the same reason.
- **Not strict (no "require branches to be up to date").** Several task PRs are open against `dev` at the same time. With strict checks, every merge would force all other PRs to be rebased and re-run before they could merge, which serialises parallel work. The cost is that a PR is validated against the `dev` it was based on, not the newest `dev`. The `push` trigger re-runs the full pipeline on every commit that lands on `dev`/`main`, so a semantic conflict between two individually green PRs shows up immediately on the branch.
- **Block force pushes and branch deletion** on `dev` and `main`.
- **No bypass actors, including administrators.** The maintainer's account is also the one that merges agent-prepared PRs. An admin bypass would therefore let a red PR merge silently through the API, and the "blocked on failure" guarantee would be lost exactly where it matters most. This still leaves an emergency path. A repository admin can always edit a ruleset, so for a hotfix while GitHub Actions is down, the maintainer sets the ruleset's enforcement to *Disabled* (Settings → Rules → Rulesets), merges, and re-enables it. That is a deliberate, one-step action recorded in the ruleset history, not a checkbox on every merge.

The pre-existing "Protect main" ruleset (default branch: PR with one approval, resolved threads, linear history, squash only, admin bypass) is left unchanged. Rulesets stack, so `main` gets the union of both rulesets. Its one-approval rule is satisfiable only through its admin bypass. The maintainer should decide whether to keep it.

## Consequences

- Renaming or adding a CI job changes the set of checks. Once the PR that renames or adds the job has run green, swap the name in the ruleset, then merge. Otherwise every PR waits forever for a check that no longer reports.
- New PHP code must pass PHPStan level 9 without a baseline. Configuration code that passes `env()` values into typed functions needs an explicit cast.
- Frontend tests are `*.test.ts` files under `resources/js/`. Pages that use Inertia's `<Head>` need the `$headManager` mock shown in `Welcome.test.ts`.
- The Docker job builds the image on every PR, but nothing is published. Image publishing is GH-008.
- E2E (Playwright) joined the pipeline with FND-008 and becomes a required check once the ruleset lists it. The clean Compose install/backup/restore smoke test (ARCHITECTURE.md §12, deployment row) is not part of this pipeline yet; it should be added as a further required check when that task lands.
