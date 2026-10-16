# ADR-024 — Team export, encrypted backups and restore

- **Status:** Accepted
- **Date:** 2026-10-20
- **Task:** CORE-012 (#21)
- **Decision inputs:** BRD §2 ("data export and backup/restore instructions sufficient to avoid lock-in"), NFR-SEC-006, NFR-REL-001, NFR-OPS-002, NFR-OPS-005; ARCHITECTURE.md §5.2, §11.1–§11.3, §12, DECIDED-003, DECIDED-004, DECIDED-011; DATABASE.md §10, §13, §16 (DB-T01, DB-T02, DB-T12), §20; API_SPEC.md §1.3, §1.4, §3, §13, §15, §18; DEVELOPMENT_PLAN.md CORE-012, §8, §9; ACCEPTANCE_SCENARIOS AUTH-02, AUTH-04, EXP-01, EXP-02, OPS-01; AGENTS.md §2, §5, §6.8; ADR-012, ADR-013, ADR-015, ADR-017, ADR-020, ADR-022

## Context

CORE-012's acceptance is "encrypted daily Cloud backups retained 14 days; restore drill documented", and DEVELOPMENT_PLAN §14 adds "async team export (`team_exports`) and backup/restore verification". The specs fix:

- **Exports:** `POST /teams/{team_id}/exports` (Owner, `Idempotency-Key`, `202` with an export ID) and `GET /teams/{team_id}/exports/{export_id}` (state and, when ready, a signed URL expiring in 24 hours; a GET never starts a job). They must be authorization-scoped and asynchronous, use a versioned JSON format, exclude secrets and other teams' data, and be removed automatically 24 hours after completion. DECIDED-011 wants an export offered before deletion.
- **Backups:** daily, encrypted, kept 14 days (DECIDED-004); database and files from compatible points in time; documented and rehearsed restore; no advertised RPO/RTO (ARCHITECTURE.md §11.3). Self-hosted operators control their own infrastructure (NFR-OPS-005), and Cloud runs the same code (NFR-OPS-001).

This ADR settles what the specs leave open. Where they are silent it takes the most conservative option.

## Decisions

### 1. Export endpoints

| Endpoint | Who | Answer |
|---|---|---|
| `POST /teams/{team}/exports` | Team Owner; `Idempotency-Key` required; no body | `202` with the export and a `Location` header |
| `GET /teams/{team}/exports/{export}` | The Owner who requested it | `200` with the export |
| `GET /teams/{team}/exports/{export}/download?expires=…&signature=…` | The Owner who requested it, with the signed URL | `200` `application/zip` |

- **Technical settlement:** API_SPEC §3 names a "signed download URL" but no route. The download route above is that URL. It sits under `/api/v1` because it reuses the team binding and the session. It is not a JSON endpoint: success is a file, and errors use the usual envelope.
- **Representation:** `id`, `team_id`, `requested_by`, `status`, `format` (`sn3.team-export`), `format_version`, `size_bytes`, `sha256`, `download_url`, `created_at`, `completed_at`, `expires_at`. `size_bytes`, `sha256` and `download_url` are set only while the export can be downloaded.
- **`status`:** `pending`, `processing`, `ready`, `failed`, and `expired` for a ready export past `expires_at` (a computed state: it is reported until `exports:prune` deletes the row).
- **No list endpoint.** API_SPEC defines none, and the export ID comes back from the POST and the `Location` header.

### 2. The export format (versioned, extensible)

A zip archive:

```
manifest.json
data/team.json
data/members.json
data/projects.json
data/project_members.json
data/statuses.json
data/labels.json                 (CORE-005)
data/tasks.json                  (CORE-005)
data/task_labels.json            (CORE-005)
data/task_checklist_items.json   (CORE-005)
```

- **`manifest.json`:** `format` (`sn3.team-export`), `format_version` (`1`), `export_id`, `team_id`, `requested_by`, `generated_at`, and `sections`, a list of `{name, file, records, sha256}` in file order. A consumer can check every file against its checksum and count.
- **Each `data/<section>.json`:** a JSON array of flat records, one per line, ordered by ID. Field names and values follow the API: snake_case, UUIDs, RFC 3339 UTC timestamps, enum values as strings, Unicode kept as is (DB-T13).
- **Version 1 sections and fields:**
  - `team`: `id`, `name`, `slug`, `description`, `owner_user_id`, `archived_at`, `created_at`, `updated_at`.
  - `members`: `user_id`, `name`, `email`, `role`, `joined_at`. The Owner already sees members' emails in `GET /teams/{team}/members`.
  - `projects`: `id`, `name`, `key`, `description`, `visibility`, `sprints_enabled`, `created_by`, `archived_at`, `created_at`, `updated_at`.
  - `project_members`: `project_id`, `user_id`, `project_role`, `added_by`, `created_at`.
  - `statuses`: `id`, `project_id`, `name`, `category`, `position` (0-based), `color`, `is_default`, `created_at`, `updated_at`.
  - Added by CORE-005 (ADR-023 §11), still format version 1:
    - `labels`: `id`, `project_id`, `name`, `color`, `created_at`, `updated_at`.
    - `tasks`: `id`, `project_id`, `key`, `number`, `title`, `description`, `status_id`, `position` (the stored rank within the status, a decimal string), `priority`, `assignee_user_id`, `due_date` (`YYYY-MM-DD`), `parent_task_id`, `created_by`, `created_at`, `updated_at`. The generated sort columns and `version` are not exported.
    - `task_labels`: `task_id`, `label_id`, `created_at`, ordered by `(task_id, label_id)` (the table has no ID).
    - `task_checklist_items`: `id`, `task_id`, `text`, `checked`, `position` (0-based), `completed_by`, `completed_at`, `created_at`, `updated_at`.
- **Versioning rule:** adding a section, or a field to a section, is backwards compatible and keeps `format_version`; consumers must ignore sections and fields they do not know. Renaming or removing a field, or changing its meaning, needs a new `format_version`. Import is outside public v1.0 (DATABASE.md §13), but every record keeps its UUID so a later importer can remap IDs.
- **Registry for later tasks.** A section is a class implementing `App\Domain\Exports\TeamExportSection` (`name()`, `records(TeamExportContext)`), listed in `App\Domain\Exports\TeamExportFormat::SECTIONS`. CORE-005 adds tasks by writing `TasksSection` and appending one line there; CORE-007 and later tasks do the same for comments, checklist items, labels, attachment manifests and so on. The interface's docblock states the rules: scope through the context, allowlist fields, stream by ID, additive changes only. `TeamExportTest` checks that section names are unique and well-formed and that no field anywhere is named like a credential.
- **Writer:** `TeamExportWriter` streams each section to its own file (memory stays flat), writes the manifest, and builds the zip with `PharData`. PHP bundles Phar, while the image has no `zip` extension, and adding one would change the image for one feature. Entries are deflated when zlib is available. Entry names come from the format, never from data (no path traversal).

### 3. Who may export, and what the export contains

- **Owner only** (API_SPEC §3, `TeamPolicy::export` from ADR-017 §5). An Admin, Member or Viewer gets `403` whatever the body (AUTH-04). They get `403` on `GET …/exports/{id}` too, *before* the export ID is looked up, so they cannot tell whether an export exists. A non-member, a trashed team, a random or malformed ID: `404` (DB-T01).
- **Only the requesting Owner** reads or downloads an export. After an ownership transfer the former Owner gets `403` (no right any more), and the new Owner gets `404` (not their export; they can start their own). This is stricter than "Owner", and the conservative reading of "authorization-scoped" exports.
- **Content: what the requester can see, resolved when the job runs.** `TeamExportContext::projectIds()` goes through `AccessibleProjects::inTeam()` for the requester (DATABASE.md §10.2), so the Owner's export holds every project, private ones included (DECIDED-003 Owner override), and the scoping would hold for any other actor (tested with an Admin: no private project, no private statuses; DB-T02).
- **Trashed projects are left out.** No read path shows them (ADR-022 §2), and they are recovered with their content within 30 days. **Tasks in the task trash are left out too** (CORE-005, ADR-023 §11), with their label links and checklist items, for the same reason: only a project manager's restore brings them back. **Archived teams can be exported.** An export reads the team and changes nothing in it, and archiving is "read-only, fully readable". It is the obvious step before deleting an archived team.
- **Never in an export:** password hashes, remember tokens, sessions, idempotency records, tokens of any kind, users' preferences or other teams. Sections build records from named fields only; a test sets a remember token and a session and checks that neither, nor any member's password hash, appears in the archive.

### 4. Lifecycle, storage and expiry

- **Request (`RequestTeamExport`):** in one transaction, locks the team row first like every team writer (ADR-018 §4), re-checks membership (`404`) and ownership (`409` on a lost right) on the locked row, refuses a second export while one is `pending` or `processing` for the team (`409 conflict`), inserts the row (`format_version`, `expires_at = now + 24 h`) and dispatches `BuildTeamExport` **after commit**. The partial unique index `team_exports_one_in_flight_unique` backs up the in-flight rule; a real-process race test (advisory-lock barrier) shows that two simultaneous requests produce one export and one `409`.
- **Job (`BuildTeamExport`):** the payload is the export ID only (AGENTS.md §5). It re-resolves everything and **refuses** (marks the export `failed`, logs `team_export.refused` with a reason, no retry) when the export expired before it ran (`expired`), the team is missing or in the trash (`team_unavailable`), the requester is missing or disabled (`requester_unavailable`), or the requester is no longer the Owner (`not_owner`). It builds the archive in the export's own work directory (§4), copies it to the export disk, and keeps it only after re-checking the team and the requester again on the locked export row. A trash or an ownership transfer during the build therefore discards the result. Unexpected errors are retried by the worker (`--tries=3`); after the last attempt `failed()` marks the export `failed` and deletes any file and its work directory.
- **Storage:** a **dedicated private disk**, `exports` by default (`storage/app/exports`, `SN3_EXPORTS_DISK`). It is outside the root of the `local` disk, which Laravel can serve through signed temporary URLs, and has `serve => false`, so the only way out is the download route. Keys are `archives/<team_id>/<export_id>.zip`: SN3 writes, reads and sweeps only under the fixed `archives/` prefix, so even a disk shared by mistake loses nothing else to `exports:prune`. Cloud can point `SN3_EXPORTS_DISK` at a private S3 disk (or bucket prefix) used for nothing else; nothing else changes.
- **Work directories (plaintext while building):** the archive is assembled in a deterministic local directory per export, `<work_dir>/<export_id>` (`sn3.exports.work_dir`, default `storage/app/exports/work`, `SN3_EXPORTS_WORK_DIR`), not in the system temp directory. The job deletes it when it ends, and clears it when an attempt starts (so a retry never reuses what a killed attempt wrote). A worker killed with SIGKILL (timeout, OOM) skips that cleanup, so `exports:prune` also deletes every work directory older than 2 hours (`TeamExportStorage::WORK_TTL_MINUTES`; a job's worst case is 3 attempts of 60 seconds plus backoff). Plaintext therefore never outlives the export's 24 hours. The work directory is on the private storage volume and, like the archives, outside the backups. Residual: PHP's own temporary files (for example PharData's rewrite during compression) go to the system temp directory and are removed when the process closes them; a SIGKILL can leave one until the container is recreated.
- **Download:** Laravel's `signed` middleware checks the signature and its expiry (`403` on a tampered, unsigned or expired link; the signature covers the team and export IDs), and the controller then requires the session of the requesting Owner. A leaked link is useless to anyone else: other members get `403`, non-members `404`, guests `401`. An export that is not ready is `404`; a ready one whose archive is gone is `410 expired`. The response is `Content-Disposition: attachment`, `Cache-Control: no-store, private`. Query strings, and so signatures, are never written to the access log, and `signature` is redacted from application logs (ADR-015).
- **Expiry:** `expires_at` is 24 hours after the request while pending, and is reset to 24 hours after completion or failure (API_SPEC §13: "automatically removed after 24 hours"). The signed URL expires at the same moment. `exports:prune` runs **hourly** at :50 (`onOneServer`, `withoutOverlapping(60)`) and deletes every export past `expires_at` with its archive, so an archive outlives its expiry by at most an hour while the API already reports it `expired`. It also deletes archives under `archives/` whose row no longer exists, and stale work directories. That covers the trash purge: `team_exports.team_id` cascades (DATABASE.md §13 rule, guarded by `TrashPurgeTest`), and the stored file goes with the next sweep, as §13 requires for stored files.
- **Not backed up.** The backup's file archive leaves out `storage/app/exports`: archives live 24 hours, while backups live 14 days, which would keep copies of the export far longer than API_SPEC §13 allows.

### 5. Idempotency and rate limits

- `Idempotency-Key` is required (API_SPEC §18): the `idempotent` middleware replays the original `202` and export for the same key and payload (EXP-02), with one row and one queued job. `GET` never queues anything (EXP-01).
- **Rate limit `team-exports`:** 10 requests per user per hour (`SN3_RATE_LIMIT_TEAM_EXPORTS_PER_HOUR`; `0` disables it on self-hosted installs, NFR-OPS-004), on top of the global 120/minute. The spec asks for limits without a number for this endpoint; each export reads the whole team, and one in flight per team already bounds the work. Replays count against the limit, as for other throttled idempotent routes.

### 6. Audit

Structured logs with IDs only (until CORE-008): `team_export.requested`, `team_export.completed` (also `size_bytes`), `team_export.downloaded` (`team_id`, `export_id`, `actor_id`); warnings `team_export.refused` (plus `reason`) and `team_export.failed`; `team_export.pruned` (`team_id`, `export_id`) and `team_export.orphan_pruned` (`export_id`). Team, project and member names never appear in logs.

### 7. Backups and restore

- **Tooling: a shell script in the PostgreSQL image, not an artisan command.** `deploy/backup/sn3-backup` runs in an optional Compose service `backup` (profile `backup`) built from the **same `postgres:16.15-trixie` image** as the database. That image already has `pg_dump`/`pg_restore` of the server's major version, `psql`, `gpg`, `tar` and `flock`.
  - *Trade-off:* an artisan command scheduled by `schedule:work` would reuse Laravel's scheduler, but the application image has no PostgreSQL client. Adding one would put a second PostgreSQL version line into the app image to keep in step with the server, and backups would then fail whenever the app is broken, which is exactly when they matter. The script also runs unchanged on hosts without Docker and against external PostgreSQL. The cost is a second scheduler (the script's `daemon` loop, or cron on hosts without Compose), documented in docs/BACKUP_RESTORE.md.
- **Database:** `pg_dump --format=custom` (compressed, consistent snapshot, selective and parallel-capable restore). Logical dumps work across minor versions and to a newer major; no point-in-time recovery in this baseline.
- **Files:** a `tar.gz` of the `storage/app` volume (mounted read-only), taken right after the dump, so the files are at least as new as the rows that reference them (ARCHITECTURE.md §11.3). Export archives are excluded (§4).
- **Encryption: `gpg --symmetric`, AES-256, with the operator's passphrase file** (S2K iterated and salted SHA-512, no extra compression). Nothing unencrypted is written to the backup directory. A passphrase is the simplest key an operator can keep in a password manager, and gpg is already in the image. Public-key encryption (the backup host cannot decrypt) is a follow-up. The script refuses passphrases shorter than 16 characters.
- **Sets and integrity:** `sn3-<UTC time>.{db.dump.gpg,storage.tar.gz.gpg,sha256}`, assembled in a work directory and moved into place with the checksum file last, so a set without it is incomplete and never restored. Every run decrypts and reads its set back before it counts as a success.
- **Schedule and retention:** daily at `SN3_BACKUP_AT` (02:30 UTC by default, before the 03:00–03:40 maintenance jobs), plus a catch-up run when the service starts and finds no set younger than 24 hours. After a failure the daemon retries up to `SN3_BACKUP_RETRIES` (3) times, `SN3_BACKUP_RETRY_MINUTES` (60) apart, never past the next daily run, then waits for the next day. Retention is by the set's name (its creation time), not file times, which copies and restores change: sets older than 14 days are deleted after each successful run, **except the newest**, so failing backups can never delete the last good one. 14 days is fixed for Cloud (DECIDED-004) and the default for self-hosted.
- **Restore only into a new database.** `restore` refuses an existing database, creates the target from `template0` and restores with `--single-transaction --exit-on-error --no-owner --no-privileges`. A partial restore is therefore impossible, and the failed target is dropped. A real recovery restores next to the live database and swaps the names (docs/BACKUP_RESTORE.md §6), so the old data stays until the operator drops it. `restore-storage` extracts into an empty directory unless `--force`.
- **Drill and verification:** `compare` counts the rows of every table in two databases exactly (the first defaults to the live database) (skipping `cache`, `cache_locks`, `sessions` and `jobs`, which change by themselves). The drill also checks `migrate:status` and the restored files. Referential integrity is checked by PostgreSQL while the constraints are re-created (DB-T12).
- **Health:** the `backup` service is healthy while its loop runs (a heartbeat touches its state file every minute) and the last backup succeeded, and unhealthy after a failure until the next success (a retry or the next daily run), so monitoring sees backup failures (ARCHITECTURE.md §11.2).
- **CI:** the Compose install job runs the drill on every PR (ADR-013's planned extension): a team export through the worker and its download, then backup, verify, wrong-passphrase refusal, encrypted-at-rest checks, restore into a fresh database, `compare`, `migrate:status`, refusal to restore over an existing database, file restore without exports, retention, and then the real restore of docs/BACKUP_RESTORE.md §6 (database swap, `TRUNCATE sessions, cache, cache_locks`, files back into the `app-storage` volume, a post-backup change gone, old sessions refused, sign-in working, the daemon turning healthy).
- **Cloud and offsite copies** are operator and Cloud-ops responsibilities. The repository cannot provision buckets, KMS keys or alerts; docs/BACKUP_RESTORE.md §3–§4 gives their exact configuration (private versioned bucket in a separate account, SSE-KMS, write-only uploader, 14-day lifecycle, alerts after 26 hours without a new set).

### 8. Limits and follow-ups

- **Export size:** the job runs within the worker's `--timeout=60` (below `DB_QUEUE_RETRY_AFTER=90`, ADR-015). Before comments and attachments make archives large, those tasks must measure exports of a realistic team and, if needed, give the job its own longer timeout on a dedicated queue with a matching `retry_after`, or split the build.
  - **Measured with the CORE-005 sections** (`TeamExportWriter::build` on a 2-vCPU development container, PostgreSQL 16 on the same host, 10 projects and 11 members): 10,000 tasks with 40,000 checklist items and 20,000 label links took **9.1 s** (2.8 MB archive); 50,000 tasks with 200,000 checklist items and 100,000 links took **33.3 s** (13.9 MB). Peak memory stayed at 46.5 MB in both (the sections stream). Build time grows linearly, so a single team stays inside the 60-second timeout up to roughly 80,000 tasks with checklists of this size. That is far beyond a small team (BRD), so the timeout stays; CORE-008 must re-measure with comments and attachments, which will dominate.
- **CORE-005:** added `labels`, `tasks`, `task_labels` and `task_checklist_items` (§2), excluding trashed tasks (§3). **CORE-008 and later:** add sections (§2). Attachments add a manifest section and the binary files under `files/` in the same archive, which streams files from their disk; with an attachment archive, `TeamExportWriter` should stop rewriting the zip for compression (PharData) and stream it instead.
- **CORE-006 (UI):** the team deletion screen offers "Export first" (DECIDED-011), starts an export and shows its progress and download. Until then the offer is in API_SPEC §3 and docs/SELF_HOSTING.md.
- **Backups:** public-key encryption, point-in-time recovery (WAL archiving) for Cloud, and an upload hook for offsite copies. GH-008 adds the upgrade and rollback rehearsal to the same CI job.

## Consequences

- New tables: `team_exports` (migration `2026_10_20_000200`). Beyond DATABASE.md §20's columns it has `format_version`, `size_bytes`, `sha256` and `completed_at` (all needed by the representation above), the in-flight partial unique index, and a check that a ready export has its file, size, checksum and completion time. `expires_at` is never null.
- New scheduled task (`exports:prune`, hourly), new rate limiter (`team-exports`), new disk (`exports`), new optional Compose service (`backup`).
- `.gitignore` and `.dockerignore` exclude `backups/`, `backup-passphrase` and export archives, so neither lands in git or the image.

## Open product questions (implemented conservatively)

1. Should an export include projects that are in the trash (the Owner can still recover them)? Implemented: no, because no read path shows them.
2. Should a new Owner see exports their predecessor requested? Implemented: no; only the requester reads or downloads an export.
3. Is 10 export requests per user per hour the right Cloud limit? Implemented: 10, configurable.
