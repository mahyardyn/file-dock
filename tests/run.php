<?php
declare(strict_types=1);

/**
 * FileDock Zero-Dependency Automated Test Runner.
 * Usage: php tests/run.php
 */

require_once __DIR__ . '/TestContext.php';
require_once __DIR__ . '/ComplianceTest.php';
require_once __DIR__ . '/UnitTest.php';
require_once __DIR__ . '/HttpE2ETest.php';

$rootDir = dirname(__DIR__);
$targetFile = $rootDir . DIRECTORY_SEPARATOR . 'filedock.php';

if (!is_file($targetFile)) {
    fwrite(STDERR, "ERROR: filedock.php not found at {$targetFile}\n");
    exit(1);
}

$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'filedock-qa-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0755, true)) {
    fwrite(STDERR, "ERROR: Unable to create temporary test directory: {$tempDir}\n");
    exit(1);
}

$ctx = new TestContext();

try {
    runComplianceTests($ctx, $targetFile);
    runUnitTests($ctx, $targetFile, $tempDir);
    runHttpE2ETests($ctx, $targetFile, $tempDir);
} finally {
    removeDirectoryRecursive($tempDir);
}

echo "\n========================================\n";
echo "Summary: {$ctx->passed} passed, {$ctx->failed} failed\n";
echo "========================================\n";

exit($ctx->failed > 0 ? 1 : 0);
