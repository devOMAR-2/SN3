# Contributing to SN3

Thanks for your interest in سنع (SN3).

## Project status
The R0 foundation is complete and R1 (core features) is next. Please discuss proposed work in an Issue before writing implementation code. Do not treat a documented task as authorization to implement it.

## Source of truth
1. `AGENTS.md` for AI-agent operating rules.
2. `docs/READINESS_REPORT.md` for documentation readiness and unresolved implementation validation.
3. `docs/DEVELOPMENT_PLAN.md` for task IDs and dependencies.
4. `docs/BRD.md`, `docs/ARCHITECTURE.md`, `docs/DATABASE.md`, `docs/API_SPEC.md`, and `docs/DESIGN_SYSTEM.md` for requirements and technical contracts.

If documents conflict, open a documentation Issue with exact file/section references; don't silently choose a new product policy.

## Issues
Use the GitHub Issue Forms for bugs, features, and development tasks. Planned tasks must keep their IDs (e.g. `FND-001`), release, dependencies, and acceptance criteria. Search existing Issues before opening a duplicate.

## Workflow (once implementation is authorized)
1. Take an Issue marked **Ready** with its dependencies resolved.
2. Create a branch such as `feature/FND-001-foundation`, `fix/CORE-005-validation`, or `docs/update-api-spec`.
3. Make a focused change. Follow security, tenant isolation, localization, accessibility, and testing requirements.
4. Branch from `dev` and open the Pull Request against `dev`, using the template. Link the Issue with `Closes #123` only when the PR fully resolves it. `main` only receives release PRs that merge `dev` into `main`.
5. Wait for required checks and reviews before merging. Never bypass branch protection.

Use conventional commits: `feat:`, `fix:`, `docs:`, `test:`, `chore:`, `refactor:`.

## Local checks
Run the checks listed under **Local development** in the [README](README.md) (`composer lint`, `composer analyse`, `composer test`, `npm run lint`, `npm run format:check`, `npm run typecheck`, `npm run test`, `npm run build`, `npm run e2e`) before opening a PR. [docs/TESTING.md](docs/TESTING.md) explains the test layers and the seeded tenants.

## Continuous integration
Every pull request to `dev` or `main` runs the CI workflow (`.github/workflows/ci.yml`). A PR can be merged only when all required checks are green: PHP, Frontend, E2E (Playwright), Dependency audit, Dependency review and Docker image. The Compose install job also runs on every PR. Fix a failing check in the PR; don't re-run it until it passes by chance, and don't ask for a bypass. Force pushes and deletion of `dev` and `main` are blocked. Design and rules: [ADR-013](docs/adr/ADR-013-continuous-integration.md).

## Quality
- Never commit credentials, tokens, production data, or `.env` files.
- Add tests for changed behavior, including denied-access cases and cross-team isolation.
- Preserve Arabic RTL and English LTR behavior.
- Do not claim tests passed unless you ran them.
- Don't add external services, payment gates, or scope beyond approved requirements.

## Licensing
By contributing, you agree that your contribution is provided under **AGPL-3.0-only**. You must have the rights to submit the code.
