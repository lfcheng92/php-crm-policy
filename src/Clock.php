<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
