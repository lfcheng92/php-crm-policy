<?php
declare(strict_types=1);

use Lfcheng\CrmPolicy\PolicyEngine;

if (!class_exists(PolicyEngine::class)) {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) { throw new RuntimeException('Load your application vendor/autoload.php before including installed examples.'); }
    require $autoload;
}
