<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy\Sqlite;

use Lfcheng92\CrmPolicy\Decision;

final readonly class ExecutionResult
{
    public function __construct(public Decision $decision, public bool $replayed) {}
}
