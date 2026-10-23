<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy\Tests\Unit;
use Lfcheng\CrmPolicy\{Action,CapacityPolicy,Command,CustomerStatus,PolicyEngine,Reason,Role,Scope,Snapshot};
use Lfcheng\CrmPolicy\Tests\Support\{Fixtures as F,FrozenClock};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicBoundaryTest extends TestCase
{
    #[DataProvider('malformedArrays')]
    public function testMalformedArrayInputsHaveExplicitExceptions(string $case): void
    {
        $this->expectException(\InvalidArgumentException::class);$now=new \DateTimeImmutable('2026-01-01T00:00:00Z');
        match($case){
            'customer scalar'=>new Snapshot('t',['bad'],[],[],$now),
            'member scalar'=>new Snapshot('t',[],['bad'],[],$now),
            'customer object'=>new Snapshot('t',[new \stdClass()],[],[],$now),
            'member object'=>new Snapshot('t',[],[new \stdClass()],[],$now),
            'customer associative'=>new Snapshot('t',['a'=>F::customer()],[],[],$now),
            'member associative'=>new Snapshot('t',[],['a'=>F::member()],[],$now),
            'numeric grant'=>new Role('role',[1=>Scope::Tenant]),
            'scalar grant'=>new Role('role',['claim'=>'tenant']),
        };
    }
    public static function malformedArrays(): iterable
    {
        foreach(['customer scalar','member scalar','customer object','member object','customer associative','member associative','numeric grant','scalar grant'] as $case){yield [$case];}
    }
    #[DataProvider('mixedBatchPolicies')]
    public function testMixedStatusNumericKeyBatchesConserveCapacity(Action $action,bool $won,bool $abandoned): void
    {
        $clock=new FrozenClock();$count=1+(int)$won+(int)$abandoned;
        $customers=[F::customer('1',owner:$action===Action::Claim?null:'alice'),F::customer('01',owner:$action===Action::Claim?null:'alice',status:CustomerStatus::Won),F::customer('z',owner:$action===Action::Claim?null:'alice',status:CustomerStatus::Abandoned)];
        $snapshot=new Snapshot('t',$customers,[F::member(limit:3),F::member('bob',limit:3)],['alice'=>$action===Action::Claim?0:$count,'bob'=>0],$clock->now());
        $engine=new PolicyEngine(new CapacityPolicy($won,$abandoned),$clock);
        $decision=$engine->decide(new Command('t','alice','r',$action,['z','1','01','1'],$action===Action::Transfer?'bob':null),$snapshot);
        self::assertTrue($decision->allowed);self::assertCount(3,$decision->plan->changes);
        self::assertSame(match($action){Action::Claim=>$count,Action::ReturnToPool=>-$count,Action::Transfer=>0},array_sum($decision->plan->capacityDeltas));
        foreach($decision->plan->changes as $change){self::assertSame(match($action){Action::Claim=>'alice',Action::Transfer=>'bob',Action::ReturnToPool=>null},$change->nextOwnerId);}
        foreach($customers as $customer){self::assertSame($action===Action::Claim?null:'alice',$customer->ownerId);}
    }
    public static function mixedBatchPolicies(): iterable
    {
        foreach(Action::cases() as $action){foreach([false,true] as $won){foreach([false,true] as $abandoned){yield [$action,$won,$abandoned];}}}
    }
    #[DataProvider('offsets')]
    public function testProtectionDeadlineIsAnInstantAcrossTimezones(string $offset): void
    {
        $clock=new FrozenClock();$until=$clock->now()->setTimezone(new \DateTimeZone($offset));
        $engine=new PolicyEngine(new CapacityPolicy(false,false),$clock,0);
        $command=new Command('t','alice','r',Action::Claim,['a']);
        $snapshot=new Snapshot('t',[F::customer('a',protected:true,until:$until)],[F::member()],['alice'=>0],$clock->now());
        self::assertTrue($engine->decide($command,$snapshot)->allowed);
        $future=new Snapshot('t',[F::customer('a',protected:true,until:$until->modify('+1 microsecond'))],[F::member()],['alice'=>0],$clock->now());
        self::assertSame(Reason::Protected,$engine->decide($command,$future)->reason);
        $clock->time=$clock->time->modify('+1 microsecond');
        self::assertSame(Reason::SnapshotExpired,$engine->decide($command,$snapshot)->reason);
    }
    public static function offsets(): iterable { yield ['+14:00'];yield ['-12:00'];yield ['+05:45']; }
}
