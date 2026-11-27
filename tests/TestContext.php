<?php
declare(strict_types=1);

/**
 * Lightweight assertion and filesystem helpers for FileDock test gates.
 */
final class TestContext
{
    /** @var int */
    public int $passed = 0;

    /** @var int */
    public int $failed = 0;

    /** @var string */
    private string $currentGate = '';

    public function beginGate(string $name): void
    {
        $this->currentGate = $name;
        echo "\n=== {$name} ===\n";
    }

    public function pass(string $label): void
    {
        $this->passed++;
        echo "  [PASS] {$label}\n";
    }

    public function fail(string $label, string $detail = ''): void
    {
        $this->failed++;
        $suffix = $detail !== '' ? " — {$detail}" : '';
        echo "  [FAIL] {$label}{$suffix}\n";
    }

    public function assertTrue(bool $condition, string $label, string $detail = ''): void
    {
        if ($condition) {
            $this->pass($label);
        } else {
            $this->fail($label, $detail);
        }
    }

    public function assertSame($expected, $actual, string $label): void
    {
        if ($expected === $actual) {
            $this->pass($label);
        } else {
            $expStr = var_export($expected, true);
            $actStr = var_export($actual, true);
            $this->fail($label, "expected {$expStr}, got {$actStr}");
        }
    }

    public function assertContains(string $needle, string $haystack, string $label): void
    {
        if (strpos($haystack, $needle) !== false) {
            $this->pass($label);
        } else {
            $this->fail($label, "missing substring '{$needle}'");
        }
    }

    /**
     * Assert that a callable throws an exception of the expected class.
     *
     * @param string $expectedClass
     * @param callable $fn
     * @param string $label
     */
    public function assertThrows(string $expectedClass, callable $fn, string $label): void
    {
        try {
            $fn();
            $this->fail($label, "expected {$expectedClass}, no exception thrown");
        } catch (\Throwable $e) {
            if ($e instanceof $expectedClass) {
                $this->pass($label);
            } else {
                $this->fail($label, 'expected ' . $expectedClass . ', got ' . get_class($e) . ': ' . $e->getMessage());
            }
        }
    }
}

/**
 * Recursively remove a directory tree (including symlinks without following them).
 */
function removeDirectoryRecursive(string $dir): void
{
    if (!file_exists($dir) && !is_link($dir)) {
        return;
    }
    if (is_link($dir) || !is_dir($dir)) {
        @unlink($dir);
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_link($path) || !is_dir($path)) {
            @chmod($path, 0777);
            @unlink($path);
        } else {
            removeDirectoryRecursive($path);
        }
    }
    @chmod($dir, 0777);
    @rmdir($dir);
}
