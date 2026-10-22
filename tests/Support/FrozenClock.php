<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy\Tests\Support;

use Lfcheng92\CrmPolicy\Clock;

final class FrozenClock implements Clock
{
    public function __construct(public \DateTimeImmutable $time = new \DateTimeImmutable('2026-01-01T12:00:00Z')) {}
    public function now(): \DateTimeImmutable { return $this->time; }
}
