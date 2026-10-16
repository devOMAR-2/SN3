# ADR-021 — Workflow statuses, project settings and immutable task keys

- **Status:** Accepted
- **Date:** 2026-10-16
- **Task:** CORE-004 (#13)
- **Decision inputs:** BRD FR-CUST-001, FR-CUST-002, FR-TASK-003, UC-001, UC-004, UC-005; ARCHITECTURE.md §6 (Workflows module), §6.1, §7; DATABASE.md §4, §5, §11, §16 (DB-T01, DB-T02, DB-T04, DB-T05), §19, §20; API_SPEC.md §1.4, §4, §5, §6.1, §12, §17, §18; DESIGN_SYSTEM.md §5.1, §7.1; acceptance scenarios PROJ-01, WF-01, AUTH-04; ADR-017, ADR-018, ADR-020

## Context

CORE-003 gave every project a key, a `next_task_number` counter and a `sprints_enabled` flag, but no workflow. ADR-020 left three things to CORE-004: project statuses, `/projects/{project}/settings`, and making the key immutable once the first task exists. Tasks themselves are CORE-005. So this task has to settle the key freeze and task-number allocation before the `tasks` table exists, in a form CORE-005 can call without changing it.

## Decisions

### 1. Endpoints
API_SPEC §5 gives the paths. All of them sit under the `auth` group of `/api/v1` and hang off the `{project}` binding (AccessibleProjects). A hidden, foreign or unknown project therefore answers 404 for every one of them, its statuses included (DB-T01, DB-T02).

| Endpoint | Who | Notes |
|---|---|---|
| `GET /projects/{project}/statuses` | Anyone who can see the project | Board order (`position`, then `id`), cursor-paginated (§1.1). `meta.workflow_version` and `ETag`. |
| `POST /projects/{project}/statuses` | `ProjectPolicy::manageWorkflow` | Appends the status at the end. `201`. |
| `PATCH /projects/{project}/statuses/{status}` | `manageWorkflow` | `name`, `category`, `color`; requires `If-Match` (status version). |
| `DELETE /projects/{project}/statuses/{status}` | `manageWorkflow` | Optional `replacement_status_id`; requires `If-Match` (status version). `204`. |
| `PUT /projects/{project}/statuses/order` | `manageWorkflow` | `{ "status_ids": [...] }`, the full ordered list; requires `If-Match` (workflow version). `200` with the statuses in their new order. |
| `GET /projects/{project}/settings` | Anyone who can see the project | `project_id`, `sprints_enabled`, `key_frozen`, `version`. |
| `PATCH /projects/{project}/settings` | `update` | `sprints_enabled`; requires `If-Match` (project version). |

- **Who manages the workflow.** §5 and §18 give status writes to "Owner/Project Manager". §17 lists the project `manager`'s "project settings, workflow, membership" as separate things and extends only *settings* to the Owner/Admin of a team-visible project. The conservative reading applies:
  - **Workflow** (status create, update, delete, reorder) uses a new ability, `ProjectPolicy::manageWorkflow`, with the same rule as `manageMembers`: the Team Owner always, or an explicit project `manager` who is a Team Admin or Member. A Team Admin without the `manager` role gets 403 even on a team-visible project, and a Team Viewer never qualifies (AUTH-04).
  - **Settings** (`PATCH /settings`) stay on `ProjectPolicy::update`, like `PATCH /projects/{project}` (ADR-020 §5): additionally a Team Admin on a team-visible project.
- **Errors.** A caller who can see the project but lacks the right gets 403 whatever the body, because the FormRequests authorise before they validate. A `{status}` that does not belong to the route's project, including one from another project the caller manages, is a 404.
- **`PATCH /statuses/{status}` and the order.** The §5 table lists "order" among the PATCH fields, but the §5 text says `position` is read-only and reordering happens only through `PUT …/statuses/order`. This follows the text. `position` (and `is_default`) in a PATCH body is `unknown_field`.

### 2. Schema (migration group 03, first part)
`2026_10_19_000100_create_project_statuses_table` follows DATABASE.md §5 and §20. Its columns are `id`, `project_id`, `team_id`, `name varchar(100)`, `category`, `position numeric(20,10)`, `color varchar(16) NULL`, `is_default`, `version`, and timestamps. Where the spec left a choice open:

- **Project and team.** `(project_id, team_id) → projects(id, team_id) ON DELETE CASCADE`, as ADR-020 §2 requires of every descendant. CORE-010's trash purge removes statuses with their project, and a status can never carry another team's ID.
- **`UNIQUE(project_id, id)`** is the target of CORE-005's `(status_id, project_id)` key, so a task can never use another project's status (DB-T04).
- **Names.** DATABASE §5 says to "consider unique case-insensitive status names per project". This adopts it as a functional unique index on `(project_id, lower(name))`; a duplicate is a 409. The column stays `varchar(100)` as specified. The API limit is 1–50 characters (§5), checked after trimming.
- **Default status.** There is at most one per project, a partial unique index on `(project_id) WHERE is_default`, and it is always a `todo` status: `CHECK (NOT is_default OR category = 'todo')`. New tasks start in it (CORE-005), so it must never be a done status.
- **Order.** `UNIQUE(project_id, position)`, which is stricter than the §5 index on `(project_id, position)`, makes the board order total. It is `DEFERRABLE INITIALLY IMMEDIATE`, which makes PostgreSQL check it at the end of each statement instead of row by row, so a reorder can rewrite every rank in one UPDATE (§3). Nothing defers it past the statement.
- **CHECK constraints:**
  - `category IN ('todo','in_progress','done')`;
  - a non-blank name;
  - `color` is `NULL` or `^#[0-9A-F]{6}$`;
  - `position >= 0`;
  - `version > 0`;
  - the default is a `todo` status.
- **Workflow version.** `projects.workflow_version bigint NOT NULL DEFAULT 1 CHECK (> 0)` (`2026_10_19_000110`), see §3.
- **Index.** `(team_id, project_id)`, for team-scoped maintenance (purge and export).
- **Upgrade.** The migration gives every existing project the default workflow (§4), trashed projects included, named in the language of the project's creator. Existing projects therefore have statuses after the upgrade, just like new ones. It is one set-based `INSERT … SELECT … CROSS JOIN (VALUES …)` with a `CASE` on `users.locale`, not a row-by-row loop. IDs are UUIDv7 built in SQL (the clock's milliseconds in the first 48 bits of `gen_random_uuid()`, version nibble set to 7), because PostgreSQL 16 has no v7 generator. Rollback drops the table and the column.

### 3. Ordering scheme
- **Ranks.** `position` is the stored rank. CORE-004 keeps the ranks dense, `0..n-1` in board order:
  - a new status gets the last rank + 1 (`coalesce(max(position) + 1, 0)`);
  - a delete renumbers the statuses that remain, in their current order;
  - a reorder writes `0..n-1` in the order given.
- **What the API returns.** The ordinal and the stored rank are the same number, so the API returns `position` as that integer (§5, "0-based ordinal derived from the stored rank"). Fractional ranks would only pay off for single-item moves between anchors, which is how tasks move (§6.1). Statuses move only through the full-list `PUT`.
- **Locking.** Every status writer holds the project write lock (§7) while it computes ranks. Ranks therefore cannot collide or leave gaps, and `UNIQUE(project_id, position)` would refuse a collision anyway.
- **Workflow version (API_SPEC §1.4 "workflow configuration").** The workflow as a whole is versioned by `projects.workflow_version`. Without it a stale reorder would silently overwrite a newer one: two managers load [A, B, C], one saves [C, B, A], the other then saves [B, A, C] from the stale copy, and both get 200.
  - **Reading it.** `GET /statuses` returns it as `meta.workflow_version` and as `ETag: "<n>"`. The reorder response carries the new value the same way, and `DELETE` (204) carries it as the `ETag`. Status create and update responses carry it in `meta.workflow_version` only; their `ETag` is the status's own `version`, so a client can send it straight back as `If-Match` on that status. A paging client uses the first page's version (or `limit=100`). It is read with the project (route binding) before the statuses, so a change committed in between can only make it older than the list: the next reorder gets 412 instead of overwriting.
  - **Reorder requires it.** `PUT …/statuses/order` needs `If-Match` with it: missing is 428, stale is 412, and 423 (archived) still wins. Then the list must contain exactly the project's current statuses, else 409. Malformed lists (not a list, duplicates, non-UUIDs, more than the status cap) are 422.
  - **Every change moves it.** Every status create, update, delete and reorder increments it under the project lock. A no-op (a PATCH that changes nothing, a reorder into the current order) keeps it.
  - **Status changes keep their own version.** `PATCH` and `DELETE` of a status still take that status's `If-Match` (`project_statuses.version`, §18): an edit to one status does not conflict with an edit to another.
  - **Separate from `projects.version`.** Workflow edits do not make `PATCH /projects` or `/settings` fail with 412, and the reverse.
- **Reorder does not bump status versions.** `position` is not writable through a status PATCH, so a rename racing a reorder loses nothing, and bumping every status version would make concurrent renames fail with 412 for no reason (ADR-020 §7 reasons the same way about memberships). The workflow version covers the order.

### 4. Default workflow, categories and deletion
- **Default workflow (FR-CUST-002, UC-001).** `CreateProject` seeds the default statuses in its own transaction, so a project never exists without them:

  | Rank | Status | Category | Default for new tasks |
  |---|---|---|---|
  | 0 | To do | `todo` | yes |
  | 1 | In progress | `in_progress` | no |
  | 2 | Done | `done` | no |

  Names come from `lang/{ar,en}/workflow.php` in the creator's saved locale. The strings follow DESIGN_SYSTEM §7.1 `task.status.*`: للإنجاز, قيد التنفيذ, مكتمل. They are ordinary, editable names. The category is the language-neutral identifier that ARCHITECTURE §6.2 asks for. Seeded statuses have no colour; the UI styles them by category.
- **Categories.** Statuses have the categories `todo`, `in_progress` and `done`. Every project keeps at least one `todo` and one `done` status (§5). A change that would break this is a 409:
  - deleting the last status of either category;
  - moving the last status of either category to another category.
- **The default stays a `todo` status.** New tasks start in the default (CORE-005), so it must never be a done or in-progress status. When the default status moves to another category, the flag passes to the first remaining `todo` status by position (the todo rule guarantees one); renaming or recolouring keeps it. The database enforces the same rule with a CHECK (§2).
- **Status cap.** A project holds at most **50** statuses; the 51st is a 409. The spec sets no number, but leaving it unbounded is not an option: the reorder body, the board and the status list must stay bounded. With 50 statuses the whole workflow fits in one page at the maximum `limit` of 100.
- **Colour.** Optional `#RRGGBB`, trimmed and upper-cased first, so `#abcdef` becomes `#ABCDEF`. `#FFF` and named colours are rejected (`regex`).
- **Deletion (UC-005, WF-01).** These steps run in one transaction under the team → project → status locks:
  1. Check the state: 423 if archived, 404 if the status is gone, then 412 if the version is stale.
  2. Check `replacement_status_id`, if given. It must be another status of the same project. A status of another project is answered exactly like a random ID (422 `exists`), so the endpoint is no probe. The deleted status itself gives `different`.
  3. Apply the todo/done rule (409).
  4. Delete the status.
  5. If it was the default, the replacement becomes the default when it is a `todo` status; otherwise (no replacement, or a non-todo one) the first remaining `todo` status by position does.
  6. Renumber the remaining statuses and increment the workflow version.

  `tasks` does not exist yet, so every status is empty today. **CORE-005 adds the task step** (step 4):
  - move every task of the deleted status to the replacement, in the same transaction;
  - answer 409 when the status still has tasks and no replacement was given;
  - make its `(status_id, project_id)` foreign key `DEFERRABLE` NO ACTION (never CASCADE or RESTRICT, ADR-022 §5): the database still refuses, at the end of the statement, to delete a status that tasks use, while a purge that removes the project's tasks and statuses together passes.
- **Transitions.** Neither the specs nor the data model restrict which status a task may move to, so any status of the same project is a valid next status. There is no transition table and no transition endpoint. ARCHITECTURE §6.1 "valid transitions" therefore means: the target status belongs to the task's project, which the composite key enforces (DB-T04), and the move is an authorised write (CORE-005/CORE-007). A `done`-category status is what completion and GitHub mapping key on (DECIDED-005; API_SPEC §10–§11).

### 5. Task numbers and the key freeze
- **Allocation (DATABASE §5, §11; DB-T05).** `App\Domain\Projects\TaskNumbers::allocate($lockedProject)` is the allocator CORE-005 calls. It runs `UPDATE projects SET next_task_number = next_task_number + 1 WHERE id = ? RETURNING next_task_number - 1`, which is never `MAX(task_number)+1`.
  - **Calling it.** CORE-005 calls it inside the transaction that inserts the task, after `ProjectWriteLock::project($project, $actor, 'contribute')`. The lock order stays team → project, and the archived and access re-checks run first.
  - **Outside a transaction** it throws `LogicException`, because the number would be committed without its task.
  - **Rollback.** A rolled-back task returns its number together with everything else. A committed number is never issued again.
  - **Formatting.** `TaskNumbers::key($project, $n)` formats `{KEY}-{n}`, e.g. `WEB-104`.
  - **Version.** Allocation does not change `projects.version`, because a task creation is not a project edit.
- **When the key is frozen (PROJ-01).** The key is frozen once a task number has been issued: `next_task_number > 1` (`Project::keyIsFrozen()`). DATABASE.md already has this column, so no `key_frozen_at` column is added. Deleting tasks does not unfreeze the key, because links to their keys may still exist (FR-TASK-003).
- **Freeze in the API.** `UpdateProject` checks the freeze on the locked row and answers **409 `conflict`** to a key change. API_SPEC §1.3 lists state conflicts under 409 and names no more specific code. Sending the current key, in any case, is not a change and succeeds. The other fields stay editable.
- **Telling clients.** `GET /settings` exposes `key_frozen`, so a client can stop offering the key field (DESIGN_SYSTEM §5.1: "users may edit before first task only").
- **Database backstop.** A trigger, `projects_guard_task_numbering`, refuses (`check_violation`) two things on any path:
  - lowering `next_task_number`, so numbers are never reused (`projects_next_task_number_monotonic`);
  - changing `project_key` once `next_task_number > 1` (`projects_project_key_frozen`).

  It uses `CREATE OR REPLACE FUNCTION`, because `migrate:fresh` drops tables but not functions.
- **Concurrency.** A key change and the first allocation both lock the project row. Either the key changes first, and the first task gets the new key, or the allocation comes first and the change is a 409. A number is never issued under a key that changes afterwards. Real-process races cover both this and DB-T05.

### 6. Settings
- **Part of the Project resource.** Settings are part of the Project resource (§1.4). `PATCH /settings` therefore checks `If-Match` against `projects.version` and increments it, which is the same version that `PATCH /projects/{project}` uses. This follows ADR-020's representation, which already exposes `version`.
- **Only `sprints_enabled` so far.** It is the only setting with a column (DATABASE §4), boolean and strictly typed (`true`/`false`; `1` or `"yes"` give `boolean`). §5 also mentions "points" and "enabled views", but there are no columns for them yet. FLEX-005 (story points) and FLEX-002/FLEX-003 (views) add them, and until then they are `unknown_field`. Metadata such as `name` belongs to `PATCH /projects/{project}`, so here it is `unknown_field`.
- **Disabling sprints** while a sprint is active must be refused (SPR-02). Sprints arrive with FLEX-005, which adds that check to `UpdateProjectSettings`.
- **Audit.** A change is logged as `project.settings_changed` (§8).

### 7. Locking and state checks
- **Lock order.** Every workflow writer runs in one transaction and locks the team row, then the project row through `ProjectWriteLock::project(…, 'manageWorkflow')`, then status rows (`FOR NO KEY UPDATE`, so CORE-005's task inserts are not blocked by the foreign-key check). This is the lock order of ADR-018/ADR-020, so workflow writers cannot deadlock with team or project writers.
- **Re-checks on the locked rows:**
  - an archived team or project gives 423, before any `If-Match` comparison (423 wins over 412);
  - a project the actor can no longer see gives 404;
  - an actor who lost the right (for example a manager demoted to contributor) gets 409;
  - a status removed meanwhile gives 404.
- **Concurrency tests.** Real-process races (`TaskNumberConcurrencyTest`) show:
  - three simultaneous allocations get 1, 2 and 3;
  - a key change racing the first allocation never leaves a task number under a changed key;
  - a status created while the workflow is reordered leaves dense positions in both possible orders (the reorder gets 412 when the create commits first);
  - four simultaneous creates get four distinct, dense positions. This test fails when the team and project locks are removed from `ProjectWriteLock`, which was checked.

### 8. Audit
Until CORE-008 adds the activity model, these structured `info` events are logged, with IDs only (never project or status names):

| Event | Fields |
|---|---|
| `workflow.status_created` | `category` |
| `workflow.status_recategorised` | `from`, `to`, `default_status_id` (the status that took over the default, if any) |
| `workflow.status_deleted` | `replacement_status_id` |
| `workflow.statuses_reordered` | none |
| `project.settings_changed` | `sprints_enabled` |

Each event also carries `team_id`, `project_id`, `actor_id`, and `status_id` where there is one. Renames and colour changes are not logged.

## Consequences

- **CORE-005:**
  - creates tasks through `ProjectWriteLock::project(…, 'contribute')` + `TaskNumbers::allocate()` in the insert transaction;
  - references `project_statuses(project_id, id)` from `tasks(project_id, status_id)` as `DEFERRABLE` NO ACTION, never CASCADE or RESTRICT (ADR-022 §5);
  - starts new tasks in the `is_default` status;
  - adds the task move and the "non-empty status needs a replacement" 409 to `DeleteStatus` (WF-01).
- **CORE-007** drives task movement between any statuses of the project; there are no transition rules to consult.
- **CORE-010:** the purge removes statuses through the project cascade. Restore needs nothing extra, because statuses are never soft-deleted.
- **FLEX-002/FLEX-004/FLEX-005:**
  - FLEX-005 adds the active-sprint check to `PATCH /settings` and the story points setting;
  - FLEX-002 builds the settings UI on these endpoints;
  - FLEX-004's templates seed statuses through the same rules (todo + done, cap, unique names). The Blank template's single `To do` status (DESIGN_SYSTEM §5.1) conflicts with §5 "at least one `todo` and one `done`", and FLEX-004 must settle that.
- **Open questions:**
  - Should the default status follow DESIGN_SYSTEM §5.2 ("the project's first active status") instead of the explicit `is_default` flag that DATABASE §5 defines? This keeps the flag, which no endpoint can set directly.
  - Is the 50-status cap the right number?
