# ADR-026 — Team and project navigation, project creation and task detail UI

- **Status:** Accepted
- **Date:** 2026-10-21
- **Task:** CORE-006 (#15)
- **Decision inputs:** DEVELOPMENT_PLAN CORE-006 ("No dead ends; role-based actions hidden AND server-denied"); DESIGN_SYSTEM.md §1–§16; ARCHITECTURE.md §4, §5, §8, §10; API_SPEC.md §0.3, §1.1, §1.4, §3–§6, §12, §14.3, §17; BRD FR-AUTH-003, FR-TEAM-001/004/005/006, FR-PROJ-001..008, FR-TASK-002/003/004/007/008, FR-CUST-001/002, NFR-UX-001/002; ACCEPTANCE_SCENARIOS AUTH-02, AUTH-04, I18N-01; ADR-014, ADR-017, ADR-018, ADR-020, ADR-021, ADR-022, ADR-023, ADR-024

## Context

CORE-001 to CORE-005, CORE-010 and CORE-012 built teams, projects, workflows, tasks, the trash and exports as `/api/v1` endpoints. FND-006 built the app shell, tokens, i18n and themes. CORE-006 is the first set of screens on top of them. Two constraints shape every decision below:

- a hidden team, project or task must look exactly like a missing one in the UI too (DECIDED-003, API_SPEC §0.4, DS-ACC-006/014);
- an action is offered only when the server would allow it, and the server still refuses it on its own (acceptance criterion of CORE-006).

## Decisions

### 1. How pages get their data: Inertia props for the page, `/api/v1` for lists and every write

ARCHITECTURE §8 reserves JSON endpoints for "asynchronous UI needs, external clients, and webhooks" and uses Inertia pages for normal UI; API_SPEC §0.3 says `/api/v1` "is a JSON HTTP interface for asynchronous browser operations" and accepts same-origin sessions; API_SPEC §15 forbids duplicated business logic between Inertia actions and JSON endpoints. FND-004 already set the pattern: Inertia pages whose forms submit to `/api/v1` with the session cookie and `X-XSRF-TOKEN` (`resources/js/lib/api.ts`).

CORE-006 keeps that pattern and makes it explicit:

- **Web controllers are read-only.** `App\Http\Controllers\Web\{Team,Project,Task}PageController` answer `GET` only. They resolve the resource through the tenancy resolvers (§2), authorise `view` with the policy, and render the page with: the resource in the same representation as the API (`TeamResource`, `ProjectResource`, `TaskResource`, …), the small bounded reference lists the page needs (≤ 50 statuses, ≤ 100 labels, ≤ 100 checklist items, ≤ 100 subtasks, the eligible assignees), the `can` map (§3) and the `lock` reason (`team_archived`, `project_archived` or null).
- **Every write goes to `/api/v1`.** Creating a team, a project or a task, editing, archiving, deleting, restoring, role changes, exports: the page calls the existing endpoint, so the same FormRequests (`RejectsUnknownFields`, authorise-before-validate), actions, locks, `If-Match`, `Idempotency-Key`, 423/409/412 handling and audit logs apply. No web route mutates anything, so there is no second write path to keep in step.
- **Paginated lists are read from `/api/v1`** with the opaque cursor (`useCursorList`): the team list, team members, a team's projects, project members and the project's task list. `CursorPagination` stays the only paginator, and the client never holds more than the pages the user asked for (DESIGN_SYSTEM §10). These lists have honest loading (skeleton), empty, and error (retry + request ID) states (§9).
- **After a write** the page updates its local state from the API response, or calls `router.reload()` when the abilities may have changed (ownership transfer, archive), or navigates (create, delete, slug or key change).

Rejected alternatives: Inertia form posts to new web routes (a second write path that would have to duplicate idempotency, `If-Match` and the envelope), and a client that fetches everything from `/api/v1` (then the server could not answer a hidden resource with a 404 page before any UI renders, and `can` would need a new API surface).

### 2. Routes and resolution (API_SPEC §1.1)

| Route | Page | Resolution |
|---|---|---|
| `/` | `Home` | the user's teams (shared `workspace` prop) |
| `/teams` | `Teams/Index` | `GET /api/v1/teams`; deleted teams from `RecoverableResources::teams()` |
| `/teams/new` | `Teams/Create` | — |
| `/t/{team_slug}` | `Teams/Show` | `AccessibleTeams::findBySlugOrFail()` |
| `/t/{team_slug}/members` | `Teams/Members` | same |
| `/t/{team_slug}/settings` | `Teams/Settings` | same; 403 unless `viewsTeamSettings` |
| `/t/{team_slug}/projects` | `Projects/Index` | same; deleted projects from `RecoverableResources::projectsInTeam()` |
| `/t/{team_slug}/projects/new` | `Projects/Create` | same; `TeamPolicy::createProject` (403) |
| `/t/{team_slug}/p/{project_key}` | `Projects/Show` | `AccessibleProjects::findByKeyOrFail()` inside the resolved team |
| `/t/{team_slug}/p/{project_key}/settings` | `Projects/Settings` | same; 403 unless `viewsProjectSettings` |
| `/tasks/{task_id}` | `Tasks/Show` | the existing `{task}` binding (`AccessibleTasks`) |

- `{teamSlug}` and `{projectKey}` are route bindings in `TenancyServiceProvider`, next to `{team}`, `{project}` and `{task}`. They run the **same queries** as the API bindings (membership; the AccessibleProjects predicate), only keyed by slug or key. A team the user does not belong to, a trashed team or project, a private project the user may not see, a project of another team behind this team's slug, an unknown or malformed slug or key: all the same 404.
- Slugs are matched exactly (they are stored lower-case, `[a-z0-9-]{3,40}`). Keys are upper-cased first, so `/p/web` and `/p/WEB` are the same page; anything outside `[A-Z][A-Z0-9]{1,9}` is a 404 without a query.
- **Technical settlement (task URL).** API_SPEC §1.1 lists `/t/{team_slug}/p/{project_key}` but no task page. Task links use `/tasks/{task_id}` (the UUID): DESIGN_SYSTEM §5.2 says "links use stable internal UUID so renaming the project never breaks links", and a team slug can change at any time while a task's UUID never does. Breadcrumbs show `Team / Project / KEY`.
- **Technical settlement (team pages).** §1.1 lists `/t/{team_slug}/projects`; the overview, members and settings pages follow the same prefix. CORE-002 adds its invitation pages under `/teams/{team}/invitations` and `/invite`; nothing here collides with them.
- The web routes are in the `auth` group; guests are redirected to `/login`. They are read-only, so the global per-user request limiter (ADR-016) is enough.

### 3. `can` maps: server-made, read-only in the client

`App\Http\Pages\Abilities` builds every `can` map from the policies (`TeamPolicy`, `ProjectPolicy`), combined with the resource state that makes every write a 423:

- **Team** (`Abilities::team`): `view_settings`, `update`, `create_project`, `invite` and `invite_admin` (for CORE-002's panel), `change_member_roles`, `remove_other_members`, `transfer_ownership`, `leave`, `archive`, `unarchive`, `delete`, `export`.
- **Project** (`Abilities::project`): `view_settings`, `update`, `manage_members`, `manage_workflow`, `manage_labels`, `contribute`, `restore_tasks`, `archive`, `unarchive`, `delete`.
- **Task page**: `edit` and `delete` (= project `contribute`), `manage_labels`.
- Every write flag is `false` while the team or the project is archived; `unarchive` is offered instead to whoever may archive. An archived team freezes its projects' lifecycle too (ADR-022 §2), so a project's `unarchive` is `false` then. `export` stays on for an archived team (ADR-024).
- `leave` is `false` for the Owner, who has to transfer ownership first (TEAM-01's 409); the policy itself lets the Owner through so the API can answer 409.
- **Per-row rules on the members page.** The team member list is paginated from `/api/v1` and its rows carry no abilities. The page applies the policy's own per-row rule to the server's flags: `change_member_roles` and `remove_other_members` never apply to the row of `team.owner_user_id` (TeamPolicy::changeMemberRole refuses the Owner's row), and "remove" is replaced by "Leave" on the user's own row. These are facts the server sends (who the Owner is, who the user is), not a permission computed in the browser; the API refuses anything else anyway.
- The page controllers use the same helpers (`viewsTeamSettings`, `viewsProjectSettings`) to answer 403 for a settings page the user may not open, so the link and the page cannot disagree.

**Hidden AND server-denied, tested together.** `tests/Feature/Pages/{TeamPagesTest,ProjectPagesTest,TaskPageTest}.php` render the pages for every team role × visibility × project role that matters, assert the `can` map, and then send the matching API request for each flag: allowed → 2xx, not allowed → 403 (visible) or 404 (hidden). A flag that ever disagrees with the server fails the test.

### 4. Error pages for browser routes

`App\Exceptions\PageExceptionRenderer` (registered with `$exceptions->respond()`) answers 403, 404, 419, 429, 500 and 503 on browser routes with the Inertia `Error` page, in the user's language and inside the app shell, with a way back (Home, Teams) — no dead ends. The page shows the status and a generic text only: a hidden team, project or task and a missing one get the identical "This page isn't available" (DESIGN_SYSTEM §6, §9.4). `/api/v1` keeps the JSON envelope (`ApiExceptionRenderer` runs first). The framework's default answer stays for:

- requests other than GET and HEAD: the URL of a failed `PUT /locale` (419 CSRF, 429 throttle) or of another write is no page to show or reload;
- requests that matched no route (no session, no locale);
- server errors in debug mode;
- any failure while rendering the page itself, for example the database being down while the shared props (`auth`, `workspace`) are read: the renderer catches it and returns the original response.

A route-binding 404 is thrown by `SubstituteBindings`, which sorts before `HandleInertiaRequests`, so the renderer shares the Inertia props itself (locale, `auth`, `workspace`) before rendering. `ErrorPageTest` pins this.

### 5. Task detail

- **Editing model (DESIGN_SYSTEM §5.2).** Title and description use an explicit Save (Enter saves the title, Escape cancels). Status, assignee, priority, due date and labels save on change, with a visible "Saving… / All changes saved / Not saved" state (also announced in a polite live region) and roll back to the stored value on failure.
- **Concurrency (API_SPEC §1.4, §14.3).** `lib/taskEditor.ts` sends every `PATCH /tasks/{id}` with `If-Match` set to the version the client last received, and runs edits one after another, so two quick edits on the same page never conflict with each other. A 412 is never retried or merged: the page shows "Changed by someone else" with a **Reload** button (`GET /tasks/{id}`), rolls the auto-saved field back, and keeps any text still being edited (title or description drafts survive the reload) so the user can reapply it (DESIGN_SYSTEM §9.7).
- **423.** A write refused with 423 after the page loaded switches the page to read-only and shows the "read-only now" banner; a page loaded on an archived team or project shows its banner from the start and offers no editors.
- **404 while open.** A write or reload answered 404 (deleted, made private, access removed) shows "no longer available" with a link to the project; the page names nothing it should not.
- **Assignees.** The picker lists `TaskAssignments::eligibleUsers()`, which uses the same SQL predicate as the API's eligibility check, so the picker never offers someone the API would refuse (Viewers, non-members of a private project, disabled accounts). The list is capped at the first **200** eligible people by name (`TaskPageController::ASSIGNEE_LIMIT`); a larger team gets `assigneesTruncated: true` and the picker says so. The current assignee is always added, so an assigned task never shows an empty picker. A searchable picker that pages through the API is a follow-up (it needs an eligible-assignee endpoint).
- **Labels.** Only what task detail needs: show the task's labels, tick project labels (`label_ids` replaces the set), and — for the Owner and Project Managers — create a label inline so the picker is never a dead end. Renaming, colours and deleting labels are FLEX-002.
- **Checklist and subtasks.** Tick, add, rename, move up/down (keyboard, with a live announcement) and delete checklist items; list subtasks and add one with the quick-add (`parent_task_id`). Checklist items are not versioned (ADR-023 §7), so they take no `If-Match`.
- **Description** is shown as plain text with its line breaks (`white-space: pre-wrap`, `dir="auto"`), never as HTML. Markdown rendering with sanitisation belongs with the rich text editor (DESIGN_SYSTEM §4) and is not part of this task.
- **Mobile** (DESIGN_SYSTEM §3.3): the task is a full-width page with a sticky header (key, title, Close); the details column follows the content in DOM order, which is also the keyboard order.
- **Extension point for CORE-008.** `<div data-extension-point="task-collaboration">` below the subtasks is where comments, activity and attachments mount.

### 6. Teams, projects and the trash

- **Team switcher** (FR-AUTH-003): a disclosure button in the sidebar listing the user's teams as links, from the shared `workspace` prop (`Workspace::switcher()`, AccessibleTeams, by name, at most 50 with "All teams" beyond). The active team is only the page being viewed; nothing is stored server-side (ARCHITECTURE §5.1). The sidebar also lists the current team's first 25 projects (`GET /api/v1/teams/{team}/projects`), so private projects appear only for those who may see them.
- **Team creation wizard** (DESIGN_SYSTEM §3.4): name (slug and description optional) → first project, with "Skip for now". The optional invitations step is CORE-002's.
- **Members page**: role changes (Owner), removal (Owner), leaving (anyone but the Owner), ownership transfer (Owner) with confirmation. The transfer dialog offers every member: it reads the whole member list from the API, 100 at a time, when it opens, not only the pages loaded on screen. `<div data-extension-point="team-invitations">` marks where CORE-002's `InvitationsPanel` mounts (it can use `can.invite` and `can.invite_admin`).
- **Team settings**: name, slug (changing it moves the page to the new URL), description; export (`TeamExportPanel`: `POST …/exports` with an `Idempotency-Key` that is kept until the export is accepted, so a retry after a failure replays instead of queueing a second export; poll until ready; signed download link); archive/unarchive; delete with the **"Export first"** step (DECIDED-011, ADR-022 §5, ADR-024): the dialog first offers the export, then asks the user to type the team name.
- **Project creation** (DESIGN_SYSTEM §5.1): name, key (suggested from ASCII letters of the name while the user has not edited it; empty for an all-Arabic name, so the user picks one), description, visibility (team-visible by default, with what each means), and optional explicit members with a role. Members are added after the create with `PUT /projects/{id}/members/{user}`; if any of those fail, the project exists and the user lands on its settings page with an error toast to finish. Icon/colour and templates are not offered: the API accepts neither yet (ADR-020 §2, FLEX-004).
- **Project settings**: general (name, key until the first task — `key_frozen` disables it —, description, visibility with a warning before making a project private), the sprints switch (`PATCH …/settings`), workflow statuses (move up/down, rename/recategorise, add, delete with a replacement for its tasks; every write with the status or workflow `If-Match`, and the list re-read afterwards; 412 shows a notice and the fresh list), explicit members, and the danger zone (archive/unarchive, delete with the project key typed). Project writes send `If-Match` with the project version; a 412 shows a reload prompt.
- **423 on the settings pages**: a write refused with 423 (the team or project was archived meanwhile) shows the "read-only now" banner at once and reloads the page props, whose `can` flags then hide every editor. The same applies to the workflow editor and project members on the project settings page, and to role changes on the members page.
- **Restore from the trash**: `/teams` lists the Owner's trashed teams, and a team's projects page lists its trashed projects, each with "Can be restored until …" and Restore (`POST …/restore-deleted`). The lists come from new `RecoverableResources::teams()` and `projectsInTeam()`, which apply exactly the predicates of the restore bindings (Owner only for teams; AccessibleProjects on trashed rows plus `restoreDeleted` for projects; 30-day window) and are capped at 100 rows. The restore rule is part of the SQL (Owner membership for teams; Team Owner, or a Team Admin or Member holding the `manager` role, for projects), so it is applied **before** the limit and rows the user may not restore can never crowd out ones they may; the Gate check afterwards is a guard. **Technical settlement:** API_SPEC has no endpoint that lists the trash; listing it in the page props keeps it scoped to what the user may recover, so a private project never appears for a Team Admin who is not a member.

### 7. The task list (deliberately simple)

The project page has a "Tasks" section that CORE-007 replaces (`data-replaceable="core-007"`): `GET /api/v1/projects/{project}/tasks?sort=position` (board order), 50 per page with "Load more", a status filter, quick add (Enter creates, Escape clears; the `Idempotency-Key` is kept until the task is created, so a retry never duplicates it), and each row a link to the task. It says on screen that Board and List views are coming. No sorting, drag and drop or other filters.

### 8. Frontend structure and accessibility

- **New UI primitives** (`Components/ui`): `AppDialog` (native `<dialog>` + `showModal()`: focus trap, Escape, inert page, focus restored; bottom sheet on mobile), `ConfirmDialog` (typed confirmation), `SelectField`, `TextAreaField` (counter), `EmptyState`, `SkeletonRows`, `LoadError`, `AppBadge`, `ButtonLink`, `TechnicalId`, `ToastRegion`. Reka UI is still not needed: native `<dialog>`, `<select>` and a disclosure button cover these needs with full keyboard support, so no dependency was added (ADR-014 §6 leaves Reka UI to the first task that needs a combobox).
- **Workspace components** (`Components/Workspace`): `WorkspaceLayout` wraps FND-006's `AppLayout` (unchanged) and fills its `navigation` and `user-menu` slots; `WorkspaceNav` (icons with accessible names in the tablet rail), `TeamSwitcher`, `AppBreadcrumbs`, `LockBanner`, `PageHeader`, `WizardSteps`, `VisibilityBadge`, `TeamExportPanel`. Manual collapse of the desktop sidebar (ADR-014 §6) is not added: it needs a change to the shared `AppLayout` and a stored preference, and is left as a follow-up.
- **Localization**: new namespaces `nav`, `ui`, `team`, `project`, `task`, `workflow`, `checklist`, `label`, `trash`, `lock`, `conflict`, `error`, `empty`, in Arabic and English (`satisfies Messages` keeps them complete), including DESIGN_SYSTEM §7.1's keys (`nav.my_work`, `project.visibility.*`, `task.status.*`, `error.generic`, `empty.tasks`, …). `resources/js/i18n/catalogue.test.ts` scans every component, page and helper for `t('…')` keys and fails if one is missing in either language. Status, priority, visibility and role are always text (with an icon), never colour alone (DS-ACC-015). Task keys, project keys, slugs, email addresses and breadcrumbs of task keys render LTR (`TechnicalId`, `dir="ltr"`).
- **Keyboard**: every action is a button or link; reordering statuses and checklist items uses Move up/Move down buttons with a live announcement of the new position; dialogs restore focus; nothing depends on hover or drag.
- `useApiForm` gained an optional `headers` argument (`If-Match`, `Idempotency-Key`) and exposes the last error, both additive.

### 9. Tests

- **Pest** (`tests/Feature/Pages`): a key that exists in two of the user's teams resolves to the project of the team in the URL; `can.manage_labels` on the task page with a label-create probe; `invite`/`invite_admin`; the assignee cap; trash lists behind more than 100 non-restorable rows; the Error page's GET-only rule, its database-down fallback, the page-load 429 with `Retry-After`, and the debug-mode 500 branch; guests redirected; the role × visibility × project-role matrix for team, project and task pages (component, props, `can`), each flag cross-checked against the API; 404 for other teams, trashed and private resources, cross-team keys and malformed slugs/keys/IDs, with no name in the body; 403 settings pages; archived teams and projects; key freeze; the switcher scoping and cap; eligible assignees; trash lists (expired, other people's and private-without-membership excluded); the localized Error page, its shared props, Inertia visits, and the untouched API envelope. Key guards were mutation-checked: the slug and key bindings without their membership/visibility filter, the archived check in `contribute`, the settings 403s, the project-creation guard, the trash list's `restoreDeleted` filter, `remove_other_members` widened to Admins and the assignee predicate each make a test fail.
- **Vitest**: the task editor (serialised `If-Match`, 412 → conflict without retry, 423, reset), key suggestion, date formatting across time zones, request helpers, the catalogue completeness check, and component tests for the members page (controls per ability, the Owner's row, rollback on refusal, the CORE-002 extension point) and task detail (read-only Viewer, `If-Match` save, 412 → reload prompt with the draft kept and the field rolled back, 423 → read-only, Arabic RTL, plain-text description, the CORE-008 extension point).
- **Playwright** (`e2e/tests/core-journey.spec.ts`): the main path on desktop — sign in, create a team, its first project, a task (quick add), open it, edit title, priority and checklist, reload to see them persisted, then switch to Arabic and check RTL, the translated labels and the LTR task key; and a `@ui` navigation test on desktop and mobile (team switcher, drawer on mobile, no horizontal scroll at 375px-class widths, another team's page is the generic 404). The journey writes only as `journey_en`, a new fixture account in no seeded team (`journey_users` in `e2e/fixtures/tenants.json`), so the read-only specs running in parallel never see its data. The journey takes about 5 seconds.

## Consequences

- **CORE-007** replaces the `data-replaceable="core-007"` section of `Projects/Show.vue` with the Board and List views and reuses `StatusChip`, `PriorityBadge`, `QuickAddTask` and `useCursorList`.
- **CORE-008** mounts comments, activity and attachments at `data-extension-point="task-collaboration"` in `Tasks/Show.vue`; its writes follow the same `/api/v1` pattern.
- **CORE-002** mounts `InvitationsPanel` at `data-extension-point="team-invitations"` in `Teams/Members.vue` and may add the invitation step to the team wizard (`Teams/Create.vue`).
- **FLEX-002** builds label management, status colours and custom fields into `Projects/Settings.vue`; **FLEX-005/006** add the Backlog and Sprints tabs when `sprints_enabled`.
- A future `/api/v1` trash listing, if the API ever needs one, should reuse `RecoverableResources::teams()` / `projectsInTeam()`.

## Open questions (implemented conservatively)

1. **Arabic-only project names** get no suggested key (keys are ASCII, API_SPEC §4); the user types one. Should the UI suggest a transliteration instead?
2. **Task URL** `/tasks/{id}` is not in API_SPEC §1.1's page list; is a `/t/{slug}/p/{key}/…` form wanted in addition?
3. **Desktop sidebar manual collapse** (DESIGN_SYSTEM §3.1) is not implemented; it needs a change to the FND-006 `AppLayout`.
4. **Members page per-row abilities** are derived from team-level flags plus the Owner's ID (§3); should the members API carry per-row abilities instead?
5. **"My work" and "Inbox"** (DESIGN_SYSTEM §3.1) are not in the sidebar: no task owns those pages yet (`GET /me/tasks` exists; the Inbox is CORE-009).
