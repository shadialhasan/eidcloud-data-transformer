<?php

declare(strict_types=1);

// Load autoloader
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/../src/Autoloader.php';
}

require_once __DIR__ . '/DataTransformerTest.php';

use EidCloud\DataTransformer\Tests\DataTransformerTest;

echo "\033[1;36m============================================================\033[0m\n";
echo "\033[1;36m  EidCloud Data Transformer Test Runner (PHP " . PHP_VERSION . ")\033[0m\n";
echo "\033[1;36m============================================================\033[0m\n\n";

$startTime = microtime(true);
$testSuite = new DataTransformerTest();
$results = $testSuite->runAll();

$elapsedMs = round((microtime(true) - $startTime) * 1000, 2);
$memMb = round(memory_get_peak_usage(true) / (1024 * 1024), 2);
$assertions = $testSuite->getAssertionsCount();

echo "\n\033[1;36m------------------------------------------------------------\033[0m\n";
if ($results['failed'] === 0) {
    echo "\033[1;32mTEST SUITE PASSED! [{$results['passed']}/{$results['total']} tests, {$assertions} assertions]\033[0m\n";
    echo "Time: {$elapsedMs} ms | Peak Memory: {$memMb} MB\n";
    echo "\033[1;36m------------------------------------------------------------\033[0m\n";
    exit(0);
} else {
    echo "\033[1;31mTEST SUITE FAILED! [{$results['failed']} failed, {$results['passed']} passed]\033[0m\n";
    foreach ($results['failures'] as $test => $err) {
        echo "  - {$test}: {$err}\n";
    }
    echo "Time: {$elapsedMs} ms | Peak Memory: {$memMb} MB\n";
    echo "\033[1;36m------------------------------------------------------------\033[0m\n";
    exit(1);
}
