<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy;

/** An inert proposal. Never pass an untrusted plan directly to persistence. */
final readonly class CustomerChange
{
    public function __construct(
        public string $customerId,
        public ?string $previousOwnerId,
        public ?string $nextOwnerId,
        public string $previousDepartmentId,
        public string $nextDepartmentId,
        public int $expectedVersion,
    ) {}
}
