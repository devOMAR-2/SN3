# ADR-022 — Archive, trash, recovery and purge for teams and projects

- **Status:** Accepted
- **Date:** 2026-10-19
- **Task:** CORE-010 (#19)
- **Decision inputs:** BRD FR-PROJ-008, NFR-SEC-006; ARCHITECTURE.md §5.2 step 6, DECIDED-004, DECIDED-005, DECIDED-011; DATABASE.md §3, §4, §13, §18, §19, §20; API_SPEC.md §1.3, §1.4, §3, §4, §13, §15; DEVELOPMENT_PLAN.md CORE-010 and §14; ACCEPTANCE_SCENARIOS AUTH-02, AUTH-04, DEL-01, DEL-02; AGENTS.md §2; ADR-017, ADR-018, ADR-020

## Context

DECIDED-011 fixes the lifecycle: archive is reversible and read-only; delete moves a team or project to a 30-day trash, after which a scheduled job purges it; the Owner alone deletes a team, the Owner or a Project Manager a project; hard deletion removes application data and queued integration links; nothing is ever deleted on GitHub. DEVELOPMENT_PLAN §14 requires archive and deletion to be independent states. The schema already has both columns (`archived_at`, `deleted_at`, ADR-017 §2, ADR-020 §2), every writer already answers 423 on an archived team or project, and `AccessibleTeams` / `AccessibleProjects` already hide trashed rows. This ADR settles what the specs leave open.

## Decisions

### 1. Endpoints
API_SPEC §3 and §4 are authoritative for the paths. All are under the `auth` group of `/api/v1` and take no body (any body field is `unknown_field`, §1.2). Role checks run before validation, so a caller without the right gets 403 whatever the body (AUTH-04).

| Endpoint | Who | Answer |
|---|---|---|
| `POST /teams/{team}/archive` | Team Owner | 200, team |
| `POST /teams/{team}/restore` (unarchive) | Team Owner | 200, team |
| `DELETE /teams/{team}` | Team Owner | 204 |
| `POST /teams/{team}/restore-deleted` | Team Owner | 200, team |
| `POST /projects/{project}/archive` | Owner or Project Manager; `If-Match` | 200, project |
| `POST /projects/{project}/restore` (unarchive) | Owner or Project Manager; `If-Match` | 200, project |
| `DELETE /projects/{project}` | Owner or Project Manager; `If-Match` | 204 |
| `POST /projects/{project}/restore-deleted` | Owner or Project Manager | 200, project |

### 2. Two independent states
- **Archived** (`archived_at`) is read-only and fully readable. Every writer locks the team row and then the project row and answers `423 resource_locked` while either is archived (ADR-018 §4, ADR-020 §7). Lists, details and member lists keep working, and an archived project's history is untouched (FR-PROJ-008).
- **Archiving a team** archives everything in it *implicitly*: every project writer checks the team first, so all its projects are read-only. The projects' own `archived_at` is **not** copied or changed. Unarchiving the team therefore returns each project to the state it had; a project that was archived on its own stays archived.
- **Trashed** (`deleted_at`) is invisible. Only the root row's `deleted_at` is set; nothing below it is deleted, copied or flagged:
  - a trashed team disappears from every read path (`AccessibleTeams`), and so do all its projects (`AccessibleProjects` requires a team that is not trashed);
  - a trashed project disappears from its team's list, its detail and member routes;
  - every writer answers 404, because it re-reads the team and project rows without trashed rows.

  Recovery is then just clearing `deleted_at`: memberships, projects, content and archive states come back exactly as they were.
- **Combinations (the conservative choices where the specs are silent):**
  - **Deleting an archived team or project is `423`.** Archived means read-only, and deletion is a write. Unarchive first.
  - **An archived team freezes its projects' lifecycle too:** archive, unarchive, delete and restore-deleted of a project in an archived team are all `423`.
  - **A project in a trashed team cannot be recovered on its own** (404, because the team is invisible). Recovering the team comes first.
  - **Recovering a team does not recover projects that were trashed on their own** before. They stay in the trash with their own 30-day clock, which keeps running while the team is in the trash.
  - **An archived team does not pause the trash clock.** A project trashed before its team was archived keeps counting down while the team is archived, and the purge deletes it on schedule, even though the archived team cannot recover it (423) until it is unarchived.
  - **Leaving or being removed from an archived team stays `423`** (ADR-018 §6 left this open for CORE-010). Membership is part of the team's state, and the Owner can unarchive.
- **Idempotent transitions:** archiving an archived resource, or unarchiving an active one, changes nothing and answers 200 (projects keep their `version`). Retries are therefore harmless without an `Idempotency-Key`, which §1.4 does not require here.

### 3. Who may do it
- **Teams:** the Owner alone, for all four operations (API_SPEC §3, DECIDED-011; `TeamPolicy::archive`, `delete` and `restore` from ADR-017 §5). An Admin, Member or Viewer gets 403 on a team they belong to. A non-member gets 404.
- **Projects:** API_SPEC §4 and DECIDED-011 say "Owner or Project Manager". `ProjectPolicy::archive`, `delete` and `restoreDeleted` use the same rule as project membership (`manageMembers`):
  - the Team Owner, whether or not they have a membership row;
  - a Team Admin or Member with the `manager` project role.

  Two consequences:
  - **The Admin settings exception does not apply.** ADR-020 §5 lets a Team Admin edit a team-visible project's *settings* without a membership (API_SPEC §17), but §17 says nothing about the lifecycle, so §4's stricter wording applies.
  - **A Team Viewer stays read-only** even as a `manager` (DECIDED-003).

  A visible project the caller may not change gives 403. A hidden one gives 404, as everywhere (ADR-020 §4).

### 4. Recovery: `RecoverableResources` and the 30-day window
The restore-deleted routes bind `{trashedTeam}` and `{trashedProject}` through `App\Support\Tenancy\RecoverableResources`. `AccessibleTeams` and `AccessibleProjects` are unchanged and keep hiding trashed rows from every other path.

- **Team:** a trashed team is returned only to its Owner.
- **Project:**
  - the query is the `AccessibleProjects` predicate on trashed rows, so a private project stays hidden from non-members, Team Admins included (DECIDED-003, DB-T02);
  - the project is returned only if the caller may also recover it (`restoreDeleted`);
  - its team must not be in the trash.
- **Everyone else gets 404, never 403.** That includes members who could see the resource before it was trashed: the trash is invisible to anyone who cannot recover it, so it confirms nothing. Live resources, expired ones, foreign, random and malformed IDs get the same 404.
- **Window:** `App\Domain\Trash\TrashRetention` (30 days, DECIDED-004; a product decision, so not configurable):
  - a row is recoverable while `deleted_at > now − 30 days`;
  - it is purgeable once `deleted_at <= now − 30 days`.

  The predicates are exact complements, so the answer never depends on when the purge last ran. Past the window, recovery is 404 even before the purge runs. 410 `expired` is reserved for invitations and integration state (§1.3), and 404 matches what the client sees after the purge.
- **Re-checks under the lock:** the actions re-check the binding's conclusions on the locked rows (window, visibility, right). Anything that no longer holds is a 404. A resource that a concurrent request already recovered is returned unchanged.

### 5. Purge: `trash:purge`
`App\Domain\Trash\PurgeTrash`, run by `php artisan trash:purge` daily at 03:40 UTC (`routes/console.php`, `onOneServer()`, `withoutOverlapping(60)`: the overlap lock expires after an hour, so a crashed run cannot block the next day's).

- **Only roots are deleted:** projects first, then teams (a team takes its remaining projects with it). Everything below a root goes by `ON DELETE CASCADE`: today team memberships, projects, project memberships and idempotency records; later statuses, tasks, comments, attachment metadata, notifications, repository links and the GitHub outbox. So the purge "removes application data and queued integration links" (DECIDED-011, DEL-02) without listing tables, and a new descendant table is purged without changing this class.
- **Deferred keys:** each delete runs in its own transaction with `SET CONSTRAINTS ALL DEFERRED`.
  - `project_memberships_team_id_user_id_foreign` (`DEFERRABLE INITIALLY IMMEDIATE`, ADR-020 §2) would otherwise be checked after the cascade to `team_memberships` and before the one to `projects`, and would refuse the team purge.
  - Deferring `ALL` rather than this one constraint is deliberate. Later keys between two descendants without a cascade will hit the same problem, for example `tasks (team_id, assignee_user_id) → team_memberships` (DATABASE §5) or `tasks (status_id, project_id) → project_statuses`.
  - Only DEFERRABLE constraints are affected, and they are checked at COMMIT, when the whole subtree is gone.
- **Rule for every later migration.** `TrashPurgeTest` guards it: it walks `pg_constraint` recursively from `teams` and `projects` through every `ON DELETE CASCADE` key, whatever the tables' columns, to find all the tables a purge deletes from (the subtree). Then:
  - a foreign key to `teams` or `projects`, from any table, must be `ON DELETE CASCADE`;
  - a foreign key to any other table of the subtree must cascade or `SET NULL`/`SET DEFAULT`, or be a `DEFERRABLE` NO ACTION key whose source table is itself in the subtree, so that it is checked at COMMIT, when both rows are gone. A `RESTRICT` key cannot be deferred, and a referencing row outside the subtree would still exist at COMMIT, so both block the purge.

  The test also creates probe tables with blocking keys (for example `RESTRICT` to `projects` from a table without `team_id`) and checks that the guard reports them.
- **Batches and idempotency:**
  - candidates are read by keyset in batches of 100;
  - each row is deleted in its own short transaction, after locking its team row (the writers' lock order, ADR-018 §4);
  - the `DELETE` repeats the trash condition (`deleted_at IS NOT NULL AND deleted_at <= cutoff`), so a row recovered after it was selected survives. The recovery holds the team and project locks, and PostgreSQL re-evaluates the condition once they are released.

  Re-running the purge, or running it concurrently, deletes nothing twice.
- **One bad row does not stop the purge.** A row whose delete fails, including at COMMIT (a deferred constraint or trigger), is rolled back whole and logged as `trash.purge_failed` (warning; `type`, `id`, `team_id` only, because database error messages may quote values). The run continues with the next row, and `trash:purge` exits non-zero so that the scheduler and monitoring see it. The row is retried on the next run, so a fixed id order cannot make one row block every later run. Partial indexes on `deleted_at WHERE deleted_at IS NOT NULL` (`2026_10_19_000200`) keep the daily lookup cheap.
- **Freed identifiers:** slugs and project keys stay reserved while the row is in the trash (ADR-018 §2, ADR-020 §2, DEC-018), so recovery never conflicts. The purge frees them.
- **Never remote:** the purge, and trashing itself, delete local rows only. They never call GitHub or any other service (AGENTS.md §2 and §6.6, DECIDED-005, DECIDED-011), and a test runs the purge under `Http::preventStrayRequests()`. **The GitHub integration (GH-001 onwards) must keep it that way:**
  - repository links, object links, Issue mappings and outbox rows are local rows that cascade from their project;
  - no deletion, trash or purge path may enqueue a remote write;
  - an outbox or sync job whose project is trashed or gone must drop itself without calling GitHub.

### 6. Versions, locks and concurrency
- **Projects are versioned resources (API_SPEC §1.4, DATABASE.md §20):**
  - archive, unarchive and DELETE require `If-Match` (428 without it, 412 when stale, with no change) and increment `version`. The 423 checks run before the version comparison (423 wins over 412), as for PATCH. A versioned delete also stops someone from trashing a project that another user changed meanwhile, for example made private.
  - restore-deleted does not require `If-Match`, because no read path exposes a trashed project's version. It still increments `version`, so a representation fetched before the deletion is stale (412) after recovery.
  - Teams have no version column (ADR-017 §8), so team endpoints take no `If-Match`.
- **Locks:** every lifecycle writer locks the team row first (`Team::WRITE_LOCK`), then the project row, like every other writer. It re-checks the caller's access on the locked rows: 404 if they can no longer see the resource, 409 if they see it but lost the right (ADR-020 §7). Restore-deleted answers 404 in both cases (§4). `ProjectWriteLock::assertAllowed()` is the access part of `assertWritable()` without the archived check, for the writers that act on an archived project itself.
- **Real-process races** (`LifecycleConcurrencyTest`, advisory-lock barrier as in DB-T06):
  - Archiving a team while one of its projects is edited: either the edit commits first and the team is then archived, or the edit gets 423. An edit never lands in an archived team.
  - A recovery racing the purge (run as if two days later, so both are entitled to act): either the project is recovered and survives, or it is purged and the recovery is a 404. Never both, and never a 500.

### 7. Audit
Until the activity model arrives (CORE-008), these structured `info` events are logged with IDs only, never names (ADR-020 §9):
- `team.archived`, `team.unarchived`, `team.trashed`, `team.restored_from_trash` (`team_id`, `actor_id`);
- `project.archived`, `project.unarchived`, `project.trashed`, `project.restored_from_trash` (`team_id`, `project_id`, `actor_id`);
- `trash.project_purged` (`team_id`, `project_id`) and `trash.team_purged` (`team_id`); `trash.purge_failed` (warning: `type`, `id`, `team_id`).

## Consequences and follow-ups

- **CORE-004 onwards:** every new team- or project-scoped table follows the cascade and deferrable rule in §5. `TrashPurgeTest` fails otherwise.
- **CORE-005 onwards:** jobs carry IDs and re-resolve their project at run time, so a trashed project's queued work finds nothing (404 semantics) and does nothing. This is DEL-01's "no queued writes executed while deleted". No project-scoped job exists yet.
- **CORE-007 (attachments):** a cascade removes attachment *rows*, not stored files. The attachments task must make the purge delete the stored objects of purged projects too, for example by collecting storage keys before the delete, or with a sweeper of orphaned keys.
- **CORE-009 (notifications):** notification rows that reference a team or project must cascade with it, and rendering must keep re-checking access (DATABASE §8).
- **CORE-012:** DECIDED-011 says exports are offered before deletion. Offering one in the delete flow belongs with exports and the UI.
- **CORE-006:** archive, trash and recovery screens (confirmation, "in trash until …"), in Arabic and English.