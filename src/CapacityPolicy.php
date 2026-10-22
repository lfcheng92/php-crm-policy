<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy;

final readonly class CapacityPolicy
{
    public function __construct(public bool $countWon, public bool $countAbandoned) {}

    public function counts(CustomerStatus $status): bool
    {
        return match ($status) {
            CustomerStatus::Active => true,
            CustomerStatus::Won => $this->countWon,
            CustomerStatus::Abandoned => $this->countAbandoned,
        };
    }
}
