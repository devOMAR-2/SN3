# SN3 (سنع) — API & HTTP Contract Specification

> **Document:** `API_SPEC.md` · **Version:** 1.0.0 · **Status:** FINAL — implementation baseline  
> **Product:** SN3 (سنع), free and open-source small-team project management · **Date:** 2026-10-12  
> **Audience:** AI coding agents, Laravel/Vue developers, QA, security reviewers  
> **Dependencies:** `BRD.md`, `ARCHITECTURE.md`, `DATABASE.md`, `DESIGN_SYSTEM.md`.

## 0. Binding implementation contract

1. Implement the API described here without asking for further product decisions. No endpoints or behavior in this document are tentative.
2. In case of conflict: explicit user-confirmed product choices > the final binding decision registers in `ARCHITECTURE.md` / `DATABASE.md` > this HTTP contract > earlier provisional language. If the final architecture and database disagree, choose the stricter tenant/privacy/security behavior and log a technical correction in the repository; do not invent new product behavior.
3. The browser application uses **Laravel session authentication, CSRF, and Inertia**. `/api/v1` is a JSON HTTP interface for asynchronous browser operations and future external clients. No public personal-access-token issuance is required for v1.0. External API tokens are **not** enabled by default. `/api/v1` endpoints accept authenticated same-origin sessions. Do not place bearer tokens in browser storage.
4. Every response and background operation enforces Team and Project authorization server-side; never trust IDs supplied by clients. Every private-project denial is indistinguishable from nonexistence (404), except the Owner's authorized view.
5. All request/response fields use `snake_case`; public identifiers are UUIDv7, except readable task keys (e.g. `WEB-104`) and GitHub IDs, which are decimal strings in JSON to avoid JavaScript integer precision loss.
6. Timestamps use RFC 3339 UTC (`2026-10-12T09:00:00Z`). Dates without times use `YYYY-MM-DD`. Strings are Unicode. User-facing text is translated through `ar`/`en` resources; API error codes are stable English identifiers.
7. All mutation endpoints are protected by CSRF for session clients. Strict validation, authorization, and rate limiting apply on the server; the UI cannot bypass them.
8. `P0` is the initial usable core. `P1` is a committed subsequent release, not an optional paid tier. GitHub App repository linking and PR/branch/commit associations are required by public v1.0; two-way Issues sync by v1.1.

## 1. API transport and conventions

### 1.1 Base URLs and content types

- JSON base: `/api/v1` over HTTPS in production; local Docker development may use HTTP on localhost.
- Browser page routes are Inertia and are **not** JSON contracts: `/login`, `/register`, `/teams`, `/t/{team_slug}/projects`, `/t/{team_slug}/p/{project_key}`, `/my-work`, `/inbox`, `/settings`.
- Webhooks: `/webhooks/github` (separate from `/api/v1`). GitHub callback: `/integrations/github/callback` (server-side verified flow).
- `Accept: application/json` and `Content-Type: application/json` for JSON bodies. Multipart uploads use `multipart/form-data`.
- `Accept-Language: ar|en`; default language is user's saved preference, falling back to `ar`. Language does not affect IDs or machine-readable codes.
- All list endpoints return cursor pagination with default `limit=25`, maximum `limit=100`, stable tie-breaking by `id`.
- Lists must enforce authorization *before* filtering, counts, search, pagination, and serialization.

### 1.2 Standard envelopes

Success, single object:

```json
{"data":{"id":"018f8c70-0000-7000-8000-000000000001","name":"Website"},"meta":{"request_id":"req_01JEXAMPLE"}}
```

Success, collection:

```json
{"data":[],"meta":{"next_cursor":null,"has_more":false,"limit":25,"request_id":"req_01JEXAMPLE"}}
```

Failure:

```json
{"error":{"code":"validation_failed","message":"The provided data is invalid.","fields":{"name":["required"]},"request_id":"req_01JEXAMPLE"}}
```

- No success envelope for `204 No Content`; no body.
- `fields` is omitted unless validation failed; keys are field paths such as `items.0.title`.
- `request_id` is generated server-side and included in structured logs; never include secrets or stack traces in responses.
- `POST` create returns `201` and the created resource; `GET` returns `200`; mutation returns `200` with updated representation; deletion returns `204` unless a long-running operation is queued (`202`).
- `PATCH` means partial update; absent field means unchanged; explicit `null` clears a nullable field. Unknown fields are rejected with `validation_failed`.

### 1.3 Error codes and status mapping

| HTTP | `error.code` | Contract |
|---|---|---|
| 400 | `bad_request` | Invalid JSON, cursor or malformed input |
| 401 | `unauthenticated` | No valid authenticated session |
| 403 | `forbidden` | Authorized to know resource exists, but action not permitted |
| 404 | `not_found` | Missing or hidden team/project/task/resource |
| 409 | `conflict` | Duplicate, state transition, membership, GitHub mapping or unique-key conflict |
| 410 | `expired` | Expired invitation or removed external integration state |
| 413 | `payload_too_large` | Attachment/body exceeds configured limit |
| 419 | `csrf_invalid` | Missing/expired CSRF protection |
| 422 | `validation_failed` | Field validation errors |
| 423 | `resource_locked` | Archived/read-only or temporarily locked resource |
| 429 | `rate_limited` | Retry after `Retry-After` seconds |
| 503 | `service_unavailable` | Optional provider/queue dependency temporarily unavailable |

Do not return a 403 that confirms the existence of an inaccessible private project. Use 404 for its children and for search matches as well.

### 1.4 Concurrency, idempotency and filtering

- Mutable Task, Project, Sprint, workflow configuration, and Issue mapping responses include integer `version` starting at `1`.
- Mutating these resources requires `If-Match: "<version>"`. Missing header: `428 precondition_required`; mismatch: `412 version_mismatch`, with no mutation. Server increments version atomically in a transaction.
- For `POST` task creation, invite creation, sprint start/close, GitHub sync action, and attachment initiation, require `Idempotency-Key` (UUID string), scoped to authenticated actor + endpoint + tenant, retained 24 hours; replay returns the original result, same payload; different payload returns 409 `idempotency_key_reused`.
- Filter syntax: explicit documented query keys only, no arbitrary SQL-like filter expressions. Multiple values use repeated keys, e.g. `status_id[]=...`.
- Default sort is `created_at desc, id desc`; user-selectable sort fields are allowlisted per endpoint. Cursor is opaque, signed, and expires after 24 hours.
- Rate limits: Cloud authenticated users 120 requests/minute with burst protection, separate stricter limits for auth, invitations, uploads, and GitHub sync; Self-Hosted limits are configurable. Return `Retry-After` on 429.

## 2. Identity, sessions, preferences (P0)

| Method | Path | Operation | Permission |
|---|---|---|---|
| POST | `/auth/register` | Create user, send verification email | Guest |
| POST | `/auth/login` | Authenticate; regenerate session | Guest |
| POST | `/auth/logout` | Revoke session, invalidate CSRF | Authenticated |
| POST | `/auth/password/forgot` | Request password reset | Guest |
| POST | `/auth/password/reset` | Redeem reset token | Guest with valid token |
| POST | `/auth/email/resend` | Resend verification | Authenticated |
| GET | `/auth/email/verify/{id}/{signature}` | Verify email, signed temporary URL | Valid signed link |
| GET | `/me` | Current user, verified flag, preferences | Authenticated |
| PATCH | `/me` | Display name, language, timezone, theme | Authenticated |
| GET | `/me/teams` | Teams visible to current user | Authenticated |
| GET | `/me/tasks` | Authorized assigned tasks across teams | Authenticated |

`GET /me/tasks` (CORE-005, ADR-023 §3): the caller's assigned, live tasks in every project they can see at the time of the request, across teams, filtered by the AccessibleProjects rule before sorting and pagination. `sort` is `created_at` (default), `updated_at`, `due_date` or `priority`, as for the project task list (§6.1).

Registration: `name` 1–100 chars, `email` valid unique normalized address, `password` min 12 chars, maximum 128 chars, breached/common password validation if available without third-party mandatory service. Auth endpoints are throttled, use generic responses for password reset/email existence, and never return session tokens in JSON. Verified email required to create/join a team and redeem invitations. Preferences: `locale` (`ar`/`en`), `theme` (`system`/`light`/`dark`), `timezone` valid IANA name. Default `locale=ar`, `theme=system`, `timezone=UTC` until user selects.

## 3. Teams and memberships (P0)

| Method | Path | Operation | Permission |
|---|---|---|---|
| GET | `/teams` | List user's teams | Member of listed teams |
| POST | `/teams` | Create team; creator becomes Owner | Verified user |
| GET | `/teams/{team_id}` | Team summary | Team member |
| PATCH | `/teams/{team_id}` | Name, slug, description, avatar | Owner/Admin |
| GET | `/teams/{team_id}/members` | List memberships | Team member |
| PATCH | `/teams/{team_id}/members/{user_id}` | Change role | Owner, subject to role rules |
| DELETE | `/teams/{team_id}/members/{user_id}` | Remove member / self-leave | Owner or self, with invariants |
| POST | `/teams/{team_id}/ownership-transfer` | Transfer Owner role | Current Owner |
| POST | `/teams/{team_id}/archive` | Archive team | Owner |
| POST | `/teams/{team_id}/restore` | Restore archived team | Owner |
| DELETE | `/teams/{team_id}` | Soft-delete into 30-day trash | Owner |
| POST | `/teams/{team_id}/restore-deleted` | Recover during 30 days | Owner |
| POST | `/teams/{team_id}/exports` | Initiate asynchronous export; return 202 with export ID | Owner |
| GET | `/teams/{team_id}/exports/{export_id}` | Read export status and signed download URL when ready | Owner |

Create team body: `name` (1–100), optional `slug` (`[a-z0-9-]{3,40}`, unique), `description` (max 1000). Slug is presentation-only; UUID is authoritative. Team has exactly one Owner; Owner cannot self-leave without transfer; no privilege escalation; Member/Viewer cannot change memberships. Admin may invite Member/Viewer but cannot assign Admin/Owner. Owner alone may assign Admin or transfer Owner. `ownership-transfer` body `{ "new_owner_user_id": "uuid" }`, requires existing active team member and transactional swap.

**Implementation contract (CORE-010, ADR-022): archive and trash.** These points settle details §3 and §4 leave open, for teams and projects alike:

- **Bodies:** the lifecycle endpoints take no body; any field is `unknown_field`.
- **Who:** team archive, restore, delete and restore-deleted are Owner only. Project archive, restore, delete and restore-deleted are the Team Owner or a Project Manager (a Team Admin or Member with the `manager` role). A Team Viewer is read-only even as a manager. The Team Admin's settings exception for team-visible projects (§12 note) does not extend to the lifecycle.
- **Answers:** archive and restore (unarchive) return `200` with the resource; `DELETE` returns `204`; restore-deleted returns `200` with the resource. Archive on an archived resource, and restore on an active one, change nothing and return `200`.
- **Archive:**
  - Archived is read-only, and every write answers `423 resource_locked`. Reads keep working.
  - An archived team makes all its projects read-only without changing their own `archived_at`, so unarchiving the team returns each project to the state it had.
  - Deleting an archived team or project is `423`; unarchive it first. Any lifecycle change to a project of an archived team is `423`.
- **Trash:**
  - `DELETE` only sets `deleted_at`. The team (with all its projects) or the project disappears from every list, detail and child route, and every write answers `404`. Nothing is deleted until the purge.
  - Slugs and project keys stay reserved while in the trash (`409` on reuse).
- **Restore-deleted:**
  - Only within 30 days of the deletion (`deleted_at > now − 30 days`), and only for a caller who may recover the resource. A trashed private project stays hidden from non-members.
  - Everyone else gets `404`, never `403`, and so does an expired, purged, live, random or malformed ID.
  - A project whose team is in the trash is `404` (recover the team first). A project whose team is archived is `423`.
  - Recovering a team brings back its projects except those trashed on their own, which keep their own 30-day clock.
- **Versions:** project archive, restore and `DELETE` require `If-Match` (`428`/`412`, 423 checked first) and increment `version` (§1.4). Restore-deleted needs no `If-Match`, because no read path exposes a trashed project's version, but it increments `version`. Teams are not versioned.
- **Purge:** `trash:purge` runs daily and permanently deletes teams and projects trashed 30 or more days ago, with everything that belongs to them. It never deletes anything on GitHub (§13).
- **Export before deletion (DECIDED-011, CORE-012):** clients offer `POST /teams/{team_id}/exports` before `DELETE /teams/{team_id}`. A team in the trash cannot be exported (`404`), and its pending exports are refused when their job runs.

**Implementation contract (CORE-012, ADR-024): team exports.** These points settle details §3, §13 and §18 leave open:

- **Who:** the Team Owner only (`403` for Admin, Member and Viewer whatever the body, checked before the export ID is looked up; `404` for non-members). An export is read and downloaded only by the Owner who requested it: after an ownership transfer the former Owner gets `403` and the new Owner `404`.
- **`POST /teams/{team_id}/exports`:** no body (any field is `unknown_field`), `Idempotency-Key` required (`400` without it). Answers `202` with the export and `Location: /api/v1/teams/{team_id}/exports/{export_id}`. While another export of the team is `pending` or `processing`: `409 conflict`. Rate-limited per user (10 per hour by default; `429` with `Retry-After`). An archived team can be exported.
- **Export representation:** `{ "id", "team_id", "requested_by", "status", "format": "sn3.team-export", "format_version": 1, "size_bytes", "sha256", "download_url", "created_at", "completed_at", "expires_at" }`. `status` is `pending`, `processing`, `ready`, `failed` or `expired`. `size_bytes`, `sha256` and `download_url` are `null` unless the export is `ready`.
- **Download (technical settlement: the route behind the signed URL):** `GET /teams/{team_id}/exports/{export_id}/download?expires=…&signature=…` returns the zip (`application/zip`, attachment, `Cache-Control: no-store`). The signature expires with the export, 24 hours after it completed, and the request also needs the requesting Owner's session. Invalid or expired signature: `403`; export not ready: `404`; archive already removed: `410 expired`.
- **Expiry:** the export and its archive are deleted within an hour after `expires_at` (24 hours after completion or failure; a request that is never processed expires 24 hours after it was made). Afterwards the export ID is `404`.
- **Format:** a zip with `manifest.json` (format, version, IDs, generation time, and per section its file, record count and SHA-256) and one JSON array per section in `data/`: `team`, `members`, `projects`, `project_members`, `statuses`, and since CORE-005 `labels`, `tasks`, `task_labels`, `task_checklist_items` (all still version 1, an additive change), more as later tasks add data. New sections and fields keep the version; anything else bumps it (ADR-024 §2). Secrets, password hashes, tokens and sessions are never included; projects are those the Owner can see, private ones included, trashed ones excluded; likewise, tasks in the task trash (with their label links and checklist items) are excluded (ADR-023 §11).

### 3.1 Invitations

| Method | Path | Operation | Permission |
|---|---|---|---|
| POST | `/teams/{team_id}/invitations` | Create email-specific or share-link invitation | Owner/Admin |
| GET | `/teams/{team_id}/invitations` | List invitation metadata (no raw tokens) | Owner/Admin |
| DELETE | `/teams/{team_id}/invitations/{invitation_id}` | Revoke invitation | Owner/Admin |
| GET | `/invitations/{token}/preview` | Minimal team name + role; no private data | Holder of valid token |
| POST | `/invitations/{token}/accept` | Join team | Verified authenticated user |

`POST` body: `{ "type":"email", "email":"a@example.com", "role":"member" }` or `{ "type":"link", "role":"member" }`. Email invitation: target email must match verified account email, expires after 7 days, one redemption. Link: expires after 7 days, max 20 redemptions, revocable; requires verified account. Admin can invite only Member/Viewer; Owner may send email-specific Admin invites; no invitation can grant Owner. Tokens are cryptographically random, shown only once on creation, stored hashed, excluded from logs/analytics. Redeeming a link never grants private-project membership.

## 4. Projects, visibility and membership (P0)

| Method | Path | Operation | Permission |
|---|---|---|---|
| GET | `/teams/{team_id}/projects` | Authorized project list | Team member; privacy filtered |
| POST | `/teams/{team_id}/projects` | Create project | Owner/Admin |
| GET | `/projects/{project_id}` | Project detail | Authorized project viewer |
| PATCH | `/projects/{project_id}` | Edit metadata, visibility, settings | Owner or Project Manager |
| POST | `/projects/{project_id}/archive` | Archive | Owner or Project Manager |
| POST | `/projects/{project_id}/restore` | Unarchive | Owner or Project Manager |
| DELETE | `/projects/{project_id}` | Soft-delete | Owner or Project Manager |
| POST | `/projects/{project_id}/restore-deleted` | Restore within 30 days | Owner or Project Manager |
| GET | `/projects/{project_id}/members` | Explicit project membership list | Authorized project viewer |
| PUT | `/projects/{project_id}/members/{user_id}` | Add/change Project Manager/Contributor | Owner or Project Manager |
| DELETE | `/projects/{project_id}/members/{user_id}` | Remove explicit project membership | Owner or Project Manager |
| GET | `/projects/{project_id}/activity` | Project audit/activity timeline | Authorized project viewer |

Create: `{ "name":"Website", "key":"WEB", "visibility":"team", "template_id":null, "description":"..." }`. `visibility` is `team` or `private`, defaults to `team`. `key` uppercase `[A-Z][A-Z0-9]{1,9}`, unique within team; immutable after first task. Project creator becomes Project Manager. A private project's content is visible only to explicit project members and Team Owner; **Team Admin has no implicit access to private content**, even though Admin can create projects. Project Manager may grant access only to existing team members; cannot transfer a user across teams. A Team Viewer may view team-visible projects but may not modify tasks; being added to a private project does not upgrade the Team Viewer to write permissions. Effective write permission requires both Team role allowing writes and project membership/visibility allowing writes. A Project Contributor may create/edit tasks in an authorized project, but cannot manage project settings or membership. For team-visible projects, Team Member can work on tasks without explicit project membership; Team Viewer remains read-only. Archived projects are read-only until restored. Project removal hides all child resources and invalidates queued writes.

**Implementation contract (CORE-003, ADR-020).** These points settle details §4 leaves open:

- **Create:**
  - `name` is trimmed, 1–180 characters. `description` is trimmed, at most 5000 characters; `null` or empty clears it.
  - `key` is trimmed and upper-cased before validation.
  - `template_id` must be `null` until team templates exist (FLEX-004); otherwise `prohibited`.
  - A key used by another project of the team, including one in the trash, is `409 conflict`.
  - No `Idempotency-Key` is required (§1.4 doesn't list project creation; a retry hits the unique key, `409`).
- **Representation:** `id`, `team_id`, `name`, `key`, `description`, `visibility`, `role` (the caller's explicit project role, or `null`), `created_by`, `version`, `archived_at`, `created_at`, `updated_at`.
- **`GET /teams/{team_id}/projects`:** only projects the caller can see, filtered before cursor pagination; sorted `created_at desc, id desc`.
- **`PATCH /projects/{project_id}`:**
  - Accepts `name`, `key`, `description` and `visibility`, with the create rules.
  - Requires `If-Match` (`428` / `412`). Once a task number has been issued the key is frozen: a different `key` is `409 conflict`; the current key (any case) is accepted as a no-op (CORE-004, ADR-021 §5).
  - Project settings (`sprints_enabled`, …) go through `PATCH /projects/{project_id}/settings` (§5); here they are `unknown_field`.
- **`PUT /projects/{project_id}/members/{user_id}`:**
  - Body `{ "role": "manager" | "contributor" }`. Returns `201` when it adds the user, `200` when it changes or keeps their role.
  - The user must be a member of the project's team; otherwise `404`.
- **`DELETE /projects/{project_id}/members/{user_id}`:** `204`, or `404` without a membership.
- **Member endpoints and versions (technical correction of §18, ADR-020 §7):** §18 lists `projects.version` as the concurrency control for `/projects/*/members*`. They do not take `If-Match` and do not change `projects.version`: §1.4 versions the Project resource, and bumping its version on every membership change would make unrelated project edits fail with 412. Concurrent changes are serialised by row locks instead.
- **Member list:** each entry has `user_id`, `name`, `role`, `added_by` and `added_at`. `email` is included only for the Team Owner and Admins.
- **Status codes:** an archived team or project gives `423 resource_locked` on every write, checked before `If-Match` (423 wins over 412). A caller who can no longer see the project when their request reaches the lock gets `404`; one who still sees it but lost the right gets `409 conflict`. A taken key is a generic `409 conflict` that names no project (DEC-018); project create and `PATCH` are rate-limited per user (`429 rate_limited`).
- **Archive and trash:** `/archive`, `/restore`, `DELETE` and `/restore-deleted` follow the CORE-010 contract in §3 (ADR-022).
- **Not yet implemented:** `/activity` (CORE-008).

## 5. Workflows, labels and project configuration (P0/P1)

| Method | Path | Operation | Permission |
|---|---|---|---|
| GET | `/projects/{project_id}/statuses` | Ordered statuses | Project viewer |
| POST | `/projects/{project_id}/statuses` | Add status | Owner/Project Manager |
| PATCH | `/projects/{project_id}/statuses/{status_id}` | Rename, color, category, order | Owner/Project Manager |
| DELETE | `/projects/{project_id}/statuses/{status_id}` | Remove empty status or migrate tasks | Owner/Project Manager |
| PUT | `/projects/{project_id}/statuses/order` | Reorder status columns | Owner/Project Manager |
| GET | `/projects/{project_id}/labels` | List labels | Project viewer |
| POST | `/projects/{project_id}/labels` | Create label | Owner/Project Manager |
| PATCH | `/projects/{project_id}/labels/{label_id}` | Update label | Owner/Project Manager |
| DELETE | `/projects/{project_id}/labels/{label_id}` | Remove label and associations | Owner/Project Manager |
| GET | `/projects/{project_id}/settings` | Effective project configuration | Project viewer |
| PATCH | `/projects/{project_id}/settings` | Toggle sprints, points, enabled views | Owner/Project Manager |

Status: `name` 1–50, `color` hex RGB, `category` one of `todo`, `in_progress`, `done`, `position` is read-only in responses (0-based ordinal derived from the stored rank in `project_statuses.position`); reordering is done only through `PUT /projects/{project_id}/statuses/order`, which accepts the full ordered list of status IDs. Every project has at least one status in `todo` and one in `done`. Default statuses are To Do, In Progress, Done. Status IDs are project-local. Deleting a nonempty status requires `replacement_status_id` in same project and a transaction moving all tasks. Status changes preserve activity log. Sprints default disabled; disabling after use retains historic sprint data, hides planning controls, and prevents starting new sprints until reenabled.

**Implementation contract (CORE-004, ADR-021).** These points settle details §5 leaves open:

- **Statuses:**
  - **Representation:** `id`, `project_id`, `name`, `category`, `color` (`#RRGGBB` or `null`), `position` (0-based ordinal), `is_default` (the status new tasks start in; always a `todo` status), `version`, `created_at`, `updated_at`.
  - **Workflow version:** the workflow as a whole has a version (§1.4 "workflow configuration"), `projects.workflow_version`. `GET /statuses` and the reorder response return it as `meta.workflow_version` and as `ETag: "<n>"`; `DELETE` (`204`) returns it as the `ETag`. Status create and update responses return it in `meta.workflow_version`, while their `ETag` is the status's own `version`, which that status's `PATCH`/`DELETE` expect in `If-Match`. Clients that page `GET /statuses` must use the first page's `workflow_version` for the reorder (or request `limit=100`; a project has at most 50 statuses). Every status create, update, delete and reorder increments it; a no-op keeps it. It is independent of the project `version`.
  - **`GET`:** board order (`position`, then `id`), cursor-paginated like every list.
  - **`POST`:** `{ "name", "category", "color" }`. `name` is trimmed, 1–50 characters and unique in the project ignoring case (`409 conflict` otherwise). `color` is trimmed and upper-cased; `#FFF` and named colours are `regex`. The new status goes last; `position` and `is_default` are `unknown_field`. A project holds at most 50 statuses (`409`).
  - **`PATCH`:** `name`, `category`, `color` (`null` clears it). Requires `If-Match` with the status `version`. `position` and `is_default` are `unknown_field`. **Technical correction:** the table above lists "order" for PATCH, but the text says `position` is read-only and reordering goes through `PUT …/statuses/order` only; the text applies.
  - **`DELETE`:** optional body `{ "replacement_status_id": "uuid" }`. Requires `If-Match` with the status `version`. A replacement that is not another status of the same project is `422` (`exists`, or `different` for the status itself), answered identically for another project's status and a random ID. If the status was the default, a `todo` replacement becomes the default, otherwise the first remaining `todo` status by position. `204`.
  - **Order:** `PUT …/statuses/order` takes `{ "status_ids": [ … ] }`, every status ID of the project exactly once, and requires `If-Match` with the workflow version: missing is `428`, stale is `412 version_mismatch` (a reorder made from a stale list never overwrites a newer one), `423` still wins. A list that no longer matches the project's statuses is `409 conflict`; a malformed list is `422`. Answers `200` with the statuses in their new order; status versions are unchanged.
  - **Invariants:** a delete or category change that would leave no `todo` or no `done` status is `409 conflict`. When the default status moves out of `todo`, the default passes to the first remaining `todo` status by position.
- **Default workflow:** To do (`todo`, default), In progress, Done, created with the project, named in the creator's language (`للإنجاز`, `قيد التنفيذ`, `مكتمل` in Arabic).
- **Transitions:** unrestricted between the statuses of one project. There are no transition rules or endpoints in v1.0.
- **Settings:**
  - **Representation:** `GET /settings` returns `project_id`, `sprints_enabled`, `key_frozen` (true once a task number has been issued) and `version`, which is the **project's** version.
  - **`PATCH /settings`:** accepts `sprints_enabled` (strict boolean). It requires `If-Match` with the project version and increments it, which is the version `PATCH /projects/{project_id}` uses. Points and enabled views are `unknown_field` until FLEX-005/FLEX-002 add them.
- **Authorization and status codes:**
  - Status writes and the reorder are "Owner/Project Manager" as the table says: the Team Owner, or an explicit project `manager` who is a Team Admin or Member (the membership rule, §12 note). `PATCH /settings` needs the right to `PATCH /projects/{project_id}`, which a Team Admin also has on a team-visible project. Everyone else who can see the project gets `403` whatever the body; a hidden project is `404` for every endpoint here.
  - An archived team or project is `423` on every write, before `If-Match`.

**Implementation contract (CORE-005, ADR-023 §6): labels.**

- **Representation:** `id`, `project_id`, `name`, `color` (`#RRGGBB` or `null`), `created_at`, `updated_at`. `GET /labels` is in name order (`name`, then `id`), cursor-paginated.
- **`POST`:** `{ "name", "color" }`. `name` is trimmed, 1–50 characters and unique in the project ignoring case (`409 conflict`); `color` is optional, trimmed and upper-cased, `#RRGGBB` only (`regex`). A project holds at most 100 labels (`409`).
- **`PATCH`:** `name` and/or `color` (`null` clears it). Labels are not among the versioned resources of §1.4: no `If-Match`, no `version`.
- **`DELETE`:** `204`. The label's task associations go with it, and every task that carried it gets a new `version` (its `label_ids` changed).
- **Who:** writes are "Owner/Project Manager", the workflow rule (§12 note): the Team Owner, or an explicit project `manager` who is a Team Admin or Member. Attaching and detaching labels is a task edit (`label_ids`, §6.1), open to every project writer. A `{label_id}` of another project is `404`.


## 6. Tasks, checklist, comments, attachments (P0)

### 6.1 Tasks

| Method | Path | Operation | Permission |
|---|---|---|---|
| GET | `/projects/{project_id}/tasks` | List/search/filter tasks | Project viewer |
| POST | `/projects/{project_id}/tasks` | Create task | Project writer |
| GET | `/tasks/{task_id}` | Task detail | Project viewer |
| PATCH | `/tasks/{task_id}` | Edit task | Project writer |
| DELETE | `/tasks/{task_id}` | Soft-delete task | Project writer |
| POST | `/tasks/{task_id}/restore` | Restore task | Project Manager/Owner |
| POST | `/tasks/{task_id}/move` | Move across columns/order within same project | Project writer |
| GET | `/tasks/{task_id}/activity` | Task activity timeline | Project viewer |
| POST | `/tasks/{task_id}/watchers` | Watch task | Project viewer with team membership |
| DELETE | `/tasks/{task_id}/watchers/me` | Unwatch task | Watcher |

Create body:

```json
{
  "title":"Fix login validation",
  "description":"Validate inputs and errors",
  "status_id":"018f8c70-0000-7000-8000-000000000002",
  "priority":"high",
  "assignee_user_id":null,
  "due_date":"2026-10-20",
  "label_ids":[],
  "parent_task_id":null,
  "sprint_id":null,
  "story_points":null
}
```

- `title`: 1–200 chars; `description`: Markdown source up to 50,000 chars, sanitized on rendering; `priority`: `none|low|medium|high|urgent` (default `none`).
- Exactly **one nullable primary assignee**. Assignee must be active Team Member/Owner/Admin with project access and write capability; Team Viewer cannot be assignee. Watchers are separate.
- `parent_task_id` optional, same project, maximum three descendant levels (root depth 0, deepest depth 3), no cycles. Subtasks share task model and can have independent statuses/assignees.
- `story_points` nullable integer from 1 through 100, available only when enabled; `sprint_id` nullable and same project; it is a virtual API field derived from the single `sprint_tasks` row where `removed_at IS NULL`. Setting null closes current membership and places the task in backlog. Changing sprint atomically closes the old membership and inserts a new row. A task belongs to at most one current planned/active sprint.
- Readable task key generated atomically from project key + monotonic sequence (`WEB-104`); never accept task key from create payload. Task key remains stable after creation.
- Task `status_id` must belong to same project. `label_ids` must belong to same project. `due_date` is optional date, no automatic overdue status change.
- `GET /projects/{id}/tasks` supports `q`, `status_id[]`, `assignee_user_id`, `priority[]`, `label_id[]`, `sprint_id`, `due_before`, `due_after`, `sort` (`created_at`, `updated_at`, `due_date`, `priority`, `position`). Full-text search must not leak private projects.
- `POST /tasks/{id}/move`: `{ "status_id":"uuid", "before_task_id":null, "after_task_id":"uuid" }`; at most one of before/after non-null; both anchors must be in same target status and project. Server chooses stable fractional/order rank and periodically rebalances.

**Implementation contract (CORE-005, ADR-023).** These points settle details §6.1 leaves open:

- **Representation:** `id`, `project_id`, `key` (`{project_key}-{number}`), `number`, `title`, `description`, `status_id`, `priority`, `assignee_user_id`, `due_date` (`YYYY-MM-DD` or `null`), `label_ids` (sorted), `parent_task_id`, `created_by`, `version`, `created_at`, `updated_at`. Single-task responses carry `ETag: "<version>"`. `sprint_id` and `story_points` join it with FLEX-005, the board `position` with `/move` (CORE-007).
- **Create:** requires `Idempotency-Key` (§1.4); missing or malformed is `400 bad_request`. `status_id` is optional: without it (or `null`) the task starts in the project's default status (§5). `title` is trimmed; a blank `description` is `null`. `due_date` must be a real date, not before `1000-01-01`. `label_ids` is a list of at most 20 distinct label IDs. `sprint_id` and `story_points` accept only `null` until FLEX-005 (`prohibited`). `key` and any other field are `unknown_field`. A new task goes to the end of its status column.
- **References:** a status, label or parent of another (or hidden) project and an ineligible assignee are all `422` with `exists` on the field, exactly like a random ID. Eligible assignees are those who may write tasks in the project (§12): the Team Owner; a Team Admin or Member on a team-visible project; on a private project, a Team Admin or Member with an explicit membership. A Team Viewer, another team's user or a disabled account never is.
- **Subtasks:** a parent inside the task's own subtree (or the task itself) is `422 cycle`; a tree deeper than three levels below its root is `422 max_depth`.
- **`PATCH`:** requires `If-Match` (`428` / `412`, no write); `423` (archived) wins over `412`. `label_ids` replaces the whole set. A task whose `status_id` changes goes to the end of its new column. A real change, a label-set change included, increments `version`; a no-op keeps it.
- **`DELETE`:** any project writer, as the table says; requires `If-Match`; `204`. A task with live subtasks is `409 conflict`. The task leaves every list and detail route; its number is never reissued and the project key stays frozen (§5).
- **`POST /tasks/{task_id}/restore`:** Owner or project manager (an Admin needs the `manager` role; a Viewer never qualifies). No body, no `If-Match`; increments `version`; `200` with the task. Anyone else, and any task that is not in the trash, gets `404`. A subtask whose parent is still deleted is `409`. Deleted tasks are kept until their project is purged.
- **Losing access (§12 "revoking team membership immediately invalidates project access"):** an assignee who can no longer write tasks in the project is unassigned in the same transaction as the change: team removal or leaving, demotion to Viewer, ownership transfer (former Owner, private projects without membership), removal from a private project, or the project made private. The task's `version` moves.
- **List (`GET /projects/{project_id}/tasks`):** `q` matches the title as a case-insensitive literal substring, or the number or key exactly (`104`, `WEB-104`); several values of one key are alternatives, different keys must all hold; `due_after` and `due_before` are inclusive and leave out tasks without a due date (`due_before` earlier than `due_after` is `422 after_or_equal`). `sort`: `created_at` (default, newest first), `updated_at` (newest first), `due_date` (soonest first, none last), `priority` (urgent first), `position` (board order: status position, then the rank within the status); ties by `id`. A cursor is bound to the sort it was issued for: reused with another `sort` it is `400 bad_request` (also on `/me/tasks`). `sprint_id` is `prohibited` until FLEX-005.
- **Idempotent replay:** a replayed create returns the original status, body and `ETag`, with `Idempotent-Replayed: true`.
- **Status codes:** an archived team or project is `423` on every task, label and checklist write; a trashed one, or a task the caller cannot see, is `404`. A caller who can see the project but may not write gets `403` whatever the body. One who lost access while the request waited gets `404`, one who lost the right `409`.
- **Not yet implemented:** `/move` (CORE-007), `/activity` (CORE-008), `/watchers` (CORE-009), dependencies (§19).

### 6.2 Comments and checklist

| Method | Path | Operation | Permission |
|---|---|---|---|
| GET | `/tasks/{task_id}/comments` | List comments | Project viewer |
| POST | `/tasks/{task_id}/comments` | Add comment | Project writer |
| PATCH | `/tasks/{task_id}/comments/{comment_id}` | Edit own comment | Author; project write permission |
| DELETE | `/tasks/{task_id}/comments/{comment_id}` | Delete own or moderate | Author or Owner/Project Manager |
| GET | `/tasks/{task_id}/checklist` | List checklist items | Project viewer |
| POST | `/tasks/{task_id}/checklist` | Create checklist item | Project writer |
| PATCH | `/tasks/{task_id}/checklist/{item_id}` | Edit text, checked, position | Project writer |
| DELETE | `/tasks/{task_id}/checklist/{item_id}` | Delete item | Project writer |

**Implementation contract (CORE-005, ADR-023 §7): checklist.** Items are `{ "id", "task_id", "text", "checked", "position", "completed_by", "completed_at", "created_at", "updated_at" }`, listed in checklist order. `POST` takes `{ "text" }` (trimmed, 1–300) and appends the item; the 101st item of a task is `409`. `PATCH` takes `text`, `checked` (strict boolean; `true` records who completed it and when, `false` clears both; re-sending the current state changes nothing) and `position` (the 0-based index to move to; past the last item is `422 max`). `DELETE` is `204`. Checklist items are not versioned (§1.4 versions the Task; the checklist is not part of its representation), so they take no `If-Match` and do not change the task's `version`; locks serialise concurrent edits. **Technical reading of §18:** the `tasks.version` column there names the endpoint family's concurrency control; it applies to the task itself. An `{item_id}` of another task is `404`.

Comment body `{ "body":"Please review @..." }`, max 10,000 Markdown chars; mention IDs must be explicit server-validated eligible users, not arbitrary free-text matches. Checklist text max 300; maximum 100 checklist items per task. Comment edits keep edit timestamp and audit record; deleted comments become tombstones in activity logs without exposing content to unauthorized users.

### 6.3 Attachments

| Method | Path | Operation | Permission |
|---|---|---|---|
| POST | `/tasks/{task_id}/attachments` | Multipart upload | Project writer |
| GET | `/tasks/{task_id}/attachments` | List metadata | Project viewer |
| GET | `/attachments/{attachment_id}/download` | Auth-checked download/short-lived URL | Project viewer |
| DELETE | `/attachments/{attachment_id}` | Remove attachment | Uploader or Owner/Project Manager |

Cloud: maximum 25 MiB per file, 2 GiB stored attachments per team; reject over quota before persisting. Self-Hosted may configure limits. Validate actual MIME/file signature where feasible; randomized storage key; original filename is metadata only; never use public storage for private project attachments. Download must re-check current project access and use short-lived signed object-storage URLs (≤60 seconds) or stream through authorized Laravel endpoint. Malware scanning may be asynchronous; quarantined files cannot be downloaded.

## 7. Sprints and backlog (public v1.0; optional per project)

| Method | Path | Operation | Permission |
|---|---|---|---|
| GET | `/projects/{project_id}/sprints` | List sprints | Project viewer |
| POST | `/projects/{project_id}/sprints` | Create planned sprint | Project writer |
| GET | `/sprints/{sprint_id}` | Sprint detail | Project viewer |
| PATCH | `/sprints/{sprint_id}` | Edit planned sprint | Project writer |
| POST | `/sprints/{sprint_id}/start` | Start sprint | Project Manager/Owner |
| POST | `/sprints/{sprint_id}/complete` | Complete sprint | Project Manager/Owner |
| POST | `/sprints/{sprint_id}/tasks` | Assign backlog tasks | Project writer |
| DELETE | `/sprints/{sprint_id}/tasks/{task_id}` | Return task to backlog | Project writer |
| GET | `/projects/{project_id}/backlog` | Unassigned tasks | Project viewer |

Sprint has `name` (1–100), optional `goal` (≤2000), `start_date`, `end_date` (end ≥ start), `state` (`planned|active|completed`); API dates map to database `sprints.starts_at` / `sprints.ends_at` at the project timezone boundaries and are returned as ISO dates. Only one active sprint per project. Allowed transitions: `planned → active → completed`; no cancellation or reopening in v1.0. Starting requires dates and enabled sprints. Completing records completion time and keeps unfinished tasks in backlog (do not auto-close tasks). Completed sprint membership is retained in history. No mutation to completed sprint except Owner-authorized administrative correction with audit log. Sprint metrics are derived from task state, not manually editable.

## 8. Custom fields, saved views, templates (public v1.0)

| Method | Path | Operation | Permission |
|---|---|---|---|
| GET/POST | `/projects/{project_id}/custom-fields` | List/create definitions | Viewer / Owner or Project Manager |
| PATCH/DELETE | `/projects/{project_id}/custom-fields/{field_id}` | Update/retire definition | Owner/Project Manager |
| PUT | `/tasks/{task_id}/custom-fields/{field_id}` | Set typed value | Project writer |
| DELETE | `/tasks/{task_id}/custom-fields/{field_id}` | Clear value | Project writer |
| GET/POST | `/projects/{project_id}/views` | List/create views | Viewer / Project writer |
| PATCH/DELETE | `/projects/{project_id}/views/{view_id}` | Edit/delete own view or manage shared | Owner of view / Project Manager |
| GET/POST | `/teams/{team_id}/templates` | List/create draft template | Member / Member+ |
| POST | `/teams/{team_id}/templates/{template_id}/publish` | Publish shared template | Owner/Admin |
| PATCH/DELETE | `/teams/{team_id}/templates/{template_id}` | Modify/remove template | Draft owner; shared Owner/Admin |

Custom field types: `text`, `number`, `date`, `boolean`, `single_select`, `multi_select`, `url`. Maximum 30 definitions per project, 100 select options per field, 500-char text value. Definitions immutable in type after first value exists; retirement hides field but preserves historical values for export. Server validates typed values. Views: `board|list|calendar`, private by default; shared views require Project Manager permission. A view stores allowlisted filters, sort, visible columns, not executable expressions. Templates are team-scoped; any member may draft, Owner/Admin publish; applying a template copies configuration rather than linking live objects.

## 9. Inbox, notifications and activity (P0/P1)

| Method | Path | Operation | Permission |
|---|---|---|---|
| GET | `/notifications` | Paginated own inbox | Authenticated |
| POST | `/notifications/{notification_id}/read` | Mark read | Recipient |
| POST | `/notifications/read-all` | Mark own visible inbox read | Authenticated |
| GET | `/notifications/unread-count` | Own unread count | Authenticated |
| GET | `/me/activity` | Own authorized recent activity | Authenticated |

Notifications triggered by assignment, comment mention, invitation, and task update for watchers; batch/dedupe noisy events. If project access is revoked, redact or remove inaccessible notifications immediately; unread counts cannot leak private project names. Email notifications are queued and respect per-user preferences. Activity is append-only with actor, action, timestamp and authorized resource reference; do not store secrets or sensitive webhook payloads in activity text.

## 10. GitHub App and repository linkage (public v1.0)

| Method | Path | Operation | Permission |
|---|---|---|---|
| GET | `/integrations/github/install-url` | Return server-generated GitHub App install URL/state | Owner/Project Manager |
| GET | `/integrations/github/installations` | List authorized installation metadata | Owner/Project Manager |
| GET | `/projects/{project_id}/github/repositories` | List linked repos | Project viewer |
| POST | `/projects/{project_id}/github/repositories` | Link repo/installation | Owner/Project Manager |
| DELETE | `/projects/{project_id}/github/repositories/{link_id}` | Unlink repo without remote deletion | Owner/Project Manager |
| GET | `/tasks/{task_id}/github/activity` | Associated commits, branches, PRs, checks | Project viewer |
| PATCH | `/projects/{project_id}/github/automation` | Configure opt-in status rules | Owner/Project Manager |
| GET | `/projects/{project_id}/github/status` | Integration health, last event/sync, failures | Owner/Project Manager |

- Install URL uses signed, single-use, short-lived OAuth state tied to actor/team/project; callback validates state and installation ownership. GitHub App private key and installation tokens remain server-side only; tokens are short-lived and never stored in plain DB columns.
- Linking body `{ "installation_id":"123456", "repository_id":"987654" }`; both are decimal strings; verify GitHub App access to repo, team membership and project permission. A project may link multiple repositories. A repo may link to multiple projects, but issue-to-task mappings are unique and non-ambiguous per linked repo.
- Commit/PR/branch associations use task key with bounded parser matching `(^|[^A-Z0-9])WEB-104([^A-Z0-9]|$)` case-insensitively, plus explicit user association if needed. Never auto-link to a private project visible to a different team solely from an ambiguous key; resolve only among repositories linked to the project and validate exact project key.
- Automation rules supported: `pr_opened -> in_progress`, `pr_merged -> done`, `pr_closed_unmerged -> no_change`; all disabled by default. Auto-transition is only applied if status mapping is configured and project has corresponding status category; record actor as integration bot and source event. Avoid overriding a manually changed status with an older event.
- Webhook `POST /webhooks/github`: validate raw-body `X-Hub-Signature-256` HMAC SHA-256 using constant-time comparison before parsing; allowlist event types; dedupe `X-GitHub-Delivery`; verify installation/repository association; enqueue idempotent work; return `202` promptly. Invalid signature returns `401` without processing. Webhook secret rotation supports current and previous secret for a bounded overlap.
- GitHub delivery payloads are untrusted. Never reflect raw payloads in UI, never fetch arbitrary user-supplied URLs, and never expose repo metadata to unauthorized project viewers.

## 11. GitHub Issues bidirectional synchronization (v1.1)

| Method | Path | Operation | Permission |
|---|---|---|---|
| GET | `/projects/{project_id}/github/issue-sync` | Current configuration and sync health | Project Manager/Owner |
| PATCH | `/projects/{project_id}/github/issue-sync` | Enable/disable and field mapping | Project Manager/Owner |
| POST | `/tasks/{task_id}/github/issues/link` | Link existing Issue | Project writer |
| POST | `/tasks/{task_id}/github/issues/create` | Create remote Issue and mapping | Project writer |
| DELETE | `/tasks/{task_id}/github/issues/{mapping_id}` | Unlink without deleting Issue | Project Manager/Owner |
| POST | `/projects/{project_id}/github/reconcile` | Queue reconciliation | Project Manager/Owner |
| GET | `/projects/{project_id}/github/conflicts` | List sanitized conflicts | Project Manager/Owner |
| POST | `/projects/{project_id}/github/conflicts/{conflict_id}/resolve` | Resolve conflict explicitly | Project Manager/Owner |

- Issue sync disabled by default. Explicit enablement requires GitHub App Issues read/write permission; if unavailable return 409 `integration_permission_missing`.
- One Issue maps to at most one local Task; one Task maps to at most one Issue **per linked repository**. Reject ambiguous mappings and duplicate link attempts with 409.
- Canonical local fields: task title, description/body, labels, primary assignee, status. Incoming GitHub edits are accepted only if no pending local change to mapped field; if both changed since last checkpoint, preserve local value, store sanitized conflict and surface it in UI. Never silently overwrite either side.
- GitHub closed maps to Done category, reopened restores previous nonterminal status where available, else first To Do status. Deleting/unlinking local task never deletes a GitHub Issue. Deleting/unlinking remote Issue never hard-deletes local Task.
- Outbound changes use queued idempotent jobs, origin markers, remote ETags/revision checkpoints when available, exponential backoff with jitter, and dedupe to prevent echo loops. Failed jobs remain inspectable; core task operations succeed even if GitHub is offline.
- Conflict resolution body `{ "resolution":"keep_local" }` or `{ "resolution":"accept_remote" }`; the latter applies only mapped remote fields after authorization and logs a new revision. No implicit merge of Markdown descriptions.

## 12. Permissions matrix and visibility invariants

| Operation | Team Owner | Team Admin | Team Member | Team Viewer |
|---|---|---|---|---|
| Team settings | Yes | Yes | No | No |
| Transfer ownership | Yes | No | No | No |
| Invite Member/Viewer | Yes | Yes | No | No |
| Create project | Yes | Yes | No | No |
| See team-visible project | Yes | Yes | Yes | Yes |
| See private project | Yes | Only if explicitly added | Only if explicitly added | Only if explicitly added |
| Edit tasks in team-visible project | Yes | Yes | Yes | No |
| Edit tasks in private project | Yes | Only if added | Only if added | No |
| Manage project settings/members | Yes | Only if Project Manager | Only if Project Manager | No |
| Read task attachments/GitHub activity | If authorized | If authorized | If authorized | If authorized |

- **Technical correction (CORE-003, ADR-020 §5):** the row "Manage project settings/members" conflicts with §17 and DATABASE.md §19, which let Owner/Admin manage **team-visible** project *settings*. Per §0.2 the final rules apply to settings only: a Team Admin edits a team-visible project's settings (`PATCH /projects/{project_id}`) without a membership row. Project **membership** (`PUT`/`DELETE /projects/{project_id}/members/{user_id}`) stays "Owner or Project Manager" as in §4, so an Admin cannot pre-add themselves and keep access after a project is made private. The **workflow** (`/projects/{project_id}/statuses*`, CORE-004) follows the same "Owner/Project Manager" rule as membership (§5, §18; §17 lists settings, workflow and membership separately), while `PATCH /projects/{project_id}/settings` follows `PATCH /projects/{project_id}` (ADR-021 §1). Private projects: an Admin manages only as an explicit `manager`.
- Private-project Admins not explicitly added must receive 404 for detail, tasks, search hits, notifications, file download, GitHub activity, and any aggregate exposing content.
- Team Owner has implicit access to private projects even without project membership row.
- A Team Viewer with Project Manager label is **still read-only**; effective permission is the intersection of Team role and project permission, with Owner exception.
- Revoking team membership immediately invalidates project access and downloads; invalidate cached authorizations and cancel pending actor-originated privileged jobs as needed.
- All authorization checks happen before resolving child resources or disclosing their metadata.

## 13. Security, privacy, data retention and operations

- HTTPS, secure HttpOnly SameSite cookies, CSRF, session rotation, authorization policies, input validation, output escaping, sanitized Markdown, Content Security Policy, audit logging.
- Never log invitation tokens, GitHub tokens, passwords, attachment signed URLs, private content in unauthenticated error messages, or raw provider secrets.
- Cloud quotas: 25 MiB per attachment, 2 GiB attachments/team; unlimited projects/tasks/members subject to fair-use and rate limits. Self-Hosted may configure quotas without losing product functionality.
- Team/project deletion: 30-day recoverable trash then scheduled purge; archived entities are read-only; exports offered before deletion; external GitHub objects are never automatically deleted. Encrypted Cloud backups daily retained 14 days; operational logs retained 30 days. Backups age out independently of active-data deletion.
- Storage exports are authorization-scoped and asynchronous; completed export download uses short-lived signed URL and is automatically removed after 24 hours. Include metadata, tasks, comments, custom fields and authorized attachments; exclude provider secrets and other teams' data.
- All queue handlers re-check team/project existence and integration state. Use transactional outbox for externally visible mutations and idempotent retryable workers.
- Health routes: `/health/live` returns `200` if process is live; `/health/ready` returns `200` only when DB and required app services are healthy, else `503`. No sensitive dependency details in public responses.
- Cloud and Self-Hosted run the same code. Deployment-specific mail/storage/queue configuration must not change endpoint semantics or authorization.

## 14. Testable contract examples

### 14.1 Create task (P0)

```http
POST /api/v1/projects/018f8c70-0000-7000-8000-000000000001/tasks
Accept: application/json
Content-Type: application/json
Idempotency-Key: 24d413eb-6d4b-4d87-93bb-aaf4f66e45ab
X-CSRF-TOKEN: <session-csrf>

{"title":"Fix login validation","status_id":"018f8c70-0000-7000-8000-000000000002"}
```

Expected: `201`, task with generated `key`, `version: 1`, authorized project reference, and `meta.request_id`. Repeating with identical idempotency key/body returns same task, no duplicate.

### 14.2 Hidden private project

```http
GET /api/v1/projects/018f8c70-0000-7000-8000-000000000003
Accept: application/json
```

Expected for Team Admin not explicitly added: `404` with `error.code=not_found`, no project name in response, logs, or unrelated notifications.

### 14.3 Stale task update

```http
PATCH /api/v1/tasks/018f8c70-0000-7000-8000-000000000004
If-Match: "2"
Content-Type: application/json

{"priority":"high"}
```

If stored version is 3, expected `412` `version_mismatch`; no write or activity event. UI reloads task and prompts user to reapply edit.

### 14.4 GitHub webhook replay

Same verified `X-GitHub-Delivery` ID sent twice: first returns `202` and enqueues one event, second returns `202` and enqueues none. A failed signature returns `401` and persists no event.

## 15. QA acceptance and AI agent execution checklist

- [ ] Every endpoint has a feature test for success, invalid input, unauthenticated access, forbidden/hidden access, and cross-team IDs.
- [ ] Private-project Admin sees neither contents nor existence through search, lists, counts, notifications, attachments, GitHub, or exports.
- [ ] Team Owner can recover and manage private projects; Team Viewer cannot write even if added as Project Manager.
- [ ] Team and project deletion/recovery/archiving obey 30-day lifecycle and prevent queued writes.
- [ ] Task keys and versions are atomic under concurrency; idempotency prevents duplicate writes.
- [ ] Assignee, status, label, sprint, project membership and custom-field IDs cannot cross project/team boundaries.
- [ ] Arabic and English validation messages render correctly; UI uses RTL/LTR while JSON field names remain stable.
- [ ] GitHub signature, delivery dedupe, installation/repository authorization, permission revocation, retry, conflict and echo-loop tests pass.
- [ ] Upload limits, private downloads, path traversal prevention, MIME validation, and storage quota race conditions are covered.
- [ ] API schema is exported as OpenAPI 3.1 in `openapi/sn3-v1.yaml`, generated from or validated against implemented endpoints; CI fails on undocumented route/response drift.
- [ ] Browser Inertia form actions use the same application services and policy checks as JSON endpoints; no duplicated business logic.
- [ ] No paid-only feature flags or separate Cloud/Self-Hosted feature branches.

## 16. Implementation ordering and traceability

1. **P0:** Sessions/users → teams/members/invitations → projects/private access → statuses/tasks/board → comments/checklist/attachments → notifications/search/export → GitHub App installation/repo linking/PR and commit activity.
2. **R2 (before public v1.0):** Sprints/backlog → custom fields/views/templates → responsive and accessibility completion.
3. **R3 (public v1.0):** GitHub App, repository linkage, branches/commits/PRs, configurable automation.
4. **R4 (public v1.1):** Optional GitHub Issues two-way sync, conflict UI and reconciliation.
5. Build policy tests and tenant-isolation tests **before** implementing each endpoint group. Deliver OpenAPI and generated contract tests alongside each module.
6. BRD traceability: team membership `DEC-007/008`, task model `DEC-009`, optional sprints `DEC-010`, customization `DEC-011`, GitHub `DEC-012`, invitations `DEC-013`, private projects `DEC-014..017`. Maintain endpoint-to-`FR-*` mappings in the implementation issue tracker.

**FINAL:** This document defines the HTTP/API behavior of SN3. Implement it as written; do not replace fixed choices with unanswered questions.

## 17. Final effective permission rules (normative)

- Private-project nonmember (including Team Admin) gets `404` for list/detail/aggregates/search/inbox/attachments/integration metadata. Owner has full override.
- Team Viewer: read-only in every project; project role cannot elevate it.
- Project `manager`: project settings, workflow, membership, templates/views as permitted; project `contributor`: task CRUD, comments, and ordinary collaboration, not membership or project configuration.
- Team-visible project: Owner/Admin can manage project settings; Member may edit tasks; Viewer reads. Private project: only Owner or explicit manager/contributor membership, subject to team-level limits.
- Every state-changing request checks team membership, project access, CSRF/session, validation and optimistic concurrency where applicable. Revoked memberships invalidate future downloads and webhook-derived user-visible activity.
- Public v1.0 contains optional sprints, custom fields, templates and views. Public v1.1 adds optional bidirectional Issues sync; all GitHub sync defaults OFF.


## 18. Endpoint-to-schema authorization and concurrency crosswalk (normative)
| Endpoint family | Core tables | Required access | Concurrency |
|---|---|---|---|
| `/auth/*`, `/me*` | users, user_preferences, sessions | own identity | CSRF, throttling |
| `/teams*`, `/members*` | teams, team_memberships | team role; Owner-only transfer/delete | audit changes |
| `/teams/*/invitations*` | team_invitations | Owner/Admin invite rules | idempotent create |
| `/projects*`, `/projects/*/members*` | projects, project_memberships | team scope + private ACL | `projects.version` |
| `/projects/*/statuses*` | project_statuses | Owner/Project Manager | `project_statuses.version` |
| `/projects/*/tasks*`, `/tasks*` | tasks, labels, task_labels, task_checklist_items | project writer | `tasks.version`; idempotent create |
| `/tasks/*/comments*`, `/attachments*` | task_comments, task_attachments, task_activities | project ACL; recheck downloads | idempotent upload initiation |
| `/projects/*/sprints*` | sprints, sprint_tasks | project writer | `sprints.version`; idempotent start/close |
| `/projects/*/custom-fields*` | custom_field_definitions, custom_field_options, task_custom_field_values | manager defines; writer edits values | validated field type |
| `/projects/*/views*`, `/teams/*/templates*` | project_views, project_templates | personal/shared permissions | validated JSON config |
| `/inbox*` | notifications, user_preferences | recipient; recheck project access | cursor |
| `/integrations/github*`, `/webhooks/github` | github_installations, team_github_installations, github_repositories, project_repositories, github_webhook_deliveries, github_object_links | project ACL, installation authorization, HMAC | webhook delivery dedupe |
| GitHub Issues sync (v1.1) | github_issue_mappings, github_sync_outbox, github_sync_conflicts | authorized manager; opt-in | mapping version + idempotency |
| `/teams/*/export*` | team_exports | Team Owner | async request; 24h download expiry |

- `428 precondition_required` and `412 version_mismatch` are mandatory response codes for versioned mutations, supplementing §1.3.
- `POST /teams/{team_id}/exports` initiates an asynchronous export, requires an `Idempotency-Key`, and returns `202` with an export ID; `GET /teams/{team_id}/exports/{export_id}` returns state and, when ready, a signed URL expiring in 24 hours. No GET request starts a job.
- API endpoint syntax in §§2–11 is authoritative. Crosswalk patterns above describe families, not additional routes.


## 19. Task dependencies contract (public v1.0)
| Method | Path | Request | Response | Authorization | Persistence |
|---|---|---|---|---|---|
| GET | `/tasks/{task_id}/dependencies` | none | `{ "data": [{ "id", "blocker_task_id", "blocked_task_id" }] }` | project reader | `task_dependencies` |
| POST | `/tasks/{task_id}/dependencies` | `{ "blocked_task_id": "uuid" }` (route task is blocker) | `201` dependency object | project writer | `task_dependencies` |
| DELETE | `/tasks/{task_id}/dependencies/{dependency_id}` | none | `204` | project writer | `task_dependencies` |

All dependency references must belong to the same project; self-edges and cycles return `422 invalid_dependency_graph`; cross-project IDs return `404` to avoid information disclosure. POST requires `Idempotency-Key` and cannot create duplicate edges. No automatic workflow transitions.
