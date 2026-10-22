<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
