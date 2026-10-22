<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy;

use Lfcheng92\CrmPolicy\Internal\Guard;

final readonly class Role
{
    /** @param array<string, Scope> $grants Explicit grants; an empty map grants nothing. */
    public function __construct(public string $name, public array $grants)
    {
        Guard::id($name);
        foreach ($grants as $action => $scope) {
            if (Action::tryFrom($action) === null || !$scope instanceof Scope) {
                throw new \InvalidArgumentException('Invalid role grant.');
            }
        }
    }
}
