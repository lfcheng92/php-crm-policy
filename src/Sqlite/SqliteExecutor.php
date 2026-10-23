<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy\Sqlite;

use Lfcheng\CrmPolicy\{Action, CapacityPolicy, Clock, Command, Customer, CustomerStatus, Decision, Member, PolicyEngine, Role, Scope, Snapshot, SystemClock};

/** SQLite-only reference executor. All cooperating writers must use this protocol. */
final class SqliteExecutor
{
    private readonly PolicyEngine $engine;
    private bool $running = false;

    public function __construct(private readonly \PDO $pdo, private readonly CapacityPolicy $capacity, private readonly Clock $clock = new SystemClock())
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') { throw new \InvalidArgumentException('SQLite PDO required.'); }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->assertRollbackJournal();
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $this->engine = new PolicyEngine($capacity, $clock);
    }

    public function execute(Command $command): ExecutionResult
    {
        if ($this->running || $this->pdo->inTransaction()) { throw new \LogicException('Use a dedicated PDO connection without an outer transaction.'); }
        $this->assertExecutionConditions();
        // Explicit SQL COMMIT/ROLLBACK pair with BEGIN IMMEDIATE: PDO bookkeeping is not relied on.
        $this->transactionStatement('BEGIN IMMEDIATE');
        $this->running = true;
        try {
            $this->assertExecutionConditions();
            $query = $this->statement('SELECT fingerprint, result_json FROM main.crm_requests WHERE tenant_id = ? AND request_id = ?');
            $this->run($query, [$command->tenantId, $command->requestId]);
            $stored = $query->fetch(\PDO::FETCH_ASSOC);
            $query->closeCursor();
            if (is_array($stored)) {
                if (!hash_equals(Fields::string($stored['fingerprint'] ?? null), $command->fingerprint())) { throw new IdempotencyConflict('Request ID has a different command.'); }
                $decision = DecisionCodec::decode(Fields::string($stored['result_json'] ?? null));
                $this->validateReplay($decision, $command);
                $result = new ExecutionResult($decision, true);
            } else {
                $decision = $this->engine->decide($command, $this->snapshot($command));
                if ($decision->plan !== null) {
                    $update = $this->statement('UPDATE main.crm_customers SET owner_id = ?, department_id = ?, version = version + 1 WHERE tenant_id = ? AND id = ? AND version = ? AND owner_id IS ?');
                    foreach ($decision->plan->changes as $change) {
                        $this->run($update, [$change->nextOwnerId, $change->nextDepartmentId, $command->tenantId, $change->customerId, $change->expectedVersion, $change->previousOwnerId]);
                        $affected = $update->rowCount();
                        $update->closeCursor();
                        if ($affected !== 1) { throw new ConcurrentChange('Customer changed while applying the plan.'); }
                    }
                }
                $insert = $this->statement('INSERT INTO main.crm_requests (tenant_id, request_id, fingerprint, result_json) VALUES (?, ?, ?, ?)');
                $encoded = DecisionCodec::encode($decision);
                $this->run($insert, [$command->tenantId, $command->requestId, $command->fingerprint(), $encoded]);
                $affected = $insert->rowCount();
                $insert->closeCursor();
                if ($affected !== 1) { throw new PersistenceFailure('The idempotency record was not inserted.'); }
                // Verify after every write: a later trigger must not undo an earlier change or receipt.
                $this->verifyStoredResult($command, $decision, $encoded);
                $result = new ExecutionResult($decision, false);
            }
            $this->transactionStatement('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            try { $this->transactionStatement('ROLLBACK'); } catch (\Throwable $rollbackError) {
                throw new \RuntimeException('Rollback failed; discard this connection.', 0, $rollbackError);
            }
            throw $error;
        } finally {
            $this->running = false;
        }
    }

    private function snapshot(Command $command): Snapshot
    {
        $ids = $command->customerIds;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->statement('SELECT * FROM main.crm_customers WHERE tenant_id = ? AND id IN (' . $placeholders . ') ORDER BY id');
        $this->run($query, [$command->tenantId, ...$ids]);
        $customers = [];
        $memberIds = [$command->actorId];
        if ($command->targetId !== null) { $memberIds[] = $command->targetId; }
        while (is_array($row = $query->fetch(\PDO::FETCH_ASSOC))) {
            $owner = Fields::nullableString($row['owner_id'] ?? null);
            if ($owner !== null) { $memberIds[] = $owner; }
            $until = Fields::nullableString($row['protection_until'] ?? null);
            $customers[] = new Customer(Fields::string($row['tenant_id'] ?? null), Fields::string($row['id'] ?? null), $owner, Fields::nullableString($row['department_id'] ?? null), CustomerStatus::from(Fields::string($row['status'] ?? null)), $this->storedBool($row['protected'] ?? null), $until === null ? null : Fields::instant($until), Fields::int($row['version'] ?? null));
        }
        $query->closeCursor();
        $memberIds = array_values(array_unique($memberIds));
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $query = $this->statement('SELECT * FROM main.crm_members WHERE tenant_id = ? AND id IN (' . $placeholders . ')');
        $this->run($query, [$command->tenantId, ...$memberIds]);
        $members = [];
        $usage = [];
        while (is_array($row = $query->fetch(\PDO::FETCH_ASSOC))) {
            $roles = [];
            foreach (Fields::list(json_decode(Fields::string($row['roles_json'] ?? null), true, 512, JSON_THROW_ON_ERROR)) as $item) {
                $data = Fields::object($item);
                $grants = [];
                foreach (Fields::object($data['grants'] ?? null) as $action => $value) { $grants[Action::from($action)->value] = Scope::from(Fields::int($value)); }
                $roles[] = new Role(Fields::string($data['name'] ?? null), $grants);
            }
            $id = Fields::string($row['id'] ?? null);
            $limit = $row['capacity_limit'] ?? null;
            $members[] = new Member(Fields::string($row['tenant_id'] ?? null), $id, Fields::nullableString($row['department_id'] ?? null), $this->storedBool($row['active'] ?? null), $limit === null ? null : Fields::int($limit), $roles);
            $usage[$id] = 0;
        }
        $query->closeCursor();
        $query = $this->statement('SELECT owner_id, status, COUNT(*) AS total FROM main.crm_customers WHERE tenant_id = ? AND owner_id IN (' . $placeholders . ') GROUP BY owner_id, status');
        $this->run($query, [$command->tenantId, ...$memberIds]);
        while (is_array($row = $query->fetch(\PDO::FETCH_ASSOC))) {
            if ($this->capacity->counts(CustomerStatus::from(Fields::string($row['status'] ?? null)))) {
                $id = Fields::string($row['owner_id'] ?? null);
                $count = Fields::int($row['total'] ?? null);
                if ($count > PHP_INT_MAX - ($usage[$id] ?? 0)) { throw new \OverflowException('Capacity count overflow.'); }
                $usage[$id] = ($usage[$id] ?? 0) + $count;
            }
        }
        $query->closeCursor();
        return new Snapshot($command->tenantId, $customers, $members, $usage, $this->clock->now());
    }

    private function assertExecutionConditions(): void
    {
        if ($this->pdo->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION) {
            throw new \LogicException('SQLite execution requires PDO::ERRMODE_EXCEPTION.');
        }
        $this->assertRollbackJournal();
    }

    private function transactionStatement(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) { throw new PersistenceFailure('SQLite transaction control failed.'); }
    }

    private function statement(string $sql): \PDOStatement
    {
        $query = $this->pdo->prepare($sql);
        if ($query === false) { throw new PersistenceFailure('SQLite could not prepare a statement.'); }
        return $query;
    }

    /** @param list<int|string|null> $parameters */
    private function run(\PDOStatement $query, array $parameters): void
    {
        try {
            if (!$query->execute($parameters)) { throw new PersistenceFailure('SQLite statement failed.'); }
        } catch (\Throwable $error) {
            try { $query->closeCursor(); } catch (\Throwable) { /* Preserve the SQL failure. */ }
            throw $error;
        }
    }

    private function verifyStoredResult(Command $command, Decision $decision, string $encoded): void
    {
        if ($decision->plan !== null) {
            $query = $this->statement('SELECT owner_id, department_id, version FROM main.crm_customers WHERE tenant_id = ? AND id = ?');
            foreach ($decision->plan->changes as $change) {
                $this->run($query, [$command->tenantId, $change->customerId]);
                $row = $query->fetch(\PDO::FETCH_ASSOC);
                $query->closeCursor();
                if (!is_array($row) || $row['owner_id'] !== $change->nextOwnerId
                    || $row['department_id'] !== $change->nextDepartmentId || $row['version'] !== $change->expectedVersion + 1) {
                    throw new PersistenceFailure('SQLite did not retain the planned customer state.');
                }
            }
        }
        $query = $this->statement('SELECT fingerprint, result_json FROM main.crm_requests WHERE tenant_id = ? AND request_id = ?');
        $this->run($query, [$command->tenantId, $command->requestId]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        $query->closeCursor();
        if (!is_array($row) || $row['fingerprint'] !== $command->fingerprint() || $row['result_json'] !== $encoded) {
            throw new PersistenceFailure('SQLite did not retain the idempotency result.');
        }
    }

    private function assertRollbackJournal(): void
    {
        // Inspect only: the caller owns journal configuration and may change it between calls.
        $query = $this->pdo->query('PRAGMA main.journal_mode');
        if ($query === false) { throw new \LogicException('Cannot inspect SQLite journal mode.'); }
        try { $mode = $query->fetchColumn(); } finally { $query->closeCursor(); }
        if (!is_string($mode) || !in_array(strtolower($mode), ['delete', 'truncate', 'persist', 'memory', 'wal'], true)) {
            throw new \LogicException('SQLite journal mode must support rollback; OFF and unknown modes are not supported.');
        }
    }

    private function validateReplay(Decision $decision, Command $command): void
    {
        if ($decision->customerId !== null && !in_array($decision->customerId, $command->customerIds, true)) {
            throw new \UnexpectedValueException('Stored rejection does not match command.');
        }
        $plan = $decision->plan;
        if ($plan === null) { return; }
        $ids = array_map(static fn ($change): string => $change->customerId, $plan->changes);
        if ($plan->tenantId !== $command->tenantId || $plan->actorId !== $command->actorId
            || $plan->requestId !== $command->requestId || $plan->action !== $command->action || $ids !== $command->customerIds) {
            throw new \UnexpectedValueException('Stored plan does not match command.');
        }
    }

    private function storedBool(mixed $value): bool
    {
        if ($value !== 0 && $value !== 1) { throw new \UnexpectedValueException('Invalid stored boolean.'); }
        return $value === 1;
    }
}
