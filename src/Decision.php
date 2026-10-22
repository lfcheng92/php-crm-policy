<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy;

final readonly class Decision
{
    private function __construct(public bool $allowed, public ?Reason $reason, public ?string $customerId, public ?ChangePlan $plan) {}
    public static function allow(ChangePlan $plan): self { return new self(true, null, null, $plan); }
    public static function deny(Reason $reason, ?string $customerId = null): self { return new self(false, $reason, $customerId, null); }
}
