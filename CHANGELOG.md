# Changelog

## Unreleased

- Standardize runtime and development PSR-4 namespaces on `Lfcheng\CrmPolicy\`.
- Composer package name stays `lfcheng92/crm-policy`; repository and package coordinates are unchanged.
- Earlier private-candidate consumers must update imports/FQCNs and regenerate Composer autoload files; no compatibility aliases are provided.

- Qualify persistent SQLite tables/indexes in main to prevent TEMP/attached-schema shadowing.
- Verify planned customer state and idempotency storage before COMMIT; roll back suppressed or reverted database writes.
- Reject a connection changed to PDO silent/warning mode; make row fetching independent of default fetch mode.
- Add real WAL batch/transfer races, deferred-COMMIT failure recovery, numeric/unicode tenant IDs and input/capacity/timezone invariants.
- License independently written original code under MIT; preserve dependency notices.
- Remove machine-specific acceptance records and internal upload instructions from distributed source.

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

No production, arbitrary-database or physical-service guarantee is implied by these tests.
