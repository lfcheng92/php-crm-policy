<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy\Tests\Integration;

use Lfcheng\CrmPolicy\{Action, CapacityPolicy, Command, CustomerStatus, Reason, Scope};
use Lfcheng\CrmPolicy\Sqlite\{ConcurrentChange, IdempotencyConflict, Schema, SqliteExecutor};
use Lfcheng\CrmPolicy\Tests\Support\{Fixtures as F, FrozenClock};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqliteExecutorTest extends TestCase
{
    private \PDO $pdo;
    private string $directory;
    private string $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crm-policy-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->database = $this->directory . '/isolated.sqlite';
        $this->pdo = new \PDO('sqlite:' . $this->database);
        Schema::create($this->pdo);
        F::storeMember($this->pdo, F::member());
        F::storeMember($this->pdo, F::member('bob'));
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        foreach (glob($this->directory . '/*') ?: [] as $path) { unlink($path); }
        rmdir($this->directory);
    }

    private function executor(?CapacityPolicy $capacity = null): SqliteExecutor
    {
        return new SqliteExecutor($this->pdo, $capacity ?? new CapacityPolicy(false, false), new FrozenClock());
    }

    private function row(string $id = 'c1', string $tenant = 't'): array
    {
        $query = $this->pdo->prepare('SELECT * FROM crm_customers WHERE tenant_id = ? AND id = ?');
        $query->execute([$tenant, $id]);
        $result = $query->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($result);
        return $result;
    }

    private function requestCount(): int { return (int) $this->pdo->query('SELECT COUNT(*) FROM crm_requests')->fetchColumn(); }

    public function testClaimAndDuplicateRequestChangeOnlyOnce(): void
    {
        F::storeCustomer($this->pdo, F::customer()); $executor = $this->executor();
        $command = new Command('t', 'alice', 'r1', Action::Claim, ['c1', 'c1']);
        $first = $executor->execute($command); $again = $executor->execute(new Command('t', 'alice', 'r1', Action::Claim, ['c1']));
        self::assertTrue($first->decision->allowed); self::assertFalse($first->replayed); self::assertTrue($again->replayed);
        self::assertEquals($first->decision, $again->decision);
        self::assertSame('alice', $this->row()['owner_id']); self::assertSame(1, $this->row()['version']); self::assertSame(1, $this->requestCount());
    }

    public function testIdempotencyConflictDoesNotWrite(): void
    {
        F::storeCustomer($this->pdo, F::customer()); $executor = $this->executor();
        $executor->execute(new Command('t', 'alice', 'r1', Action::Claim, ['c1']));
        try { $executor->execute(new Command('t', 'bob', 'r1', Action::Claim, ['c1'])); self::fail('Expected conflict.'); }
        catch (IdempotencyConflict) { self::assertSame('alice', $this->row()['owner_id']); self::assertSame(1, $this->requestCount()); }
    }

    public function testRejectedRequestIsReplayedUntilNewRequestId(): void
    {
        $this->pdo->exec("UPDATE crm_members SET capacity_limit = 0 WHERE id = 'alice'");
        F::storeCustomer($this->pdo, F::customer()); $executor = $this->executor();
        $command = new Command('t', 'alice', 'r1', Action::Claim, ['c1']);
        self::assertSame(Reason::CapacityExceeded, $executor->execute($command)->decision->reason);
        $this->pdo->exec("UPDATE crm_members SET capacity_limit = 1 WHERE id = 'alice'");
        $replay = $executor->execute($command);
        self::assertTrue($replay->replayed); self::assertSame(Reason::CapacityExceeded, $replay->decision->reason);
        self::assertTrue($executor->execute(new Command('t', 'alice', 'r2', Action::Claim, ['c1']))->decision->allowed);
        self::assertSame(1, $this->row()['version']);
    }

    public function testReturnAndTransferAreAtomicAndPreserveDepartmentRules(): void
    {
        F::storeCustomer($this->pdo, F::customer(owner: 'alice')); $executor = $this->executor();
        $transfer = $executor->execute(new Command('t', 'alice', 'transfer', Action::Transfer, ['c1'], 'bob'));
        self::assertTrue($transfer->decision->allowed); self::assertSame('bob', $this->row()['owner_id']);
        self::assertSame(['alice' => -1, 'bob' => 1], $transfer->decision->plan?->capacityDeltas);
        $return = $executor->execute(new Command('t', 'bob', 'return', Action::ReturnToPool, ['c1']));
        self::assertTrue($return->decision->allowed); self::assertNull($this->row()['owner_id']);
        self::assertSame('sales', $this->row()['department_id']); self::assertSame(2, $this->row()['version']);
    }

    public function testCrossDepartmentTransferRequiresTenantGrant(): void
    {
        F::storeMember($this->pdo, F::member('lead', scope: Scope::Tenant));
        F::storeMember($this->pdo, F::member('carol', department: 'support'));
        F::storeCustomer($this->pdo, F::customer(owner: 'alice'));
        $executor = $this->executor();
        self::assertSame(Reason::PermissionDenied, $executor->execute(new Command('t', 'alice', 'denied', Action::Transfer, ['c1'], 'carol'))->decision->reason);
        self::assertTrue($executor->execute(new Command('t', 'lead', 'accepted', Action::Transfer, ['c1'], 'carol'))->decision->allowed);
        self::assertSame('support', $this->row()['department_id']); self::assertSame('carol', $this->row()['owner_id']);
    }

    public function testBatchCapacityAndProtectionDenyWholeBatch(): void
    {
        $this->pdo->exec("UPDATE crm_members SET capacity_limit = 1 WHERE id = 'alice'");
        F::storeCustomer($this->pdo, F::customer('c1')); F::storeCustomer($this->pdo, F::customer('c2'));
        $executor = $this->executor();
        $decision = $executor->execute(new Command('t', 'alice', 'r1', Action::Claim, ['c1', 'c2']))->decision;
        self::assertSame(Reason::CapacityExceeded, $decision->reason); self::assertNull($this->row()['owner_id']); self::assertNull($this->row('c2')['owner_id']);
        $this->pdo->exec("UPDATE crm_members SET capacity_limit = 2 WHERE id = 'alice'");
        $this->pdo->exec("UPDATE crm_customers SET protected = 1 WHERE id = 'c2'");
        self::assertSame(Reason::Protected, $executor->execute(new Command('t', 'alice', 'r2', Action::Claim, ['c1', 'c2']))->decision->reason);
        self::assertSame(0, $this->row()['version']); self::assertSame(0, $this->row('c2')['version']);
    }

    #[DataProvider('countConfigurations')]
    public function testDatabaseCountsUseSameConfig(bool $won, bool $abandoned, int $expectedUsage): void
    {
        F::storeCustomer($this->pdo, F::customer('active', owner: 'alice'));
        F::storeCustomer($this->pdo, F::customer('won', owner: 'alice', status: CustomerStatus::Won));
        F::storeCustomer($this->pdo, F::customer('abandoned', owner: 'alice', status: CustomerStatus::Abandoned));
        F::storeCustomer($this->pdo, F::customer());
        $this->pdo->prepare('UPDATE crm_members SET capacity_limit = ? WHERE id = ?')->execute([$expectedUsage, 'alice']);
        self::assertSame(Reason::CapacityExceeded, $this->executor(new CapacityPolicy($won, $abandoned))->execute(new Command('t', 'alice', 'r1', Action::Claim, ['c1']))->decision->reason);
        $this->pdo->prepare('UPDATE crm_members SET capacity_limit = ? WHERE id = ?')->execute([$expectedUsage + 1, 'alice']);
        self::assertTrue($this->executor(new CapacityPolicy($won, $abandoned))->execute(new Command('t', 'alice', 'r2', Action::Claim, ['c1']))->decision->allowed);
    }

    public static function countConfigurations(): iterable
    {
        yield [false, false, 1]; yield [true, false, 2]; yield [false, true, 2]; yield [true, true, 3];
    }

    public function testSameIdsAndRequestIdsCannotCrossTenants(): void
    {
        F::storeMember($this->pdo, F::member(limit: 1, tenant: 'other'));
        $this->pdo->exec("UPDATE crm_members SET capacity_limit = 1 WHERE tenant_id = 't' AND id = 'alice'");
        F::storeCustomer($this->pdo, F::customer()); F::storeCustomer($this->pdo, F::customer(tenant: 'other'));
        $executor = $this->executor();
        self::assertTrue($executor->execute(new Command('t', 'alice', 'same-request', Action::Claim, ['c1']))->decision->allowed);
        self::assertNull($this->row(tenant: 'other')['owner_id']);
        self::assertTrue($executor->execute(new Command('other', 'alice', 'same-request', Action::Claim, ['c1']))->decision->allowed);
        self::assertSame(2, $this->requestCount());
        self::assertSame(Reason::ActorNotFound, $executor->execute(new Command('missing-tenant', 'alice', 'r', Action::Claim, ['c1']))->decision->reason);
        self::assertSame(1, $this->row()['version']); self::assertSame(1, $this->row(tenant: 'other')['version']);
    }

    #[DataProvider('failurePoints')]
    public function testSqlFailureRollsBackChangesAndIdempotencyRecord(string $point): void
    {
        F::storeCustomer($this->pdo, F::customer('a')); F::storeCustomer($this->pdo, F::customer('b'));
        $trigger = $point === 'second customer'
            ? "CREATE TRIGGER fail BEFORE UPDATE ON crm_customers WHEN NEW.id = 'b' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END"
            : "CREATE TRIGGER fail BEFORE INSERT ON crm_requests BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END";
        $this->pdo->exec($trigger); $executor = $this->executor(); $command = new Command('t', 'alice', 'r', Action::Claim, ['a', 'b']);
        try { $executor->execute($command); self::fail('Expected SQL failure.'); } catch (\PDOException) {}
        self::assertNull($this->row('a')['owner_id']); self::assertNull($this->row('b')['owner_id']);
        self::assertSame(0, $this->row('a')['version']); self::assertSame(0, $this->requestCount());
        $this->pdo->exec('DROP TRIGGER fail');
        self::assertTrue($executor->execute($command)->decision->allowed); self::assertSame(1, $this->requestCount());
    }

    public static function failurePoints(): iterable { yield ['second customer']; yield ['idempotency insert']; }

    public function testZeroAffectedRowsRaiseConcurrentChangeAndRollback(): void
    {
        F::storeCustomer($this->pdo, F::customer());
        $this->pdo->exec("CREATE TRIGGER ignore_update BEFORE UPDATE ON crm_customers BEGIN SELECT RAISE(IGNORE); END");
        try { $this->executor()->execute(new Command('t', 'alice', 'r', Action::Claim, ['c1'])); self::fail('Expected defensive conflict.'); }
        catch (ConcurrentChange) { self::assertNull($this->row()['owner_id']); self::assertSame(0, $this->requestCount()); }
    }

    public function testMalformedRoleDataThrowsAndNeverGrants(): void
    {
        F::storeCustomer($this->pdo, F::customer());
        $this->pdo->exec("UPDATE crm_members SET roles_json = '{invalid' WHERE id = 'alice'");
        try { $this->executor()->execute(new Command('t', 'alice', 'r', Action::Claim, ['c1'])); self::fail('Expected malformed roles error.'); }
        catch (\JsonException) { self::assertNull($this->row()['owner_id']); self::assertSame(0, $this->requestCount()); }
    }

    public function testCorruptReplayThrowsAndDoesNotChangeCustomer(): void
    {
        F::storeCustomer($this->pdo, F::customer()); $executor = $this->executor(); $command = new Command('t', 'alice', 'r', Action::Claim, ['c1']);
        $executor->execute($command);
        $this->pdo->prepare('UPDATE crm_requests SET result_json = ?')->execute([json_encode(['v' => 999], JSON_THROW_ON_ERROR)]);
        try { $executor->execute($command); self::fail('Expected corrupt replay error.'); }
        catch (\UnexpectedValueException) { self::assertSame(1, $this->row()['version']); self::assertSame(1, $this->requestCount()); }
    }

    public function testOuterTransactionIsRejectedWithoutRollingItBack(): void
    {
        F::storeCustomer($this->pdo, F::customer()); $executor = $this->executor(); $this->pdo->beginTransaction();
        try { $executor->execute(new Command('t', 'alice', 'r', Action::Claim, ['c1'])); self::fail('Expected outer transaction error.'); }
        catch (\LogicException) { self::assertTrue($this->pdo->inTransaction()); $this->pdo->rollBack(); }
        self::assertSame(0, $this->requestCount());
    }

    public function testSqliteBusyIsAnExceptionAndCanBeRetried(): void
    {
        F::storeCustomer($this->pdo, F::customer());
        $other = new \PDO('sqlite:' . $this->database); $executor = new SqliteExecutor($other, new CapacityPolicy(false, false));
        $other->exec('PRAGMA busy_timeout = 0'); $this->pdo->exec('BEGIN IMMEDIATE');
        $command = new Command('t', 'alice', 'r', Action::Claim, ['c1']);
        try { $executor->execute($command); self::fail('Expected lock conflict.'); }
        catch (\PDOException $e) { self::assertSame(5, $e->errorInfo[1]); }
        finally { $this->pdo->exec('ROLLBACK'); }
        self::assertSame(0, $this->requestCount()); self::assertTrue($executor->execute($command)->decision->allowed);
    }

    #[DataProvider('races')]
    public function testRealCompetingProcesses(string $scenario): void
    {
        F::storeCustomer($this->pdo, F::customer());
        if ($scenario === 'last capacity') { F::storeCustomer($this->pdo, F::customer('c2')); $this->pdo->exec("UPDATE crm_members SET capacity_limit = 1 WHERE id = 'alice'"); }
        $args = match ($scenario) {
            'same customer' => [['alice', 'r1', 'c1'], ['bob', 'r2', 'c1']],
            'same request' => [['alice', 'r1', 'c1'], ['alice', 'r1', 'c1']],
            'last capacity' => [['alice', 'r1', 'c1'], ['alice', 'r2', 'c2']],
        };
        $workers = []; $locked = false;
        try {
            foreach ($args as $arg) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/claim-worker.php', $this->database, ...$arg], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process); $workers[] = [$process, $pipes];
            }
            foreach ($workers as [, $pipes]) { self::assertSame('READY', $this->line($pipes[1])); }
            // Keep both real PHP processes waiting for this database's write lock.
            $this->pdo->exec('BEGIN IMMEDIATE'); $locked = true;
            foreach ($workers as [, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); }
            foreach ($workers as [, $pipes]) { self::assertSame('EXECUTING', $this->line($pipes[1])); }
            $this->pdo->exec('COMMIT'); $locked = false;
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = json_decode($this->line($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
                fclose($pipes[0]); fclose($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error);
            }
            $workers = [];
            $allowed = array_column($results, 'allowed');
            if ($scenario === 'same request') {
                self::assertSame([true, true], $allowed); $replayed = array_column($results, 'replayed'); sort($replayed);
                self::assertSame([false, true], $replayed); self::assertSame(1, $this->requestCount());
            } else {
                sort($allowed); self::assertSame([false, true], $allowed);
                self::assertContains($scenario === 'last capacity' ? Reason::CapacityExceeded->value : Reason::AlreadyOwned->value, array_column($results, 'reason'));
                self::assertSame(2, $this->requestCount());
            }
            self::assertSame(1, (int) $this->pdo->query('SELECT SUM(version) FROM crm_customers')->fetchColumn());
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM crm_customers WHERE owner_id IS NOT NULL')->fetchColumn());
        } finally {
            if ($locked) { $this->pdo->exec('ROLLBACK'); }
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) { proc_terminate($process); }
                foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                if (is_resource($process)) { proc_close($process); }
            }
        }
    }

    public static function races(): iterable { yield ['same customer']; yield ['same request']; yield ['last capacity']; }

    #[DataProvider('invalidStoredTimes')]
    public function testRelativeOrMalformedStoredTimesFailClosed(string $time): void
    {
        F::storeCustomer($this->pdo, F::customer(protected: true));
        $this->pdo->prepare('UPDATE crm_customers SET protection_until = ?')->execute([$time]);
        try { $this->executor()->execute(new Command('t', 'alice', 'r', Action::Claim, ['c1'])); self::fail('Expected invalid stored timestamp.'); }
        catch (\UnexpectedValueException) { self::assertNull($this->row()['owner_id']); self::assertSame(0, $this->requestCount()); }
    }

    public static function invalidStoredTimes(): iterable
    {
        yield ['yesterday']; yield ['2026-01-01T12:00:00']; yield ['2026-02-31T12:00:00.000000+00:00'];
    }

    public function testStoredProtectionUsesInjectedClock(): void
    {
        $clock = new FrozenClock();
        F::storeCustomer($this->pdo, F::customer(protected: true, until: $clock->now()->modify('+1 microsecond')));
        $executor = new SqliteExecutor($this->pdo, new CapacityPolicy(false, false), $clock);
        self::assertSame(Reason::Protected, $executor->execute(new Command('t', 'alice', 'before', Action::Claim, ['c1']))->decision->reason);
        $clock->time = $clock->time->modify('+1 microsecond');
        self::assertTrue($executor->execute(new Command('t', 'alice', 'at', Action::Claim, ['c1']))->decision->allowed);
    }

    public function testTamperedReplayCannotReturnAnotherTenantPlan(): void
    {
        F::storeCustomer($this->pdo, F::customer()); $executor = $this->executor();
        $command = new Command('t', 'alice', 'r', Action::Claim, ['c1']); $executor->execute($command);
        $json = $this->pdo->query('SELECT result_json FROM crm_requests')->fetchColumn();
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR); $data['plan']['tenant'] = 'other';
        $this->pdo->prepare('UPDATE crm_requests SET result_json = ?')->execute([json_encode($data, JSON_THROW_ON_ERROR)]);
        try { $executor->execute($command); self::fail('Expected mismatched replay rejection.'); }
        catch (\UnexpectedValueException) { self::assertSame('alice', $this->row()['owner_id']); self::assertSame(1, $this->row()['version']); }
    }

    public function testMissingStoredRolesNeverGrants(): void
    {
        F::storeCustomer($this->pdo, F::customer()); $this->pdo->exec("UPDATE crm_members SET roles_json = '[]' WHERE id = 'alice'");
        self::assertSame(Reason::PermissionDenied, $this->executor()->execute(new Command('t', 'alice', 'r', Action::Claim, ['c1']))->decision->reason);
        self::assertNull($this->row()['owner_id']);
    }

    public function testPartialMissingBatchDoesNotApplyExistingCustomers(): void
    {
        F::storeCustomer($this->pdo, F::customer());
        self::assertSame(Reason::CustomerNotFound, $this->executor()->execute(new Command('t', 'alice', 'r', Action::Claim, ['c1', 'missing']))->decision->reason);
        self::assertNull($this->row()['owner_id']); self::assertSame(0, $this->row()['version']);
    }

    public function testConstructorRejectsDisabledJournalWithoutChangingIt(): void
    {
        F::storeCustomer($this->pdo, F::customer());
        self::assertSame('off', $this->pdo->query('PRAGMA journal_mode = OFF')->fetchColumn());
        try { $this->executor(); self::fail('Expected unsupported journal mode.'); }
        catch (\LogicException $error) { self::assertStringContainsString('journal mode', $error->getMessage()); }
        self::assertSame('off', $this->pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertNull($this->row()['owner_id']); self::assertSame(0, $this->requestCount());
    }

    public function testJournalChangedAfterConstructionIsRejectedBeforeSpillAndTrigger(): void
    {
        F::storeCustomer($this->pdo, F::customer('a'));
        // Put b on a different customer leaf page; the failing trigger forces dirty-page spilling.
        for ($i = 0; $i < 150; ++$i) {
            F::storeCustomer($this->pdo, F::customer('filler-' . str_pad((string) $i, 100, '0', STR_PAD_LEFT), department: str_repeat('d', 100)));
        }
        F::storeCustomer($this->pdo, F::customer('b'));
        $this->pdo->exec('CREATE TABLE synthetic_pressure (id INTEGER PRIMARY KEY, payload TEXT)');
        $insert = $this->pdo->prepare('INSERT INTO synthetic_pressure (payload) VALUES (?)');
        for ($i = 0; $i < 300; ++$i) { $insert->execute([str_repeat('x', 4000)]); }
        $insert->closeCursor();
        $this->pdo->exec("CREATE TRIGGER fail BEFORE UPDATE ON crm_customers WHEN NEW.id = 'b' BEGIN UPDATE synthetic_pressure SET payload = replace(payload, 'x', 'y'); SELECT RAISE(ABORT, 'synthetic failure'); END");
        $executor = $this->executor();
        $this->pdo->exec('PRAGMA cache_size = 5');
        $this->pdo->exec('PRAGMA cache_spill = ON');
        self::assertSame('off', $this->pdo->query('PRAGMA journal_mode = OFF')->fetchColumn());
        $command = new Command('t', 'alice', 'spill-safe', Action::Claim, ['a', 'b']);
        try { $executor->execute($command); self::fail('Expected journal mode rejection before any update.'); }
        catch (\LogicException $error) { self::assertStringContainsString('journal mode', $error->getMessage()); }
        self::assertSame('off', $this->pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertNull($this->row('a')['owner_id']); self::assertNull($this->row('b')['owner_id']);
        self::assertSame(0, $this->row('a')['version']); self::assertSame(0, $this->requestCount());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM synthetic_pressure WHERE substr(payload, 1, 1) = 'y'")->fetchColumn());

        // Only the caller restores configuration. The same failing trigger now rolls back all effects.
        self::assertSame('delete', $this->pdo->query('PRAGMA journal_mode = DELETE')->fetchColumn());
        try { $executor->execute($command); self::fail('Expected the real trigger failure.'); } catch (\PDOException) {}
        self::assertNull($this->row('a')['owner_id']); self::assertNull($this->row('b')['owner_id']);
        self::assertSame(0, $this->row('a')['version']); self::assertSame(0, $this->requestCount());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM synthetic_pressure WHERE substr(payload, 1, 1) = 'y'")->fetchColumn());
        $reopened = new \PDO('sqlite:' . $this->database);
        self::assertSame(0, (int) $reopened->query('SELECT COUNT(*) FROM crm_customers WHERE owner_id IS NOT NULL')->fetchColumn());
        self::assertSame(0, (int) $reopened->query("SELECT COUNT(*) FROM synthetic_pressure WHERE substr(payload, 1, 1) = 'y'")->fetchColumn());
        $reopened = null;
        $this->pdo->exec('DROP TRIGGER fail');
        self::assertTrue($executor->execute($command)->decision->allowed);
        self::assertSame(1, $this->requestCount()); self::assertSame(1, $this->row('a')['version']);
        self::assertSame(1, $this->row('b')['version']);
    }

    #[DataProvider('rollbackJournalModes')]
    public function testSupportedJournalModeRemainsUnchangedAndRollsBack(string $mode): void
    {
        self::assertSame($mode, $this->pdo->query('PRAGMA journal_mode = ' . $mode)->fetchColumn());
        F::storeCustomer($this->pdo, F::customer('a')); F::storeCustomer($this->pdo, F::customer('b'));
        $this->pdo->exec("CREATE TRIGGER fail BEFORE UPDATE ON crm_customers WHEN NEW.id = 'b' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $executor = $this->executor(); $command = new Command('t', 'alice', 'mode-safe', Action::Claim, ['a', 'b']);
        try { $executor->execute($command); self::fail('Expected real SQL failure.'); } catch (\PDOException) {}
        self::assertNull($this->row('a')['owner_id']); self::assertNull($this->row('b')['owner_id']);
        self::assertSame(0, $this->row('a')['version']); self::assertSame(0, $this->requestCount());
        self::assertSame($mode, $this->pdo->query('PRAGMA journal_mode')->fetchColumn());
        $this->pdo->exec('DROP TRIGGER fail');
        self::assertTrue($executor->execute($command)->decision->allowed);
        self::assertSame($mode, $this->pdo->query('PRAGMA journal_mode')->fetchColumn());
    }

    public static function rollbackJournalModes(): iterable
    {
        foreach (['delete', 'truncate', 'persist', 'memory', 'wal'] as $mode) { yield $mode => [$mode]; }
    }

    /** @param resource $stream */
    private function line($stream): string
    {
        $read = [$stream]; $write = null; $except = null;
        if (stream_select($read, $write, $except, 10) !== 1) { throw new \RuntimeException('Worker barrier timed out.'); }
        $line = fgets($stream);
        if ($line === false) { throw new \RuntimeException('Worker exited before its result.'); }
        return trim($line);
    }
}
