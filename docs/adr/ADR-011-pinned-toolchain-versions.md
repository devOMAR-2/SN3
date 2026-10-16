# ADR-011 — Pinned toolchain versions for R0

- **Status:** Accepted
- **Date:** 2026-10-16
- **Task:** FND-001 (#2)
- **Decision inputs:** ARCHITECTURE.md §2 ("Versions") and §16 DECIDED-010; AGENTS.md §2 and §5

## Context

DECIDED-010 fixes the major lines — Laravel 12.x, PHP 8.3+, Vue 3.x, TypeScript 5.x, Inertia 2.x, PostgreSQL 16+, Node.js 22 LTS — and requires exact, tested patch versions to be pinned in lockfiles at initialization. ARCHITECTURE.md §2 requires the actual versions to be recorded in an ADR.

## Decision

1. The application lives at the repository root as a single Laravel application (ADR-001/002). It was scaffolded from the official `laravel/laravel` skeleton **v12.12.2**, not from a starter kit: authentication (FND-004) and the design system (FND-006) are separate tasks and must follow `API_SPEC.md` and `DESIGN_SYSTEM.md` instead of starter-kit defaults.
2. `composer.lock` and `package-lock.json` are committed and are the source of truth for installed versions. `package.json` also uses exact versions (`.npmrc` sets `save-exact=true`), so a manifest diff always shows the exact version change.
3. `composer.json` sets `config.platform.php = 8.3.0`. The lock is therefore resolved for the lowest supported PHP, so `composer install` works on any PHP ≥ 8.3 without re-resolving.
4. `package.json` `engines.node` is `^22.12.0` and `.nvmrc` is `22`.
5. Dependabot (Composer, npm, GitHub Actions) opens weekly update PRs against `dev`. Each one is reviewed and must pass CI (FND-003). Dependabot ignores major updates of the DECIDED-010 components (Laravel, Inertia, Vue, TypeScript), because changing a major line requires a new ADR.

### Locked versions at initialization

| Component | Constraint | Locked |
|---|---|---|
| PHP (platform floor) | `^8.3` | 8.3.0 resolution target |
| laravel/framework | `^12.0` | 12.70.0 |
| inertiajs/inertia-laravel | `^2.0` | 2.0.28 |
| pestphp/pest (+ pest-plugin-laravel) | `^4.0` | 4.7.8 (+ 4.1.0), PHPUnit 12.5.33 |
| laravel/pint | `^1.24` | 1.32.1 |
| vue | exact | 3.5.44 |
| @inertiajs/vue3 | exact | 2.3.29 |
| typescript | exact | 5.9.3 |
| vue-tsc | exact | 3.3.13 |
| vite | exact | 8.3.4 |
| laravel-vite-plugin | exact | 3.2.0 |
| @vitejs/plugin-vue | exact | 6.0.10 |
| tailwindcss / @tailwindcss/vite | exact | 4.3.3 |
| eslint / eslint-plugin-vue / @vue/eslint-config-typescript | exact | 10.12.0 / 10.11.1 / 14.9.0 |
| prettier / eslint-config-prettier | exact | 3.9.10 / 10.1.8 |
| PostgreSQL | 16+ | Verified against 16.15 |
| Node.js | 22 LTS | Verified against 22.22.0 |

Transitive versions are in the lockfiles.

### Tooling

| Concern | Tool | Command |
|---|---|---|
| PHP formatting | Pint (`laravel` preset) | `composer lint` / `composer format` |
| PHP tests | Pest 4 (Feature, Unit, Architecture suites) | `composer test` |
| TS/Vue linting | ESLint flat config (`eslint-plugin-vue` recommended + `@vue/eslint-config-typescript`) | `npm run lint` |
| TS/Vue formatting | Prettier | `npm run format:check` / `npm run format` |
| Type checking | vue-tsc (strict) | `npm run typecheck` |
| Build | Vite | `npm run build` |

PHP static analysis, Vitest and CI wiring are FND-003.

## Consequences and known risks

- **Laravel 12 support window.** Upstream bug-fix support for Laravel 12 ended on 2026-08-13; security fixes end on **2027-02-24**. Laravel 13 is the current major. If public v1.0 ships after that date, it would ship on an unsupported framework. DECIDED-010 is binding, so this ADR does not change it. The maintainer should schedule an explicit decision on a Laravel 13 upgrade before R3.
- **Inertia 2 and TypeScript 5 are previous majors.** Inertia 3.x and TypeScript 7.x are the current upstream `latest` releases. Both 2.x and 5.x are pinned as DECIDED-010 requires. The same pre-R3 review applies.
- **Dev-only advisory accepted.** `npm audit` reports GHSA-vfj7-8cjw-p6xm (braces, high) through `@vue/eslint-config-typescript → fast-glob → micromatch → braces`. At the time of writing, no patched `braces` release exists. The only fix npm proposes is a downgrade that makes ESLint 10 unusable. The package runs only in local/CI linting and only expands glob patterns written in this repository, so untrusted input never reaches it. `npm audit --omit=dev` reports 0 vulnerabilities. Re-evaluate when upstream ships a fix.
- The stock skeleton `users`, `cache` and `jobs` migrations and the `User` model are kept unchanged as the starting point. FND-004/FND-005 must replace the `users` schema with `DATABASE.md` §3 (UUIDv7 `id`, `citext` email, `locale`, `timezone`) before any data exists.
