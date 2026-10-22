<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lfcheng92\CrmPolicy\{Action, CapacityPolicy, Command};
use Lfcheng92\CrmPolicy\Sqlite\SqliteExecutor;

try {
    $pdo = new PDO('sqlite:' . $argv[1]);
    $executor = new SqliteExecutor($pdo, new CapacityPolicy(false, false));
    fwrite(STDOUT, "READY\n"); fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') { throw new RuntimeException('Missing start barrier.'); }
    fwrite(STDOUT, "EXECUTING\n"); fflush(STDOUT);
    $result = $executor->execute(new Command('t', $argv[2], $argv[3], Action::Claim, [$argv[4]]));
    fwrite(STDOUT, json_encode(['allowed' => $result->decision->allowed, 'reason' => $result->decision->reason?->value, 'replayed' => $result->replayed], JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
