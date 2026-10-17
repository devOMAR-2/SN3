# ADR-029 — Inbox notifications, watchers and notification preferences

- **Status:** Accepted
- **Date:** 2026-10-23
- **Task:** CORE-009 (#18)
- **Decision inputs:** BRD FR-COL-003, FR-COL-004, FR-COL-005, FR-TEAM-006, FR-PROJ-007, FR-I18N-001, authorization test invariant; ARCHITECTURE.md ADR-006, ADR-007, §5.2, §6.7, §7, DECIDED-003, DECIDED-006, DECIDED-011; DATABASE.md §8, §10, §11 "Change project visibility", §12, §16 (DB-T08); API_SPEC.md §1, §2, §6.1, §9, §12, §13, §14.2, §17, §18; DESIGN_SYSTEM.md §3.1, §3.4 (Inbox, Profile), §6, DS-ACC-014; DEVELOPMENT_PLAN.md CORE-009 ("No private-project content leaked via notifications"); acceptance scenario NOT-01; AGENTS.md §5, §6; ADR-018, ADR-020, ADR-022, ADR-023 §5, ADR-025, ADR-026, ADR-027 §2

## Context

CORE-009 adds the inbox: in-app notifications for assignments, mentions, comments and updates on watched tasks, and the invitation notification ADR-025 left open (FR-COL-003); email notifications and per-user preferences (FR-COL-004); and the rule that a notification never shows a private project to someone who cannot see it (FR-COL-005, NOT-01). API_SPEC §9 lists the endpoints and asks to "batch/dedupe noisy events" and to "redact or remove inaccessible notifications immediately"; §6.1 lists `/tasks/{id}/watchers`; DATABASE.md §8 gives the `notifications` and `user_preferences` tables and requires "Notification rendering must recheck authorization at read time". CORE-008 left the `UsersMentioned` event for this task (ADR-027 §2). This ADR settles what the specs leave open, taking the most conservative option where they are silent.

## Decisions

### 1. Watchers (`/tasks/{task}/watchers`)

| Endpoint | Who | Answer |
|---|---|---|
| `POST /tasks/{task}/watchers` | anyone who can see the task ("Project viewer with team membership"), a Team Viewer included | `201` with `{task_id, user_id, created_at}`; `200` with the same watch when already watching |
| `DELETE /tasks/{task}/watchers/me` | the watcher | `204`; `404` when the caller does not watch the task |

- `{task}` resolves through AccessibleTasks: a hidden, trashed, foreign, random or malformed task is a 404. No body (`unknown_field`). An archived team or project is `423` on both (archived is read-only for everyone, DECIDED-011).
- The writers lock team → project (`ProjectWriteLock`, ability `view`) → task, like every project writer, so a watch cannot interleave with a membership or visibility change (a watch that lost the race is a 404).
- A watch is personal: there is no endpoint listing a task's watchers. The task page receives the viewer's own `watching` flag.
- `task_watchers` (technical addition; DECIDED-006 "watchers are separate entities if implemented"): PK `(task_id, user_id)`, `team_id`, `project_id`, `created_at`; every key cascades (ADR-022 §5).
- A watcher who loses access keeps the row but receives nothing (§2, §3); if access returns, the watch is active again. Rows go with the task, project, team or user.

### 2. What notifies whom

| Type | Recipient | Caused by |
|---|---|---|
| `task.assigned` | the new assignee | a task created with, or edited to, an assignee who is not the actor |
| `task.updated` | the task's watchers | a real task edit (`PATCH /tasks/{id}` that changed something); only the changed **field names** are kept |
| `task.commented` | the task's watchers | a new comment |
| `comment.mentioned` | each newly mentioned user | `UsersMentioned` (ADR-027 §2), on create and on edits that add a mention |
| `team.invitation` | the verified, enabled account an email invitation is addressed to | an email invitation (not a share link: it has no recipient) |

- **Never the actor.** A new assignee who also watches the task gets `task.assigned` only; users a comment mentions get `comment.mentioned` and not `task.commented` for it. An unassignment, an assignment released by a membership change (ADR-023 §5, `source: system`), checklist changes, attachments, comment edits and deletions, trash and restore notify nobody.
- **Hooks.** The writers dispatch after-commit events carrying IDs only: `TaskChanged` (CreateTask, UpdateTask), `CommentAdded` and the existing `UsersMentioned` (comments), `InvitationSent` (CreateInvitation). They never fire for a refused, stale or rolled-back write. The queued listener `SendInboxNotifications` hands them to `App\Domain\Notifications\Notifier` (the first project-scoped jobs, ADR-027 §5).
- **Send-time check.** Each delivery is one transaction that first takes a `SHARE` lock on the team row. Every membership, role, ownership, visibility and trash writer takes that row's write lock first (ADR-018 §4), so nobody can lose access between the check and the insert. It then re-resolves the live task, project and team (anything trashed or gone: nothing is sent), the live comment and the current mention, the current assignee, and keeps only recipients who can see the project now (`App\Support\Tenancy\ProjectAudience`, the ProjectPolicy::view rule in SQL; CommentMentions uses the same class now).
- **Batch/dedupe.** The task types collapse: the partial unique index `notifications_collapse_unique (recipient_user_id, task_id, type) WHERE read_at IS NULL` lets an `INSERT … ON CONFLICT DO UPDATE` raise `event_count`, move `updated_at` and the actor, and merge the field names of the unread notification instead of adding a row. Once it is read, the next event starts a new one. A mention is one notification per comment and user, an invitation one per invitation (`ON CONFLICT DO NOTHING`), so a comment edited to drop and re-add a mention, or a job run twice, never notifies twice. Recipients are processed in user-ID order, so concurrent deliveries do not deadlock on the indexes.
- **Invitations (ADR-025 "Consequences").** Only an account whose address is verified receives it: an unverified account could have been registered by anyone with that address (API_SPEC §3.1 "verified matching email"). It is informational: the token exists only in the invitation email, so the notification says to open that link (no in-app accept, which would be new API behaviour). It is never emailed (the invitation already is an email).

### 3. Access is re-checked at read time (NOT-01)

**Choice: read time, not transactional deletion.** `App\Domain\Notifications\Inbox::visibleTo()` is the only way notifications are read: a notification is in a user's inbox only while they may see what it is about **now**:

- task notifications: the project passes `AccessibleProjects::constrain()` for the recipient (team membership; on a private project an explicit membership or the Team Owner; team and project not in the trash) and the task is live; a mention also needs the comment to be live and still to mention the recipient;
- invitation notifications: the invitation is still pending (not revoked, accepted, used up or expired), it is addressed to the recipient's verified email, its team is not in the trash and the recipient has not joined it.

Everything else is left out of the list, the unread count (the shared Inertia `inbox.unread_count` included), `POST …/{id}/read` (`404`, the same as a random ID) and `POST /notifications/read-all`, which marks only visible rows and leaves hidden ones untouched, so no count or answer reveals that something hidden exists. Nothing is rendered from a copy: `payload` holds field names only, and titles, keys and names are read from the live rows (InboxPresenter), so the project name never leaves the database for someone who cannot see it (API_SPEC §14.2 "no project name in … unrelated notifications").

Why read time:

- **Every path at once.** Access ends in at least eight ways: team removal, leaving, demotion (none today removes visibility: a Team Viewer still reads what they could see), removal from a private project, the project made private, a trashed project, a trashed task, a trashed team and an ownership transfer (the former Owner loses private projects without a membership). A deletion hook in each writer would have to stay complete as writers are added (CORE-007 moves, GitHub); one predicate cannot miss a path, and it is the same AccessibleProjects rule every other read uses (DATABASE.md §10.2).
- **Nothing is lost by a reversible change.** A project in the 30-day trash, a project made private and public again, or a member removed by mistake and added back get their notifications back; transactional deletion would have destroyed them.
- **No race.** A notification written by a job that ran just after a removal (§2 makes that impossible, but jobs are at-least-once) is still filtered.
- **Cost.** The predicate is semi-joins on indexed keys (`notifications_recipient_updated_at_index`, the AccessibleProjects subquery, the task and mention primary keys); the inbox is paginated (25, at most 100).

Rows are deleted only with what they name (ADR-022 §5 cascades: team, project, task, comment, invitation, recipient).

### 4. Inbox endpoints and representation

- **`GET /notifications`:** the caller's visible notifications, latest event first (`updated_at desc, id desc`), cursor-paginated (`limit` 1–100, default 25). `status=all` (default) or `unread`; anything else is `422 in`. The cursor is bound to the status (reused with the other one: `400`).
- **Representation:** `id`, `type`, `team` `{id, name}`, `project` `{id, key, name}` or `null`, `task` `{id, key, title}` or `null`, `comment_id`, `actor` `{id, name}` or `null` (a deleted account), `fields` (the changed task fields of `task.updated`, sorted), `event_count`, `invitation` `{role, expires_at}` or `null`, `read_at`, `created_at` (first event), `updated_at` (latest event). The page is rendered in one query per kind of row (`CursorPagination::respondMany`).
- **`GET /notifications/unread-count`:** `{"unread_count": n}` over the same predicate.
- **`POST /notifications/{id}/read`:** `200` with the notification; already read stays as it was (idempotent). No body (`unknown_field`). Someone else's, hidden, random or malformed: `404`.
- **`POST /notifications/read-all`:** `200` with `{"marked_read": n}`, the visible unread notifications it marked. No body.
- Authenticated session and CSRF like every `/api/v1` route; the global rate limit applies.

### 5. Preferences and email

- **Preferences (DATABASE.md §8 columns, API_SPEC §2 "preferences"):** `GET /me` (and the Inertia `auth.user`) now carries `email_notifications` and `in_app_notifications`; `PATCH /me` accepts both as strict booleans (`boolean:strict`; `null` is `required`). Both default to on, as the columns created by FND-004 do. The specs name no per-type switches, so there are none (`settings` stays unused).
- **In-app off:** the inbox and the unread count show nothing (rows are still recorded, so email still works, and they come back if the inbox is turned on again). **Both off:** nothing is recorded.
- **Email (API_SPEC §9 "queued"; ARCHITECTURE ADR-006):** a notification's email is scheduled once per window: `SendNotificationEmail::schedule()` sets `email_due_at` only where it is empty and dispatches the job after the commit, delayed by `SN3_NOTIFICATION_EMAIL_DELAY_SECONDS` (default 120). Events collapsed meanwhile go out in that one email. The job carries the notification ID only, is `ShouldBeEncrypted` and `afterCommit`, and when it runs it locks the row and re-checks: still due; recipient enabled, verified and still wanting email; still allowed to see it (the §3 predicate); and, if they use the inbox, not already read there. Then it renders the email (`InboxNotificationMail`, sent with `notifyNow()` inside the job, so titles never sit on the queue) and closes the window (`email_due_at` cleared, `emailed_at` set). Without a worker or SMTP the inbox works and the email is not delivered (SELF_HOSTING.md).
- **Mail content:** in the recipient's language (`User::preferredLocale()`), Arabic and English (`lang/*/notifications.php` `inbox.*`); actor names, titles and project and team names are Markdown-escaped (`App\Notifications\PlainMarkdown`, extracted from TeamInvitationNotification) and reduced to one line in the subject; the action links to `/tasks/{id}`, which checks access again; the footer points to `/settings`.

### 6. Interface

- **`/inbox`** (Inertia `Inbox/Index`; API_SPEC §1.1 already listed it): All/Unread filter (toggle buttons with `aria-pressed`), each item with what happened, the task key as `TechnicalId`, the title with `dir="auto"`, the project and team, the time and the changed fields; "Mark as read" per unread item and "Mark all as read", announced in a polite live region without moving focus (DESIGN_SYSTEM §6). Opening an item marks it read and visits `/tasks/{id}` (a plain link, so a new tab works). A notification the server no longer shows (404) leaves the list. Empty states for both filters; a note when in-app notifications are off.
- **Navigation:** Inbox is in the sidebar (DESIGN_SYSTEM §3.1; this resolves ADR-026 §5's "Inbox is CORE-009") and a bell in the top bar (§3.1 "notifications"), both with the unread count as a badge (`99+` beyond 99) and in the accessible name ("Inbox, 3 unread"). The count comes from the shared `inbox` prop and follows the inbox page's changes.
- **Task detail:** a Watch / Watching toggle (`aria-pressed`) with a hint, for anyone who can see the task, hidden on an archived team or project.
- **Profile:** a "Notifications" group with the two preferences, saved with the profile.
- Every string in Arabic and English (`resources/js/i18n/locales/*/inbox.ts`, `nav.inbox`, `task.watch*`).

### 7. Schema (`2026_10_23_000100`)

- `notifications` as in DATABASE.md §8 plus the technical columns `task_id`, `comment_id`, `invitation_id`, `event_count`, `updated_at`, `email_due_at`, `emailed_at` (§2, §5). `team_id` is NOT NULL (every type belongs to a team).
- Keys: `recipient_user_id → users`, `team_id → teams`, `(project_id, team_id) → projects`, `(task_id, project_id) → tasks`, `comment_id → task_comments`, `invitation_id → team_invitations` cascade; `actor_user_id → users` sets NULL. The trash purge's recursive key guard (TrashPurgeTest) covers both new tables.
- CHECKs: `type` in the five types; the shape per type (task types: project and task, no comment or invitation; mention: also the comment; invitation: the invitation only); `payload` a JSON object of at most 1 KiB; `event_count >= 1`; `updated_at >= created_at`.
- Indexes: DATABASE.md §12's `(recipient_user_id, read_at, created_at DESC)`, the inbox order `(recipient_user_id, updated_at DESC, id DESC)`, the three partial unique indexes of §2, `email_due_at` (partial), and the cascade keys.

### 8. Concurrency and tests

- Real processes (`tests/Feature/Notifications/NotificationConcurrencyTest.php`, the advisory-lock barrier of TaskConcurrencyTest): four simultaneous deliveries to one watcher end as one notification with `event_count` 4 and every field name, never a duplicate or a unique-violation error; a watch racing the watcher's removal from the private project either fails (404) or lands first, and the removed member receives nothing either way.
- NOT-01 for every way access ends (`NotificationRedactionTest`), triggers and collapsing (`NotificationTriggersTest`), the endpoints (`InboxApiTest`), watchers (`WatcherTest`), email (`NotificationEmailTest`), the schema (`NotificationSchemaTest`), the pages (Vitest) and the journey in both viewports (`e2e/tests/inbox.ui.spec.ts`). The key tests were mutation-checked: removing the project check, the live-task check, the mention check, the visibility filter of "mark all read", the send-time audience filter, the actor exclusion, the email's read and access re-checks each makes them fail.

## Consequences and follow-ups

- **CORE-007** (`POST /tasks/{id}/move`, merged first) dispatches `TaskChanged` with `status_id` when a move changes the status, so watchers hear about it; a reorder within a column notifies nobody (`NotificationTriggersTest`).
- **GitHub (GH-\*)** changes made by the integration (`source: integration`) can use the same events with a null actor; `Notifier` would need an actor-less variant.
- **Cross-project search** (FR-TASK-008, TRACEABILITY "search … follows with CORE-009") is not part of CORE-009 (DEVELOPMENT_PLAN: "Inbox notifications and user preferences"); it stays open for a later task.
- Team exports (ADR-024) do not include notifications or watches: they are personal to members, not team content.
- No real-time push: the inbox and the badges refresh on navigation (Redis and real-time are optional, AGENTS.md §2).

## Open product questions (conservative option implemented)

1. Should creators, assignees or commenters watch a task automatically? Implemented: no; only an explicit watch.
2. Which changes count as a "task update for watchers"? Implemented: a real `PATCH /tasks/{id}` and a new comment; checklist items, attachments, comment edits and deletions, trash and restore do not notify.
3. Should a user be told when they are unassigned? Implemented: no.
4. Should an invitation notification reach an unverified account at that address, or allow accepting in-app? Implemented: verified accounts only, informational, visible while the invitation is pending.
5. Should notification preferences be per type? Implemented: two switches, email and in-app, as DATABASE.md §8 names them; both on by default (the existing column defaults).
6. Is 120 seconds the right email window, with at most one email per window and none for something already read in the inbox? Implemented: yes, configurable.
7. Should old notifications be pruned? Implemented: no age-based pruning; rows go with their team, project, task, comment, invitation or recipient.
8. Should notifications about a project come back when access does (restored project, re-added member)? Implemented: yes; access is checked at read time and nothing is deleted.
9. Should a watch end when the watcher loses access? Implemented: the row stays and is inert until access returns.
