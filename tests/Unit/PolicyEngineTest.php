<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy\Tests\Unit;

use Lfcheng92\CrmPolicy\{Action, CapacityPolicy, Command, CustomerStatus, Member, PolicyEngine, Reason, Role, Scope, Snapshot, SystemClock};
use Lfcheng92\CrmPolicy\Tests\Support\{Fixtures as F, FrozenClock};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyEngineTest extends TestCase
{
    public function testClaimCreatesDeduplicatedPlanWithoutMutation(): void
    {
        $clock = new FrozenClock(); $customer = F::customer();
        $command = new Command('t', 'alice', 'req', Action::Claim, ['c1', 'c1']);
        $snapshot = new Snapshot('t', [$customer], [F::member()], ['alice' => 0], $clock->now());
        $decision = (new PolicyEngine(new CapacityPolicy(false, false), $clock))->decide($command, $snapshot);
        self::assertTrue($decision->allowed); self::assertNull($decision->reason);
        self::assertNotNull($decision->plan); self::assertCount(1, $decision->plan->changes);
        self::assertSame(['alice' => 1], $decision->plan->capacityDeltas);
        self::assertSame('alice', $decision->plan->changes[0]->nextOwnerId);
        self::assertSame(0, $decision->plan->changes[0]->expectedVersion);
        self::assertNull($customer->ownerId); self::assertSame(0, $customer->version);
    }

    public function testCanonicalBatchAndFingerprint(): void
    {
        $a = new Command('t', 'alice', 'r1', Action::Claim, ['2', '01', '1', '2']);
        $b = new Command('t', 'alice', 'r2', Action::Claim, ['1', '2', '01']);
        self::assertSame(['01', '1', '2'], $a->customerIds);
        self::assertSame($a->fingerprint(), $b->fingerprint());
        self::assertNotSame($a->fingerprint(), (new Command('other', 'alice', 'r', Action::Claim, ['1', '2', '01']))->fingerprint());
    }

    public function testReturnAllowsOverCapacityOwnerToRelease(): void
    {
        $clock = new FrozenClock();
        $decision = (new PolicyEngine(new CapacityPolicy(true, true), $clock))->decide(
            new Command('t', 'alice', 'r', Action::ReturnToPool, ['c1']),
            new Snapshot('t', [F::customer(owner: 'alice')], [F::member(limit: 0)], ['alice' => 3], $clock->now()),
        );
        self::assertTrue($decision->allowed); self::assertNotNull($decision->plan);
        self::assertSame(['alice' => -1], $decision->plan->capacityDeltas);
        self::assertNull($decision->plan->changes[0]->nextOwnerId);
        self::assertSame('sales', $decision->plan->changes[0]->nextDepartmentId);
    }

    #[DataProvider('scopeCases')]
    public function testExplicitScopes(Action $action, Scope $scope, ?string $owner, string $customerDept, string $targetDept, bool $allowed): void
    {
        $clock = new FrozenClock();
        $members = [F::member(scope: $scope), F::member('bob', department: $customerDept), F::member('carol', department: $targetDept)];
        $decision = (new PolicyEngine(new CapacityPolicy(false, false), $clock))->decide(
            new Command('t', 'alice', 'r', $action, ['c1'], $action === Action::Transfer ? 'carol' : null),
            new Snapshot('t', [F::customer(owner: $owner, department: $customerDept)], $members, ['alice' => $owner === 'alice' ? 1 : 0, 'bob' => 1, 'carol' => 0], $clock->now()),
        );
        self::assertSame($allowed, $decision->allowed);
        if (!$allowed) { self::assertSame(Reason::PermissionDenied, $decision->reason); self::assertNull($decision->plan); }
        if ($allowed && $action === Action::Transfer) {
            self::assertNotNull($decision->plan); self::assertSame($targetDept, $decision->plan->changes[0]->nextDepartmentId);
        }
    }

    public static function scopeCases(): iterable
    {
        yield 'own claim local pool' => [Action::Claim, Scope::Own, null, 'sales', 'sales', true];
        yield 'own claim foreign pool' => [Action::Claim, Scope::Own, null, 'other', 'sales', false];
        yield 'department claim foreign pool' => [Action::Claim, Scope::Department, null, 'other', 'sales', false];
        yield 'tenant claim foreign pool' => [Action::Claim, Scope::Tenant, null, 'other', 'sales', true];
        yield 'own return own' => [Action::ReturnToPool, Scope::Own, 'alice', 'sales', 'sales', true];
        yield 'own return colleague' => [Action::ReturnToPool, Scope::Own, 'bob', 'sales', 'sales', false];
        yield 'department return colleague' => [Action::ReturnToPool, Scope::Department, 'bob', 'sales', 'sales', true];
        yield 'department return foreign' => [Action::ReturnToPool, Scope::Department, 'bob', 'other', 'sales', false];
        yield 'tenant return foreign' => [Action::ReturnToPool, Scope::Tenant, 'bob', 'other', 'sales', true];
        yield 'own transfer local' => [Action::Transfer, Scope::Own, 'alice', 'sales', 'sales', true];
        yield 'own transfer colleague' => [Action::Transfer, Scope::Own, 'bob', 'sales', 'sales', false];
        yield 'own transfer foreign target' => [Action::Transfer, Scope::Own, 'alice', 'sales', 'other', false];
        yield 'department transfer local' => [Action::Transfer, Scope::Department, 'bob', 'sales', 'sales', true];
        yield 'department transfer foreign target' => [Action::Transfer, Scope::Department, 'bob', 'sales', 'other', false];
        yield 'tenant transfer cross department' => [Action::Transfer, Scope::Tenant, 'bob', 'other', 'third', true];
    }

    #[DataProvider('capacityCases')]
    public function testStatusCountingIsConfigurable(CustomerStatus $status, bool $countWon, bool $countAbandoned): void
    {
        $clock = new FrozenClock(); $policy = new CapacityPolicy($countWon, $countAbandoned);
        $decision = (new PolicyEngine($policy, $clock))->decide(new Command('t', 'alice', 'r', Action::Claim, ['c1']),
            new Snapshot('t', [F::customer(status: $status)], [F::member(limit: 0)], ['alice' => 0], $clock->now()));
        self::assertSame(!$policy->counts($status), $decision->allowed);
        if ($policy->counts($status)) { self::assertSame(Reason::CapacityExceeded, $decision->reason); }
    }

    public static function capacityCases(): iterable
    {
        foreach (CustomerStatus::cases() as $status) {
            foreach ([false, true] as $won) { foreach ([false, true] as $abandoned) {
                yield $status->value . '-' . (int) $won . '-' . (int) $abandoned => [$status, $won, $abandoned];
            } }
        }
    }

    #[DataProvider('protectionCases')]
    public function testProtectionBoundary(Action $action, ?string $until, bool $blocked): void
    {
        $clock = new FrozenClock();
        $customer = F::customer(owner: $action === Action::Claim ? null : 'alice', protected: true, until: $until === null ? null : new \DateTimeImmutable($until));
        $decision = (new PolicyEngine(new CapacityPolicy(false, false), $clock))->decide(
            new Command('t', 'alice', 'r', $action, ['c1'], $action === Action::Transfer ? 'bob' : null),
            new Snapshot('t', [$customer], [F::member(), F::member('bob')], ['alice' => 1, 'bob' => 0], $clock->now()),
        );
        self::assertSame(!$blocked, $decision->allowed);
        if ($blocked) { self::assertSame(Reason::Protected, $decision->reason); }
    }

    public static function protectionCases(): iterable
    {
        foreach (Action::cases() as $action) {
            yield $action->value . '-indefinite' => [$action, null, true];
            yield $action->value . '-future' => [$action, '2026-01-01T12:00:00.000001Z', true];
            yield $action->value . '-exact' => [$action, '2026-01-01T12:00:00Z', false];
            yield $action->value . '-past' => [$action, '2026-01-01T11:59:59Z', false];
        }
    }

    #[DataProvider('missingCases')]
    public function testMissingOrUntrustedDataFailsClosed(string $case, Reason $reason): void
    {
        $clock = new FrozenClock(); $member = F::member(); $customer = F::customer();
        $members = [$member]; $customers = [$customer]; $usage = ['alice' => 0]; $tenant = 't';
        $at = $clock->now();
        switch ($case) {
            case 'actor missing': $members = []; break;
            case 'inactive': $members = [F::member(active: false)]; break;
            case 'no grant': $members = [F::member(grants: [])]; break;
            case 'named admin': $members = [new Member('t', 'alice', 'sales', true, 5, [new Role('admin', [])])]; break;
            case 'member department': $members = [F::member(department: null, scope: Scope::Tenant)]; break;
            case 'customer missing': $customers = []; break;
            case 'customer department': $customers = [F::customer(department: null)]; break;
            case 'limit missing': $members = [F::member(limit: null)]; break;
            case 'usage missing': $usage = []; break;
            case 'snapshot tenant': $tenant = 'other'; break;
            case 'actor tenant': $members = [F::member(tenant: 'other')]; break;
            case 'customer tenant': $customers = [F::customer(tenant: 'other')]; break;
            case 'future snapshot': $at = $at->modify('+1 microsecond'); break;
            case 'stale snapshot': $at = $at->modify('-60 seconds -1 microsecond'); break;
            case 'already owned': $customers = [F::customer(owner: 'alice')]; break;
        }
        $decision = (new PolicyEngine(new CapacityPolicy(false, false), $clock))->decide(new Command('t', 'alice', 'r', Action::Claim, ['c1']), new Snapshot($tenant, $customers, $members, $usage, $at));
        self::assertFalse($decision->allowed); self::assertSame($reason, $decision->reason); self::assertNull($decision->plan);
    }

    public static function missingCases(): iterable
    {
        yield ['actor missing', Reason::ActorNotFound]; yield ['inactive', Reason::ActorInactive];
        yield ['no grant', Reason::PermissionDenied]; yield ['named admin', Reason::PermissionDenied];
        yield ['member department', Reason::DepartmentMissing]; yield ['customer missing', Reason::CustomerNotFound];
        yield ['customer department', Reason::DepartmentMissing]; yield ['limit missing', Reason::CapacityMissing];
        yield ['usage missing', Reason::CapacityMissing]; yield ['snapshot tenant', Reason::TenantMismatch];
        yield ['actor tenant', Reason::TenantMismatch]; yield ['customer tenant', Reason::TenantMismatch];
        yield ['future snapshot', Reason::SnapshotExpired]; yield ['stale snapshot', Reason::SnapshotExpired];
        yield ['already owned', Reason::AlreadyOwned];
    }

    #[DataProvider('transferFailures')]
    public function testTransferAndReturnFailClosed(string $case, Reason $reason): void
    {
        $clock = new FrozenClock(); $action = Action::Transfer;
        $actor = F::member(scope: Scope::Tenant); $owner = F::member('owner'); $target = F::member('target');
        $customer = F::customer(owner: 'owner'); $usage = ['owner' => 1, 'target' => 0];
        switch ($case) {
            case 'target missing': $target = null; break;
            case 'target inactive': $target = F::member('target', active: false); break;
            case 'target department': $target = F::member('target', department: null); break;
            case 'target tenant': $target = F::member('target', tenant: 'other'); break;
            case 'not owned': $customer = F::customer(); break;
            case 'same owner': $customer = F::customer(owner: 'target'); break;
            case 'owner missing': $owner = null; break;
            case 'owner tenant': $owner = F::member('owner', tenant: 'other'); break;
            case 'owner department': $owner = F::member('owner', department: null); break;
            case 'department inconsistent': $owner = F::member('owner', department: 'other'); break;
            case 'usage missing': unset($usage['owner']); break;
            case 'usage inconsistent': $usage['owner'] = 0; break;
        }
        $members = array_values(array_filter([$actor, $owner, $target]));
        $decision = (new PolicyEngine(new CapacityPolicy(false, false), $clock))->decide(new Command('t', 'alice', 'r', $action, ['c1'], 'target'), new Snapshot('t', [$customer], $members, $usage, $clock->now()));
        self::assertSame($reason, $decision->reason); self::assertFalse($decision->allowed); self::assertNull($decision->plan);
    }

    public static function transferFailures(): iterable
    {
        yield ['target missing', Reason::TargetNotFound]; yield ['target inactive', Reason::TargetInactive];
        yield ['target department', Reason::DepartmentMissing]; yield ['target tenant', Reason::TenantMismatch];
        yield ['not owned', Reason::NotOwned]; yield ['same owner', Reason::SameOwner];
        yield ['owner missing', Reason::OwnerNotFound]; yield ['owner tenant', Reason::TenantMismatch];
        yield ['owner department', Reason::DepartmentMissing]; yield ['department inconsistent', Reason::InconsistentSnapshot];
        yield ['usage missing', Reason::CapacityMissing]; yield ['usage inconsistent', Reason::InconsistentSnapshot];
    }

    public function testBatchCapacityIsCumulativeAndAtomic(): void
    {
        $clock = new FrozenClock(); $engine = new PolicyEngine(new CapacityPolicy(false, false), $clock);
        $command = new Command('t', 'alice', 'r', Action::Claim, ['c2', 'c1']);
        $snapshot = new Snapshot('t', [F::customer('c1'), F::customer('c2')], [F::member(limit: 1)], ['alice' => 0], $clock->now());
        $decision = $engine->decide($command, $snapshot);
        self::assertSame(Reason::CapacityExceeded, $decision->reason); self::assertNull($decision->plan);
        $snapshot = new Snapshot('t', [F::customer('c1'), F::customer('c2', protected: true)], [F::member()], ['alice' => 0], $clock->now());
        $decision = $engine->decide($command, $snapshot);
        self::assertSame(Reason::Protected, $decision->reason); self::assertSame('c2', $decision->customerId); self::assertNull($decision->plan);
    }

    public function testExactCapacityAndSnapshotAgeBoundaries(): void
    {
        $clock = new FrozenClock(); $engine = new PolicyEngine(new CapacityPolicy(false, false), $clock);
        $decision = $engine->decide(new Command('t', 'alice', 'r', Action::Claim, ['c1']), new Snapshot('t', [F::customer()], [F::member(limit: PHP_INT_MAX)], ['alice' => PHP_INT_MAX - 1], $clock->now()->modify('-60 seconds')));
        self::assertTrue($decision->allowed);
        $decision = $engine->decide(new Command('t', 'alice', 'r', Action::Claim, ['c1']), new Snapshot('t', [F::customer()], [F::member(limit: PHP_INT_MAX)], ['alice' => PHP_INT_MAX], $clock->now()));
        self::assertSame(Reason::CapacityExceeded, $decision->reason);
    }

    public function testSnapshotMicrosecondsAlsoWorkBeforeEpoch(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('1970-01-01T00:00:00.100000Z'));
        $engine = new PolicyEngine(new CapacityPolicy(false, false), $clock, 1);
        $command = new Command('t', 'alice', 'r', Action::Claim, ['c1']);
        $snapshot = new Snapshot('t', [F::customer()], [F::member()], ['alice' => 0], new \DateTimeImmutable('1969-12-31T23:59:59.100000Z'));
        self::assertTrue($engine->decide($command, $snapshot)->allowed);
        $snapshot = new Snapshot('t', [F::customer()], [F::member()], ['alice' => 0], new \DateTimeImmutable('1969-12-31T23:59:59.099999Z'));
        self::assertSame(Reason::SnapshotExpired, $engine->decide($command, $snapshot)->reason);
    }

    public function testRoleUnionUsesStrongestExplicitActionGrant(): void
    {
        $member = new Member('t', 'alice', 'sales', true, 1, [new Role('sales', ['claim' => Scope::Own]), new Role('manager', ['claim' => Scope::Tenant])]);
        self::assertSame(Scope::Tenant, $member->scopeFor(Action::Claim));
        self::assertNull($member->scopeFor(Action::Transfer));
        self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
    }
}
