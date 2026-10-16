# ADR-023 — Tasks, single assignee, labels, checklists, priorities and due dates

- **Status:** Accepted
- **Date:** 2026-10-20
- **Task:** CORE-005 (#14)
- **Decision inputs:** BRD FR-TASK-001 to -004, -006 to -008, FR-TEAM-006, DECIDED-006; ARCHITECTURE.md §5.2, §5.3, §6.1, §7; DATABASE.md §5, §10, §11, §12, §13, §16 (DB-T01 to DB-T05, DB-T08, DB-T13), §19, §20; API_SPEC.md §1, §2, §5, §6.1, §6.2, §12, §14.1, §14.3, §17, §18; acceptance scenarios AUTH-01, AUTH-04, TASK-01, TASK-02, TASK-03, PROJ-01, WF-01, IDEM-01; ADR-017, ADR-018, ADR-020, ADR-021, ADR-022

## Context

CORE-004 left task numbering, the key freeze and the workflow ready for tasks (ADR-021 §5, "Consequences"); CORE-010 fixed the rule every new key must follow for the trash purge (ADR-022 §5). CORE-005 adds the task itself, its single assignee (DECIDED-006), project labels, checklists, priorities and due dates, with validation, optimistic concurrency and scoped retrieval (DEVELOPMENT_PLAN). The UI is CORE-006/007; ordered moves (`POST /tasks/{id}/move`) are CORE-007; comments, attachments and the activity model are CORE-008; watchers and notifications CORE-009; sprints and story points FLEX-005.

## Decisions

### 1. Endpoints
All under the `auth` group of `/api/v1`. `{project}` resolves through AccessibleProjects, `{task}` through the new AccessibleTasks (§3); either way a hidden, trashed, foreign, random or malformed ID is the same 404.

| Endpoint | Who | Notes |
|---|---|---|
| `GET /projects/{project}/tasks` | anyone who can see the project | filters, sorts, cursor pages (§3) |
| `POST /projects/{project}/tasks` | `contribute` | `Idempotency-Key` required (§1.4); `201` |
| `GET /tasks/{task}` | anyone who can see the project | `ETag: "<version>"` |
| `PATCH /tasks/{task}` | `contribute` | `If-Match` |
| `DELETE /tasks/{task}` | `contribute` | `If-Match`; soft delete; `204` |
| `POST /tasks/{task}/restore` | `restoreTasks` | no `If-Match`; `200` |
| `GET /me/tasks` | authenticated | the caller's assigned tasks across teams (§3) |
| `GET /projects/{project}/labels` | anyone who can see the project | name order |
| `POST`, `PATCH`, `DELETE /projects/{project}/labels[/{label}]` | `manageLabels` | not versioned (§6) |
| `GET /tasks/{task}/checklist` | anyone who can see the project | checklist order |
| `POST`, `PATCH`, `DELETE /tasks/{task}/checklist[/{item}]` | `contribute` | not versioned (§7) |

- **Not in this task:** `/move` (CORE-007), `/activity` (CORE-008), `/watchers` (CORE-009), `/dependencies` (API_SPEC §19; no task owns it yet, listed as a follow-up).
- **Authorization order:** FormRequests authorise before they validate, so a caller who can see the project but lacks the right gets 403 whatever the body (AUTH-04). `If-Match` is read after validation (422 before 428, as for projects), and the actions check 423 before the version (423 wins over 412).

### 2. Schema (migration group 03, second part)
`2026_10_20_000100_create_tasks_table`, `…000110_create_labels_tables` and `…000120_create_task_checklist_items_table` follow DATABASE.md §5 and §20.

- **Keys (ADR-022 §5):**
  - `tasks (project_id, team_id) → projects(id, team_id)`, `labels (project_id, team_id) → projects`, both links of `task_labels` and `task_checklist_items.task_id` cascade;
  - `tasks (project_id, status_id) → project_statuses(project_id, id)`, `tasks (parent_task_id, project_id) → tasks(id, project_id)` and `tasks (team_id, assignee_user_id) → team_memberships(team_id, user_id)` are `DEFERRABLE INITIALLY IMMEDIATE` NO ACTION. They are checked at the end of every ordinary statement, and at COMMIT inside the purge's `SET CONSTRAINTS ALL DEFERRED`;
  - `tasks.created_by → users` is RESTRICT, like `projects.created_by`; `task_checklist_items.completed_by → users` sets NULL.

  `TrashPurgeTest`'s recursive guard covers all of them, and `TaskPurgeCommitTest` purges a team and a project full of assigned, labelled, nested tasks through real COMMITs.
- **DB-T03 / DB-T04 in the database:** the composite keys make a status, parent or label of another project impossible, and the assignee key makes an assignee from outside the team impossible, whatever the application does (`TaskSchemaTest`).
- **Technical corrections to DATABASE.md §5:**
  - **`due_date date`** replaces `due_at timestamptz`. API_SPEC §6.1 makes the due date a calendar date (`YYYY-MM-DD`) and has no due time; a timestamp would move the date across time zones (ARCHITECTURE §7 asks to keep due dates and due times apart).
  - **`deleted_at`** is added. API_SPEC §6.1 soft-deletes tasks and has a restore endpoint; the DATABASE.md table has no column for it.
  - **`version`** as §20 requires.
  - **`priority_rank`** (0–4) and **`due_sort`** (`COALESCE(due_date, 'infinity')`) are generated, stored columns, so the list sorts by priority and by due date with keyset pagination, and tasks without a due date sort last without comparing NULLs. They never appear in a response.
  - **Left out until a task needs them:** `start_at`, `completed_at`, `archived_at` (no API field in v1.0 so far) and `tasks.story_points` (§20, FLEX-005). Each is an expand-only migration for the task that uses it. Completion is the status category (`done`, ADR-021 §4); keeping a `completed_at` in step with category changes of statuses has no consumer yet.
  - **Checklist order:** `UNIQUE(task_id, position)` `DEFERRABLE INITIALLY IMMEDIATE` replaces the plain `(task_id, position)` index, as statuses do (ADR-021 §2).
- **CHECKs:** priority values, non-blank title, `task_number > 0`, `version > 0`, a task is never its own parent; labels: non-blank name and `#RRGGBB` colour; checklist items: non-blank text, `position >= 0`, and `completed_at`/`completed_by` set only while ticked.
- **Indexes:** `(project_id, status_id, position)`, `(project_id, assignee_user_id)`, `(team_id, project_id, updated_at DESC)`, `(project_id, due_date)` from DATABASE.md §5, plus `(project_id, created_at DESC, id DESC)` for the default list order, `(assignee_user_id, created_at DESC, id DESC)` for "my tasks", `(team_id, assignee_user_id)` for the release on membership changes (§5), and `(parent_task_id)` for subtasks.
- **Upgrade and rollback:** new tables only; rollback drops them in reverse order. `ProjectStatusSchemaTest`'s upgrade test now rolls back these migrations before CORE-004's, because `tasks` references `project_statuses`.

### 3. Retrieval and scoping
- **`AccessibleTasks`** (`App\Support\Tenancy`) is the only way to a task: `constrain()` applies `AccessibleProjects::constrain()` to the task's project, `findOrFail()` resolves `{task}`, and the task's project is then loaded through `AccessibleProjects::query()` (the viewer's own memberships eager-loaded, so policy checks cost no query). A task is never loaded first and checked afterwards (ADR-017 §4).
- **The trash is invisible** to anyone who may not restore from it: `{trashedTask}` resolves only for `restoreTasks`; everyone else, including members who could see the task before, gets the 404 of a missing task (as ADR-022 §4 does for projects).
- **`GET /projects/{project}/tasks`** (API_SPEC §6.1) supports `q`, `status_id[]`, `assignee_user_id`, `priority[]`, `label_id[]`, `due_after`, `due_before` and `sort`. Where §6.1 is silent:
  - `q` matches the title, case-insensitively, as a literal substring (`%` and `_` are not wildcards), or the task's number or key exactly (`104`, `WEB-104`; another project's key matches nothing). Descriptions are not searched; full-text search across projects belongs with search (CORE-009 and later).
  - several values of one key are alternatives (any of the statuses, priorities or labels); different keys must all hold;
  - `due_after` and `due_before` are inclusive and leave out tasks without a due date; a `due_before` earlier than `due_after` is 422 `after_or_equal`;
  - `sort`: `created_at` (default, newest first), `updated_at` (newest first), `due_date` (soonest first, no due date last), `priority` (urgent first, then newest), `position` (board order). Every order ends with `id`; directions are fixed;
  - `sprint_id` is `prohibited` until sprints exist (FLEX-005), so a client is never told a filter applied when it did not;
  - malformed values are 422 with stable codes; unknown query keys are ignored, as on every other list.
- **`GET /me/tasks`** (API_SPEC §2) lists the caller's assigned, live tasks in every project they can see right now (AccessibleTasks), across teams, before sorting and pagination. Tasks of archived projects stay listed (archived is readable); `sort` as above without `position`.
- **Representation:** `id`, `project_id`, `key`, `number`, `title`, `description`, `status_id`, `priority`, `assignee_user_id`, `due_date`, `label_ids` (sorted), `parent_task_id`, `created_by`, `version`, `created_at`, `updated_at`. `sprint_id`, `story_points` (FLEX-005) and `position` (CORE-007) are not part of it yet. Single-task responses carry `ETag: "<version>"`.

### 4. Writes, versions and the task lifecycle
- **Create (DATABASE §11):** one transaction (the `idempotent` middleware's): `ProjectWriteLock::project(…, 'contribute')`, then the references are checked on the locked state, then `TaskNumbers::allocate()`, then the insert. A rolled-back create returns its number with everything else.
  - **Status:** `status_id` must be a status of the project; without it (or with `null`) the task starts in the `is_default` status (ADR-021 §4).
  - **Position:** a new task, and a task whose status changes, goes to the end of its column (`floor(max(position)) + 1` under the project lock). Moving between anchors and rebalancing is CORE-007.
  - **Key:** `key` is never accepted (`unknown_field`); `{project_key}-{task_number}` is computed on read and cannot change, because the project key is frozen once a number has been issued (ADR-021 §5). `UpdateProject` already refuses a key change then; `TaskManagementTest` checks it end to end, including after the only task was deleted.
- **Validation (API_SPEC §6.1):** `title` trimmed, 1–200; `description` up to 50,000 characters (blank becomes `null`); `priority` `none|low|medium|high|urgent`; `due_date` a real `YYYY-MM-DD` date, not before year 1000 (PostgreSQL has no year 0, and a bound keeps the input finite); `label_ids` a list of at most 20 distinct UUIDs; `sprint_id` and `story_points` only `null` (`prohibited` otherwise) until FLEX-005, the way `template_id` waits for FLEX-004 (ADR-020 §3). Anything else is `unknown_field`.
- **References are answered like random IDs:** a status, label or parent of another project, or of a hidden one, and an ineligible assignee, all give 422 `exists`, exactly as an unknown ID does (DB-T04, §5).
- **Edit:** `PATCH` with `If-Match` (`OptimisticLock::update`, row lock `FOR NO KEY UPDATE` after the team and project locks). Absent fields stay; `null` clears `description`, `assignee_user_id`, `due_date`, `parent_task_id`; `label_ids` replaces the whole set. A real change, a label-set change included, increments `version`; a no-op keeps it. Missing `If-Match` is 428, stale is 412 with no write (TASK-03); two concurrent edits with the same version: one wins, the other is 412 (`TaskConcurrencyTest`).
- **Delete:** API_SPEC §6.1 gives `DELETE` to any "Project writer", so a contributor may delete any task of the project, including other people's. The spec is explicit, so this follows it rather than the more conservative "own tasks only"; the conservative part is that deletion is a soft delete that only the Owner or a project manager can undo. It needs `If-Match` (the task is versioned, §1.4, like project `DELETE` in ADR-022 §6) and increments `version`.
  - A task with live subtasks is a 409: deleting it would leave subtasks under a parent nobody can see. Delete or move them first.
  - Nothing is purged automatically. A trashed task stays restorable for as long as its project exists; the project purge removes it (open question below).
- **Restore:** "Project Manager/Owner" (`ProjectPolicy::restoreTasks`, the `manageMembers` rule: a Team Admin needs the `manager` role, a Team Viewer never qualifies). No `If-Match`, because no read path exposes a trashed task's version; it increments `version`. A task already restored is returned unchanged; a subtask whose parent is still trashed is a 409.
- **Subtasks (TASK-01, TASK-02):** `parent_task_id` must be a live task of the same project; the tree is at most three levels below the root; a parent inside the task's own subtree, or the task itself, is 422 `cycle`, a tree that would be too deep 422 `max_depth`. The check walks the subtree with trashed tasks included, so restoring a subtask can never break the depth rule, and it runs under the project lock, which every task writer of the project takes first, so no concurrent change can interleave.
- **Archived and trashed:** every task, label and checklist write answers 423 on an archived team or project (before 412) and 404 on a trashed one; reads of archived projects keep working.
- **Re-checks under the lock:** 404 when the actor can no longer see the project, 409 when they see it but lost the right (ADR-020 §7).

### 5. The single assignee
- **Who is eligible (API_SPEC §6.1, DATABASE §19):** "active Team Member/Owner/Admin with project access and write capability; Team Viewer cannot be assignee". That is exactly `ProjectPolicy::contribute`, plus an account that is not disabled. `TaskAssignments` holds the rule as one SQL expression used both for the check and for the release, and `TaskAccessMatrixTest` asserts, for every team role × visibility × project role, that it agrees with the policy.
- **No leak:** an ineligible user (a Viewer, a non-member of a private project, someone from another team, a disabled account) gets the same 422 `exists`, with the same message, as a random UUID.
- **Losing eligibility (the specs are silent; the conservative choice):** the assignment is cleared in the same transaction as the change that took the right away, and the task's `version` moves, because its representation changed:

  | Change | Released |
  |---|---|
  | Team removal or leaving (`RemoveMember`) | every task of the team assigned to the user (before the membership row goes; the deferrable key refuses the delete otherwise) |
  | Demotion to Team Viewer (`ChangeMemberRole`) | the user's tasks in the team |
  | Ownership transfer (`TransferOwnership`) | the former Owner's tasks in private projects they are not a member of |
  | Project membership removed (`RemoveProjectMember`) | the user's tasks in that project, if it is private |
  | Project made private (`UpdateProject`) | every assignee of that project without a membership |

  Trashed tasks and tasks of archived projects are included, so a task restored or unarchived later never brings back an assignee who lost access meanwhile. These writers already hold the team row lock (and the project lock where there is one), the first lock every task writer takes, so an assignment cannot slip in between. A real-process race shows that an assignment racing a team removal ends with no assignment and no database error, whichever commits first. The release is logged as `task.assignments_released` (`team_id`, `project_id` or `user_id` where scoped, `reason`, `tasks`).
- **Edits to those shared writers are additive:** one call each, placed after their own change.

### 6. Labels
- **CRUD (API_SPEC §5):** "Owner/Project Manager" for create, update and delete, the workflow rule (`ProjectPolicy::manageLabels` delegates to `manageMembers`); reading is for anyone who can see the project. Attaching and detaching labels is a task edit (`label_ids` on `POST`/`PATCH /tasks`, `contribute`); the spec has no separate attach endpoint.
- **Names and colours:** `name` trimmed, 1–50 characters (the status rule), unique per project ignoring case (409); `color` optional `#RRGGBB`, upper-cased.
- **Bounds (the spec sets none; ADR-021 §4 reasons the same way about statuses):** at most 100 labels per project (409) and 20 per task (422 `max`), so pickers, filters and the request body stay bounded.
- **Not versioned:** §1.4 lists Task, Project, Sprint, workflow configuration and Issue mappings, not labels, so label `PATCH`/`DELETE` take no `If-Match`; the team → project → label locks serialise them. A rename does not touch tasks (they show label IDs only).
- **Delete:** removes the label and, by cascade, its links ("Remove label and associations"); the tasks that carried it, trashed ones too, get a new `version` first, because their `label_ids` change.

### 7. Checklists
- **Endpoints (API_SPEC §6.2):** list, create, `PATCH` (`text`, `checked`, `position`), delete; "Project writer" for writes. The API fields are `text` and `checked`; the columns stay `content` and `is_done` (DATABASE §5).
- **Bounds:** `text` trimmed, 1–300; at most 100 items per task (the 101st is a 409, like the status cap).
- **Toggle:** `checked: true` records `completed_by` and `completed_at`, `false` clears both; re-sending the current state changes nothing, so a toggle retried, or sent by two people at once, is harmless. `checked` is a strict boolean (`1` or `"yes"` give `boolean`).
- **Reorder:** `position` is the 0-based index the item moves to; the others close up. Ranks stay dense (`0..n-1`), rewritten in one statement under `UNIQUE(task_id, position)`. An index past the last item is 422 `max`.
- **Not versioned (a reading of API_SPEC §18):** §18's `tasks.version` describes the endpoint family; §1.4 versions the Task, and the checklist is not part of the task's representation. Checklist edits are serialised by the team → project → task → items locks and do not change the task's `version`: otherwise ticking an item would make every concurrent task edit fail with 412 (ADR-020 §7 reasons the same way about memberships).

### 8. Status deletion with tasks (WF-01, ADR-021 §4 step 4)
`DeleteStatus` now counts the status's tasks, trashed ones included (they keep their status until restored, and the database key still references it):
- with tasks and no `replacement_status_id`: 409, no change;
- with a replacement: every task moves to the end of the replacement's column in the same transaction, keeping its relative order, with a new `version`.

`workflow.status_deleted` gains a `tasks_moved` count.

### 9. Concurrency
- Lock order stays team → project → task → children for every writer (ADR-018 §4, ADR-020 §7); label and checklist writers take the same first two locks. Task rows are locked `FOR NO KEY UPDATE`, so inserting a label link or checklist item (KEY SHARE for its key) is not blocked.
- **Real-process races** (`TaskConcurrencyTest`, advisory-lock barrier as in DB-T06):
  - four simultaneous creates get numbers 1–4 and four distinct positions (DB-T05). The allocator's `UPDATE … RETURNING` row lock alone already serialises creates, so this race does not detect a missing `ProjectWriteLock`; the 423 and re-check tests do (checked by removing the lock);
  - two edits with the same `If-Match`: exactly one wins;
  - an assignment racing the assignee's team removal: the removal clears it, or the assignment is 422; never a 500, never a task assigned to a non-member.
- **Idempotency (IDEM-01):** `POST /projects/{project}/tasks` uses the `idempotent` middleware. Same key and body replay the `201` (one row, one task number); a different body is 409 `idempotency_key_reused`; a missing or malformed key is 400. The stored record now carries the project's team (`EnforceIdempotency` reads `{project}` as well as `{team}`), so it is removed with the team.

### 10. Audit and jobs
- **Structured `info` events, IDs only** (never titles, descriptions, label names or checklist text; ADR-020 §9): `task.created`, `task.updated` (`fields`: the names of the changed fields), `task.status_changed` (`from`, `to`), `task.assignee_changed` (`from`, `to`), `task.trashed`, `task.restored_from_trash`, `task.assignments_released`, `label.created`, `label.updated`, `label.deleted` (`tasks`), `task.checklist_item_created`, `task.checklist_item_updated` (`fields`), `task.checklist_item_deleted`. Each carries `team_id`, `project_id`, `actor_id` and the resource IDs. CORE-008 replaces them with the activity model.
- **Jobs:** CORE-005 dispatches none. ADR-022's rule stands for the first one (CORE-008/009 notifications, GH-*): carry IDs only and re-resolve the project at run time, so a trashed project gets no queued writes (DEL-01).

## Consequences

- **CORE-006/007** build task detail, board and list on these endpoints. CORE-007 adds `/move` and decides whether the representation exposes `position`; it should keep "status change via PATCH appends to the column".
- **CORE-008** adds comments, attachments and `task_activities`; its tables cascade from `tasks` (the purge rule) and its activity entries replace the log events above. Its mention validation can reuse `TaskAssignments::eligible()`'s rule for "eligible users".
- **CORE-009** notifications for assignment use `task.assignee_changed`; they must re-check access at read time, and an assignment released by §5 should not notify the removed user about a project they can no longer see.
- **FLEX-005** adds `tasks.story_points`, sprint membership, and lifts the `prohibited` on `sprint_id` and `story_points` (request and list filter).
- **FLEX-003** saved views store the allowlisted filters of §3.
- **Dependencies** (API_SPEC §19, FR-TASK-007) need an owner task; they reuse the subtree walk and the project lock.
- **Open questions (implemented conservatively):**
  - How long do trashed tasks stay restorable? Today until the project is purged; a 30-day task trash (DECIDED-004 speaks of teams and projects) would need a purge step for tasks.
  - Should an assignee who loses access be unassigned (implemented) or kept and hidden?
  - May a contributor delete other people's tasks? Implemented as API_SPEC §6.1 says ("Project writer"), with restore reserved to managers.
  - Should deleting a task with subtasks cascade to them instead of answering 409?
  - Are the caps (100 labels per project, 20 per task) the right numbers?
  - Should `q` also search descriptions, and should the list offer an "unassigned" filter? Neither is in §6.1.
