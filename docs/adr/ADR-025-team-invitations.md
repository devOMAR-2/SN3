# ADR-025 — Team invitations: email and share links

- **Status:** Accepted
- **Date:** 2026-10-17
- **Task:** CORE-002 (#11)
- **Decision inputs:** BRD FR-TEAM-002, FR-TEAM-003, UC-002, DEC-013, §9 "abuse of invitation links"; DECIDED-007; API_SPEC.md §1.3, §1.4, §2, §3, §3.1, §13, §18; DATABASE.md §0 (rules 4 and 6), §3, §11 "Invite acceptance", §16 DB-T07; ARCHITECTURE.md §8, §9 (invitation tokens), §11; DESIGN_SYSTEM.md §6 "Invite UI"; ACCEPTANCE_SCENARIOS INV-01, INV-02, INV-03, AUTH-04; ADR-017, ADR-018, ADR-022, ADR-024

## Context

The specs fix the invitation model (DECIDED-007, DATABASE.md §3 FINAL): an invitation is bound to one email address (one use) or is a share link (up to 20 uses); both expire after 7 days and can be revoked; accepting needs a signed-in account with a verified email, and an email invitation needs that email to match. An Admin may invite Members and Viewers; only the Owner may send an email invitation for an Admin; no invitation grants ownership; redeeming never grants a project membership. Tokens are random, shown once, stored hashed and never logged. API_SPEC §3.1 lists five endpoints; §1.4 asks for `Idempotency-Key` on invitation creation and stricter rate limits for invitations.

This ADR settles what the specs leave open. Where they are silent it takes the most conservative option.

## Decisions

### 1. Schema (`team_invitations`)

Migration `2026_10_21_000100` follows DATABASE.md §3, with the FINAL rules enforced by CHECK constraints so no code path can break them:

- `invited_role` in `admin`, `member`, `viewer`; Admin only with an email.
- `max_uses` is 1 with an email and 20 without (technical correction: DATABASE.md lists `max_uses int NULL`; the FINAL rule always sets it, so the column is NOT NULL).
- `0 <= use_count <= max_uses`, and `accepted_at` is set exactly when `use_count = max_uses` (for an email invitation, the redemption time; for a link, when its last use is taken).
- `token_hash` is 32 bytes (SHA-256) and unique; `email` is stored trimmed and lower-cased (citext, like `users.email`); `expires_at > created_at`.
- `team_id` cascades (ADR-022 §5; the recursive guard in `TrashPurgeTest` covers the table), so a purged team takes its invitations. `invited_by` cascades too: an invitation never outlives the account that issued it.
- Partial index `(team_id, email) WHERE email IS NOT NULL` for the duplicate check below.

### 2. Tokens

- 32 bytes from the OS CSPRNG, written as unpadded base64url (43 characters). Only `sha256(token)` is stored. Malformed tokens never reach the controller (route constraint: 404).
- The create response returns the token and the link `/invite#<token>` once. Both email and link invitations return it, so an operator without SMTP (ARCHITECTURE.md §11) can still share an invitation by hand.
- **Idempotency replay without the token.** API_SPEC §1.4 replays "the original result", but DATABASE.md §0 rule 6 forbids storing raw invitation tokens. `EnforceIdempotency` gained a narrow, additive option: a controller names response fields that are stored, and so replayed, as `null` (here `data.token` and `data.url`). A replay is otherwise identical (same invitation, `Idempotent-Replayed: true`). A client that lost the first response revokes the invitation and creates a new one.
- **Never in logs or the queue.** The link carries the token in the URL fragment (like password resets), which browsers never send. The API paths `/api/v1/invitations/<token>/…` do carry it, so the Apache access log writes those requests with the token replaced (`SetEnvIf` plus a second `LogFormat`; `setenvif` is enabled explicitly), and `RedactSensitiveData` replaces 43-character tokens after `/invitations/` or `/invite#` in any log record. The invitation email is queued with `ShouldBeEncrypted`, so neither the token nor the address sits in plain text in `jobs` or `failed_jobs`.

### 3. Create, list, revoke

| Endpoint | Who | Answer |
|---|---|---|
| `POST /teams/{team}/invitations` | Owner/Admin; `Idempotency-Key` required | `201` with the invitation, `token` and `url` |
| `GET /teams/{team}/invitations` | Owner/Admin | `200`, cursor-paginated metadata, newest first |
| `DELETE /teams/{team}/invitations/{invitation}` | Owner/Admin | `204`, idempotent |

- **Order of answers:** non-member 404 (`{team}` binding); Member or Viewer 403 whatever the body (AUTH-04); then validation (422); then the role check: an Admin asking for `admin` is 403 (`TeamPolicy::inviteAs`). `admin` on a share link is 422 `in`.
- **Under the team row lock** (team first, ADR-018 §4): archived 423; actor no longer a member 404; actor no longer allowed to grant that role 409.
- **Duplicates:** an email that already belongs to a member, or that has a pending invitation to the team, is 409 `conflict`; revoke the pending one to send another. Several share links may exist at once.
- **Representation:** `id`, `team_id`, `type` (`email`/`link`), `email` (null for links), `role`, `status`, `use_count`, `max_uses`, `invited_by` (`user_id`, `name`), `expires_at`, `accepted_at`, `revoked_at`, `created_at`. `status` is derived: `revoked`, else `accepted` (all uses taken), else `expired`, else `pending`. Never the token or its hash.
- **Revocation:** any invitation of the team, by the Owner or an Admin (API_SPEC §3.1 lists "Owner/Admin" without a role limit, and revoking only removes access). Revoking a revoked, used-up or expired invitation changes nothing and still answers 204. Archived team: 423, like every team write.
- **Email:** an email invitation is mailed after the commit, in the language of the account that already uses the address if there is one, else the inviter's. The response never says whether the address has an account.

### 4. Preview and accept

- `GET /invitations/{token}/preview` is open to any holder of the token, signed in or not (the guest needs it before signing up). It returns the team name, the role, the type and the expiry; nothing else (no team ID, no addresses, no members or projects), with `Cache-Control: no-store`.
- `POST /invitations/{token}/accept` needs a session and a verified email (`verified` middleware: 403).
- **Answers, in this order** (the team row, then the invitation row, are locked first): unknown token, or a team that is gone or in the trash, 404; the caller is already a member, 200 with the team and their role unchanged, no use taken (DATABASE.md §11 "upsert membership idempotently"); archived team 423; revoked, used up or expired 410 `expired`; an email invitation for another address 403. Otherwise `201` with the team: the membership is created with the invited role and one use is taken by a guarded `UPDATE` (`use_count < max_uses AND revoked_at IS NULL AND expires_at > now()`).
- **Never a project membership.** Joining by link makes a team member; private projects stay hidden (DECIDED-003).
- **Concurrency:** two users racing for the last use of a link get one 201 and one 410; one user accepting twice at once joins once (DB-T07; real-process tests).

### 5. An invitation never grants more than its issuer could today

The specs say an Admin cannot invite Admins, but not what happens to an invitation when its issuer later loses the right. Conservative choice: the pending invitations are revoked in the same transaction as the change, under the team lock.

- Leaving or being removed (`RemoveMember`): all of the member's pending invitations.
- A role change to Member or Viewer (`ChangeMemberRole`): all of them.
- An ownership transfer (`TransferOwnership`): the former Owner's pending Admin invitations; their Member and Viewer invitations stay valid, since an Admin may grant those.

Accepted and expired invitations are left as they are.

### 6. Rate limits

API_SPEC §1.4 asks for stricter invitation limits; the numbers are not specified, so they are configurable:

- `SN3_RATE_LIMIT_INVITATIONS_PER_HOUR` (default 30): creations per user per hour. Each email invitation sends mail.
- `SN3_RATE_LIMIT_INVITATION_TOKENS_PER_MINUTE` (default 10): previews and accepts per signed-in user per minute, and four times that per IP address (several people in one office may redeem one share link).

A token is 256 random bits, so these limits slow abuse; they are not what keeps tokens unguessable.

### 7. No new expiry or use settings

DECIDED-007 is FINAL: 7 days, one use, 20 uses. FR-TEAM-003 mentions "configurable link usage limits"; the FINAL decision fixes the values, so they are constants, not settings.

### 8. Interface

- `/invite` (Inertia, any visitor): reads the token from the fragment, removes it from the address bar, shows the preview and offers sign in or registration to guests, verification to unverified users, and "Join the team" to verified users. A guest's token is kept in this tab's `sessionStorage` for the sign-in round trip only, and the login page returns to `/invite` when one is pending.
- `InvitationsPanel` (create, show the link once with a copy button, list with statuses, revoke with an inline confirmation) is a self-contained component for the team Members page. Until CORE-006's team pages land, it is hosted at `/teams/{team}/invitations` (Owner/Admin; 403 for other members, 404 otherwise). Admin is offered only to the Owner and only for email invitations; the server enforces both.
- E2E: `e2e/tests/invitation.ui.spec.ts` (desktop and mobile). It uses two new `workflow_users` in the E2E fixture, who belong to no seeded team, so the read-only isolation suite stays deterministic.

## Consequences

- CORE-006 mounts `InvitationsPanel` on its Members page (DESIGN_SYSTEM.md §6 "Members/Invites") and links the team page from the invitation success screen, which currently continues to `/`.
- CORE-009 (notifications) may add the in-app invitation notification (FR-COL-003); nothing here sends one.
- CORE-008 (activity) can replace the `team.invitation_*` structured logs.
- Team exports (ADR-024) do not include invitations: they hold addresses of non-members and expire within 7 days.

## Open product questions (conservative option implemented)

1. Should an invitation outlive its issuer's loss of the right to invite? Implemented: no, it is revoked (§5).
2. Should a pending email invitation be replaced by a new one to the same address instead of answering 409? Implemented: 409; revoke first.
3. Are 30 creations per user per hour and 10 token checks per user per minute the right Cloud limits? Implemented: those, configurable.
