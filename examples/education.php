<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Lfcheng92\CrmPolicy\{Action, CapacityPolicy, Command, Customer, CustomerStatus, Member, PolicyEngine, Reason, Role, Scope, Snapshot, SystemClock};

// Synthetic education business: enrolled/won customers continue to consume advisor capacity.
$clock = new SystemClock();
$advisor = new Member('education-demo', 'advisor', 'admissions', true, 1, [new Role('advisor', ['claim' => Scope::Department])]);
$customer = new Customer('education-demo', 'sample-enrolment', null, 'admissions', CustomerStatus::Won, false, null, 0);
$snapshot = new Snapshot('education-demo', [$customer], [$advisor], ['advisor' => 1], $clock->now());
$decision = (new PolicyEngine(new CapacityPolicy(countWon: true, countAbandoned: false), $clock))->decide(
    new Command('education-demo', 'advisor', 'demo-education-1', Action::Claim, ['sample-enrolment']), $snapshot,
);
if ($decision->allowed || $decision->reason !== Reason::CapacityExceeded) { throw new RuntimeException('Education example failed.'); }
echo "Education: counted won customer is rejected when advisor capacity is full.\n";
