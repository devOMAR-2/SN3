# ADR-020 — Projects, visibility and explicit project membership

- **Status:** Accepted
- **Date:** 2026-10-16
- **Task:** CORE-003 (#12)
- **Decision inputs:** BRD DEC-014 to DEC-017, FR-PROJ-001 to -007, §7 role matrix and footnote, authorization test invariant; ARCHITECTURE.md §5.2, §5.3, DECIDED-003, DECIDED-011; DATABASE.md §4, §10, §11, §16, §19, §20; API_SPEC.md §0.4, §1, §4, §12, §14.2, §17, §18; ADR-017, ADR-018

## Context

CORE-003 adds projects to the team model from CORE-001: a project is team-visible (the default) or private, and private access comes only from an explicit project membership, except for the Team Owner. DECIDED-003 makes non-disclosure strict: a Team Admin who is not a member must not learn that a private project exists, not even through a count or a 403. Later tasks (tasks, search, attachments, notifications, exports, GitHub) inherit whatever rule this task sets, so the rule must sit in one reusable place that they cannot bypass.

## Decisions

### 1. Endpoints
API_SPEC §4 is authoritative for the paths (§18). All are under the `auth` group of `/api/v1`.

| Endpoint | Who |
|---|---|
| `GET /teams/{team}/projects` | Any team member. Only the projects they can see, cursor-paginated, newest first. |
| `POST /teams/{team}/projects` | Owner or Admin. The creator becomes the project's `manager`. |
| `GET /projects/{project}` | Anyone who can see the project |
| `PATCH /projects/{project}` | Project managers (see §5). `name`, `key`, `description`, `visibility`; requires `If-Match`. |
| `GET /projects/{project}/members` | Anyone who can see the project |
| `PUT /projects/{project}/members/{user}` | Project managers. Adds a team member (201) or changes their role (200). |
| `DELETE /projects/{project}/members/{user}` | Project managers. 204. |

`/projects/{project}` is not nested under a team, so ADR-017's "resolve the team first" pattern takes this form: the `{project}` binding runs one query that joins the project to the caller's team membership and the visibility rule (§4). It never loads a project first and checks access afterwards.

Archive, restore, trash and purge (`/archive`, `/restore`, `DELETE /projects/{project}`, `/restore-deleted`) are CORE-010. Statuses, the task key freeze and `/settings` are CORE-004. `GET /projects/{project}/activity` needs the activity model (CORE-008).

### 2. Schema (migration group 02)
`2026_10_18_000200_create_projects_tables` follows DATABASE.md §4 and §20. That covers every column, including `sprints_enabled`, `next_task_number`, `version`, `archived_at` and `deleted_at`, plus `UNIQUE(team_id, project_key)`, `UNIQUE(id, team_id)`, the `(team_id, visibility, archived_at)` index, and, on memberships, `UNIQUE(project_id, user_id)`, `(user_id, project_id)` and `(team_id, project_id)`. The decisions the spec left open:

- **CHECK constraints:**
  - `visibility IN ('team','private')` and `project_role IN ('manager','contributor')`;
  - the key format `^[A-Z][A-Z0-9]{1,9}$` (API_SPEC §4);
  - a non-blank name;
  - `next_task_number > 0` and `version > 0`.
- **Keys and the trash:** `UNIQUE(team_id, project_key)` also covers trashed projects. A key therefore stays reserved for the 30-day recovery window (DECIDED-011), as team slugs do (ADR-018 §2). Reusing it is a 409.
- **Foreign-key actions:**
  - `projects.team_id` and `(project_id, team_id) → projects(id, team_id)` cascade. Only the trash purge deletes teams and projects.
  - `created_by` and `added_by` are `RESTRICT`, like `teams.owner_user_id`.
  - `(team_id, user_id) → team_memberships(team_id, user_id)` has **no** ON DELETE action. A team membership cannot be deleted while project memberships still reference it, so project access can never outlive team membership. `RemoveMember` deletes them explicitly (§6).
  - That key is also `DEFERRABLE INITIALLY IMMEDIATE`. PostgreSQL checks it while cascading a team delete to `team_memberships`, before the cascade to `projects` has removed the project memberships. A team purge is therefore refused unless the transaction runs `SET CONSTRAINTS project_memberships_team_id_user_id_foreign DEFERRED` (or deletes project memberships first). **CORE-010's purge must do this.** Every other path is checked immediately.
- **The Owner's access:** no synthetic membership rows (DATABASE §4); the Owner is recognised in the authorization layer. A real row for the Owner can exist: the Owner gets one as a project's creator, or a manager can add the Owner like anyone else.
- **Columns the spec doesn't list:** `icon`/`color` (BRD FR-PROJ-001) are not in DATABASE.md §4, so they are not added here. ADR-017 §2 made the same choice for team avatars.

### 3. Validation
- **`key`:** trimmed and upper-cased first (DATABASE §4: "normalize uppercase at application boundary"), then checked against `[A-Z][A-Z0-9]{1,9}`. `web` becomes `WEB`; `موقع`, `WEB-1` and `1WEB` get `regex`.
- **`name`:** trimmed, 1–180 characters (the column width; API_SPEC gives no limit).
- **`description`:** trimmed, at most 5000 characters, `null` or empty clears it. API_SPEC gives no limit, and leaving the input unbounded is not an option, so 5000 is the conservative choice here.
- **`visibility`:** `team` or `private`, default `team` (DEC-017).
- **`template_id`:** accepted only as `null` (`prohibited` otherwise) until team templates exist (FLEX-004).
- **Other fields:** `sprints_enabled` and other settings go through `PATCH /projects/{project}/settings` (API_SPEC §5), not here. Any other body field is `unknown_field` (§1.2).
- **Order of checks:** role-restricted requests authorise before they validate, so a caller without the right gets 403 whatever the body (AUTH-04).

### 4. Visibility: `AccessibleProjects`
`App\Support\Tenancy\AccessibleProjects` is the single authorization query that DATABASE.md §10.2 asks for. A user sees a project only if they are a member of its team (the team not in the trash), the project is not in the trash, **and** one of these holds:
- the project is team-visible;
- the user has a `project_memberships` row for it;
- the user's team role is `owner`.

- **`query()`, `inTeam()`, `findOrFail()`:** these serve the list, the binding and the detail route. They eager-load the viewer's own team and project memberships and mark each project as loaded for that viewer (`Project::$membershipsLoadedFor`), so policy checks cost no extra queries and a list takes a constant number of queries.
- **`constrain(Builder $projects, User $user)`:** applies the same predicate to any project query. Later resources must reach projects through it or through the `{project}` binding, for example `Task::query()->whereHas('project', fn ($p) => $accessibleProjects->constrain($p, $user))`. A `Project::find()` followed by a policy check is not allowed.
- **Hidden means 404:** a hidden project gives the same 404 `not_found` envelope as a random or malformed ID. The body and logs never include its name, key or ID (API_SPEC §14.2). That holds for every child endpoint, members included.
- **Lists:** the list applies the predicate before cursor pagination, so `has_more`, `next_cursor` and page sizes only ever count visible projects. No endpoint returns a project count.
- **Visibility changes (FR-PROJ-007):** a change takes effect on the next request, because nothing caches project access. Every read evaluates the predicate. Search indexes, notifications, files and GitHub-linked data must keep that property and re-evaluate at read time. A cache that any of them adds must be invalidated by visibility and membership changes.

### 5. Effective permissions (`ProjectPolicy`)
The policy computes the intersection of team role and project role, with the Owner exception:

| Team role | Team-visible project | Private project |
|---|---|---|
| Owner | view, manage, contribute | view, manage, contribute (no row needed) |
| Admin | view, settings, contribute; members only as `manager` | nothing, unless a member. As `manager`: manage and contribute. As `contributor`: contribute. |
| Member | view, contribute; manage as `manager` | nothing, unless a member. As `manager`: manage and contribute. As `contributor`: contribute. |
| Viewer | view | view, only as a member |

- **Abilities:** "manage" is `update` (PATCH) and `manageMembers` (PUT/DELETE members). "Contribute" (`contribute`) is the write ability that task and comment endpoints use from CORE-005 on. The Projects tests cover the matrix for all 26 combinations.
- **Spec conflict resolved:**
  - API_SPEC §12 says a Team Admin manages project settings and members "only if Project Manager"; §4's PATCH and member rows say "Owner or Project Manager".
  - §17 ("Final effective permission rules (normative)") and DATABASE.md §19 say "Team-visible project: Owner/Admin can manage project settings". They say nothing about membership.
  - API_SPEC §0.2 ranks the final registers above earlier contract text, so the Admin edits a **team-visible** project's **settings** (PATCH) without a membership row. **Membership** (PUT/DELETE members) follows §4: Owner or Project Manager only.
  - For **private** projects the stricter rule applies: an Admin manages only as an explicit `manager`.
  - API_SPEC §12 carries a note that records this reading.
- **Why membership is excluded:** if an Admin could add themselves to every team-visible project, they would keep access to any of them that is later made private, which defeats DECIDED-003 ("Admin cannot add themselves" to private projects).
- **Consequence:** an Admin may make a team-visible project private, and then loses access to it unless they are a member.
- **Team Viewer:** read-only regardless of project role (DECIDED-003). PATCH and member changes give 403 even for a Viewer with the `manager` role.
- **403 vs 404:** a 403 only ever concerns a project the caller can already see. Everyone else gets the 404 from the binding first.

### 6. Team changes reach projects at once
- **Removal:** removing or leaving a team (`RemoveMember`) deletes the person's project memberships in that team in the same transaction, trashed projects included (ADR-018 §6). The database refuses the removal if any are left (§2). The audit event `team.member_removed` now carries `project_memberships_revoked`. Re-joining the team does not restore private access.
- **Role changes:** take effect on the next request. Demoting a project `manager` to Team Viewer makes them read-only straight away.
- **Ownership transfer:** implicit private access moves with ownership. The previous Owner, now an Admin (ADR-018 §4), keeps only their explicit memberships.

### 7. Locking, concurrency, idempotency
- **Lock order:**
  - Every project writer (`CreateProject`, `UpdateProject`, `SetProjectMember`, `RemoveProjectMember`) locks the team row first (`Team::WRITE_LOCK`), as the team writers do (ADR-018 §4). It then locks the project row (`Project::WRITE_LOCK`, `FOR NO KEY UPDATE`, also for the version-checked PATCH, so future child inserts such as tasks are not blocked by the foreign-key check), then memberships.
  - That gives one lock order (team → project → memberships) shared with `RemoveMember`, `ChangeMemberRole` and `TransferOwnership`, so writers cannot deadlock or interleave.
- **Re-checks on the locked rows:** `ProjectWriteLock` checks these after locking:
  - an archived team or archived project gives 423 `resource_locked` (reads stay available);
  - the actor must still see the project; if it became invisible while they waited (made private, membership removed), the answer is 404, exactly like a trashed or missing project (API_SPEC §0.4);
  - the actor must still hold the ability, because their team or project role may have changed while they waited; if not, 409 `conflict` ("reload and retry"), as ADR-018 does for a former Owner;
  - for PATCH these checks run before the `If-Match` comparison, so an archived project answers 423 even with a stale version;
  - for a new project member, the target's team membership must still exist (read after the team lock), otherwise 404.
- **Real-process races:** `ProjectConcurrencyTest` races real processes. A team removal racing a project add never leaves an orphan or produces a 500. Two creates with the same key produce one project and one 409, because the unique index is the authority and its violation is mapped to 409.
- **`If-Match`:** `PATCH /projects/{project}` requires it (API_SPEC §1.4): missing → 428, stale → 412, no change applied. A successful change increments `version`; a no-op keeps it.
- **Membership endpoints are not versioned (a technical correction of API_SPEC §18).** §1.4 lists Project, not memberships, among the versioned resources. The §18 row for `/projects*` and `/projects/*/members*` names the version column of that endpoint family. Bumping `projects.version` on every member change would make unrelated project edits fail with 412. `PUT` sets a role, which is idempotent by nature, and the row locks serialise concurrent changes.
- **No `Idempotency-Key` on project creation.** §1.4 requires the key for task creation, invitations, sprint start/close, GitHub sync and attachments, not for projects. A retried create hits the unique key and gets 409, so no duplicate can arise.

### 8. Membership details
- **`PUT` body:** `{ "role": "manager" | "contributor" }`. There is no project viewer role, so `viewer` is `in`.
- **Eligible users:** only members of the project's own team; anyone else is a 404, the same answer as for an unknown or malformed ID. This keeps the endpoint from becoming a probe for accounts outside the team.
- **Who may change memberships:** a manager may change or remove anyone, themselves included. The Owner keeps implicit manager authority, so a project is never left without someone who can manage it. A contributor cannot remove themselves: API_SPEC §4 gives membership removal to "Owner or Project Manager" only.
- **Member list:** shows name, role, `added_by` and `added_at`. Email addresses appear only to the Team Owner and Admins, like the team member list (ADR-018 §5), so a project never shows more than the team already does.

### 9. Audit
Until the activity model arrives (CORE-008), these structured `info` events are logged, each with `team_id`, `project_id` and `actor_id`:
- `project.created` (with `visibility`);
- `project.visibility_changed` (`from`, `to`) (NFR-SEC-005);
- `project.member_added` (`user_id`, `role`), `project.member_role_changed` (`user_id`, `from`, `to`), `project.member_removed` (`user_id`, `role`).

Events carry IDs only, never project names or descriptions, because those may be private.

### 10. Project keys are approved team-level metadata (DEC-018)
`UNIQUE(team_id, project_key)` is required: keys appear in task keys and in `/t/{slug}/p/{key}`. A create or key change by someone who cannot see a private (or trashed) project therefore gets 409 for its key, which confirms that a project with that key exists. No code change can hide this while keys are unique per team. The BRD authorization invariant allows only team-level metadata that is explicitly approved, so the product owner approved project keys as such on 2026-10-16 (BRD DEC-018), with these mitigations:
- **Generic 409:** the body is the standard `conflict` envelope with no project name, ID, visibility or fields. A test pins this.
- **Rate limit:** `POST /teams/{team}/projects` and `PATCH /projects/{project}` share the `project-writes` limiter, 20 requests per minute per user (`SN3_RATE_LIMIT_PROJECT_WRITES_PER_MINUTE`; `0` disables it on self-hosted instances), answering `429 rate_limited` with `Retry-After`.
- **Audit:** every key conflict logs `project.key_conflict` (warning) with `team_id` and `actor_id` only, so repeated conflicts by one user are visible to operators. The holding project is never logged.

Only the key is disclosed. A project's name, description, members and content stay hidden as in §4.

## Consequences

- CORE-004 and later resources hang off `{project}` or `AccessibleProjects::constrain()`, and use `ProjectPolicy::contribute` for writes. Every descendant table carries `(project_id, team_id)` and references `projects(id, team_id)`.
- CORE-004 must make `key` immutable after the first task (PROJ-01).
- CORE-010 implements archive/trash. Its purge must defer `project_memberships_team_id_user_id_foreign`. Restoring a trashed project needs a resolver variant that includes trashed projects, because `AccessibleProjects` hides them.
- CORE-009 notifications, and later search, exports and GitHub features, must re-check project visibility at read and send time (FR-PROJ-007).
- CORE-006 builds the project UI on these endpoints.
