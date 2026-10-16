# ADR-015 — Runtime operations: queue, scheduler, structured logs and mail

- **Status:** Accepted
- **Date:** 2026-10-16
- **Task:** FND-007 (#8)
- **Decision inputs:** ARCHITECTURE.md §7, §11.1 and §11.2; AGENTS.md §5 "Async" and §6.8; API_SPEC.md §1 (`request_id`) and §13; DECIDED-004 (operational logs retained 30 days); ADR-012 (container runtime)

## Context

FND-002 shipped the web container only. Jobs could be queued but nothing processed them, logs were plain text, and the default `log` mailer wrote whole messages, including sign-in and reset links, to the log. FND-007 has to make jobs work without Redis and make a missing or broken SMTP server harmless to normal use.

## Decision

### Queue

1. **PostgreSQL is the queue** (`QUEUE_CONNECTION=database`, tables from the skeleton migration). Redis stays optional and unused by default. The image ships no Redis extension, so nothing can try to connect at boot.
2. **Worker defaults** (`docker-compose.yml` `worker` command): `--tries=3 --backoff=10,60,300 --timeout=60 --sleep=3 --max-time=3600 --json`. `--json` makes the per-job status lines on stdout JSON too.
   - `--timeout` (60 s) is below the connection's `retry_after` (`DB_QUEUE_RETRY_AFTER`, 90 s), so a slow job is killed before another worker could pick up the same row. `MAIL_TIMEOUT` (10 s) keeps an unreachable SMTP server well inside that budget.
   - Only the first two backoff values are used with three tries. The third is there for jobs that raise their own `$tries`.
   - `--max-time` recycles the process hourly; `restart: unless-stopped` starts a new one.
   - A job class may set its own `$tries`, `$backoff` and `$timeout`, which override these defaults.
3. **Failures stay visible:** after the last attempt a job goes to `failed_jobs` (`database-uuids` driver). Operators use `queue:failed` / `queue:retry` / `queue:forget` (documented in `docs/SELF_HOSTING.md`). The failed-job and batch connection default now falls back to `pgsql` instead of the framework's `sqlite`.
4. **`pcntl`** is added to the image so `queue:work` handles SIGTERM (finish the current job, then exit) and enforces `--timeout`. The extension is also loaded under mod_php, where nothing calls it. The base image's `STOPSIGNAL` is Apache's `SIGWINCH`, which the worker ignores. Docker would then kill it after the grace period (exit 137, verified), so Compose sets `stop_signal: SIGTERM` for the worker and the scheduler, `stop_grace_period: 75s` (more than `--timeout`) and `init: true`.

### Scheduler

5. **`schedule:work` as its own service**, same image. `routes/console.php` contains only what is needed now: `queue:prune-failed --hours=720` (30 days, matching the operational retention of DECIDED-004) and `queue:prune-batches`, daily, with `onOneServer()` (database cache lock) so an accidental second scheduler or a later replicated Cloud deployment runs each slot once. Domain jobs (trash purge, exports, GitHub reconciliation) are added by their own tasks.
6. **Health without Apache:** the image `HEALTHCHECK` curls Apache, which isn't running in these containers, so Compose overrides it. The worker touches `storage/framework/queue-worker.heartbeat` on every loop (`Looping` event), and the scheduler touches `scheduler.heartbeat` when `schedule:work` starts and after every `schedule:run` (console events). The healthcheck fails when the file is older than 3 minutes. That proves the loop is alive, unlike checking for the process. `storage/framework` is per-container, so replicas don't share heartbeats. `init: true` matters for the scheduler: `schedule:work` installs no signal handler, and as PID 1 it would ignore SIGTERM until killed.
7. **Entrypoint:** the worker and scheduler get the same `APP_KEY` check and `php artisan optimize` as Apache. `optimize` writes only to `bootstrap/cache` and `storage/framework`, which are in each container's own filesystem (only `storage/app` is a shared volume), so several containers starting at once cannot race. Migrations remain manual.

### Structured logs

8. **JSON lines on stderr in containers** (`LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`, pinned in Compose, with stack traces as `file:line` frames only). Local development keeps the readable `stack`/`single` default. File logs outside Docker use the `daily` channel, now kept 30 days by default (DECIDED-004).
9. **Request IDs:** `AssignRequestId` is **global middleware, prepended**, so health probes, 404s and error responses are correlated, not just `web` routes. A future `api` group gets it without another edit. An incoming `X-Request-Id` is reused only if it matches `\A[A-Za-z0-9][A-Za-z0-9._-]{7,63}\z`. That accepts proxy UUIDs and ULIDs, and rejects CR/LF, quotes, markup and oversize values that could forge or break log lines. Otherwise the middleware generates `req_<ULID>`, following the `req_01J…` form of API_SPEC.md §1. A UUID would work too, but this keeps logs and future API envelopes consistent. The ID goes into the response header, the `request_id` request attribute (for the future API `meta.request_id`) and Laravel `Context`. It is a correlation aid only: a client can choose its own value, so it never drives authorization or rate limiting.
10. **Job IDs:** Laravel already serializes `Context` into job payloads and restores it, flushing previous data, on `JobProcessing`. A listener then adds `job_id` (payload UUID, the same ID as in `failed_jobs`), `job_name` and `job_attempt`. A job therefore logs both its own ID and the `request_id` of the request that dispatched it. Context is serialized into the jobs table, so it must hold correlation IDs only, never secrets or private content.
11. **Redaction for every channel:** Laravel pushes the bound `Illuminate\Contracts\Log\ContextLogProcessor` onto every channel it creates (stacks included) and runs it first. SN3 binds `ScrubbedContextLogProcessor`, which adds Context data and then applies `RedactSensitiveData`. That processor replaces values under sensitive keys, recursively in `context` and `extra`, and sensitive query parameters inside the message and string values. Keys are matched case-insensitively with separators removed, on the fragments `password`, `passwd`, `secret`, `token`, `authorization`, `cookie`, `signature`, `apikey`, `privatekey` and `credential`. Because it runs before `PsrLogMessageProcessor`, a `{password}` placeholder can't copy a secret into the message. This is a safety net only. Code must still not log request bodies, private task content or unnecessary personal data (AGENTS.md §6.8). Rejected alternatives: a per-channel `tap` or `processors` entry, which every new channel would have to remember, and a custom formatter, which wouldn't cover non-JSON channels.

### Mail

12. **All user-facing mail is queued** (`ShouldQueue` notifications or mailables). The web request only inserts a `jobs` row, so an unreachable SMTP server can't 500 a page. SMTP failures are retried and then land in `failed_jobs`, where the operator can retry them after fixing SMTP. FND-004 and later tasks must follow this convention.
13. **Mailers:** `MAIL_MAILER=log` remains the default. `smtp` is recommended in production. `failover` (smtp, then log) is configured and documented as an opt-in: it never fails a job, but a message that falls back to the log is lost. We prefer recoverable failures for verification and reset mail, so failover is not the default.
14. **The `log` transport no longer logs bodies outside local development.** Laravel's `LogTransport` writes the whole message, and SN3 messages contain verification, reset and invitation links that API_SPEC.md §13 forbids in logs. `MailManager::extend('log')` swaps in `MetadataLogTransport` unless `mail.mailers.log.include_body` is true. That flag (`MAIL_LOG_BODY`) defaults to `APP_ENV === 'local'`, and Compose pins it to `false`. The replacement logs a warning ("Mail was not delivered…") with the message ID and recipient count, so an operator without SMTP sees the problem instead of a silent success (ARCHITECTURE.md §11.1). Even full local bodies pass through redaction, so `signature=`/`token=` parameters are masked. To click links during development, use a local SMTP catcher (e.g. Mailpit) with `MAIL_MAILER=smtp`.
15. No `mail:test` command. The documented `tinker` one-liner covers the operator need without new code or translated strings.

## Consequences

- `docker compose up -d --wait` now starts and waits for `app`, `worker`, `scheduler` and `postgres`. `worker` and `scheduler` reuse `sn3-app:local` (`pull_policy: never`), so `docker compose build` must run first, as already documented.
- New environment variables, documented in `.env.example`: `LOG_DAILY_DAYS`, `LOG_STDERR_FORMATTER`, `DB_QUEUE_RETRY_AFTER`, `MAIL_TIMEOUT`, `MAIL_LOG_BODY`.
- Docker's own log retention is the operator's responsibility (documented). The application can't prune `docker logs`.
- `QueryException` messages include SQL bindings (framework behaviour). Redaction can't recognize a secret inside a bound SQL value. Code must not let exceptions carrying credentials reach logs unhandled.
- Redis queue/cache/session support needs an image with the `redis` extension. It is documented as not yet supported, and a later task can add it without changing application code.
