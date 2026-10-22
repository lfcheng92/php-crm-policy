<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy;

final readonly class ChangePlan
{
    /**
     * @param non-empty-list<CustomerChange> $changes
     * @param array<array-key, int> $capacityDeltas
     */
    public function __construct(
        public string $tenantId,
        public string $actorId,
        public string $requestId,
        public Action $action,
        public array $changes,
        public array $capacityDeltas,
        public \DateTimeImmutable $decidedAt,
    ) {}
}
