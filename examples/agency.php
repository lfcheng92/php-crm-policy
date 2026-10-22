<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Lfcheng\CrmPolicy\{Action, CapacityPolicy, Command, Customer, CustomerStatus, Member, PolicyEngine, Role, Scope, Snapshot, SystemClock};

// Synthetic agency: won and abandoned customers do not occupy active prospect capacity.
$clock = new SystemClock();
$member = new Member('agency-demo', 'seller', 'sales', true, 1, [new Role('seller', ['claim' => Scope::Own])]);
$customer = new Customer('agency-demo', 'sample-won', null, 'sales', CustomerStatus::Won, false, null, 0);
$snapshot = new Snapshot('agency-demo', [$customer], [$member], ['seller' => 1], $clock->now());
$decision = (new PolicyEngine(new CapacityPolicy(countWon: false, countAbandoned: false), $clock))->decide(
    new Command('agency-demo', 'seller', 'demo-agency-1', Action::Claim, ['sample-won']), $snapshot,
);
if (!$decision->allowed || $decision->plan === null || $decision->plan->capacityDeltas !== []) { throw new RuntimeException('Agency example failed.'); }
echo "Agency: excluded won customer can be claimed at the active-capacity limit.\n";
