# SN3 (سنع) — DEVELOPMENT_PLAN.md
> **Status:** FINAL implementation baseline | **Version:** 1.0.0 | **Language:** English (engineering); product UI Arabic + English.
> **Purpose:** Deterministic, dependency-ordered delivery plan for AI coding agents. No product-choice questions are delegated to implementers.

## 0. Authority, inputs, and execution rules
1. Read `BRD.md`, `ARCHITECTURE.md`, `DATABASE.md`, `DESIGN_SYSTEM.md`, and `API_SPEC.md` before coding. This plan defines execution order, not a replacement for those specifications.
2. The latest **binding decision registers** in final Architecture/Database documents supersede older BRD open questions. API contracts govern endpoint syntax; Database governs physical schema; Design System governs visual behavior. If contradictions remain, log a specification defect, choose the interpretation that preserves tenant isolation and established binding decisions, and include a regression test. Never silently weaken security.
3. Product: **سنع / SN3**, free Cloud and Self-Hosted with identical features; AGPL-3.0-only; Saudi-green `#145A38`; English Space Grotesk, Arabic IBM Plex Sans Arabic.
4. Technical baseline: Laravel 12 / PHP 8.3+, Vue 3 / TypeScript / Inertia 2, PostgreSQL 16+, Docker Compose, modular monolith. Pin exact supported dependency versions in lockfiles. Redis and real-time are optional; the product must function without either.
5. **Do not fabricate deployment secrets, GitHub App credentials, domain names, SLA guarantees, or external service accounts.** Supply documented environment variables and local test doubles. Secrets are runtime inputs, not product decisions.
6. Work in vertical slices; every slice includes migrations, policies, service logic, UI, tests, translation strings, and documentation where applicable. No feature is accepted merely because an endpoint or screen exists.
7. Default-deny permissions; never query cross-team objects without team scoping; project-private objects require explicit project membership except Team Owner. Team Admin cannot access private-project content by role alone.
8. Task is a single entity with optional labels and custom fields; exactly one nullable primary assignee; sprints optional by project. Public v1.0 includes GitHub App linking, branches/commits/PRs and basic automation; v1.1 includes optional bidirectional Issues sync.
9. No paid feature gates or arbitrary limits on projects, tasks, or team members. Cloud fair-use limits: 25 MB/attachment, 2 GB attachment storage/team, 120 authenticated requests/minute/user (burst control); self-host limits configurable.

## 1. Release strategy
| Release | Deliverable | Public launch? | Required quality |
|---|---|---|---|
| R0 — Foundation | Repo, CI, Docker, auth shell, i18n, tenancy and authorization infrastructure | No | Security baseline and repeatable install |
| R1 — Core | Teams, invitations, projects, private membership, workflows, tasks, board/list, comments, attachments, inbox | Internal alpha | End-to-end core journey, cross-tenant denial tests |
| R2 — Customization | Custom fields, saved views, project templates, optional sprints/backlog, responsive UX polish | Beta | Sprint lifecycle and config tests |
| R3 — GitHub v1.0 | GitHub App, repository linking, branches/commits/PRs, automation, webhook hardening | **Public v1.0** | Replay, permission, retry and disconnected-provider tests |
| R4 — GitHub v1.1 | Optional two-way GitHub Issues sync, conflict inbox, import/export sync behavior | **Public v1.1** | Idempotency, loop prevention, conflict and recovery tests |

A release is shippable only after the gate in §7. R0/R1/R2 are milestones, not a substitute for the committed public GitHub v1.0 functionality.

## 2. Work item format and agent assignment
Each work item has: **ID, owner role, dependencies, deliverable, acceptance**. Suggested owner roles: `ARCH` architecture, `DB` schema, `BE` backend, `FE` frontend, `UX` design implementation, `QA` tests, `OPS` operations, `INT` integrations. These are *roles*, not requirements to run separate autonomous agents. Work may run in parallel only when dependencies are satisfied and contracts are stable.

**Definition of Ready (DoR):** upstream contracts identified; tenant/permission rules known; expected tests named; dependencies merged; no unresolved blocker. **Definition of Done (DoD):** merged code, green automated checks, Arabic/English text, keyboard/RTL/responsive behavior where applicable, security review, documentation and migration/rollback notes.

## 3. R0 — Foundation (EPIC FND)
| ID | Owner | Depends on | Deliverable | Acceptance |
|---|---|---|---|---|
| FND-001 | ARCH/OPS | — | Monorepo Laravel/Inertia/Vue/TS, linting, formatting, env examples | Clean clone installs without proprietary services |
| FND-002 | OPS | FND-001 | Compose app + PostgreSQL + reverse-proxy example, health/readiness | Fresh self-host instance starts with documented commands; persistent DB volume |
| FND-003 | QA/OPS | FND-001 | CI: PHP lint/static analysis/Pest; TS lint/typecheck/Vitest; build; dependency scanning | PR blocked on failure; no secrets in logs |
| FND-004 | BE | FND-001 | Authentication, verification, password reset, sessions, CSRF, rate limits | Auth tests include invalid/expired sessions and CSRF |
| FND-005 | DB/BE | FND-004 | UUIDv7 IDs, teams/memberships, policies and scoped query patterns | Cross-team IDs always deny; owner and membership constraints tested |
| FND-006 | FE/UX | FND-001 | SN3 design tokens, fonts, app shell, RTL/LTR and theme | Arabic and English pages render correctly at 320/768/1280px |
| FND-007 | OPS/BE | FND-002 | Scheduler, DB-backed queues fallback, structured logging, mail adapter | Jobs work without Redis; missing SMTP does not crash core usage |
| FND-008 | QA | FND-004,FND-005,FND-006 | E2E harness and seeded test tenants | Deterministic tests prove no cross-tenant leakage |

**R0 exit:** FND-001–008 done, installation verified on a clean Linux host, CI passing, secrets/config docs reviewed.

## 4. R1 — Core (EPIC CORE)
| ID | Owner | Depends on | Deliverable | Acceptance |
|---|---|---|---|---|
| CORE-001 | DB/BE | FND-005 | Team create/update, owner transfer, roles Owner/Admin/Member/Viewer | Ownership remains valid; Viewer cannot mutate |
| CORE-002 | BE/FE | CORE-001,FND-007 | Email and share-link invitations with verification, expiry and revocation | Email invite bound to verified address; link expires 7 days, max 20 uses |
| CORE-003 | DB/BE | CORE-001 | Projects, visibility and explicit project membership | Private content visible only to invited members and Team Owner |
| CORE-004 | DB/BE | CORE-003 | Workflow statuses, transitions, unique immutable task key | Project key freezes after first task; status ordering consistent |
| CORE-005 | DB/BE | CORE-004 | Tasks CRUD, single assignee, labels, checklist, priorities, due dates | Validation, optimistic concurrency, scoped task retrieval |
| CORE-006 | FE/UX | CORE-003,CORE-005,FND-006 | Team/project navigation, create project, task detail | No dead ends; role-based actions hidden AND server-denied |
| CORE-007 | FE/BE | CORE-005,CORE-006 | Kanban drag/drop and List view with filters/pagination | Reorder and status moves persist; keyboard fallback |
| CORE-008 | DB/BE | CORE-005 | Comments, mentions, activity audit, attachments | File access scoped; 25MB Cloud cap; upload abuse tests |
| CORE-009 | BE/FE | CORE-008,FND-007 | Inbox notifications and user preferences | No private-project content leaked via notifications |
| CORE-010 | DB/BE | CORE-003 | Archive/trash/recover/purge for projects and teams | 30-day recovery; only authorized deletion; no remote GitHub deletion |
| CORE-011 | QA | CORE-001..CORE-010 | Core E2E, authorization matrix and data isolation suite | Two teams + private projects, direct URL/API, search, files, inbox tested |
| CORE-012 | OPS | CORE-010 | Backup/restore and team export baseline | Encrypted daily Cloud backups retained 14 days; restore drill documented |

**R1 exit:** Complete account → team → invitation → project → task → assignment → board → comment workflow; tests for every role and private project.

## 5. R2 — Customization and Agile (EPIC FLEX)
| ID | Owner | Depends on | Deliverable | Acceptance |
|---|---|---|---|---|
| FLEX-001 | DB/BE | CORE-004,CORE-005 | Custom field definitions/options/values with type validation | Fields isolated per project; invalid values rejected |
| FLEX-002 | FE/UX | FLEX-001 | Project settings: statuses, labels, fields, view preferences | Reordering and editing do not corrupt tasks |
| FLEX-003 | DB/BE | CORE-005 | Saved views with filter and sort configurations | View access scoped; query safe and indexed |
| FLEX-004 | DB/BE | CORE-003,FLEX-002 | Team templates, personal drafts and publication flow | Owner/Admin publish; members cannot publish shared templates |
| FLEX-005 | DB/BE | CORE-005 | Optional sprint lifecycle and backlog | Disabled project has no sprint UI; active sprint invariants enforced |
| FLEX-006 | FE/UX | FLEX-005,FLEX-002 | Sprint planning, start/end, backlog and task assignment | Mobile responsive; RTL drag alternatives |
| FLEX-007 | FE/UX | FLEX-003,FLEX-006 | Calendar view and responsive/keyboard/accessibility polish | Screen reader labels, focus and date locale tests |
| FLEX-008 | QA | FLEX-001..FLEX-007 | Customization and sprint regression suite | Changes preserve existing task state and authorization |

**R2 exit:** Simple projects stay simple; Agile can be enabled per project; templates and customizations operate without cross-project bleed.

## 6. R3 — GitHub public v1.0 (EPIC GH)
| ID | Owner | Depends on | Deliverable | Acceptance |
|---|---|---|---|---|
| GH-001 | INT/OPS | FND-007,CORE-003 | GitHub App config, installation callback, encrypted token handling | Minimal permissions; no PAT requirement; no secrets exposed |
| GH-002 | DB/BE | GH-001 | Repository discovery/link/unlink per authorized project | Private repo names hidden from unauthorized project users |
| GH-003 | INT/BE | GH-001 | Verified webhook ingress, deduplication, delivery log, retries | Invalid signature denied; replay is idempotent |
| GH-004 | INT/BE | GH-002,GH-003,CORE-005 | Task-key association for branches, commits and PRs | Correct task linkage; malformed refs safely ignored |
| GH-005 | BE/FE | GH-004 | GitHub activity section in task detail | Accurate state; provider unavailable is non-blocking |
| GH-006 | BE/INT | GH-004,FLEX-002 | Optional per-project automation rules for PR open/merge | Disabled by default; rule authorization, audit and retries |
| GH-007 | OPS/QA | GH-001..GH-006 | GitHub App integration tests, fake provider, webhook/retry chaos tests | No infinite loops; no cross-team data disclosure |
| GH-008 | OPS | CORE-012,GH-007 | Public release packaging, AGPL notices, security policy, install guide | Cloud and self-host parity smoke tests; v1.0 tag/release |

**R3 exit:** Public v1.0 requires all GH tasks and quality gates. A self-host installation needs an externally reachable webhook URL to receive GitHub events; document local-development alternatives.

## 7. R4 — GitHub Issues two-way sync (EPIC SYNC)
| ID | Owner | Depends on | Deliverable | Acceptance |
|---|---|---|---|---|
| SYNC-001 | DB/INT | GH-003,GH-004 | Issue mappings and field ownership model | At most one mapping per task per repository; unique constraints |
| SYNC-002 | INT/BE | SYNC-001 | Outbound create/update/close/reopen jobs and outbox | Retries idempotent; no deletion propagation |
| SYNC-003 | INT/BE | SYNC-001,SYNC-002 | Inbound Issues processing and conflict detection | Local pending changes win; conflict event visible |
| SYNC-004 | FE/UX | SYNC-003 | Enable/disable sync, field mapping and conflict UI | No accidental enable; explicit authorization and explanation |
| SYNC-005 | INT/QA | SYNC-002..SYNC-004 | Reconciliation, replay, rate limit and recovery tests | No echo loops; manual unlink preserves both systems |
| SYNC-006 | OPS | SYNC-005 | Public v1.1 release and upgrade/rollback guide | Upgrade from v1.0 preserves links and data |

## 8. Release-wide non-functional gates
Every release candidate MUST pass:
- **Security:** tenant isolation; Team Owner exception; private project denial for nonmembers including Team Admin; server-side authorization on every write/read, attachments, search, inbox and GitHub metadata.
- **Reliability:** idempotent webhook handling, transaction boundaries, optimistic concurrency where specified, safe retries, migrations from a clean DB and from previous release.
- **UX:** Space Grotesk English, IBM Plex Sans Arabic, SN3 green palette, RTL/LTR, light/dark themes, keyboard accessibility, responsive 320px+.
- **Operations:** Cloud and Self-Hosted feature parity; documented Compose boot, upgrade, backup/restore, environment settings; no Redis hard dependency.
- **Quality:** all unit/integration/E2E tests pass, static analysis and dependency checks pass, no critical known security defects, accessibility and performance smoke checks recorded.
- **Privacy:** 30-day trash policy, 14-day encrypted Cloud backup retention, no unauthorized data in logs, exports scoped to authorized users.
- **API:** error envelope, pagination, idempotency, concurrency and HTTP statuses match `API_SPEC.md`.
- **Open source:** license notices, contributor setup, reproducible build, no proprietary runtime requirement.

## 9. Agent execution and handoff
1. Analyst converts the current epic into CRs with exact IDs, tests and dependencies. No new feature scope without explicit change control.
2. Architect validates interfaces and permission boundaries; DB agent writes migrations and indexes before API endpoints depend on them.
3. Backend and frontend agents may work in parallel only after request/response contracts and authorization semantics are frozen.
4. Tester independently validates acceptance criteria, negative cases, tenant isolation, translations and responsive states.
5. Publisher runs staging smoke tests, backup/restore rehearsal, migration upgrade test and produces tagged release notes.
6. Failed acceptance returns to owning task; do not mark the epic done until regression tests pass.
7. Agent output must include: changed files, migration notes, tests executed/results, security implications, contract changes, and remaining *technical defects*. Do not turn implementation choices into product-owner questions.

## 10. Risk controls and mitigation
| Risk | Control |
|---|---|
| Cross-tenant or private-project data leakage | Mandatory policies, scoped queries, two-team E2E matrix, direct-object-ID abuse tests |
| GitHub permission overreach | GitHub App least privilege, project-level authorization, encrypted credentials, signature verification |
| GitHub webhook replay/loops | Delivery IDs, dedup keys, outbox and origin markers, bounded retries |
| Free Cloud abuse | Rate limiting, storage quotas, safe uploads, fair-use monitoring without paid feature gates |
| Self-host complexity | Single Compose stack, optional services, example env and upgrade docs |
| Spec drift across files | Contract tests, change log, explicit precedence in §0; align BRD before release |
| Scope creep | Fixed v1.0/v1.1 gates; future ideas require a separate post-v1.1 backlog |

## 11. Traceability and progress ledger
- Task IDs are immutable. Track status as `not_started | in_progress | blocked | in_review | done`; status is operational and does not change scope.
- A completed item links to commit/PR, test run and acceptance evidence.
- Requirements trace: BRD `FR-*` → epic task ID → API endpoint/DB entity/UI screen → tests. Preserve original BRD requirement IDs.
- Suggested execution order: `FND → CORE → FLEX → GH → SYNC`, with dependency-safe parallel work within each release.
- Project completion means **public v1.1 is delivered**, with both hosting models verified and all release gates satisfied.

## 12. Immediate first execution batch
Start with `FND-001`, `FND-002`, `FND-003`, and `FND-006` after checking exact lockfile-compatible versions. Next implement `FND-004` and `FND-005`, then `FND-008`. Do not start feature screens or GitHub synchronization before tenancy, authentication and authorization foundations pass tests.

## 13. Reconciliation gate

Use `TRACEABILITY.csv` to link all FR/NFR to task/test identifiers. Private-project nonmembers including Team Admin must not discover existence or metadata. Only project roles `manager|contributor`; Team Viewer read-only. v1.0 includes R0–R3; v1.1 adds R4 Issues sync. No implementation until explicit user authorization.


## 14. Final contract alignment requirements
- FND-005 must create schema versions and API idempotency records defined in Database §20 before versioned endpoints are implemented.
- CORE-010 must implement independent archive vs deleted state and 30-day purge.
- FLEX-005/006 must enforce one active sprint per project, one current sprint per task, history preservation, and backlog carryover.
- CORE-012 implements async team export (`team_exports`) and backup/restore verification.
- QA must validate API §18 crosswalk including 428/412 responses and no private-project information disclosure.
