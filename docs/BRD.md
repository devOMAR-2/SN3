# SN3 (سنع) — Business Requirements Document (BRD) — FINAL

> **Project:** Open-source project management for small teams  
> **Document ID:** BRD-PM-001  
> **Version:** 1.0.0  
> **Status:** FINAL PRODUCT BASELINE — no open product decisions; implementation not authorized  
> **Date:** 2026-10-12  
> **Primary language:** Arabic  
> **Audience:** AI Business Analysts, Product Architects, UX Designers, Backend/Frontend Engineers, QA Agents, Maintainers

## 0. AI AGENT EXECUTION CONTRACT

This file is the canonical **business requirements baseline**, not an instruction to immediately implement the entire system.

1. The decisions in this reconciled baseline are final. Any legacy proposal wording is non-normative; engineering changes require a documented change request, not autonomous invention.
2. Never silently invent requirements, permissions, pricing, feature limits, legal terms, or integration behavior.
3. Preserve requirement IDs in all derived artifacts: PRD, architecture, schema, API, UI specifications, test cases, and release plans.
4. Distinguish **MUST** (mandatory), **SHOULD** (strong preference), **MAY** (optional). Priority: **P0** core launch, **P1** subsequent committed release, **P2** future consideration.
5. Never sacrifice tenant isolation, authorization, data integrity, or webhook security to ship faster.
6. Produce implementation work as small, independently testable vertical slices with acceptance criteria and dependencies.
7. All user-facing copy must support Arabic and English; RTL and LTR are first-class behaviors, not visual patches.
8. Cloud and Self-Hosted must share one codebase and the same product capabilities; deployment configuration may differ.
9. If a proposed implementation conflicts with a CONFIRMED decision, stop and surface the conflict.
10. No code generation is requested by this document. Await implementation instructions.

## 1. Executive Summary

Build a lightweight, intuitive, customizable project-management web application for small teams, comparable in core workflow utility to Jira while deliberately avoiding enterprise complexity. The platform is completely free, open-source, available as a hosted multi-tenant cloud service and as a self-hosted deployment. Users create teams, invite collaborators, create projects, organize and assign tasks, optionally run sprints, customize workflows/fields/views/templates, and optionally integrate GitHub with two-way Issues synchronization and automation rules.

**Positioning:** Simple by default, customizable by choice.  
**Target team size:** Primarily 2–15 members; no arbitrary hard self-hosted member cap (confirmed; no artificial member limit).  
**Target users:** Software teams, designers, agencies, student groups, and other small collaborative teams.

### 1.1 Success outcomes (binding acceptance targets)

- First-time user can create a team, project, and task without mandatory configuration.
- Typical task creation takes few interactions, without requiring Agile terminology.
- No user-facing paid feature gates or subscriptions.
- Cloud and Self-Hosted pass the same functional test suite.
- Unauthorized cross-team and private-project data access is blocked across UI, APIs, search, notifications, files, and integrations.
- Self-hosting is documented and reproducible with Docker Compose.

## 2. Confirmed Decisions Register

| ID | Decision | Status |
|---|---|---|
| DEC-001 | Completely free product, no subscriptions or paid feature tiers | CONFIRMED |
| DEC-002 | Open source and independently self-hostable | CONFIRMED |
| DEC-003 | Official hosted Cloud plus Self-Hosted, both free | CONFIRMED |
| DEC-004 | Same capabilities in Cloud and Self-Hosted | CONFIRMED |
| DEC-005 | Arabic and English, including RTL/LTR | CONFIRMED |
| DEC-006 | Fully responsive web UI for desktop and mobile | CONFIRMED |
| DEC-007 | Team/workspace with multiple projects and members | CONFIRMED |
| DEC-008 | Team roles: Owner, Admin, Member, Viewer | CONFIRMED |
| DEC-009 | Unified Task model with labels and custom fields, not mandatory separate issue types | CONFIRMED |
| DEC-010 | Sprints are optional per project | CONFIRMED |
| DEC-011 | Project-specific statuses, fields, views, and templates | CONFIRMED |
| DEC-012 | GitHub integration, optional two-way Issues sync, configurable automations | CONFIRMED |
| DEC-013 | Public account registration; team invitations via email or link | CONFIRMED |
| DEC-014 | Projects may be Team-visible or Private to selected members | CONFIRMED |
| DEC-015 | Team Owner may access/manage private projects; Admin has no implicit access to private project content | CONFIRMED |
| DEC-016 | Team-visible projects are visible to team members subject to their permissions | CONFIRMED |
| DEC-017 | New projects default to Team-visible, with Private selectable | CONFIRMED |

## 3. Scope and Release Boundaries

### 3.1 P0 — Minimum Viable Product

- Accounts, sessions, account recovery, localization.
- Team creation, membership, invitations, fixed team roles.
- Project creation, visibility control, membership, project settings.
- Unified tasks with stable human-readable project-scoped identifiers, descriptions, assignment, status, priority, due date, labels.
- Project Kanban board with configurable statuses and drag/drop, accessible alternatives on mobile.
- Task comments, basic activity history, basic in-app notifications.
- Basic task search/filtering with strict authorization.
- Responsive Arabic/English interface.
- Cloud multi-tenancy and self-hosted deployment documentation.
- Data export and backup/restore instructions sufficient to avoid lock-in (required for public v1.0).

### 3.2 Final public release boundaries

- **Public v1.0 (R0–R3):** All P0 core features plus optional sprints/backlog, custom fields, saved Board/List/Calendar views, reusable project templates, subtasks/checklists, attachments, mentions, GitHub App installation/repository linking, branch/commit/PR associations and optional PR automations.
- **Public v1.1 (R4):** Optional bidirectional GitHub Issues synchronization, conflict management and reconciliation.
- GitHub Actions/CI status display is a post-v1.1 enhancement, not a v1.0 launch gate.
- All features are free in Cloud and Self-Hosted.

### 3.3 Out of Scope at Initial Launch

- Paid plans, subscriptions, premium-only features.
- Enterprise resource planning, advanced portfolio management, financial accounting, complex approval chains.
- Native iOS/Android apps, offline-first synchronization, AI features, plugin marketplace.
- GitLab/Bitbucket connectors (design extension points only).
- Arbitrary scripting by tenants, workflow execution with unrestricted code.

## 4. Personas and Primary Jobs to Be Done

| Persona | Needs |
|---|---|
| Team Owner | Create team, invite people, manage access, oversee all projects |
| Team Admin | Manage members/settings and permitted projects without bypassing private access |
| Member | Create/update assigned work, collaborate, use board/sprints |
| Viewer | Inspect authorized work without changing it |
| Project Participant | Collaborate in one private project without seeing other private projects |
| Self-hosted Administrator | Install, configure, upgrade, back up, and recover the deployment |

## 5. Business Domain Model

```text
Deployment (Cloud or Self-Hosted)
  └── User Account (may belong to multiple Teams)
       └── Team / Workspace
            ├── Team Membership (Owner/Admin/Member/Viewer)
            ├── Team Invitations
            └── Project (Team-visible or Private)
                 ├── Project Membership (explicit for Private)
                 ├── Project Configuration / Template
                 ├── Statuses / Workflow
                 ├── Custom Field Definitions
                 ├── Views / Saved Filters
                 ├── Task (project-scoped stable key)
                 │    ├── Assignee, labels, priority, due date
                 │    ├── Custom Field Values
                 │    ├── Subtasks / Checklist
                 │    ├── Comments / Attachments / Activity
                 │    └── GitHub Linked Objects
                 ├── Backlog / Sprint (optional)
                 └── GitHub Repositories / Sync Policies / Automation Rules
```

**Invariant:** Every project belongs to exactly one team; every task belongs to exactly one project. User membership alone does not grant access to private projects, except to the Team Owner.

## 6. Functional Requirements

### 6.1 Accounts and Localization

- **FR-AUTH-001 [MUST/P0]** Users can register with email and password on Cloud; self-hosted operators can configure registration availability (Self-Hosted registration enabled by operator setting; Cloud registration enabled).
- **FR-AUTH-002 [MUST/P0]** Users can sign in/out, reset passwords, and manage profile, locale, and timezone.
- **FR-AUTH-003 [MUST/P0]** One user can join multiple teams and switch active team context.
- **FR-I18N-001 [MUST/P0]** Arabic and English localization covers UI, validation, email templates, and notifications.
- **FR-I18N-002 [MUST/P0]** Layout direction, typography, icons, navigation, and form controls work correctly in RTL/LTR; mixed-script task content remains readable.
- **FR-I18N-003 [SHOULD/P0]** Language is a per-user preference; user-authored text is not automatically translated.

### 6.2 Teams, Membership, Invitations

- **FR-TEAM-001 [MUST/P0]** Authenticated users can create teams with name and optional icon/description.
- **FR-TEAM-002 [MUST/P0]** Owner/Admin can invite existing or new users via email or shareable invitation link.
- **FR-TEAM-003 [MUST/P0]** Invitations have expiry, revocation, and configurable link usage limits (7-day expiry, email-specific single use, share-link maximum 20 uses).
- **FR-TEAM-004 [MUST/P0]** Fixed roles: Owner, Admin, Member, Viewer.
- **FR-TEAM-005 [MUST/P0]** Team Owner alone can transfer ownership; last owner cannot be removed without ownership transfer.
- **FR-TEAM-006 [MUST/P0]** Removal from a team revokes project access, API access, and relevant notifications promptly.
- **FR-TEAM-007 [MUST/P0]** Team boundaries are enforced server-side, never by client filters alone.

### 6.3 Project Lifecycle and Privacy

- **FR-PROJ-001 [MUST/P0]** Authorized users create projects with name, short key, description, icon/color, and template.
- **FR-PROJ-002 [MUST/P0]** Visibility modes: `team` (default) and `private`.
- **FR-PROJ-003 [MUST/P0]** Team-visible projects are discoverable to all team members; mutations respect team/project permissions.
- **FR-PROJ-004 [MUST/P0]** Private project content is accessible only to explicit project members and the Team Owner.
- **FR-PROJ-005 [MUST/P0]** Team Admin has no implicit private-project content access, even if they can manage team settings.
- **FR-PROJ-006 [MUST/P0]** Authorized project managers can add/remove eligible team members to private projects.
- **FR-PROJ-007 [MUST/P0]** Changing project visibility triggers an authorization re-evaluation for search, files, notifications, and GitHub-linked content.
- **FR-PROJ-008 [SHOULD/P1]** Projects can be archived and restored without destroying task history.

### 6.4 Task Management

- **FR-TASK-001 [MUST/P0]** Tasks use one unified data model and may have optional labels and custom fields.
- **FR-TASK-002 [MUST/P0]** Task includes title, rich/plain description, status, priority, creator, assignee, timestamps, optional due date.
- **FR-TASK-003 [MUST/P0]** Each task has a stable, unique key such as `WEB-104`; renaming a project does not silently invalidate historical references.
- **FR-TASK-004 [MUST/P0]** Authorized users can create, edit, assign, move, and complete tasks.
- **FR-TASK-005 [MUST/P0]** Board columns map to project-defined statuses; drag/drop is optional UI, not the only interaction method.
- **FR-TASK-006 [MUST/P0]** Tasks support comments and audit/activity events.
- **FR-TASK-007 [SHOULD/P1]** Tasks support subtasks/checklists, attachments, mentions, and dependencies (task dependencies are directed `blocks` relationships; same-project only; prevent cycles; release scope v1.0).
- **FR-TASK-008 [MUST/P0]** Search, task references, and deep links enforce project privacy.
- **FR-TASK-009 [SHOULD/P1]** Bulk actions and saved filters respect authorization.

### 6.5 Customization and Views

- **FR-CUST-001 [MUST/P0]** Each project independently configures status names, ordering, and display colors.
- **FR-CUST-002 [MUST/P0]** Provide a ready-to-use default workflow with no mandatory setup.
- **FR-CUST-003 [MUST/P1]** Project admins can create custom field definitions with type, validation, optionality, and display order.
- **FR-CUST-004 [MUST/P1]** Support common field types: text, number, date, select, multi-select, boolean (final set: text, number, date, boolean, single_select, multi_select, url).
- **FR-CUST-005 [MUST/P1]** Board, List, and Calendar views can be configured per project.
- **FR-CUST-006 [MUST/P1]** Project templates are reusable configuration presets, not incompatible project subtypes.
- **FR-CUST-007 [MUST/P0]** Customization does not break historical task values or bypass permissions.
- **FR-CUST-008 [SHOULD/P1]** A project can enable or disable optional modules without deleting data by default.

### 6.6 Sprints (Optional)

- **FR-SPR-001 [MUST/P1]** Sprints are disabled unless enabled for the project.
- **FR-SPR-002 [MUST/P1]** Enabling sprints adds Backlog and Sprint views without changing the underlying Task model.
- **FR-SPR-003 [MUST/P1]** Authorized users create, plan, start, and close sprints with dates and goal.
- **FR-SPR-004 [MUST/P1]** Tasks may be scheduled into sprints; unfinished work can be moved back to backlog or a future sprint.
- **FR-SPR-005 [SHOULD/P1]** Story points and lightweight sprint progress summaries are available.
- **FR-SPR-006 [MUST/P1]** Disabling sprints preserves historical sprint membership and reporting data.

### 6.7 Collaboration and Notifications

- **FR-COL-001 [MUST/P0]** Task comments support author identity and timestamps.
- **FR-COL-002 [MUST/P0]** Significant task changes are recorded in activity history.
- **FR-COL-003 [MUST/P0]** In-app notifications for assignment, comments, and relevant invitations.
- **FR-COL-004 [SHOULD/P1]** Mentions, email notifications, and user notification preferences.
- **FR-COL-005 [MUST/P0]** Notification previews must not disclose private-project content to unauthorized users.

### 6.8 GitHub Integration

- **FR-GH-001 [MUST/P1]** Integrate via GitHub App using minimum required permissions and scoped repository installation.
- **FR-GH-002 [MUST/P1]** Authorized project administrators can link one or more GitHub repositories to a project.
- **FR-GH-003 [MUST/P1]** Associate branches, commits, and pull requests with tasks via stable task keys; provide manual linking where feasible.
- **FR-GH-004 [MUST/P1]** Display linked PR status and relevant activity within authorized tasks.
- **FR-GH-005 [MUST/P1]** Two-way GitHub Issues synchronization is optional, configured per repository/project.
- **FR-GH-006 [MUST/P1]** Explicit field mappings define which Issue fields sync; unsupported fields remain local.
- **FR-GH-007 [MUST/P1]** Deduplicate webhook events, verify signatures, handle out-of-order delivery, retry transient failures, and reconcile missed events.
- **FR-GH-008 [MUST/P1]** Prevent sync loops using event/source identifiers and change tracking.
- **FR-GH-009 [MUST/P1]** Conflict resolution behavior is documented and visible; never silently overwrite competing edits. Final v1.1 policy: local task fields are canonical; import remote edits only when no pending local change; on collision keep local values and record a visible conflict event; never silently overwrite.
- **FR-GH-010 [MUST/P1]** Configurable automations (e.g. PR opened -> In Progress, PR merged -> Done) are opt-in and can be disabled per project.
- **FR-GH-011 [MUST/P1]** GitHub outages, token revocation, and permission changes do not block local task management.
- **FR-GH-012 [MUST/P1]** Repo/Issue links and webhook-derived metadata honor project visibility; integration access does not expand user authorization.
- **FR-GH-013 [SHOULD/P2]** Show GitHub Actions check status where available (post-v1.1 backlog; not in R3/R4).
- **FR-GH-014 [SHOULD/P1]** Self-hosted operators can configure a reachable webhook endpoint and GitHub App credentials; local-only installations have documented limitations.

## 7. Authorization Rules

| Action | Owner | Admin | Member | Viewer |
|---|---|---|---|---|
| Manage team settings | Yes | Yes | No | No |
| Manage team membership | Yes | Yes | No | No |
| Transfer ownership | Yes | No | No | No |
| Create project | Yes | Yes | No | No |
| Read team-visible project | Yes | Yes | Yes | Yes |
| Read private project | Yes | Only if member | Only if member | Only if member |
| Edit tasks in accessible project | Yes | Yes* | Yes* | No |
| Comment in accessible project | Yes | Yes* | Yes* | No |

`*` Subject to project membership and project-level permissions. **FINAL** project roles: `manager` and `contributor` only. Team Viewer is read-only regardless of project membership. Team Owner has implicit manager authority. Team Admin without explicit membership cannot discover a private project or add themselves.

**Authorization test invariant:** A user who is not Team Owner and is not an explicit member of a private project must receive no content, count, filename, search snippet, notification payload, or linked GitHub metadata revealing that project, except for deliberately public team-level metadata explicitly approved later.

## 8. Core Workflows and Acceptance Criteria

### UC-001: Create a Team and Project

**Given** a registered user, **when** they create a team and choose a project template, **then** a usable project and default statuses are available without additional mandatory configuration. The creator becomes Owner.

### UC-002: Invite a Member

**Given** an authorized Owner/Admin, **when** they send a valid invitation, **then** the recipient can accept it into the intended team with the intended role; revoked/expired links fail safely; an invitation cannot grant access to unrelated teams.

### UC-003: Private Project Isolation

**Given** a private project, **when** a non-member Admin or Member attempts to access its API, URL, search result, attachment, notification, or GitHub-linked item, **then** no private content is disclosed. **Given** the Team Owner, access remains possible.

### UC-004: Create and Move Task

**Given** a member authorized to edit a project, **when** they create a task, **then** it receives a stable unique project key and default status. **When** they move it to a valid configured status, **then** the change persists and is reflected in the activity log.

### UC-005: Configure a Project Workflow

**Given** an authorized project manager, **when** they rename/reorder statuses, **then** all existing tasks retain valid status associations. Deleting a status with existing tasks requires an explicit migration/reassignment action (required safety rule).

### UC-006: Optional Sprint

**Given** a project with sprints disabled, **then** sprint navigation is hidden. **When** enabled, existing tasks remain intact and can be planned into sprints. **When** disabled again, sprint history is retained.

### UC-007: GitHub Task Linking

**Given** an installed GitHub App and linked repository, **when** a branch/commit/PR references an existing task key, **then** it is linked to that task without creating duplicate associations. Nonexistent keys do not cause unauthorized disclosure.

### UC-008: Two-way Issue Synchronization

**Given** explicit sync enabled and field mappings configured, **when** a mapped field changes on either side, **then** the corresponding change is applied or flagged as a conflict; repeated/out-of-order webhook deliveries do not duplicate tasks or overwrite newer data incorrectly.

### UC-009: Automation

**Given** an opt-in rule `PR merged -> Done`, **when** an associated PR merges, **then** the task moves to the mapped Done status if the action is authorized and valid. If the rule is disabled, the task remains unchanged.

### UC-010: Cloud / Self-Hosted Parity

**Given** identical product versions, **when** the core functional acceptance suite runs against Cloud and Self-Hosted environments, **then** behavior and feature availability match, except documented operational configuration differences.

## 9. Non-Functional Requirements

### 9.1 Security and Privacy

- **NFR-SEC-001 [MUST/P0]** Enforce authorization server-side on every endpoint and background job; include explicit negative tests.
- **NFR-SEC-002 [MUST/P0]** Strong password hashing, CSRF/session protection, input validation, output encoding, rate limiting, secure secrets management.
- **NFR-SEC-003 [MUST/P0]** Tenant-aware data access; prevent cross-team IDOR, enumeration, and attachment URL leakage.
- **NFR-SEC-004 [MUST/P1]** GitHub webhook signature verification, least-privilege App permissions, secure credential storage, event replay protection.
- **NFR-SEC-005 [MUST/P0]** Audit sensitive membership/role/visibility changes.
- **NFR-SEC-006 [MUST/P0]** Clear deletion/export/retention policy for Cloud; define backup lifecycle before production.

### 9.2 Performance, Reliability, Accessibility

- **NFR-PERF-001 [SHOULD/P0]** Fast perceived UI and low-latency common actions; benchmark targets to be set after load profile is agreed.
- **NFR-PERF-002 [MUST/P0]** Paginate large task lists and avoid loading full project history at once.
- **NFR-REL-001 [MUST/P0]** Document backup, restore, upgrade, and rollback procedures.
- **NFR-REL-002 [MUST/P1]** Queue and retry integration processing without blocking core task actions.
- **NFR-UX-001 [MUST/P0]** Responsive desktop/tablet/mobile UI with touch-friendly controls and keyboard alternatives.
- **NFR-UX-002 [SHOULD/P0]** Target WCAG 2.2 AA accessibility; validate color contrast, keyboard flow, focus, and screen-reader labels.
- **NFR-I18N-001 [MUST/P0]** No hard-coded user-facing English strings; localized date/number formats, timezone-aware display.

### 9.3 Operations and Sustainability

- **NFR-OPS-001 [MUST/P0]** One product codebase, configurable for Cloud and Self-Hosted.
- **NFR-OPS-002 [MUST/P0]** Docker Compose installation with documented environment variables, upgrades, and backups.
- **NFR-OPS-003 [MUST/P0]** Core features do not depend on proprietary paid services.
- **NFR-OPS-004 [MUST/P0]** Cloud may apply transparent fair-use safeguards for abusive resource consumption, but cannot paywall functionality; concrete limits require approval.
- **NFR-OPS-005 [MUST/P0]** Self-hosted operators control their data and deployment configuration.

## 10. Architecture Guidance (FINAL baseline)

| Layer | Candidate |
|---|---|
| Backend | Laravel, modular monolith |
| Frontend | Vue 3 + TypeScript |
| UI | Tailwind CSS + accessible component library |
| Database | PostgreSQL |
| Cache/Queue | Redis optional where possible |
| API/Auth | Laravel Sanctum or equivalent |
| Live updates | Optional real-time channel with graceful polling fallback |
| Deployment | Docker Compose |
| Testing | Pest, Vitest, Playwright |

**Cloud tenancy:** shared application with strict team-scoped data isolation; a `team_id` on relevant entities alone is not a sufficient security control. Explicit scoped queries, authorization policies, and isolation tests are required.  
**Self-Hosted:** same app with support for multiple teams in a single deployment.  
**GitHub:** integration adapter with idempotent inbound event processing, outbound job queue, mapping registry, and reconciliation jobs.  
**License:** AGPL-3.0-only. Source distribution and network-use obligations apply as defined by the license.

## 11. Conceptual Data Entities (Not Physical Schema)

`users`, `teams`, `team_memberships`, `team_invitations`, `projects`, `project_memberships`, `project_statuses`, `tasks`, `task_labels`, `labels`, `task_comments`, `task_activities`, `task_attachments`, `custom_field_definitions`, `task_custom_field_values`, `project_views`, `project_templates`, `sprints`, `sprint_tasks`, `notifications`, `github_installations`, `github_repositories`, `project_repositories`, `github_object_links`, `github_issue_mappings`, `github_webhook_deliveries`, `github_sync_outbox`, `github_automation_rules`, `audit_events`.

This list is a conceptual overview, not an independent migration specification. `DATABASE.md` is the exclusive authority for physical table names, columns, keys, and constraints.

Entity names are conceptual. Database design must explicitly address team scope, uniqueness, foreign keys, soft deletion, immutable audit data, indexing, and transactional integrity.

## 12. Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Free Cloud operating costs | Lean hosting, storage quotas for abuse prevention, observability, optional donations, documented sustainability plan |
| Private-project data leakage | Deny-by-default authorization and adversarial cross-tenant tests |
| GitHub synchronization conflicts | Explicit mapping, idempotency, conflict queue, reconciliation, visible failure states |
| Scope expansion into enterprise complexity | P0/P1 boundaries, templates, optional modules, usability review |
| Self-hosted installation friction | Single deployment guide, sample environment, health checks, migration docs |
| License compliance | Enforce AGPL-3.0-only compatibility review for dependencies and contributor guidance |
| Abuse of invitation links | Expiry, revocation, throttling, audit trail |
| Attachment storage growth | Configurable limits and storage drivers; no premium gates |

## 13. Closed decisions — implementation is not awaiting product-owner answers

All formerly OPEN-001 through OPEN-012 are **CLOSED** and replaced by the binding DECIDED-001 through DECIDED-012 register in `ARCHITECTURE.md` and `DATABASE.md`. Product name: سنع / SN3; AGPL-3.0-only; green `#145A38`; Space Grotesk + IBM Plex Sans Arabic; one nullable primary task assignee; project roles `manager|contributor`; Team Admin cannot discover private-project metadata without membership; optional sprint and GitHub Issues sync; public v1.0 includes GitHub App linkage, v1.1 adds Issues sync; Cloud fair-use and retention rules are fixed in the binding registers.

No OPEN decisions or TBD product choices remain. External credentials and actual domain names are deployment inputs, not open product decisions.

## 14. Delivery Plan and Agent Handoff

### Recommended work products in order

1. **Product Analyst:** Convert each `FR-*` and `NFR-*` into atomic user stories and identify implementation dependencies and specification defects.
2. **UX Designer:** Define information architecture, onboarding, RTL/LTR layouts, board/task/detail patterns, and mobile flows. Trace designs to requirement IDs.
3. **Solution Architect:** Write ADRs for tenancy, auth, project privacy, deployment parity, task IDs, workflow customization, and GitHub synchronization.
4. **Database Engineer:** Produce ERD and migrations only after permission boundaries and entity invariants are validated.
5. **Backend Engineer:** Define domain services, policies, APIs, events, idempotent jobs, and integration boundaries.
6. **Frontend Engineer:** Build localized accessible interfaces using approved API contracts.
7. **QA Engineer:** Produce positive, negative, cross-tenant, privacy, sync-failure, RTL, responsive, and self-hosted parity test suites.
8. **Release Engineer:** Publish reproducible Docker images, release notes, upgrade guides, backups, and secure deployment instructions.

### Definition of Ready

A work item is ready only when it has: requirement ID(s), user story, business rules, API/data implications, acceptance criteria, security/privacy considerations, localization requirements, dependencies, and test cases.

### Definition of Done

- Requirements and acceptance criteria satisfied.
- Unit/integration/e2e tests appropriate to the change pass.
- Authorization and tenant isolation covered where applicable.
- Arabic/English and mobile behavior reviewed.
- Documentation, migration, and rollback notes updated.
- No paid-only feature divergence between Cloud and Self-Hosted.

## 15. Requirement traceability

Every `FR-*` and `NFR-*` requirement is mapped in `TRACEABILITY.csv` to an implementation epic/task, source specification, test identifier, and release. This mapping is normative for delivery planning; test identifiers are planned tests, not claims of execution.

## 16. Explicit Constraints for AI Agents

- Do not interpret “fully customizable” as unrestricted arbitrary code execution or unlimited complexity.
- Do not implement paid feature gates or artificially restrict self-hosted team size.
- Do not grant Team Admin implicit read access to private project content.
- Do not infer GitHub repository access from membership in a project.
- Do not create distinct Task/Bug/Story/Epic storage models unless a new approved decision supersedes DEC-009.
- Do not make sprint concepts mandatory for projects with sprints disabled.
- Do not make GitHub availability a dependency of core task CRUD.
- Do not invent business metrics, SLA promises, retention periods, or legal compliance certifications.
- Preserve auditability of task updates and integration-originated changes.
- Apply the finalized decisions and report only genuine implementation defects, not product-choice questions.

---

**End of BRD v1.0.0.** This document is ready to be supplied as context to AI agents. No open product decisions remain. Implementation requires the user’s explicit command «ابدأ التنفيذ».

## 17. Reconciliation notice

This final BRD supersedes earlier P0/P1 shorthand. `P0` in individual FR identifiers means foundational scope, not exclusion from public v1.0. Public v1.0 ships R0–R3; v1.1 ships R4. The private-project metadata rule is strict non-disclosure, including to Team Admin nonmembers.
