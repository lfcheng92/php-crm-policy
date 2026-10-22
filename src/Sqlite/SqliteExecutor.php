<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy\Sqlite;

use Lfcheng92\CrmPolicy\{Action, CapacityPolicy, Clock, Command, Customer, CustomerStatus, Decision, Member, PolicyEngine, Role, Scope, Snapshot, SystemClock};

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
        $this->assertRollbackJournal();
        // Explicit SQL COMMIT/ROLLBACK pair with BEGIN IMMEDIATE: PDO bookkeeping is not relied on.
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->running = true;
        try {
            $this->assertRollbackJournal();
            $query = $this->pdo->prepare('SELECT fingerprint, result_json FROM crm_requests WHERE tenant_id = ? AND request_id = ?');
            $query->execute([$command->tenantId, $command->requestId]);
            $stored = $query->fetch();
            $query->closeCursor();
            if (is_array($stored)) {
                if (!hash_equals(Fields::string($stored['fingerprint'] ?? null), $command->fingerprint())) { throw new IdempotencyConflict('Request ID has a different command.'); }
                $decision = DecisionCodec::decode(Fields::string($stored['result_json'] ?? null));
                $this->validateReplay($decision, $command);
                $result = new ExecutionResult($decision, true);
            } else {
                $decision = $this->engine->decide($command, $this->snapshot($command));
                if ($decision->plan !== null) {
                    $update = $this->pdo->prepare('UPDATE crm_customers SET owner_id = ?, department_id = ?, version = version + 1 WHERE tenant_id = ? AND id = ? AND version = ? AND owner_id IS ?');
                    foreach ($decision->plan->changes as $change) {
                        $update->execute([$change->nextOwnerId, $change->nextDepartmentId, $command->tenantId, $change->customerId, $change->expectedVersion, $change->previousOwnerId]);
                        if ($update->rowCount() !== 1) { throw new ConcurrentChange('Customer changed while applying the plan.'); }
                    }
                }
                $insert = $this->pdo->prepare('INSERT INTO crm_requests (tenant_id, request_id, fingerprint, result_json) VALUES (?, ?, ?, ?)');
                $insert->execute([$command->tenantId, $command->requestId, $command->fingerprint(), DecisionCodec::encode($decision)]);
                $result = new ExecutionResult($decision, false);
            }
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            try { $this->pdo->exec('ROLLBACK'); } catch (\Throwable $rollbackError) {
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
        $query = $this->pdo->prepare('SELECT * FROM crm_customers WHERE tenant_id = ? AND id IN (' . $placeholders . ') ORDER BY id');
        $query->execute([$command->tenantId, ...$ids]);
        $customers = [];
        $memberIds = [$command->actorId];
        if ($command->targetId !== null) { $memberIds[] = $command->targetId; }
        while (is_array($row = $query->fetch())) {
            $owner = Fields::nullableString($row['owner_id'] ?? null);
            if ($owner !== null) { $memberIds[] = $owner; }
            $until = Fields::nullableString($row['protection_until'] ?? null);
            $customers[] = new Customer(Fields::string($row['tenant_id'] ?? null), Fields::string($row['id'] ?? null), $owner, Fields::nullableString($row['department_id'] ?? null), CustomerStatus::from(Fields::string($row['status'] ?? null)), $this->storedBool($row['protected'] ?? null), $until === null ? null : Fields::instant($until), Fields::int($row['version'] ?? null));
        }
        $query->closeCursor();
        $memberIds = array_values(array_unique($memberIds));
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $query = $this->pdo->prepare('SELECT * FROM crm_members WHERE tenant_id = ? AND id IN (' . $placeholders . ')');
        $query->execute([$command->tenantId, ...$memberIds]);
        $members = [];
        $usage = [];
        while (is_array($row = $query->fetch())) {
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
        $query = $this->pdo->prepare('SELECT owner_id, status, COUNT(*) AS total FROM crm_customers WHERE tenant_id = ? AND owner_id IN (' . $placeholders . ') GROUP BY owner_id, status');
        $query->execute([$command->tenantId, ...$memberIds]);
        while (is_array($row = $query->fetch())) {
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

    private function assertRollbackJournal(): void
    {
        // Inspect only: the caller owns journal configuration and may change it between calls.
        $query = $this->pdo->query('PRAGMA journal_mode');
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
