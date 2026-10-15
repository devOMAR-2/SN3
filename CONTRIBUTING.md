# Contributing to SN3

Thanks for your interest in سنع (SN3).

## Project status
This repository is in **pre-development**. Please discuss proposed work in an Issue before writing implementation code. Do not treat a documented task as authorization to implement it.

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
Run the checks listed under **Local development** in the [README](README.md) (`composer lint`, `composer test`, `npm run lint`, `npm run format:check`, `npm run typecheck`, `npm run build`) before opening a PR.

## Quality
- Never commit credentials, tokens, production data, or `.env` files.
- Add tests for changed behavior, including denied-access cases and cross-team isolation.
- Preserve Arabic RTL and English LTR behavior.
- Do not claim tests passed unless you ran them.
- Don't add external services, payment gates, or scope beyond approved requirements.

## Licensing
By contributing, you agree that your contribution is provided under **AGPL-3.0-only**. You must have the rights to submit the code.
