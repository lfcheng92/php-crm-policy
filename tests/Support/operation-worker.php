<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Lfcheng\CrmPolicy\{Action, CapacityPolicy, Command};
use Lfcheng\CrmPolicy\Sqlite\SqliteExecutor;
try {
    $data = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $executor = new SqliteExecutor(new PDO('sqlite:' . $argv[1]), new CapacityPolicy(false, false));
    fwrite(STDOUT, "READY\n"); fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') { throw new RuntimeException('Missing start barrier.'); }
    fwrite(STDOUT, "EXECUTING\n"); fflush(STDOUT);
    $result = $executor->execute(new Command('t', $data['actor'], $data['request'], Action::from($data['action']), $data['ids'], $data['target'] ?? null));
    echo json_encode(['allowed' => $result->decision->allowed, 'reason' => $result->decision->reason?->value, 'replayed' => $result->replayed], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . "\n"); exit(1);
}
