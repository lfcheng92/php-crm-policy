<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy\Sqlite;

use Lfcheng\CrmPolicy\Decision;

final readonly class ExecutionResult
{
    public function __construct(public Decision $decision, public bool $replayed) {}
}
