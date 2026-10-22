<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy;

use Lfcheng92\CrmPolicy\Internal\Guard;

final readonly class Member
{
    /** @param list<Role> $roles Role names have no implicit privileges. */
    public function __construct(
        public string $tenantId,
        public string $id,
        public ?string $departmentId,
        public bool $active,
        public ?int $capacityLimit,
        public array $roles = [],
    ) {
        Guard::id($tenantId);
        Guard::id($id);
        if ($departmentId !== null) { Guard::id($departmentId); }
        if ($capacityLimit !== null && $capacityLimit < 0) {
            throw new \InvalidArgumentException('Capacity must be non-negative; null means unknown, not unlimited.');
        }
        if (!array_is_list($roles)) { throw new \InvalidArgumentException('Roles must be a list.'); }
        foreach ($roles as $role) {
            if (!$role instanceof Role) { throw new \InvalidArgumentException('Invalid role.'); }
        }
    }

    public function scopeFor(Action $action): ?Scope
    {
        $result = null;
        foreach ($this->roles as $role) {
            $scope = $role->grants[$action->value] ?? null;
            if ($scope !== null && ($result === null || $scope->value > $result->value)) { $result = $scope; }
        }
        return $result;
    }
}
