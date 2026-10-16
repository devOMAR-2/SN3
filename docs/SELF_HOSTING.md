# Self-hosting SN3 with Docker Compose

> Scope: FND-002 (R0). This covers installing and starting the application and PostgreSQL, and putting HTTPS in front with an example reverse proxy. The queue worker and scheduler (FND-007), backups and restore (CORE-012), and upgrade guides (GH-008) are added by their own tasks.

Self-hosted SN3 has the same features as Cloud. Nothing here needs a proprietary or paid service.

## Requirements

- Linux host (amd64 or arm64) with Docker Engine 24+ and the Docker Compose v2 plugin
- About 1 GB RAM free for the application and PostgreSQL
- To use the HTTPS proxy example: a DNS name pointing at the host, with ports 80 and 443 reachable

## What runs

| Service | Image | Purpose | Published |
|---|---|---|---|
| `app` | built from `deploy/docker/Dockerfile` | Laravel + Apache (mod_php) on port 8080, runs as `www-data` | `127.0.0.1:8080` by default |
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
| `SN3_DOMAIN` | The public hostname, if you use the Caddy proxy |
| `MAIL_*` | Your SMTP server. Until it is set, mail is written to the log |

Compose forces `APP_ENV=production`, `APP_DEBUG=false`, the database host and logging to stderr, so the `.env.example` development values for those keys don't apply inside the containers.

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

`--wait` returns once PostgreSQL is healthy and the app's readiness probe passes. Open `APP_URL`. For a local trial that is `http://127.0.0.1:8080`.

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

## Day-to-day commands

```bash
docker compose ps                                  # status and health
docker compose logs -f app                         # application + Apache logs
docker compose exec app php artisan about          # runtime summary
docker compose down                                # stop (data volumes are kept)
```

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `Set DB_PASSWORD in .env` | `DB_PASSWORD` is empty in `.env`. |
| `sn3: APP_KEY is not set` in `docker compose logs app` | Run step 2's `key:generate --show` and put the value in `.env`. |
| App healthy but pages return 500 on first visit | Migrations were not run. See step 2. |
| `password authentication failed` after changing `DB_PASSWORD` | PostgreSQL keeps the password it was first initialised with. Change it inside the database (`ALTER ROLE`), or recreate the volume (destroys data). |
