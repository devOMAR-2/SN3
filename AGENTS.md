# SN3 (سنع) — AGENTS.md

> Status: FINAL | Version: 1.0.0 | Audience: coding agents and human contributors
> Mission: Build and maintain SN3, a completely free, open-source, bilingual project-management platform for small teams, deployable as a hosted multi-tenant Cloud service or a self-hosted instance with the **same features**.

## 1. Mandatory reading and precedence
Before touching code, read in this order:
1. `BRD.md` — product scope and traceable requirements.
2. `ARCHITECTURE.md` — architecture, security and binding engineering decisions.
3. `DATABASE.md` — data model, constraints, migrations and binding data decisions.
4. `DESIGN_SYSTEM.md` — finalized visual system, interaction and accessibility.
5. `API_SPEC.md` — HTTP/API request, response and error contracts.
6. `DEVELOPMENT_PLAN.md` — release sequence, task IDs, dependencies and acceptance gates.
7. This file — agent operating rules.

**Precedence by subject:** Final decision registers in Architecture/Database override older open questions in BRD; Database governs schema; API Spec governs HTTP contracts; Design System governs UI; Development Plan governs scope and order. The canonical documents in this directory and `TRACEABILITY.csv` are the authoritative baseline. If a new contradiction is discovered, file a specification defect, preserve privacy and binding decisions, and correct documents and tests together before merging the affected feature.

## 2. Immutable product decisions
- Product name: **سنع**, English **SN3** (not Sin3, NASAQ or any other name).
- All functionality is free, for both Cloud and Self-Hosted; no subscriptions, paywalls, or paid-only plugins.
- License: **AGPL-3.0-only**, with compatible dependencies and notices.
- Languages: Arabic (full RTL) and English (LTR). No hard-coded English-only UI.
- Brand: Saudi-inspired green `#145A38`; English font **Space Grotesk**, Arabic font **IBM Plex Sans Arabic**; light and dark themes.
- Stack: Laravel 12, PHP 8.3+, Vue 3, TypeScript, Inertia 2, PostgreSQL 16+, Docker Compose; modular monolith. Lock tested dependency patches.
- Redis and real-time are optional enhancements; the system must work without them.
- One account may belong to multiple teams. Roles: Owner, Admin, Member, Viewer.
- Projects are team-visible by default or private to explicit project members plus Team Owner. Team Admin without explicit project membership cannot discover a private project's existence, name, counts, or content.
- One nullable primary assignee per task; flexible labels/custom fields; optional sprints per project.
- GitHub App integration, repository links, commits, branches, PRs and automation are in public v1.0; optional two-way GitHub Issues sync is in public v1.1.
- GitHub sync must be idempotent, loop-resistant, auditable and safe under provider outages.
- Cloud operational limits: 25 MB per attachment, 2 GB attachment storage per team, 120 authenticated requests/minute per user; no arbitrary caps on projects/tasks/members. Self-host limits configurable.
- Deleted projects/teams have 30-day recovery; daily encrypted Cloud backups retained 14 days. Deletion must never delete remote GitHub resources.

## 3. First action in a coding session
1. Inspect repository tree, git status, current branch, lockfiles, test setup and the six specifications.
2. Find the next eligible task ID in `DEVELOPMENT_PLAN.md`; respect prerequisites and release order. If a task is specified by the human, work on that task instead.
3. Read existing code and tests before changing it; avoid duplicate services, tables, routes or components.
4. State a short plan internally: target task IDs, files/modules, data/permission implications, tests, rollback.
5. Implement the **smallest complete vertical slice**, not broad speculative refactors.
6. Run relevant tests, typechecks, formatters and security checks; report actual outcomes, not assumptions.
7. Summarize changed files, task IDs, test results, migrations, security implications and any genuine technical blockers.

Never assert that a command, test, deployment, review or integration succeeded unless it actually ran and succeeded.

## 4. Agent roles and handoff contract
Roles may be performed by one agent or several; separate agents are optional.

| Role | Owns | Must hand off |
|---|---|---|
| Analyst | Trace BRD FR IDs to release tasks; refine acceptance tests without expanding scope | Requirement/task map and edge cases |
| Architect | Module boundaries, authorization, dependency choices | Approved interfaces and constraints |
| Database | Migrations, constraints, indexes, query plans | Schema diff, upgrade/rollback notes, data-isolation tests |
| Backend | Policies, actions/services, validation, API contracts, jobs | Endpoints, failure cases, unit/integration tests |
| Frontend | Vue/Inertia pages, interactions, translations, responsive behavior | Screens, accessibility/RTL tests, state/error handling |
| Integration | GitHub App auth, webhook ingestion, associations and sync | Permissions, event map, retry/idempotency tests |
| QA | Independent positive/negative, privacy and end-to-end verification | Evidence, defect reports, regression coverage |
| Publisher/Ops | Docker, CI, migrations, backups, release packaging | Deployment checklist, upgrade evidence, release notes |

**Handoff format:** `Task IDs | Scope completed | Files changed | Interfaces/schema touched | Tests run + exact results | Risks/security review | Known defects | Next eligible tasks`.

## 5. Implementation conventions
- **Backend:** Laravel controllers stay thin; validation via Form Requests; authorization via policies/gates; business rules in cohesive actions/services; transactions for multi-row invariants; domain events only when justified. Never authorize only in the UI.
- **Database:** PostgreSQL is the only supported engine initially. Use UUIDv7 consistently. Add foreign keys, unique constraints and indexes for team/project scoping and common filters. Prefer migrations over runtime schema mutation; avoid unbounded N+1 queries.
- **Frontend:** Vue 3 Composition API + TypeScript, Inertia page props/contracts typed, reusable components and design tokens. Do not replace the approved palette or fonts. No excessive animations or enterprise-only complexity.
- **Localization:** All user-facing labels/errors/notifications are translatable. Arabic and English must have complete keys. Direction-aware spacing, icons, dates, validation and keyboard interactions must work in both directions.
- **API:** Follow `API_SPEC.md` for route names, status codes, envelopes, pagination, idempotency and concurrency. Never silently change public contract to match convenient implementation.
- **Async:** Use durable jobs with bounded retries and dead-letter/failure visibility. DB queue must be a supported fallback. Jobs carry only minimal identifiers and re-authorize/re-scope at execution time.
- **Secrets:** `.env.example` documents variables but contains no secrets. Never commit credentials, installation tokens, webhook secrets, private keys, production dumps or customer data.
- **Dependencies:** Choose maintained, compatible libraries; check licenses and advisories; do not add dependencies for trivial utilities.

## 6. Security and privacy invariants (non-negotiable)
1. Every team-owned query and mutation is tenant-scoped on the server. Guessing UUIDs cannot bypass scoping.
2. Project-private data — tasks, comments, attachments, search, notifications, activity and GitHub metadata — is visible only to explicit project members and Team Owner.
3. Team Admin cannot self-grant access to a private project or inspect its private content. Metadata access, if any, is limited by final architecture decisions.
4. Every mutation verifies membership, role, project visibility, ownership and resource state; validate request payloads and file uploads.
5. GitHub App tokens are encrypted at rest, least-privileged and never sent to the browser. Verify webhook signatures before processing; deduplicate deliveries and preserve audit trails.
6. Outbound sync never hard-deletes remote Issues; conflicts follow finalized field-ownership rules. No echo loops or duplicate Issue mappings.
7. Rate limits and fair-use storage quotas are enforced server-side in Cloud. Self-host operators may configure resource limits without unlocking features.
8. Logs and telemetry never include credentials, private task bodies or unnecessary personal data. Exports and backups enforce authorization and retention.
9. Security regression tests must include two unrelated teams, a private project, Owner/Admin/Member/Viewer, guessed identifiers and unauthorized direct file access.

## 7. Quality gates and definition of done
A task is `done` only when:
- Acceptance criteria for its immutable task ID are satisfied, with positive and negative automated tests.
- Relevant migrations run from a clean database and upgrade safely from the previous release; rollback or recovery steps are documented.
- PHP/TypeScript lint, type checks, unit/integration tests and affected E2E flows pass (or failures are explicitly documented; then task is **not done**).
- All new UI strings exist in Arabic and English; RTL/LTR, mobile widths, keyboard and accessible labels are tested where relevant.
- Authorization and privacy are reviewed; any new external surface has validation, throttling and error handling.
- Docs and example config are updated; no secrets, debug dumps or unused scaffold code are committed.
- A human-readable completion report identifies evidence and residual technical defects.

**Do not mark a release complete** until all its tasks and release-wide gates in `DEVELOPMENT_PLAN.md` pass. Never claim a staging/production deployment was performed without actual access and verification.

## 8. Required test matrix
| Area | Mandatory cases |
|---|---|
| Authentication | verification, expired session, CSRF, password reset, brute-force throttling |
| Teams | invite role limits, email matching, link expiration/revocation/max redemptions, ownership transfer |
| Projects | public/private visibility, membership changes, Team Admin denial, Owner override |
| Tasks | assignment cardinality, status transitions, ordering, concurrency, permissions |
| Agile | disabled sprint features, backlog, start/close lifecycle, tasks crossing sprint states |
| Customization | field types, labels, templates, views, no cross-project leaks |
| Attachments | size/type/path controls, private downloads, quota exhaustion |
| GitHub | signature validation, replay, out-of-order events, outages, revoked installation, retries |
| Issues sync | duplicate prevention, conflicting edits, no echo, unlink, close/reopen, no remote deletion |
| Localization | Arabic RTL and English LTR, translated validation, mobile + keyboard flows |
| Deployment | clean Compose install, migrations, backup/restore, upgrade and rollback |

## 9. Task sequencing
- **R0:** FND-001..008: scaffold, Compose, CI, authentication, tenancy, design foundation, jobs and E2E harness.
- **R1:** CORE-001..012: teams, invitations, project privacy, workflows, tasks, Kanban/List, comments/files, inbox, deletion, backup/export.
- **R2:** FLEX-001..008: custom fields, settings, saved views, templates, optional sprints/backlog, calendar and accessibility polish.
- **R3:** GH-001..008: GitHub App, linked repos, signed webhooks, commit/branch/PR links, automations, hardening and public v1.0.
- **R4:** SYNC-001..006: two-way Issues sync, conflict resolution, reconciliation and public v1.1.

Do not begin a dependent task before its dependencies pass. Independent tasks may be parallelized only with stable contracts and non-overlapping ownership of files/migrations.

## 10. Change control
- Fixed decisions are **not** invitations to ask the product owner again. Use documented choices.
- A bug fix preserving documented behavior needs no scope change. A new feature, paid tier, alternate hosting model, different license, different brand, or changed permission model is **out of scope** without an explicit user request.
- If an unavoidable conflict in specs threatens correctness, privacy or data loss: stop that affected change, record exact conflicting passages and safe fallback; continue other unblocked tasks. Never hide a blocking contradiction.
- Record substantive approved changes in a dated changelog and update affected docs/tests together.

## 11. Completion report template
```text
SN3 Implementation Report
Task IDs:
Status: done | blocked | in_review
Delivered:
Files/modules changed:
Database/API changes:
Security/privacy checks:
Arabic/English + accessibility checks:
Tests executed and results:
Deployment/migration notes:
Remaining defects (if any):
Next dependency-ready task IDs:
```

## 12. Initial command for a coding agent
> Read all six SN3 specification files and this AGENTS.md. Inspect the repository before editing. Start with the earliest dependency-ready R0 task, `FND-001`, unless the repository already satisfies it. Implement only the smallest complete slice, run real checks, and report evidence in the required format. Preserve the finalized product decisions, tenant isolation, bilingual design and Cloud/Self-Hosted parity. Do not ask to reopen settled product decisions.

## 13. Final traceability and release rules

Read `TRACEABILITY.csv` for FR/NFR coverage. Public v1.0 includes optional sprints/custom fields/templates/views and GitHub App branches/commits/PRs; v1.1 adds Issues two-way sync. Project roles are `manager|contributor` only. Do not start coding unless the user explicitly says «ابدأ التنفيذ».
