<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy;

use Lfcheng\CrmPolicy\Internal\Guard;

final readonly class Customer
{
    public function __construct(
        public string $tenantId,
        public string $id,
        public ?string $ownerId,
        public ?string $departmentId,
        public CustomerStatus $status,
        public bool $protected,
        public ?\DateTimeImmutable $protectionUntil,
        public int $version,
    ) {
        Guard::id($tenantId);
        Guard::id($id);
        if ($ownerId !== null) { Guard::id($ownerId); }
        if ($departmentId !== null) { Guard::id($departmentId); }
        if ($version < 0 || $version === PHP_INT_MAX) { throw new \InvalidArgumentException('Invalid customer version.'); }
        if (!$protected && $protectionUntil !== null) { throw new \InvalidArgumentException('Unprotected customer cannot have a protection deadline.'); }
    }

    public function isProtectedAt(\DateTimeImmutable $now): bool
    {
        return $this->protected && ($this->protectionUntil === null || $this->protectionUntil > $now);
    }
}
