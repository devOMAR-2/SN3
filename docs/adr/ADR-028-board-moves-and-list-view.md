# ADR-028 — Board moves and the List view

- **Status:** Accepted
- **Date:** 2026-10-17
- **Task:** CORE-007 (#16)
- **Decision inputs:** BRD FR-TASK-005, FR-TASK-008; DEVELOPMENT_PLAN CORE-007 ("Reorder and status moves persist; keyboard fallback"); API_SPEC.md §1.4, §6.1; DATABASE.md §5 (`tasks.position numeric(20,10)`, index `(project_id, status_id, position)`); DESIGN_SYSTEM.md §1, §4 (mobile), §5.3, §8; ADR-018, ADR-023, ADR-026

## Context

API_SPEC §6.1 defines `POST /tasks/{task_id}/move` with `{ "status_id", "before_task_id", "after_task_id" }`, "at most one of before/after non-null", anchors "in same target status and project", and leaves the rest to the server: "stable fractional/order rank and periodically rebalances". DESIGN_SYSTEM §5.3 asks for a board with paginated fetching per column, optimistic drag and drop that reverts and explains a refusal, and a keyboard alternative; §4 asks for one column at a time on phones and the List as the default mobile view. This ADR settles the details.

## Decisions

### 1. The move request

- **Anchors.** `before_task_id`: the task goes just before that task. `after_task_id`: just after it. Neither (both `null` or absent): the end of the column. Both: `422 prohibits` on `after_task_id`.
- **Validation.** `status_id` is required and must be a status of the task's project (`422 exists`). An anchor must be a live task of the same project in the target status; anything else (another project's task, a trashed task, a task in another status, a random UUID) is `422 exists` on that field, so the answer never says whether a foreign ID exists. The task itself as an anchor is `422 different`. Unknown fields are `unknown_field` (`RejectsUnknownFields`); authorisation comes before validation (a Viewer gets `403` whatever the body, AUTH-04).
- **Concurrency.** `If-Match` is required (`428` / `412`, no write), like every task write (ADR-023). Locks: team → project (`ProjectWriteLock`, `contribute`) → task. Under the lock: lost visibility `404`, lost the right to write `409`, archived `423`; `423` wins over `412`.
- **No `Idempotency-Key`.** §1.4 asks for it on creates; a move is idempotent by its target and guarded by `If-Match`, so a retried move either finds the task already there (no-op, below) or answers `412`.
- **Answer.** `200` with the task (same representation and `ETag` as `GET /tasks/{id}`).

### 2. Ranks

- `tasks.position` stays `numeric(20,10)`. A move takes the midpoint of the two live neighbours' ranks, rounded to 10 decimals; an open end is one whole step past the neighbour (`floor(next) - 1` at the top, `floor(previous) + 1` at the bottom; `0` in an empty column). Ranks may be negative.
- **Rebalancing.** When the midpoint does not fit strictly between the neighbours at that precision (about 33 halvings of one gap), the column is renumbered to `0..n-1` in board order inside the same transaction, and the midpoint is taken again. The project write lock serialises this with every other task writer.
- **Trashed tasks** keep their rank. They count for "end of the column" and for renumbering, so a restored task comes back where it was and does not collide with an append; they are never neighbours for a move. A live task may tie with a trashed one; ties are broken by `id`, so the order of live tasks is unaffected.
- **No-op.** A move to where the task already is (same status, already strictly between the requested neighbours) writes nothing, keeps the `version` and is still `200`. Any real move increments `version`.
- **Position is not in the representation.** ADR-023 expected `position` to join the task representation with `/move`. It does not: renumbering changes ranks without changing versions (ranks are bookkeeping, not task content), so a client caching `position` would hold stale numbers, and clients never need them. The contract is anchors in, order out: `GET /projects/{id}/tasks?sort=position` (status position, then rank, ties by `id`). A status change through `PATCH` still sends the task to the end of its new column (ADR-023).
- **Activity.** A real move records a `task.moved` activity entry (ADR-027) in the same transaction, with the from and to status IDs only; a no-op records nothing.

### 3. Board UI (`Components/Board`)

- **Columns are the project's statuses, in order.** Each column reads its own page of `GET /projects/{id}/tasks?status_id[]=…&sort=position` (25 cards, "Load more" for the next cursor page), so a large project never loads all its tasks (DS §5.3). The header shows the loaded count, with `+` while more pages exist; there is no separate count query.
- **Drag and drop** (native HTML5) is a convenience. The card moves at once (optimistic); the request anchors it before the card it was dropped above, or at the end. A refusal puts it back, reloads the affected columns and explains why in the live region: `412` the task changed meanwhile, `423` the project is read-only (the board then stops offering moves), `404` the task is gone.
- **Keyboard and touch alternative** (DS §1, §5.3, §8; DS-ACC-001). Each card has a "Move KEY…" button that opens a dialog: status, then place (top or bottom of the column; up or down one place when staying in the same column), then Move. DS §5.3 describes "action menu → Move to status"; the card has a single action today, so the button opens the dialog directly. Every move, dragged or not, is announced in a polite live region ("WEB-3 moved to Done, position 1 of 4.").
- **Quick add** sits at the end of every column and creates the task in that status (`status_id` in the create body); the column label is "Add task to {status}".
- **Viewers and archived projects** see the board without move buttons, drag handles or quick add; the server still refuses (403, 423).
- **Phones** (below the `md` breakpoint) show one column at a time with a column selector instead of horizontal scrolling (DS §4).

### 4. List view and filters

- The List is a table over `GET /projects/{id}/tasks` with the same filters as the Board plus a status filter and a sort (board order, newest, recently updated, due date, priority), all served by the existing list endpoint (ADR-023 §9), with cursor paging.
- **Filters** (text, assignee, priority, labels, due from/until, and status on the List) live in the query string (`q`, `assignee`, `priority`, `label`, `due_after`, `due_before`, `status`, `sort`, `view`), so a filtered view can be reloaded, bookmarked and shared; the page updates the address with `router.replace` (no history entry per keystroke). Unknown or malformed values are dropped on read. Saved views remain FLEX-003.
- **Assignee choices** come from the project page props: the eligible assignees (ADR-023 §3), capped at 200 with a notice when truncated.
- **Which view opens.** `?view=board|list` if present; otherwise the last view this browser chose for the project (`localStorage`, `sn3.projectView.<project_id>`, best-effort); otherwise Board from the `md` breakpoint up and List below it (DS §4). The chosen view is then written to the address so the link reproduces it. Board and List are a tab strip (`role="tablist"`, arrow keys move between tabs).

## Consequences

- E2E: `e2e/tests/board.spec.ts` (keyboard and drag moves persist after a reload; filters in the URL; server-side sort; phone layout) with a new journey user `board_en`; `e2e/tests/core-journey.spec.ts` now adds its task from the board.
- FLEX-003 (saved views) can store the same query-string filters; FLEX-005 (sprints) adds a sprint filter.
- A board with many columns and long columns still makes one request per column on open; virtualisation is left until it is measured to matter.

## Open product questions (conservative option implemented)

1. Should column headers show the total number of tasks rather than the loaded count? Implemented: the loaded count (`25+`), with no extra count query per column.
2. Should the List be the default on phones even after the user chose Board on a desktop? Implemented: the last choice in this browser wins, else the viewport decides; the choice is not synced across devices.
