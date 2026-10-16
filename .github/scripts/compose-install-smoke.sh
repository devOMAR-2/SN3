#!/usr/bin/env bash
# Clean-host install check (DEVELOPMENT_PLAN.md R0 exit, ARCHITECTURE.md §12 deployment row).
#
# Follows docs/SELF_HOSTING.md steps 1–3 on a fresh checkout, then proves the stack works end to
# end: readiness, registration through the API with CSRF, the queued mail handled by the worker,
# sign-in, no secrets in the access log, and data surviving `down` / `up`.
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

api_post() { # path, json body → prints the HTTP status
  curl -sS -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -H "X-XSRF-TOKEN: $(xsrf)" \
    -X POST "$BASE$1" --data "$2"
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

docker compose ps
echo "Clean install verified: build, key, migrate, up --wait, register, queued mail, sign-in, log hygiene, restart."
