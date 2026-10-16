# ADR-018 — Team management: create, update, roles, removal and ownership transfer

- **Status:** Accepted
- **Date:** 2026-10-16
- **Task:** CORE-001 (#10)
- **Decision inputs:** BRD FR-TEAM-001, -004 to -007 and the role matrix in §6.2; API_SPEC.md §1.3, §2, §3; DATABASE.md §3, §11, §16 (DB-T06); ACCEPTANCE_SCENARIOS AUTH-04, TEAM-01; ADR-017

## Decisions

### 1. Endpoints
CORE-001 adds the team mutations from API_SPEC §3, all on the `{team}` binding from ADR-017, so non-members get 404 before any check:

| Endpoint | Who |
|---|---|
| `POST /api/v1/teams` | Any user with a verified email (`verified` middleware → 403). The creator becomes Owner. |
| `PATCH /api/v1/teams/{team}` | Owner or Admin |
| `GET /api/v1/teams/{team}/members` | Any member. Cursor-paginated, newest first. |
| `PATCH /api/v1/teams/{team}/members/{user}` | Owner only. Sets `admin`, `member` or `viewer`. |
| `DELETE /api/v1/teams/{team}/members/{user}` | The Owner removes others. Any member may leave. |
| `POST /api/v1/teams/{team}/ownership-transfer` | Current Owner only |

Archive/restore/delete (CORE-010), exports (CORE-012) and invitations (CORE-002) come later.

For role-restricted mutations, the Form Request authorises before it validates. A Viewer therefore gets 403 whatever the body contains, and nothing changes (AUTH-04).

### 2. Status codes
| Code | When |
|---|---|
| 409 `conflict` | A requested slug is already taken, including by a team in the trash (slugs stay reserved for the 30-day recovery window). The Owner tries to leave or be removed (TEAM-01). Ownership changed while a transfer waited for its lock. |
| 423 `resource_locked` | Any mutation of an archived team |
| 422 | Validation codes, including `not_member` and `different` on `new_owner_user_id` |

`DomainConflictException`, `ResourceLockedException` and `StaleVersionException` are expected outcomes, so they are not reported to the error log.

### 3. Slugs
A requested slug must match `[a-z0-9-]{3,40}`. Without one, the slug is the ASCII form of the name, cut to 32 characters, or `team` when that leaves fewer than 3 characters (for example, an Arabic-only name). If that slug is taken, a 6-character random suffix is added. A concurrent insert of the same derived slug is retried up to five times. The slug is presentation only; the UUID is authoritative (API_SPEC §3).

### 4. Ownership transfer
`TransferOwnership` runs in one transaction:
1. Lock the team row.
2. Re-check that the caller is still the Owner.
3. Lock both memberships.
4. Demote the old Owner to **Admin**. This comes before promoting the new one, because the partial unique index allows only one owner membership at a time.
5. Promote the new Owner and update `teams.owner_user_id`.

The deferred triggers from ADR-017 re-check the result at COMMIT. API_SPEC does not say what the previous Owner becomes; Admin keeps them able to manage the team without Owner powers.

Every writer locks the team row first, so concurrent transfers are serialised. `OwnershipTransferConcurrencyTest` races two real PHP processes on committed data (DB-T06): one wins, the other gets 409, and exactly one Owner remains.

### 5. Members list privacy
Every member sees each member's name, role and join date. Email addresses are included only for the Owner and Admins, who need them to manage membership. Members and Viewers don't need other people's addresses (data minimisation; the spec leaves this open).

### 6. Removal
Removing a member deletes the membership row in a transaction. Access ends on the next request, because every team route re-resolves the membership (FR-TEAM-006). When project memberships arrive (CORE-003), they must be removed in the same transaction. Notifications (CORE-009) must re-check membership.

### 7. Not in this task
- **Team avatar:** API_SPEC §3 lists it, but it needs file storage. It comes with attachments (CORE-008). Only the `description` column is added now, through an expand-only migration.
- **Audit:** role changes, removals and transfers are logged as structured `info` events (`team.member_removed`, `team.ownership_transferred`) until the activity/audit model arrives (CORE-008).
- **UI:** team screens are part of navigation and settings (CORE-006).
