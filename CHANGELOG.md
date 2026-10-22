# Changelog

## 0.1.0 — local candidate, 2026-10-22

- Framework-independent, immutable CRM inputs and pure decisions for claim, return and transfer.
- Explicit action grants with own/department/tenant scope and fail-closed missing-data checks.
- Protected customers, injected clock and bounded, deduplicated atomic batch proposals.
- Configurable won/abandoned capacity counting; cumulative batch capacity checks.
- Reject rollback-unsafe SQLite journal modes at construction and each execution without changing caller configuration; cover post-construction changes, forced cache spills and failing-trigger rollback.
- Optional SQLite/PDO example executor with tenant keys, transactional re-evaluation, defensive versions and idempotency.
- Synthetic agency/education examples, unit/error tests, real local process races and rollback tests.
- PHPStan, lint, CI configuration, archive inspection and independent Composer consumer checks.
- Chinese/English documentation and source/license/security boundaries.

Not a published release. Public distribution requires a separate rights, security and explicit approval gate.
