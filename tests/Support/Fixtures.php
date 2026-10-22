<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy\Tests\Support;

use Lfcheng\CrmPolicy\{Customer, CustomerStatus, Member, Role, Scope};

final class Fixtures
{
    /** @param array<string, Scope>|null $grants */
    public static function member(string $id = 'alice', ?string $department = 'sales', ?int $limit = 2, Scope $scope = Scope::Own, bool $active = true, string $tenant = 't', ?array $grants = null): Member
    {
        return new Member($tenant, $id, $department, $active, $limit, [new Role('example-role', $grants ?? ['claim' => $scope, 'return' => $scope, 'transfer' => $scope])]);
    }

    public static function customer(string $id = 'c1', ?string $owner = null, ?string $department = 'sales', CustomerStatus $status = CustomerStatus::Active, bool $protected = false, ?\DateTimeImmutable $until = null, string $tenant = 't', int $version = 0): Customer
    {
        return new Customer($tenant, $id, $owner, $department, $status, $protected, $until, $version);
    }

    public static function storeMember(\PDO $pdo, Member $member): void
    {
        $roles = array_map(static fn (Role $r): array => ['name' => $r->name, 'grants' => (object) array_map(static fn (Scope $s): int => $s->value, $r->grants)], $member->roles);
        $pdo->prepare('INSERT INTO crm_members VALUES (?, ?, ?, ?, ?, ?)')->execute([$member->tenantId, $member->id, $member->departmentId, (int) $member->active, $member->capacityLimit, json_encode($roles, JSON_THROW_ON_ERROR)]);
    }

    public static function storeCustomer(\PDO $pdo, Customer $customer): void
    {
        $pdo->prepare('INSERT INTO crm_customers VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$customer->tenantId, $customer->id, $customer->ownerId, $customer->departmentId, $customer->status->value, (int) $customer->protected, $customer->protectionUntil?->format('Y-m-d\TH:i:s.uP'), $customer->version]);
    }
}
