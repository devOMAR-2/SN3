<p align="center">
  <img src="assets/sn3-preview.png" alt="سنع SN3 — Open Source Project Management" width="100%" />
</p>

**Free, open-source project management for modern teams.**

سنع منصة لإدارة الفرق والمشاريع والمهام، بواجهة عربية وإنجليزية، ويمكن تشغيلها سحابيًا أو استضافتها ذاتيًا.

> **Status:** R0 — Foundation in progress. The application scaffold exists. No product features have shipped yet.

## Planned features
- Teams, invitations, and role-based access
- Public-to-team and private projects with strict access controls
- Tasks, subtasks, workflows, Kanban, list, and calendar views
- Optional sprints, custom fields, templates, and saved views
- GitHub App integration (v1.0); optional GitHub Issues sync (v1.1)
- Arabic RTL / English LTR; light and dark themes

## Technology
Laravel 12 · PHP 8.3+ · Vue 3 · TypeScript · Inertia 2 · PostgreSQL 16+ · Docker Compose (planned, FND-002)

Exact versions are in `composer.lock` and `package-lock.json`, and are recorded in [ADR-011](docs/adr/ADR-011-pinned-toolchain-versions.md).

## Local development
Requirements: PHP 8.3+ (extensions `pdo_pgsql`, `mbstring`, `intl`), Composer 2, Node.js 22 LTS, PostgreSQL 16+. No proprietary or paid service is required. Redis is optional.

```bash
composer install
npm ci
cp .env.example .env              # Windows: copy .env.example .env
php artisan key:generate
# Create the PostgreSQL role/database named in .env (defaults: user sn3, database sn3).
php artisan migrate
npm run build                     # or `npm run dev` during development
php artisan serve
```

`composer dev` runs the web server, queue worker and Vite together.

| Check | Command |
| --- | --- |
| PHP tests (Pest) | `composer test` (uses the `sn3_testing` database from `phpunit.xml` once tests touch the DB) |
| PHP style (Pint) | `composer lint` · fix with `composer format` |
| TS/Vue lint (ESLint) | `npm run lint` |
| TS/Vue format (Prettier) | `npm run format:check` · fix with `npm run format` |
| Type check (vue-tsc) | `npm run typecheck` |
| Production build | `npm run build` |

## Roadmap
| Phase | Scope |
| --- | --- |
| R0 | Foundation |
| R1 | Core team and project management |
| R2 | Customization and sprints |
| R3 | GitHub App integration and public v1.0 |
| R4 | GitHub Issues sync and v1.1 |

Follow progress through [Issues](../../issues) and the repository's linked GitHub Project when configured. No release dates are committed yet.

## Documentation
Read [`AGENTS.md`](AGENTS.md) for AI contributor instructions, and [`docs/DEVELOPMENT_PLAN.md`](docs/DEVELOPMENT_PLAN.md) for planned tasks. Full requirements, architecture, database, API, and design documents live in `docs/`.

## Contributing
See [CONTRIBUTING.md](CONTRIBUTING.md). Please don't start unassigned implementation work. Security concerns: [SECURITY.md](SECURITY.md).

## License
Licensed under **GNU Affero General Public License v3.0 only (AGPL-3.0-only)**. See [LICENSE](LICENSE).
