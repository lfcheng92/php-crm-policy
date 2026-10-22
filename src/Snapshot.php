<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy;

use Lfcheng\CrmPolicy\Internal\Guard;

final readonly class Snapshot
{
    /** @var array<string, Customer> */
    private array $customers;
    /** @var array<string, Member> */
    private array $members;
    /** @var array<string, int> */
    private array $usage;

    /**
     * Usage must be complete for every affected owner, using the SAME CapacityPolicy as the engine.
     * @param list<Customer> $customers
     * @param list<Member> $members
     * @param array<array-key, int> $capacityUsage
     */
    public function __construct(public string $tenantId, array $customers, array $members, array $capacityUsage, public \DateTimeImmutable $capturedAt)
    {
        Guard::id($tenantId);
        $customerMap = [];
        foreach ($customers as $customer) {
            $key = 'id:' . $customer->id;
            if (isset($customerMap[$key])) { throw new \InvalidArgumentException('Duplicate customer in snapshot.'); }
            $customerMap[$key] = $customer;
        }
        $memberMap = [];
        foreach ($members as $member) {
            $key = 'id:' . $member->id;
            if (isset($memberMap[$key])) { throw new \InvalidArgumentException('Duplicate member in snapshot.'); }
            $memberMap[$key] = $member;
        }
        $usage = [];
        foreach ($capacityUsage as $id => $count) {
            Guard::id((string) $id);
            if (!is_int($count) || $count < 0) { throw new \InvalidArgumentException('Usage must be a non-negative integer.'); }
            $usage['id:' . $id] = $count;
        }
        $this->customers = $customerMap;
        $this->members = $memberMap;
        $this->usage = $usage;
    }

    public function customer(string $id): ?Customer { return $this->customers['id:' . $id] ?? null; }
    public function member(string $id): ?Member { return $this->members['id:' . $id] ?? null; }
    public function usageFor(string $id): ?int { return $this->usage['id:' . $id] ?? null; }
}
