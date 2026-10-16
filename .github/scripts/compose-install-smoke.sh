#!/usr/bin/env bash
# Clean-host install check (DEVELOPMENT_PLAN.md R0 exit, ARCHITECTURE.md §12 deployment row).
#
# Follows docs/SELF_HOSTING.md steps 1–3 on a fresh checkout, then proves the stack works end to
# end: readiness, registration through the API with CSRF, the queued mail handled by the worker,
# sign-in, no secrets in the access log, and data surviving `down` / `up`. Then CORE-012: a team
# export built by the worker and downloaded, and the docs/BACKUP_RESTORE.md restore drill
# (encrypted backup, verify, restore into a fresh database, row counts, migrations, files,
# retention, wrong passphrase, the daily `backup` service turning healthy).
#
# CI only: it writes .env in the working tree and starts the default Compose project.
set -euo pipefail

if [ "${CI:-}" != "true" ]; then
  echo "This script rewrites .env and is meant for CI only." >&2
  exit 2
fi

BASE=http://127.0.0.1:8080
EMAIL="smoke.$(date +%s)@example.com"
PASSWORD="smoke-$(openssl rand -hex 12)"
SIGNATURE_PROBE="probe$(openssl rand -hex 16)"
EMAIL_PROBE="leak.$(openssl rand -hex 6)@example.com"
JAR="$(mktemp)"
BODY="$(mktemp)"

fail() {
  echo "::error::$1"
  docker compose ps || true
  docker compose logs --no-color --tail=80 || true
  exit 1
}

# --- 1. Configure ------------------------------------------------------------------------------
cp .env.example .env
DB_PASSWORD="$(openssl rand -hex 24)"
echo "::add-mask::$DB_PASSWORD"
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" .env
sed -i "s|^APP_URL=.*|APP_URL=$BASE|" .env

# --- 2. Build, create the app key, migrate -----------------------------------------------------
docker compose build --quiet
APP_KEY="$(docker compose run --rm --no-deps app php artisan key:generate --show | tr -d '\r' | grep '^base64:' | tail -n1)"
[ -n "$APP_KEY" ] || fail "key:generate --show printed no key"
echo "::add-mask::$APP_KEY"
sed -i "s|^APP_KEY=.*|APP_KEY=$APP_KEY|" .env
docker compose run --rm app php artisan migrate --force --no-interaction

# --- 3. Start -----------------------------------------------------------------------------------
docker compose up -d --wait --wait-timeout 180 || fail "the stack did not become healthy"
[ "$(curl -fsS "$BASE/health/ready")" = '{"status":"ready"}' ] || fail "/health/ready is not ready"

xsrf() {
  python3 -c 'import sys, urllib.parse
for line in open(sys.argv[1]):
    parts = line.rstrip("\n").split("\t")
    if len(parts) == 7 and parts[5] == "XSRF-TOKEN":
        print(urllib.parse.unquote(parts[6]))' "$JAR"
}

api_post() { # path, json body, [extra header] → prints the HTTP status; the body is in $BODY
  curl -sS -o "$BODY" -w '%{http_code}' -b "$JAR" -c "$JAR" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -H "X-XSRF-TOKEN: $(xsrf)" \
    ${3:+-H "$3"} -X POST "$BASE$1" --data "$2"
}

json_field() { # dotted path → value from $BODY
  python3 -c 'import json, sys
value = json.load(open(sys.argv[1]))
for key in sys.argv[2].split("."):
    value = value[key]
print(value)' "$BODY" "$1"
}

sign_in() {
  rm -f "$JAR"
  curl -fsS -o /dev/null -b "$JAR" -c "$JAR" "$BASE/login"
  api_post /api/v1/auth/login "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}"
}

# --- Registration, queued mail, sign-in --------------------------------------------------------
curl -fsS -o /dev/null -b "$JAR" -c "$JAR" "$BASE/register"
status="$(api_post /api/v1/auth/register "{\"name\":\"Smoke Test\",\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}")"
[ "$status" = 202 ] || fail "registration answered $status, expected 202"

for attempt in $(seq 1 30); do
  pending="$(docker compose exec -T postgres psql -U sn3 -d sn3 -tAc 'select count(*) from jobs')"
  [ "$pending" = 0 ] && break
  [ "$attempt" = 30 ] && fail "the worker did not process the verification mail"
  sleep 2
done
worker_logs="$(docker compose logs --no-color worker)"
grep -q 'Mail was not delivered' <<< "$worker_logs" || fail "no mail warning from the worker"
failed="$(docker compose exec -T postgres psql -U sn3 -d sn3 -tAc 'select count(*) from failed_jobs')"
[ "$failed" = 0 ] || fail "$failed failed job(s)"

status="$(sign_in)"
[ "$status" = 200 ] || fail "sign-in answered $status, expected 200"
me="$(curl -fsS -b "$JAR" -H 'Accept: application/json' "$BASE/api/v1/me")"
grep -q "\"email\":\"$EMAIL\"" <<< "$me" || fail "/api/v1/me does not return the new user"

# --- No secrets in the access log --------------------------------------------------------------
curl -sS -o /dev/null "$BASE/api/v1/auth/email/verify/x/y?expires=1&signature=$SIGNATURE_PROBE"
curl -sS -o /dev/null "$BASE/login?email=$EMAIL_PROBE"
curl -sS -o /dev/null -H "Referer: $BASE/reset?signature=$SIGNATURE_PROBE" "$BASE/login"
sleep 1
logs="$(docker compose logs --no-color app)"
# Positive control: the access lines are in this log, with the path but not the query string.
grep -q '"GET /login HTTP/1.1" 200' <<< "$logs" || fail "no access-log line for GET /login in the app log"
if grep -q -e "$SIGNATURE_PROBE" -e "$EMAIL_PROBE" -e "$EMAIL" <<< "$logs"; then
  fail "a signature or an email address reached the app log"
fi

# --- Data survives a restart -------------------------------------------------------------------
docker compose down
docker compose up -d --wait --wait-timeout 180 || fail "the stack did not come back after down/up"
status="$(sign_in)"
[ "$status" = 200 ] || fail "sign-in after restart answered $status, expected 200"

# --- Team export through the worker (CORE-012) ------------------------------------------------
psql_sn3() { docker compose exec -T postgres psql -U sn3 -d sn3 -tAc "$1"; }
psql_sn3 "update users set email_verified_at = now() where email = '$EMAIL'" > /dev/null

status="$(api_post /api/v1/teams '{"name":"Smoke team","slug":"smoke-team"}')"
[ "$status" = 201 ] || fail "team creation answered $status, expected 201"
TEAM_ID="$(json_field data.id)"
status="$(api_post "/api/v1/teams/$TEAM_ID/projects" '{"name":"Secret plan","key":"SEC","visibility":"private"}')"
[ "$status" = 201 ] || fail "project creation answered $status, expected 201"

status="$(api_post "/api/v1/teams/$TEAM_ID/exports" '{}' "Idempotency-Key: $(python3 -c 'import uuid; print(uuid.uuid4())')")"
[ "$status" = 202 ] || fail "export request answered $status, expected 202"
EXPORT_ID="$(json_field data.id)"

for attempt in $(seq 1 30); do
  curl -fsS -o "$BODY" -b "$JAR" -H 'Accept: application/json' "$BASE/api/v1/teams/$TEAM_ID/exports/$EXPORT_ID"
  export_status="$(json_field data.status)"
  [ "$export_status" = ready ] && break
  [ "$export_status" = failed ] && fail "the worker failed the export"
  [ "$attempt" = 30 ] && fail "the export was not ready after 60 seconds (status $export_status)"
  sleep 2
done

curl -fsS -o export.zip -b "$JAR" "$(json_field data.download_url)" || fail "the export download failed"
python3 - "$EMAIL" <<'PY' || fail "the export archive is not as expected"
import json, sys, zipfile
archive = zipfile.ZipFile("export.zip")
names = archive.namelist()
assert names[0] == "manifest.json", names
manifest = json.loads(archive.read("manifest.json"))
assert manifest["format"] == "sn3.team-export" and manifest["format_version"] == 1, manifest
for section in manifest["sections"]:
    assert section["file"] in names, section
projects = json.loads(archive.read("data/projects.json"))
assert [p["key"] for p in projects] == ["SEC"], projects
content = b"".join(archive.read(name) for name in names)
assert sys.argv[1].encode() in content, "the member email is missing"
assert b"$2y$" not in content, "a password hash reached the export"
PY
[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/api/v1/teams/$TEAM_ID/exports/$EXPORT_ID/download")" = 403 ] \
  || fail "an unsigned export download was not refused"

# --- Encrypted backup and restore drill (CORE-012, docs/BACKUP_RESTORE.md) ----------------------
openssl rand -base64 32 > backup-passphrase
mkdir -p backups
backup() { docker compose --profile backup run --rm -T backup "$@"; }
in_backup() { docker compose --profile backup run --rm -T --entrypoint bash backup -c "$1"; }

docker compose exec -T app sh -c 'mkdir -p storage/app/private/smoke && echo marker > storage/app/private/smoke/marker.txt'

backup run || fail "the backup failed"
backup list
backup verify latest || fail "the backup does not verify"

# Encrypted at rest: no readable dump, no plaintext email, and a wrong passphrase cannot open it.
in_backup '! pg_restore --list /backups/*.db.dump.gpg > /dev/null 2>&1' || fail "the dump is readable without decryption"
in_backup "! grep -q '$EMAIL' /backups/*.gpg" || fail "an email address is readable in the backup"
openssl rand -base64 32 > wrong-passphrase
if SN3_BACKUP_PASSPHRASE_HOST_FILE=./wrong-passphrase backup verify latest; then
  fail "the backup opened with a wrong passphrase"
fi

# Restore into a fresh database and compare it with the live one.
backup restore latest --database sn3_restore_drill || fail "the restore failed"
backup compare sn3 sn3_restore_drill || fail "the restored database differs from the live one"
migrations="$(docker compose run --rm --no-deps -T -e DB_DATABASE=sn3_restore_drill app php artisan migrate:status)"
grep -q 'Ran' <<< "$migrations" || fail "migrate:status shows no migrations in the restored database"
if grep -q 'Pending' <<< "$migrations"; then fail "the restored database has pending migrations"; fi
# Restoring over an existing database is refused.
if backup restore latest --database sn3_restore_drill; then fail "a restore over an existing database was not refused"; fi

# Files: the storage archive restores, without export archives.
in_backup 'mkdir /tmp/restore && bash /opt/sn3-backup/sn3-backup restore-storage latest --into /tmp/restore \
  && grep -qx marker /tmp/restore/private/smoke/marker.txt && test ! -e /tmp/restore/exports' \
  || fail "the file storage did not restore as expected"

# Retention: sets older than 14 days go, the newest stays.
# shellcheck disable=SC2016 # expanded inside the container
in_backup 'cd /backups && for s in sn3-20200101T000000Z sn3-20200102T000000Z; do echo x > $s.db.dump.gpg && sha256sum $s.db.dump.gpg > $s.sha256; done'
backup prune
in_backup 'test ! -e /backups/sn3-20200101T000000Z.sha256 && test ! -e /backups/sn3-20200102T000000Z.db.dump.gpg && ls /backups/sn3-*.sha256 | grep -q .' \
  || fail "retention did not delete the old sets or kept nothing"

# The daily service starts and reports healthy.
docker compose --profile backup up -d --wait --wait-timeout 120 backup || fail "the backup service did not become healthy"
docker compose --profile backup stop backup

docker compose --profile backup ps
echo "Clean install verified: build, key, migrate, up --wait, register, queued mail, sign-in, log hygiene, restart, team export, encrypted backup, restore drill, retention."
