# Documentation readiness — targeted reconciliation

Date: 2026-10-12
Scope: static documentation audit only; **no application code or tests were run**.

## Fixed in canonical sections
- B-01: `url` added to custom-field type CHECK, with existing HTTP(S) typed JSON-string validation retained.
- B-02: replaced the obsolete GitHub visibility decision request with the binding DECIDED-008 policy and negative test requirements.
- B-03: clarified published templates are team-readable, while drafts are author-only, including for Owner/Admin; publishing others’ drafts requires explicit submission.
- I-02: aligned illustrative BRD table names with canonical database names and subordinated BRD conceptual list to database schema.
- Renamed all deliverables to generic names, and updated internal references.

## Readiness decision
**CONDITIONAL GO for documentation handoff only**, subject to endpoint-level typed contract generation and review before each implementation slice. This is **not** a production-readiness decision and **not** authorization to start implementation.

## Still required during authorized implementation
- Typed endpoint-by-endpoint OpenAPI 3.1 contracts and validation/authorization test cases (I-01).
- Execute acceptance scenarios and CI tests; current CSV contains specifications, not passing results (I-03).
- Verify migrations and actual database behavior once code exists.

No repository, application code, migrations, or deployment was created.

## Changelog — 2026-10-16 specification reconciliation
Static documentation changes only; no application code or tests were run.
- `TRACEABILITY.csv`: remapped FR-GH-002/004/005/006/007/010 to the tasks and releases that deliver them (webhook verification and automations are public v1.0; Issues sync mapping is v1.1). Remapped FR-AUTH, FR-I18N, FR-TEAM-002/003, FR-PROJ-008, FR-TASK-005, FR-COL-003..005 and FR-CUST-001/002/005/006/008 to their implementing tasks.
- `DATABASE.md`: `tasks.title` is `varchar(200)` and `task_checklist_items.content` is `varchar(300)` to match `API_SPEC.md`; removed stale "until approved" text in §13 and the open-gate handoff in §17.
- `API_SPEC.md`: status `position` is a read-only ordinal; reordering is via `PUT .../statuses/order`.
- `ACCEPTANCE_SCENARIOS.csv`: added 15 scenarios (team ownership, invitations, key immutability, status deletion, idempotency, trash lifecycle, quotas and rate limits, notification redaction, webhook signature, revoked installation). All remain "Specified; not executed".
