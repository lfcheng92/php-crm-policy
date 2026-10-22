<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Lfcheng\CrmPolicy\{Action, CapacityPolicy, Command};
use Lfcheng\CrmPolicy\Sqlite\{Schema, SqliteExecutor};

// A temporary, isolated database with invented data. No production connection or credentials.
$pdo = new PDO('sqlite::memory:');
Schema::create($pdo);
$member = $pdo->prepare('INSERT INTO crm_members VALUES (?, ?, ?, ?, ?, ?)');
$member->execute(['demo', 'seller', 'sales', 1, 1, json_encode([['name' => 'seller', 'grants' => ['claim' => 1, 'return' => 1, 'transfer' => 1]]], JSON_THROW_ON_ERROR)]);
$customer = $pdo->prepare('INSERT INTO crm_customers VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$customer->execute(['demo', 'sample', null, 'sales', 'active', 0, null, 0]);
$executor = new SqliteExecutor($pdo, new CapacityPolicy(false, false));
$command = new Command('demo', 'seller', 'claim-example-1', Action::Claim, ['sample', 'sample']);
$first = $executor->execute($command);
$again = $executor->execute($command);
$versionQuery = $pdo->query('SELECT version FROM crm_customers');
if ($versionQuery === false) { throw new RuntimeException('Cannot read example version.'); }
if (!$first->decision->allowed || !$again->replayed || (int) $versionQuery->fetchColumn() !== 1) { throw new RuntimeException('SQLite example failed.'); }
echo "SQLite: atomic claim and idempotent replay applied exactly one ownership change.\n";
