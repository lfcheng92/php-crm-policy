<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy;

use Lfcheng\CrmPolicy\Internal\Guard;

final readonly class Command
{
    /** @var non-empty-list<string> */
    public array $customerIds;

    /** @param list<string> $customerIds At most 200 unique customers and 1000 submitted entries. */
    public function __construct(
        public string $tenantId,
        public string $actorId,
        public string $requestId,
        public Action $action,
        array $customerIds,
        public ?string $targetId = null,
    ) {
        foreach ([$tenantId, $actorId, $requestId] as $id) { Guard::id($id); }
        if ($targetId !== null) { Guard::id($targetId); }
        if (($action === Action::Transfer) !== ($targetId !== null)) {
            throw new \InvalidArgumentException('Only transfer requires a target. Claim always targets the actor.');
        }
        if (!array_is_list($customerIds) || count($customerIds) > 1000) { throw new \InvalidArgumentException('Invalid batch shape or size.'); }
        $unique = [];
        foreach ($customerIds as $id) {
            if (!is_string($id)) { throw new \InvalidArgumentException('Customer IDs must be strings.'); }
            Guard::id($id);
            $unique['id:' . $id] = $id;
        }
        $ids = array_values($unique);
        sort($ids, SORT_STRING);
        if ($ids === [] || count($ids) > 200) { throw new \InvalidArgumentException('Batch must have 1..200 unique customers.'); }
        $this->customerIds = $ids;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->tenantId, $this->actorId, $this->action->value, $this->customerIds, $this->targetId], JSON_THROW_ON_ERROR));
    }
}
