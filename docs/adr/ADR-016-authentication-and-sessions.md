# ADR-016 — Authentication, sessions and the /api/v1 error contract

- **Status:** Accepted
- **Date:** 2026-10-16
- **Task:** FND-004 (#5)
- **Decision inputs:** API_SPEC.md §0, §1, §2, §13; ARCHITECTURE.md §5, §8 (ADR-005); DATABASE.md §3, §8; DECIDED-004; BRD FR-AUTH-001/002, NFR-SEC-002

## Context

FND-004 delivers sign-up, sign-in/out, email verification, password reset, sessions, CSRF and rate limits. API_SPEC defines the browser pages (`/login`, `/register`, …) as Inertia routes and the auth operations as `/api/v1` JSON endpoints that use same-origin session cookies (§0.3). It requires generic responses wherever email existence could leak (§2), the error envelope and codes (§1.2–1.3), and 429 `rate_limited` with `Retry-After` (§1.4, LIM-03).

## Decisions

### 1. JSON API on the session stack
`routes/api.php` is mounted at `/api/v1` with the `web` middleware group: cookie session, CSRF (`X-XSRF-TOKEN` from Laravel's encrypted `XSRF-TOKEN` cookie), `SetLocale` and the request limiter. No bearer or personal access tokens exist; no token is ever returned in JSON. Sanctum is not needed for same-origin use (ARCHITECTURE §8).

### 2. Error envelope for everything under /api/v1
`App\Exceptions\ApiExceptionRenderer` renders every exception on `api/*` as `{"error": {code, message, fields?, request_id}}`. The code comes from the status (§1.3 table). Two codes the table does not list are used: `method_not_allowed` (405) and `server_error` (500). Messages are translated (`lang/{ar,en}/api.php`), generic, and never contain exception text. `request_id` is the one assigned by `AssignRequestId` (ADR-015) and matches the `X-Request-Id` header.

`fields` holds **stable rule codes**, as in the §1.2 example (`{"name": ["required"]}`): Laravel's failed-rule names in snake_case (`required`, `email`, `min`, `max`, `in`, `timezone`). Three domain codes are added: `credentials` (failed sign-in), `invalid_token` (password reset) and `unknown_field` (PATCH with a field that cannot be changed). The UI maps codes to translated text (`<namespace>.errors.<field>.<code>`, else `validation.<code>`).

### 3. Registration does not reveal whether an email exists
`POST /api/v1/auth/register` always answers **202** `{"data":{"status":"verification_sent"}}` and **does not sign the user in**.

- **New email:** the user and the default `user_preferences` row are created, and a queued verification email is sent.
- **Existing email:** nothing is created; the owner gets a queued "someone tried to register with your address" email instead (not sent to disabled accounts).

Both paths hash the password, so their timing matches.

*Spec note:* §1.2 says creates return 201 with the resource, and §2 says registration creates the user. Returning the user or a session would reveal which emails exist, which conflicts with §2's "generic responses for email existence". Per §0.2 the stricter privacy behaviour wins. This should be reconciled in API_SPEC (specification defect, logged in the PR).

### 4. Sign-in, lockout and sign-out
- **Generic failures:** a wrong password, an unknown email and a disabled account all return 422 with `{"email": ["credentials"]}`.
- **Session fixation:** the session ID is regenerated on success. "Remember me" uses Laravel's remember cookie.
- **Per account+IP lockout:** 5 failed attempts per minute (`SN3_RATE_LIMIT_LOGIN_PER_MINUTE`), after which the response is 429 `rate_limited` with `Retry-After`.
- **Per IP cap:** 4× that limit across all emails, to slow credential stuffing.
- **Sign-out:** the session is invalidated (deleting its row under the database driver) and the CSRF token is regenerated.

### 5. Email verification link
The link is `GET /api/v1/auth/email/verify/{id}/{hash}`. `{hash}` is `sha256(email)` (the architecture preset forbids `sha1`), and the URL is a Laravel temporary signed URL (`expires` + `signature` query parameters, 60 minutes by default).

It works without an active session, because it is opened from a mail client. Browsers are redirected to `/login` (or `/` when already signed in) with a translated status; JSON clients get the envelope. A tampered, expired or mismatched link, or a non-existent user, are indistinguishable (403 `forbidden`, or the same redirect). Resending requires sign-in and is throttled per user.

### 6. Password reset
- **Request:** `forgot` always answers 202, whether the email is unknown, disabled or throttled by the broker.
- **Reset:** sets the password, rotates `remember_token` and **deletes every stored session** of the user. Revocation applies to the database session driver, the supported default; other drivers keep their sessions until expiry.
- **Bad input:** a bad token and an unknown email both return `{"token": ["invalid_token"]}`.
- **Token lifetime:** tokens expire after 60 minutes, and `auth:clear-resets` is scheduled daily.

### 7. Rate limits (DECIDED-004, LIM-03)
A named `requests` limiter is appended to the `web` group, after `StartSession`, so it covers Inertia pages and `/api/v1`:

- 120 requests/minute per user (per IP for guests);
- a 20/second burst cap;
- both configurable, and `0` disables a limit for self-hosting.

Health probes are outside the `web` group and are not limited. Auth endpoints have stricter named limiters (`auth-login`, `auth-register`, `auth-password`, `auth-verification`).

`SetLocale` is placed before `Authenticate` in the middleware priority list, so 401/403 messages are localized as well.

### 8. Identity schema
- **`users`:** follows DATABASE.md §3 now rather than waiting for FND-005, to avoid rewriting the table twice: UUIDv7 primary key (`HasUuids`), `name varchar(160)`, `locale` (check `ar|en`, default `ar`), `timezone` (default `UTC`), `avatar_path`, `disabled_at`, and `timestamptz` everywhere.
- **Email:** stored normalised (trimmed, lowercased). A plain unique index serves lookups, and a unique index on `lower(email)` enforces case-insensitive uniqueness.
- **`sessions.user_id`:** a UUID FK with cascade delete.
- **`user_preferences` (DATABASE.md §8):** created for `theme`; the notification flags are for CORE-009.

### 9. PostgreSQL session timezone pinned to UTC
Laravel writes timestamps without a zone. On a server whose `timezone` is not UTC, `timestamptz` columns stored wrong instants: in testing, password-reset tokens were already "expired" when created. The `pgsql` connection now sets `timezone => 'UTC'`, matching DATABASE.md §1 (store UTC).

### 10. Mail
All auth notifications implement `ShouldQueue`, following the ADR-015 convention, and render in the recipient's language through `HasLocalePreference`. The mail layout override (`resources/views/vendor/mail/html/layout.blade.php`) adds `lang`/`dir`, and the `sn3` mail theme uses logical text alignment, so Arabic mail is right-to-left.

### 11. Passwords
- **Length:** 12–128 characters, using plain `min`/`max` rules so the API codes stay specific.
- **Breach check:** optional (`SN3_PASSWORD_BREACH_CHECK`, off by default). When on, it uses the Have I Been Pwned k-anonymity range API. This is opt-in so that no third-party service is mandatory (API_SPEC §2).

### 12. Language switch for signed-in users
`PUT /locale` (FND-006) also saves `users.locale` when the user is signed in, because `SetLocale` gives the saved preference precedence over the session. Without this, the switcher would appear to do nothing for signed-in users.

## Consequences

- The UI submits forms with `axios` to `/api/v1` (`resources/js/lib/api.ts`, `useApiForm`). `axios` is now an explicit dependency, already present through Inertia.
- FND-005 can build teams on the UUID `users` table directly.
- "Verified email required to create/join a team" is enforced when those endpoints exist (FND-005/CORE-002). FND-004 provides the `verified` state and the `/verify-email` notice page.
- Open item for Omar: the 201-vs-202 registration wording in API_SPEC (§3 above).
