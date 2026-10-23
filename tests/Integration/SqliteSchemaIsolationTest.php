<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy\Tests\Integration;
use Lfcheng\CrmPolicy\{Action,CapacityPolicy,Command,Decision,Reason};
use Lfcheng\CrmPolicy\Sqlite\{DecisionCodec,Schema,SqliteExecutor};
use Lfcheng\CrmPolicy\Tests\Support\Fixtures as F;
use PHPUnit\Framework\TestCase;

final class SqliteSchemaIsolationTest extends TestCase
{
    private function database(bool $grants=true): \PDO
    {
        $pdo=new \PDO('sqlite::memory:');Schema::create($pdo);
        F::storeMember($pdo,F::member(grants:$grants?null:[]));F::storeCustomer($pdo,F::customer('a'));
        return $pdo;
    }
    private function shadow(\PDO $pdo): void
    {
        foreach(['crm_members','crm_customers','crm_requests'] as $table){$pdo->exec('CREATE TEMP TABLE '.$table.' AS SELECT * FROM main.'.$table);}
    }
    private function command(): Command { return new Command('t','alice','r',Action::Claim,['a']); }
    public function testTemporaryGrantsCannotOverrideMainPermissions(): void
    {
        $pdo=$this->database(false);$this->shadow($pdo);
        $pdo->prepare('UPDATE temp.crm_members SET roles_json=?')->execute([json_encode([['name'=>'synthetic','grants'=>['claim'=>3]]],JSON_THROW_ON_ERROR)]);
        $result=(new SqliteExecutor($pdo,new CapacityPolicy(false,false)))->execute($this->command());
        self::assertSame(Reason::PermissionDenied,$result->decision->reason);
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM main.crm_customers WHERE owner_id IS NOT NULL')->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM temp.crm_customers WHERE owner_id IS NOT NULL')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM main.crm_requests')->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM temp.crm_requests')->fetchColumn());
    }
    public function testTemporaryProtectionAndTablesCannotRedirectWrites(): void
    {
        $pdo=$this->database();$this->shadow($pdo);$pdo->exec('UPDATE temp.crm_customers SET protected=1');
        $executor=new SqliteExecutor($pdo,new CapacityPolicy(false,false));
        self::assertTrue($executor->execute($this->command())->decision->allowed);
        self::assertTrue($executor->execute($this->command())->replayed);
        self::assertSame('alice',$pdo->query("SELECT owner_id FROM main.crm_customers WHERE id='a'")->fetchColumn());
        self::assertNull($pdo->query("SELECT owner_id FROM temp.crm_customers WHERE id='a'")->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM main.crm_requests')->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM temp.crm_requests')->fetchColumn());
    }
    public function testTemporaryReceiptCannotForgeAnIdempotentResponse(): void
    {
        $pdo=$this->database();$this->shadow($pdo);$command=$this->command();
        $pdo->prepare('INSERT INTO temp.crm_requests VALUES(?,?,?,?)')->execute(['t','r',$command->fingerprint(),DecisionCodec::encode(Decision::deny(Reason::ActorInactive))]);
        $result=(new SqliteExecutor($pdo,new CapacityPolicy(false,false)))->execute($command);
        self::assertTrue($result->decision->allowed);self::assertFalse($result->replayed);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM main.crm_requests')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM temp.crm_requests')->fetchColumn());
    }
    public function testMissingMainTableCannotFallBackToAttachedDatabase(): void
    {
        $pdo=$this->database();$pdo->exec("ATTACH DATABASE ':memory:' AS synthetic; CREATE TABLE synthetic.crm_requests AS SELECT * FROM main.crm_requests; DROP TABLE main.crm_requests");
        try{(new SqliteExecutor($pdo,new CapacityPolicy(false,false)))->execute($this->command());self::fail('Expected missing main table error.');}
        catch(\PDOException){self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM main.crm_customers WHERE owner_id IS NOT NULL')->fetchColumn());}
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM synthetic.crm_requests')->fetchColumn());
    }
    public function testSchemaCreationExplicitlyTargetsMainWithExistingTempNames(): void
    {
        $pdo=new \PDO('sqlite::memory:');
        foreach(['crm_members','crm_customers','crm_requests'] as $table){$pdo->exec('CREATE TEMP TABLE '.$table.' (unrelated TEXT)');}
        Schema::create($pdo);
        self::assertSame(3,(int)$pdo->query("SELECT COUNT(*) FROM main.sqlite_master WHERE type='table' AND name LIKE 'crm_%'")->fetchColumn());
        self::assertSame(1,(int)$pdo->query("SELECT COUNT(*) FROM main.sqlite_master WHERE type='index' AND name='crm_capacity'")->fetchColumn());
        self::assertSame(3,(int)$pdo->query("SELECT COUNT(*) FROM temp.sqlite_master WHERE type='table' AND name LIKE 'crm_%'")->fetchColumn());
        self::assertSame(6,count($pdo->query('PRAGMA main.table_info(crm_members)')->fetchAll(\PDO::FETCH_ASSOC)));
        self::assertSame(1,count($pdo->query('PRAGMA temp.table_info(crm_members)')->fetchAll(\PDO::FETCH_ASSOC)));
    }
}
