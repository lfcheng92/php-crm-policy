<?php
declare(strict_types=1);

// Archive -> isolated path repository -> mirrored install -> remove repository -> run consumer.
$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/crm-policy-consumer-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$composer = $argv[1] ?? (getenv('COMPOSER_BINARY') ?: 'composer');
$composerCommand = str_ends_with($composer, '.phar') ? [PHP_BINARY, $composer] : [$composer];
$environment = array_merge(getenv(), [
    'COMPOSER_HOME' => $temporary . '/composer-home',
    'COMPOSER_CACHE_DIR' => $temporary . '/composer-cache',
    'COMPOSER_DISABLE_NETWORK' => '1',
    'COMPOSER_ROOT_VERSION' => '0.1.0',
]);
$keep = getenv('CRM_POLICY_KEEP_CONSUMER') === '1';

function removeDirectory(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) { throw new RuntimeException('Expected a real temporary directory.'); }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isDir() && !$file->isLink()) { removeDirectory($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($directory);
}

function runCommand(array $arguments, string $cwd, array $environment): void
{
    $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
    if (!is_resource($process)) { throw new RuntimeException('Cannot launch verification command.'); }
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException($output . $error); }
    if ($output !== '') { echo $output; }
}

try {
    mkdir($temporary . '/artifacts'); mkdir($temporary . '/source'); mkdir($temporary . '/consumer');
    runCommand([...$composerCommand, '--no-plugins', '--no-scripts', 'archive', '--format=zip', '--dir=' . $temporary . '/artifacts', '--file=crm-policy'], $root, $environment);
    $archive = $temporary . '/artifacts/crm-policy.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) { throw new RuntimeException('Cannot open generated source archive.'); }
    $manifest = [];
    $topLevelFiles = ['composer.json', '.gitignore', '.gitattributes', 'README.md', 'README.en.md', 'CHANGELOG.md', 'SECURITY.md', 'SOURCES.md', 'NOTICE.md', 'LICENSE', 'CONTRIBUTING.md'];
    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $name = $zip->getNameIndex($i);
        if ($name === false || str_starts_with($name, '/') || str_contains($name, '..') || str_contains($name, '\\')) { throw new RuntimeException('Unsafe archive entry.'); }
        $trimmed = rtrim($name, '/');
        if (!in_array($trimmed, $topLevelFiles, true) && !str_starts_with($name, 'src/') && !str_starts_with($name, 'examples/')) { throw new RuntimeException('Unexpected source archive entry: ' . $name); }
        if (!str_ends_with($name, '/') && (str_starts_with($name, 'src/') || str_starts_with($name, 'examples/')) && !str_ends_with($name, '.php')) { throw new RuntimeException('Non-PHP file in source/example directory: ' . $name); }
        if (preg_match('~(^|/)(vendor|tests|tools|\.git|build|logs|runtime|data)/~', $name)) { throw new RuntimeException('Excluded runtime/development directory in archive.'); }
        $zip->getExternalAttributesIndex($i, $system, $attributes);
        if ((($attributes >> 16) & 0170000) === 0120000) { throw new RuntimeException('Archive must not contain symlinks.'); }
        if (!str_ends_with($name, '/')) {
            $content = $zip->getFromIndex($i);
            if ($content === false) { throw new RuntimeException('Unreadable archive entry.'); }
            if (str_ends_with($name, '.php') && preg_match('~/Users/|/tmp/codex~', $content)) { throw new RuntimeException('Source depends on a development or legacy-project path.'); }
            if (preg_match('/BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY|gh[pousr]_[A-Za-z0-9]{20,}|AKIA[A-Z0-9]{16}/', $content)) { throw new RuntimeException('Potential secret in source archive.'); }
            $manifest[] = $name;
        }
    }
    foreach (['composer.json', 'README.md', 'README.en.md', 'LICENSE', 'SECURITY.md', 'SOURCES.md', 'src/PolicyEngine.php', 'src/Sqlite/SqliteExecutor.php'] as $required) {
        if (!in_array($required, $manifest, true)) { throw new RuntimeException('Required archive file missing: ' . $required); }
    }
    $packageManifest = json_decode($zip->getFromName('composer.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($packageManifest['license'] ?? null) !== 'MIT') { throw new RuntimeException('Original package license metadata must match LICENSE.'); }
    $zip->extractTo($temporary . '/source'); $zip->close();
    $consumer = [
        'name' => 'local/isolated-crm-consumer',
        'require' => ['php' => '^8.2', 'lfcheng92/crm-policy' => '0.1.0'],
        'repositories' => [
            ['type' => 'path', 'url' => '../source', 'options' => ['symlink' => false, 'versions' => ['lfcheng92/crm-policy' => '0.1.0']]],
            ['packagist.org' => false],
        ],
        'config' => ['allow-plugins' => false],
    ];
    file_put_contents($temporary . '/consumer/composer.json', json_encode($consumer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    runCommand([...$composerCommand, '--no-plugins', '--no-scripts', 'install', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist'], $temporary . '/consumer', $environment);
    $installed = $temporary . '/consumer/vendor/lfcheng92/crm-policy';
    if (is_link($installed) || !is_dir($installed)) { throw new RuntimeException('Consumer must receive a mirrored package, not a symlink.'); }
    if (is_dir($installed . '/vendor') || is_dir($installed . '/tests') || is_dir($installed . '/tools')) { throw new RuntimeException('Unexpected development/runtime contents in installed package.'); }
    removeDirectory($temporary . '/source');
    file_put_contents($temporary . '/consumer/smoke.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
use Lfcheng\CrmPolicy\{Action, CapacityPolicy, Command, Customer, CustomerStatus, Member, PolicyEngine, Role, Scope, Snapshot, SystemClock};
$clock = new SystemClock();
$member = new Member('isolated', 'a', 'd', true, 1, [new Role('role', ['claim' => Scope::Own])]);
$customer = new Customer('isolated', 'c', null, 'd', CustomerStatus::Active, false, null, 0);
$result = (new PolicyEngine(new CapacityPolicy(false, false), $clock))->decide(new Command('isolated', 'a', 'r', Action::Claim, ['c']), new Snapshot('isolated', [$customer], [$member], ['a' => 0], $clock->now()));
if (!$result->allowed || $result->plan?->changes[0]->nextOwnerId !== 'a') { throw new RuntimeException('Isolated consumer failed.'); }
$package = __DIR__ . '/vendor/lfcheng92/crm-policy/';
require $package . 'examples/agency.php';
require $package . 'examples/education.php';
require $package . 'examples/sqlite.php';
$pdo = new PDO('sqlite::memory:');
\Lfcheng\CrmPolicy\Sqlite\Schema::create($pdo);
$pdo->prepare('INSERT INTO crm_members VALUES (?, ?, ?, ?, ?, ?)')->execute(['smoke', 'actor', 'sales', 1, 1, json_encode([['name'=>'seller','grants'=>['claim'=>1]]], JSON_THROW_ON_ERROR)]);
$pdo->prepare('INSERT INTO crm_customers VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute(['smoke', 'sample', null, 'sales', 'active', 0, null, 0]);
$pdo->exec("CREATE TRIGGER synthetic_ignore BEFORE INSERT ON crm_requests BEGIN SELECT RAISE(IGNORE); END");
$executor = new \Lfcheng\CrmPolicy\Sqlite\SqliteExecutor($pdo, new CapacityPolicy(false, false));
$command = new Command('smoke', 'actor', 'request', Action::Claim, ['sample']);
try { $executor->execute($command); throw new RuntimeException('Ignored receipt was accepted.'); }
catch (\Lfcheng\CrmPolicy\Sqlite\PersistenceFailure) {}
if ((int) $pdo->query('SELECT COUNT(*) FROM crm_customers WHERE owner_id IS NOT NULL')->fetchColumn() !== 0) { throw new RuntimeException('Consumer rollback failed.'); }
$pdo->exec('DROP TRIGGER synthetic_ignore');
if (!$executor->execute($command)->decision->allowed || !$executor->execute($command)->replayed) { throw new RuntimeException('Consumer failure recovery failed.'); }
echo "Installed executor: suppressed receipt rolls back, retry and replay succeed.\n";
echo "Isolated consumer: core and SQLite OK after removing the source repository.\n";
PHP);
    runCommand([PHP_BINARY, 'smoke.php'], $temporary . '/consumer', $environment);
    sort($manifest);
    file_put_contents($temporary . '/artifacts/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    echo 'Archive inspection: ' . count($manifest) . " files; no vendor, logs, secrets, database files or development paths detected.\n";
    echo 'Archive SHA-256: ' . hash_file('sha256', $archive) . "\n";
    echo "Consumer installation: mirrored path package, Packagist disabled, isolated autoload, source removed.\n";
    if ($keep) { echo 'Retained verification artifacts: ' . $temporary . "/artifacts\n"; }
} finally {
    if (!$keep && is_dir($temporary)) { removeDirectory($temporary); }
}
