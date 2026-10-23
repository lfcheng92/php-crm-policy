<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy\Tests\Integration;

use Lfcheng\CrmPolicy\{Action, CapacityPolicy, Command};
use Lfcheng\CrmPolicy\Sqlite\{PersistenceFailure, Schema, SqliteExecutor};
use Lfcheng\CrmPolicy\Tests\Support\Fixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqliteFailureRecoveryTest extends TestCase
{
    private \PDO $pdo;
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        Schema::create($this->pdo);
        F::storeMember($this->pdo, F::member());
        F::storeCustomer($this->pdo, F::customer('a'));
        F::storeCustomer($this->pdo, F::customer('b'));
    }
    private function command(): Command { return new Command('t', 'alice', 'atomic', Action::Claim, ['a', 'b']); }
    private function untouched(): void
    {
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM crm_customers WHERE owner_id IS NOT NULL')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT SUM(version) FROM crm_customers')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM crm_requests')->fetchColumn());
    }
    #[DataProvider('writeCorruptionCases')]
    public function testSuppressedOrRewrittenWritesRollBackAndRetry(string $trigger): void
    {
        $executor = new SqliteExecutor($this->pdo, new CapacityPolicy(false, false));
        $this->pdo->exec($trigger);
        try { $executor->execute($this->command()); self::fail('Expected storage postcondition failure.'); }
        catch (PersistenceFailure) { $this->untouched(); }
        $this->pdo->exec('DROP TRIGGER synthetic_fault');
        $first = $executor->execute($this->command());
        self::assertTrue($first->decision->allowed); self::assertFalse($first->replayed);
        self::assertTrue($executor->execute($this->command())->replayed);
        self::assertSame(2, (int) $this->pdo->query('SELECT SUM(version) FROM crm_customers')->fetchColumn());
    }
    public static function writeCorruptionCases(): iterable
    {
        yield 'ignored receipt' => ["CREATE TRIGGER synthetic_fault BEFORE INSERT ON crm_requests BEGIN SELECT RAISE(IGNORE); END"];
        yield 'deleted receipt' => ["CREATE TRIGGER synthetic_fault AFTER INSERT ON crm_requests BEGIN DELETE FROM crm_requests WHERE tenant_id=NEW.tenant_id AND request_id=NEW.request_id; END"];
        yield 'rewritten receipt' => ["CREATE TRIGGER synthetic_fault AFTER INSERT ON crm_requests BEGIN UPDATE crm_requests SET result_json='{}' WHERE tenant_id=NEW.tenant_id AND request_id=NEW.request_id; END"];
        yield 'reverted customer' => ["CREATE TRIGGER synthetic_fault AFTER UPDATE ON crm_customers BEGIN UPDATE crm_customers SET owner_id=OLD.owner_id, version=OLD.version WHERE tenant_id=NEW.tenant_id AND id=NEW.id; END"];
        yield 'deleted customer' => ["CREATE TRIGGER synthetic_fault AFTER UPDATE ON crm_customers BEGIN DELETE FROM crm_customers WHERE tenant_id=NEW.tenant_id AND id=NEW.id; END"];
        yield 'receipt undoes customers' => ["CREATE TRIGGER synthetic_fault AFTER INSERT ON crm_requests BEGIN UPDATE crm_customers SET owner_id=NULL, version=0; END"];
        yield 'later customer undoes earlier one' => ["CREATE TRIGGER synthetic_fault AFTER UPDATE ON crm_customers WHEN NEW.id='b' BEGIN UPDATE crm_customers SET owner_id=NULL, version=0 WHERE id='a'; END"];
    }
    #[DataProvider('unsafeErrorModes')]
    public function testChangedErrorModeIsRejectedBeforeAnyWrite(int $mode): void
    {
        $executor = new SqliteExecutor($this->pdo, new CapacityPolicy(false, false));
        $this->pdo->exec("CREATE TRIGGER synthetic_fault BEFORE INSERT ON crm_requests BEGIN SELECT RAISE(ABORT,'synthetic'); END");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, $mode);
        try { $executor->execute($this->command()); self::fail('Expected PDO configuration rejection.'); }
        catch (\LogicException) { $this->untouched(); }
        self::assertSame($mode, $this->pdo->getAttribute(\PDO::ATTR_ERRMODE));
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('DROP TRIGGER synthetic_fault');
        self::assertTrue($executor->execute($this->command())->decision->allowed);
    }
    public static function unsafeErrorModes(): iterable
    {
        yield 'silent' => [\PDO::ERRMODE_SILENT]; yield 'warning' => [\PDO::ERRMODE_WARNING];
    }
    public function testDeferredConstraintCommitFailureRollsBackReceiptAndCustomers(): void
    {
        $this->pdo->exec('CREATE TABLE synthetic_parent (id INTEGER PRIMARY KEY); CREATE TABLE synthetic_child (parent_id INTEGER REFERENCES synthetic_parent(id) DEFERRABLE INITIALLY DEFERRED)');
        $this->pdo->exec("CREATE TRIGGER synthetic_fault AFTER INSERT ON crm_requests BEGIN INSERT INTO synthetic_child VALUES (404); END");
        $executor = new SqliteExecutor($this->pdo, new CapacityPolicy(false, false));
        try { $executor->execute($this->command()); self::fail('Expected COMMIT constraint failure.'); }
        catch (\PDOException $error) { self::assertSame(19, $error->errorInfo[1]); $this->untouched(); }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM synthetic_child')->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
        $this->pdo->exec('DROP TRIGGER synthetic_fault');
        self::assertTrue($executor->execute($this->command())->decision->allowed);
        self::assertTrue($executor->execute($this->command())->replayed);
    }
    public function testRawOuterTransactionIsNotCommittedOrRolledBack(): void
    {
        $executor = new SqliteExecutor($this->pdo, new CapacityPolicy(false, false));
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->pdo->exec("UPDATE crm_members SET capacity_limit=7 WHERE id='alice'");
        try { $executor->execute($this->command()); self::fail('Expected outer transaction rejection.'); }
        catch (\LogicException) { $this->untouched(); }
        self::assertSame(7, (int) $this->pdo->query("SELECT capacity_limit FROM crm_members WHERE id='alice'")->fetchColumn());
        $this->pdo->exec('ROLLBACK');
        self::assertSame(2, (int) $this->pdo->query("SELECT capacity_limit FROM crm_members WHERE id='alice'")->fetchColumn());
    }
    public function testReaderIsIndependentOfChangedDefaultFetchMode(): void
    {
        $executor = new SqliteExecutor($this->pdo, new CapacityPolicy(false, false));
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);
        self::assertTrue($executor->execute($this->command())->decision->allowed);
        self::assertTrue($executor->execute($this->command())->replayed);
        self::assertSame(\PDO::FETCH_OBJ, $this->pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE));
    }
    public function testNumericAndUnicodeIdentifiersRemainTenantScoped(): void
    {
        F::storeMember($this->pdo, F::member('1', tenant: '租户-A'));
        F::storeMember($this->pdo, F::member('01', tenant: '租户-A'));
        F::storeMember($this->pdo, F::member('1', tenant: '租户-B'));
        foreach (['1', '01'] as $id) { F::storeCustomer($this->pdo, F::customer($id, tenant: '租户-A')); }
        F::storeCustomer($this->pdo, F::customer('1', tenant: '租户-B'));
        $executor = new SqliteExecutor($this->pdo, new CapacityPolicy(false, false));
        $result = $executor->execute(new Command('租户-A', '1', 'same-key', Action::Claim, ['1', '01', '1']));
        self::assertTrue($result->decision->allowed); self::assertCount(2, $result->decision->plan->changes);
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM crm_customers WHERE tenant_id='租户-A' AND owner_id='1'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM crm_customers WHERE tenant_id='租户-B' AND owner_id IS NOT NULL")->fetchColumn());
        self::assertTrue($executor->execute(new Command('租户-B', '1', 'same-key', Action::Claim, ['1']))->decision->allowed);
    }
}
