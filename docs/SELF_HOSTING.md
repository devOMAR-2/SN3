# Self-hosting SN3 with Docker Compose

> Scope: FND-002 and FND-007 (R0). This covers installing and starting the application, queue worker, scheduler and PostgreSQL, logs and mail, and putting HTTPS in front with an example reverse proxy. Backups and restore (CORE-012) and upgrade guides (GH-008) are added by their own tasks.

Self-hosted SN3 has the same features as Cloud. Nothing here needs a proprietary or paid service.

## Requirements

- Linux host (amd64 or arm64) with Docker Engine 24+ and the Docker Compose v2 plugin
- About 1 GB RAM free for the application and PostgreSQL
- Using your own PostgreSQL 16+ instead of the bundled one: the database user must own the database (or have `CREATE` on it) so migrations can enable the bundled `citext` extension
- To use the HTTPS proxy example: a DNS name pointing at the host, with ports 80 and 443 reachable

## What runs

| Service | Image | Purpose | Published |
|---|---|---|---|
| `app` | built from `deploy/docker/Dockerfile` | Laravel + Apache (mod_php) on port 8080, runs as `www-data` | `127.0.0.1:8080` by default |
| `worker` | same image (`sn3-app:local`) | Queue worker (`php artisan queue:work`): mail and other background jobs | not published |
| `scheduler` | same image | Scheduler (`php artisan schedule:work`): periodic maintenance | not published |
| `postgres` | `postgres:16.15-trixie` | Database | not published |
| `proxy` (optional, profile `proxy`) | `caddy:2.11.7-alpine` | HTTPS reverse proxy with automatic certificates | `80`, `443` |

Persistent named volumes:

| Volume | Holds |
|---|---|
| `sn3_postgres-data` | PostgreSQL data directory |
| `sn3_app-storage` | `storage/app` (private files) |
| `sn3_caddy-data`, `sn3_caddy-config` | Caddy certificates and state (proxy only) |

`docker compose down` keeps the volumes. **`docker compose down -v` deletes all data.**

## 1. Configure

```bash
git clone https://github.com/devOMAR-2/SN3.git sn3 && cd sn3
cp .env.example .env
```

Edit `.env`:

| Variable | Set to |
|---|---|
| `DB_PASSWORD` | A long random password. **Required.** Compose refuses to start without it. Example: `openssl rand -base64 24` |
| `APP_URL` | The public URL, e.g. `https://pm.example.com` (or `http://127.0.0.1:8080` for a local trial) |
| `SESSION_SECURE_COOKIE` | `true` when served over HTTPS |
| `SN3_DOMAIN` | The public hostname, if you use the Caddy proxy (`SN3_PROXY_HTTP_PORT`/`SN3_PROXY_HTTPS_PORT` default to 80/443) |
| `MAIL_*` | Your SMTP server, with `MAIL_MAILER=smtp`. Until then mail is not delivered. See [Mail](#mail) |

Compose forces `APP_ENV=production`, `APP_DEBUG=false`, the database host, JSON logging to stderr and `MAIL_LOG_BODY=false`, so the `.env.example` development values for those keys don't apply inside the containers. The `app`, `worker` and `scheduler` services share the same image, `.env` and settings.

## 2. Build, create the app key, migrate

```bash
docker compose build
docker compose run --rm --no-deps app php artisan key:generate --show
# copy the printed base64:... value into APP_KEY= in .env
docker compose run --rm app php artisan migrate --force
```

Migrations never run automatically. Run the `migrate` command after every upgrade, before starting the new version.

## 3. Start

```bash
docker compose up -d --wait
```

`--wait` returns once PostgreSQL is healthy, the app's readiness probe passes, and the worker and scheduler report healthy. Open `APP_URL`. For a local trial that is `http://127.0.0.1:8080`.

### With the HTTPS proxy example

```bash
docker compose --profile proxy up -d --wait
```

Caddy terminates TLS for `SN3_DOMAIN` and forwards to `app:8080`. It obtains and renews the certificate automatically once DNS points at the host. With `SN3_DOMAIN=localhost`, Caddy uses its own local CA, so browsers show a certificate warning.

To use your own proxy (nginx, Traefik, a load balancer), skip the profile and forward to `127.0.0.1:${SN3_HTTP_PORT}`. The proxy must send `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host`. If your proxy runs on another host, set `SN3_HTTP_BIND` to an interface that proxy can reach, and set `TRUSTED_PROXIES` to exactly that proxy's address.

**Security:** the app trusts `X-Forwarded-*` only from `TRUSTED_PROXIES`. Under Compose, the default is the private ranges (`10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`). That is safe while the app port is bound to `127.0.0.1` (the default). If you publish the app port on a LAN or public interface, set `TRUSTED_PROXIES` to your proxy's exact IP. Otherwise LAN clients could spoof their IP address and scheme.

## Health and readiness

| Endpoint | Meaning |
|---|---|
| `GET /health/live` | `200 {"status":"ok"}` while the PHP process serves requests. Used by the image `HEALTHCHECK`. |
| `GET /health/ready` | `200 {"status":"ready"}` when the database answers and storage is writable. Otherwise `503 {"status":"unavailable"}`. Used by Compose and the proxy dependency. |

Responses never include dependency details. Failure reasons go to the container log (`docker compose logs app`). The probes don't start sessions or set cookies.

The `worker` and `scheduler` containers don't run Apache, so Compose replaces the image's HTTP healthcheck with a heartbeat check: each process touches a file in `storage/framework/` while its loop runs (the worker at least every few seconds when idle and after every job, the scheduler every minute), and the container turns `unhealthy` when the file is older than 3 minutes.

## Background jobs and the scheduler

Jobs use the PostgreSQL-backed queue (`QUEUE_CONNECTION=database`, the default). Redis is not needed and nothing connects to it by default.

The `worker` runs `queue:work --tries=3 --backoff=10,60,300 --timeout=60 --max-time=3600 --json`:

- A job that throws is retried after 10 s and then 60 s. After the third failed attempt it moves to the `failed_jobs` table, where it stays visible until you retry or delete it (pruned after 30 days).
- A job running longer than 60 s is stopped and counts as a failed attempt. `DB_QUEUE_RETRY_AFTER` (90 s) must stay above that timeout, so a job is never handed to a second worker while the first is still running it.
- `docker compose stop worker` (or a restart or upgrade) sends SIGTERM: the worker finishes the job in progress, then exits. Compose waits up to 75 s.
- The worker exits after an hour (`--max-time`) and Compose starts a fresh one, which keeps memory use flat.

The `scheduler` runs `schedule:work --whisper`, which evaluates `routes/console.php` every minute. Current tasks:

| Task | When |
|---|---|
| `queue:prune-failed --hours=720` (delete failed jobs older than 30 days) | daily 03:00 UTC |
| `queue:prune-batches --hours=48 --unfinished=72 --cancelled=72` | daily 03:10 UTC |

Run exactly one `scheduler`. Tasks also take a lock in the database cache (`onOneServer`), so an accidental second scheduler doesn't run them twice. You can run more than one `worker` (`docker compose up -d --scale worker=2`).

### Failed jobs

```bash
docker compose exec worker php artisan queue:failed               # list (ID, connection, queue, class, failed at)
docker compose exec worker php artisan queue:retry <uuid>         # put one back on the queue
docker compose exec worker php artisan queue:retry all            # retry everything, e.g. after fixing SMTP
docker compose exec worker php artisan queue:forget <uuid>        # delete one
docker compose exec worker php artisan queue:flush                # delete all failed jobs
docker compose exec scheduler php artisan schedule:list           # scheduled tasks and next run
```

The failure reason is in the worker log (`docker compose logs worker`) and in the `exception` column of `failed_jobs`.

### Redis (optional)

The image ships without a Redis client extension, and SN3 needs none. Running the queue, cache or sessions on Redis would need a custom image with the `redis` PHP extension plus `QUEUE_CONNECTION=redis` (and/or `CACHE_STORE`, `SESSION_DRIVER`) and the `REDIS_*` settings. That setup is not tested or supported yet.

## Logs

The application writes one JSON object per line to stderr (`docker compose logs`). Each line has `message`, `level_name`, `datetime`, `context` and `extra`. The worker also prints one JSON status line per job on stdout (`job`, `uuid`, `status`, `attempts`). Apache access lines in the `app` log and the start-up `optimize` output stay plain text:

- `extra.request_id`: the correlation ID of the HTTP request. It is also returned in the `X-Request-Id` response header. If your proxy sends an `X-Request-Id` of 8–64 letters, digits, `.`, `_` or `-`, it is reused; anything else is replaced with a generated `req_…` ID.
- `extra.job_id`, `extra.job_name`, `extra.job_attempt`: set while the worker runs a job. A job dispatched during a request also carries that request's `request_id`.

Secrets are redacted before anything is written: values under keys such as `password`, `token`, `secret`, `authorization`, `cookie`, `api_key` or `signature` (at any depth), and those query parameters inside URLs, become `[REDACTED]`.

Docker keeps container logs with its configured log driver. Rotate them and keep no more than the 30-day operational retention, for example with the `json-file` driver's `max-size`/`max-file` options or your log collector. For installs outside Docker, set `LOG_STACK=daily`. Files then rotate daily and are kept `LOG_DAILY_DAYS` (30) days.

## Mail

User-facing mail (sign-in verification, password reset, invitations) is always sent by the worker, never during the web request. An unreachable or misconfigured SMTP server therefore never breaks a page. The job is retried and then lands in `failed_jobs`, from which you can retry it once SMTP works.

| `MAIL_MAILER` | Behaviour |
|---|---|
| `log` (default) | Nothing is delivered. Each message produces a warning in the log ("Mail was not delivered…") with its message ID only. Bodies, addresses and links are never logged in production. |
| `smtp` | Delivers through `MAIL_HOST`/`MAIL_PORT` (`MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD`). A connection attempt gives up after `MAIL_TIMEOUT` (10) seconds. Failures are retried as above. **Recommended once you have SMTP.** |
| `failover` | Tries `smtp`, and if that fails, falls back to `log`. The job then succeeds, but the message is **not delivered** and cannot be retried. Use it only if you prefer losing a message to keeping it in `failed_jobs`. |

Without working SMTP, people can't receive verification, password-reset or invitation emails. SN3 doesn't pretend otherwise.

To check SMTP settings, send a test message from the worker (it uses the same configuration):

```bash
docker compose exec worker php artisan tinker --execute="Mail::raw('SN3 SMTP test', fn (\$m) => \$m->to('you@example.com')->subject('SN3 SMTP test'));"
```

An error here shows the SMTP problem directly. Use an address you control.

## Accounts, sign-in and limits

- **Registration** is open by default. Set `SN3_REGISTRATION_ENABLED=false` to close public sign-up; existing users can still sign in and reset passwords. Invitations (CORE-002) will remain the way to add people.
- **Email is required for account flows.** Verification and password-reset links are sent by the queue worker. Configure SMTP (see [Mail](#mail)) before inviting users; with the default `log` mailer no one receives the links.
- **HTTPS:** set `SESSION_SECURE_COOKIE=true` and an `https://` `APP_URL`. Signed verification links are checked against the URL the browser used, so `APP_URL`, the proxy's `X-Forwarded-*` headers and `TRUSTED_PROXIES` must agree.
- **Rate limits** (`SN3_RATE_LIMIT_*`): 120 requests/minute per signed-in user with a 20/second burst cap, plus stricter limits on sign-in, registration, password reset and verification. A self-hosted operator can raise them or set a limit to `0` to disable it; features never change.
- **Sessions** live in the `sessions` table (`SESSION_DRIVER=database`). Signing out deletes the session, and a password reset revokes every stored session of that user.

## Day-to-day commands

```bash
docker compose ps                                  # status and health
docker compose logs -f app                         # application + Apache logs
docker compose logs -f worker scheduler            # background jobs and scheduled tasks
docker compose exec app php artisan about          # runtime summary
docker compose down                                # stop (data volumes are kept)
```

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `Set DB_PASSWORD in .env` | `DB_PASSWORD` is empty in `.env`. |
| `sn3: APP_KEY is not set` in `docker compose logs app` | Run step 2's `key:generate --show` and put the value in `.env`. |
| App healthy but pages return 500 on first visit | Migrations were not run. See step 2. |
| `worker` or `scheduler` is `unhealthy` | Check `docker compose logs worker` (or `scheduler`). Usually the database is unreachable, migrations were not run, or `APP_KEY` is missing. |
| Mail never arrives | `MAIL_MAILER` is still `log` (look for "Mail was not delivered" in `docker compose logs worker`), or SMTP fails (`docker compose exec worker php artisan queue:failed`). |
| `permission denied to create extension "citext"` during `migrate` | Only with an external PostgreSQL: let the application's role own the database, or have an administrator run `CREATE EXTENSION citext;` in it once. |
| `password authentication failed` after changing `DB_PASSWORD` | PostgreSQL keeps the password it was first initialised with. Change it inside the database (`ALTER ROLE`), or recreate the volume (destroys data). |
