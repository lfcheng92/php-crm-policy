<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [];
foreach (['src', 'tests', 'examples', 'tools'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') { $files[] = $file->getPathname(); }
    }
}
sort($files);
foreach ($files as $path) {
    $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot launch PHP lint.'); }
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { fwrite(STDERR, $output . $error); exit(1); }
}
echo 'Syntax OK: ' . count($files) . " PHP files.\n";
