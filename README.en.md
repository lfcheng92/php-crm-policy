# CRM Policy

[中文](README.md)

Framework-independent CRM public-pool, ownership and capacity decisions for PHP 8.2+. Composer name: `lfcheng92/crm-policy`; PSR-4 namespace: `Lfcheng\CrmPolicy`. The core has no third-party runtime dependency, database calls, notifications or global application state.

This first version is not on Packagist. Original code is MIT licensed. See [SOURCES.md](SOURCES.md), [LICENSE](LICENSE), and [NOTICE.md](NOTICE.md) for provenance and the separate licenses of dependencies.

## Install locally

Add a mirrored path repository to a separate consumer's `composer.json`, then run `composer install`:

```json
{
  "repositories": [{"type": "path", "url": "../php-crm-policy", "options": {
    "symlink": false, "versions": {"lfcheng92/crm-policy": "0.1.0"}
  }}],
  "require": {"lfcheng92/crm-policy": "0.1.0"}
}
```

Load the consumer's `vendor/autoload.php`. The optional SQLite executor needs PDO and pdo_sqlite. Development tests also need PHPUnit's DOM/XML/mbstring extensions; the clean installation test needs ZipArchive and `proc_open`.

## API example

```php
<?php
require __DIR__ . '/vendor/autoload.php';
use Lfcheng\CrmPolicy\{
    Action, CapacityPolicy, Command, Customer, CustomerStatus,
    Member, PolicyEngine, Role, Scope, Snapshot, SystemClock
};
$clock = new SystemClock();
$actor = new Member('tenant-a', 'seller', 'sales', true, 20, [
    new Role('seller', ['claim' => Scope::Own, 'return' => Scope::Own]),
]);
$customer = new Customer('tenant-a', 'sample', null, 'sales', CustomerStatus::Active, false, null, 0);
$snapshot = new Snapshot('tenant-a', [$customer], [$actor], ['seller' => 19], $clock->now());
$decision = (new PolicyEngine(new CapacityPolicy(false, false), $clock))->decide(
    new Command('tenant-a', 'seller', 'request-001', Action::Claim, ['sample']), $snapshot,
);
if (!$decision->allowed) {
    echo $decision->reason->value;
} else {
    echo $decision->plan->changes[0]->nextOwnerId; // proposal only; nothing is persisted
}
```

`Member`, `Customer`, `Role`, `Command` and `Snapshot` are immutable inputs, independent of any ORM. `Decision` exposes allowed/reason/customerId/plan. An allowed `ChangePlan` contains customer changes with previous/next owner and department, expected version, capacity deltas and the injected clock time. A denial has no partial plan. Derive actor and tenant from authenticated application context; user-provided roles and counters are not trusted input.

## Rules

- Claim requires a public-pool customer (`ownerId=null`) and always assigns the actor. Return/transfer require an owner. Transfer requires an explicit target and rejects the same owner.
- Role names confer no privileges. Explicit action grants are combined by taking the broadest scope for each action. No grant means denial.
- `Own`: claim the actor's department pool; return/transfer the actor's own customers, with transfer targets in the same department.
- `Department`: both source customer and receiving member must be in the actor's department.
- `Tenant`: cross-department changes within the same tenant. Every scope rejects tenant mismatches. IDs and request keys are tenant scoped; names are never used for identity.
- Return preserves the source department as its public pool. Claim/transfer assigns the receiver's department. An existing owner's department must match the customer's department.
- All actions respect protection. Protected with no deadline means indefinite protection; a deadline equal to `Clock::now()` has expired.
- The actor and receiver must be active. The original owner must exist in the same tenant with a known, consistent department; an inactive original owner may still be reassigned by an authorized manager.
- Missing authorization, members, departments, capacity limits or relevant usage counts never grant permission. A null capacity limit means unknown, not unlimited.
- Active customers always count. Won and abandoned counting is separately configurable. Entire batches share exactly the same checks as single operations.
- Batches are deduplicated by exact ID, sorted deterministically, and limited to 200 unique IDs / 1000 submitted entries. String IDs `1` and `01` remain distinct.

Receiving capacity is checked cumulatively for the whole batch. Exactly reaching the limit is allowed; exceeding it is denied. An over-capacity member may release customers but cannot receive, including excluded statuses while already over the limit. Excluded statuses may be received at the exact limit. Releasing more counted customers than reported usage is inconsistent.

`Snapshot` must contain complete trusted counters under the same `CapacityPolicy`. The engine checks missing values and local inconsistencies; it cannot prove a supplied counter is accurate. Snapshots older than 60 seconds or in the future are denied; configure the age with the third engine constructor argument. This freshness check is not concurrency control. Two callers can receive allowed plans from the same outdated count.

## Atomic execution

Never directly persist an offline or untrusted plan. A custom executor must read authorization, ownership, protection and complete capacity within the same locking/isolation protocol, re-evaluate, compare versions and apply the entire batch atomically. Every other allocation or status-changing writer must cooperate. Notifications and reliable delivery belong to the application after commit.

`Sqlite\Schema::create(PDO)` creates an example schema in a **new dedicated** SQLite database. It does not migrate an existing CRM. `Sqlite\SqliteExecutor(PDO, CapacityPolicy, Clock)` accepts commands and returns an `ExecutionResult` with decision/replayed. See [examples/sqlite.php](examples/sqlite.php).

Persistent tables and indexes are explicitly qualified in SQLite `main`; TEMP names cannot shadow authorization, customer or receipt data, and a missing main table never falls back to an attached database. The executor uses `BEGIN IMMEDIATE` before reading, tenant-restricted queries, real database counts, expected versions and previous-owner conditions, then commits ownership changes and idempotency results together. Before commit it verifies every planned owner/department/version and the complete stored idempotency result; ignored, deleted or reverted writes throw `PersistenceFailure` and roll back. SQL failures roll back the complete batch. Composite keys and owner foreign keys scope IDs to tenants. See [SQLite transaction documentation](https://www.sqlite.org/lang_transaction.html).

The executor only inspects journal_mode at construction, before each execution and after acquiring the transaction. It accepts DELETE/TRUNCATE/PERSIST/MEMORY/WAL and rejects OFF or unknown modes with `LogicException`; it never switches the caller's journal configuration. Changing the connection to OFF after construction is also rejected. The caller must restore a supported mode before retrying. MEMORY provides rollback while the process lives, not an added crash or power-loss guarantee.

Use a dedicated PDO connection with no outer transaction. PHP 8.2/8.3 PDO may not report a transaction started with raw SQL BEGIN, so the executor also translates SQLite's specific nested-BEGIN error to `LogicException`. A failed BEGIN never grants transaction ownership: the executor neither commits nor rolls back caller-owned work. Other BEGIN errors, including lock contention and read-only storage, retain their storage exception type. Each execution requires `PDO::ERRMODE_EXCEPTION`; a connection changed to silent/warning mode is rejected before writes. The adapter sets a 5000 ms busy timeout; exhausted locks, malformed stored values and I/O errors throw rather than become business denials. Stored timestamps use exact `Y-m-d\TH:i:s.uP` format with microseconds and an explicit offset, e.g. `2026-01-01T12:00:00.000000+00:00`; relative dates are rejected. Explicit SQL COMMIT/ROLLBACK matches BEGIN; PDO's manual-BEGIN bookkeeping is not assumed.

`(tenant_id,request_id)` is the idempotency key. The same normalized command returns its original decision, including denials. Reordering or repeating customer IDs does not change the fingerprint. Reusing a key with a different actor/action/customer/target throws `IdempotencyConflict`. Use a new request ID for a new business attempt after data or policy changes. Replay is not a current-permission query. Deleting request records shortens the deduplication guarantee.

Schema and triggers are trusted deployment configuration. Triggers must not modify authorization, capacity or unrelated batch records. Postcondition checks cover planned records and receipts, not every possible trigger side effect.

This is an executable SQLite reference protocol, not a claim of automatic safety for arbitrary databases, network filesystems, replication or non-cooperating writers. Production audit storage, schema migrations, idempotency retention and customer-status update APIs remain application responsibilities.

## Denials and exceptions

Stable `Reason` values: `tenant_mismatch`, `snapshot_expired`, `actor_not_found`, `actor_inactive`, `permission_denied`, `department_missing`, `customer_not_found`, `already_owned`, `not_owned`, `owner_not_found`, `target_not_found`, `target_inactive`, `same_owner`, `protected`, `capacity_missing`, `capacity_exceeded`, `inconsistent_snapshot`.

The first failure in deterministic batch order is returned. Structural input errors throw `InvalidArgumentException`; PHP type errors may throw `TypeError`. IDs are 1..128 UTF-8 bytes without surrounding ASCII whitespace or ASCII controls. Negative counters/capacities, invalid grants, empty/oversized batches and invalid versions are rejected.

Executor exceptions include storage postcondition `PersistenceFailure`, `IdempotencyConflict`, defensive `ConcurrentChange`, outer-transaction `LogicException`, PDO/JSON and invalid stored-data exceptions. A failed BEGIN applies no business writes. Failures after BEGIN attempt rollback; rollback failure throws `RuntimeException` and requires discarding the connection. Apply bounded retries at the application boundary with the same request ID for the same command; never turn an exception into permission.

## Examples and checks

These development commands apply to the full source repository. Composer consumer archives exclude tests, tools, CI and the development lock file; installed dependencies use the application autoloader rather than running package development checks.

```sh
composer install --no-interaction --prefer-dist
composer validate --strict
composer check
php tools/consumer-test.php
composer audit --locked
```

`examples/agency.php` excludes won/abandoned customers from prospect capacity. `examples/education.php` continues counting won customers against advisor capacity. Both use synthetic inputs and the same API. `examples/sqlite.php` executes a real in-memory claim and replay.

PHPUnit covers denial/error paths, transaction rollback using failing SQLite triggers, tenant isolation and two independent PHP processes competing for one customer, one request or the last capacity slot. PHPStan level 8 checks src/examples; runtime validation is retained for arrays described by PHPDoc (`treatPhpDocTypesAsCertain: false`).

`tools/consumer-test.php [composer.phar]` archives and checks the source package, installs a mirrored path package in a clean directory with Packagist/network disabled, deletes that temporary source repository, then runs a separate consumer and all examples. `CRM_POLICY_KEEP_CONSUMER=1` retains its verification artifacts; otherwise they are removed.

CI is configured for PHP 8.2/8.3/8.4/8.5; configured jobs are not evidence that they ran. Tests cover executed environments and cases, not an absence of bugs or production guarantees. See [CONTRIBUTING.md](CONTRIBUTING.md) for development checks and [NOTICE.md](NOTICE.md) for license scope.
