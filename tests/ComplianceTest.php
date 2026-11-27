<?php
declare(strict_types=1);

/**
 * PHP Syntax Linter, Anti-Malware Compliance, and PHP 7.4+ Static Scan.
 */
function runComplianceTests(TestContext $ctx, string $targetFile): void
{
    $ctx->beginGate('1. Syntax & Compliance Tests');

    // 1. PHP Syntax Linter
    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($targetFile) . ' 2>&1', $output, $exitCode);
    $lintOut = implode("\n", $output);
    $ctx->assertTrue(
        $exitCode === 0 && strpos($lintOut, 'No syntax errors detected') !== false,
        'php -l filedock.php passes with zero syntax errors',
        $lintOut
    );

    $source = file_get_contents($targetFile);
    $ctx->assertTrue(is_string($source) && $source !== '', 'Read filedock.php source');
    if (!is_string($source)) {
        return;
    }

    // 2. strict_types declaration
    $ctx->assertTrue(
        preg_match('/<\?php\b[\s\S]{0,600}declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $source) === 1,
        'declare(strict_types=1); present at top of file'
    );

    // 3. Tokenize PHP code (ignores HTML/JS heredoc contents & comments)
    $tokens = token_get_all($source);
    $significant = [];
    foreach ($tokens as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_WHITESPACE || $tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                continue;
            }
            // Skip heredoc/nowdoc body text (embedded HTML/CSS/JS)
            if ($tok[0] === T_ENCAPSED_AND_WHITESPACE || $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
        }
        $significant[] = $tok;
    }

    $hasDollarRequest = false;
    $bannedCalls = [
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'popen',
        'pcntl_exec',
        'eval',
        'assert',
        'create_function',
        'base64_decode',
        'gzinflate',
        'gzuncompress',
        'str_rot13',
    ];
    $php8Functions = ['str_contains', 'str_starts_with', 'str_ends_with'];

    $foundBanned = [];
    $foundPhp8Syntax = [];
    $foundBacktick = false;
    $foundDollarCurly = false;

    $count = count($significant);
    for ($i = 0; $i < $count; $i++) {
        $tok = $significant[$i];
        $prev = $i > 0 ? $significant[$i - 1] : null;
        $next = $i + 1 < $count ? $significant[$i + 1] : null;

        if (!is_array($tok)) {
            if ($tok === '`') {
                $foundBacktick = true;
            }
            continue;
        }

        $id = $tok[0];
        $text = $tok[1];
        $line = $tok[2];

        if ($id === T_VARIABLE && $text === '$_REQUEST') {
            $hasDollarRequest = true;
        }

        if ($id === T_EVAL) {
            $foundBanned[] = "eval() at line {$line}";
        }

        if (defined('T_MATCH') && $id === T_MATCH) {
            $foundPhp8Syntax[] = "match expression at line {$line}";
        }
        if (defined('T_ATTRIBUTE') && $id === T_ATTRIBUTE) {
            $foundPhp8Syntax[] = "PHP 8 attribute at line {$line}";
        }
        if (defined('T_ENUM') && $id === T_ENUM) {
            $foundPhp8Syntax[] = "enum at line {$line}";
        }
        if (defined('T_READONLY') && $id === T_READONLY) {
            $foundPhp8Syntax[] = "readonly at line {$line}";
        }
        if ($id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $foundDollarCurly = true;
        }

        // Check return type hints `: mixed` or `: static`
        if ($prev === ':' && ($id === T_STRING || $id === T_STATIC)) {
            $lower = strtolower($text);
            if ($lower === 'mixed' || $lower === 'static') {
                $foundPhp8Syntax[] = "PHP 8 return type '{$lower}' at line {$line}";
            }
        }

        // Check standalone function calls (not method calls ->fn or static ::fn or declarations)
        if ($id === T_STRING && $next === '(') {
            $isMethodOrDecl = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
            if (!$isMethodOrDecl) {
                $lower = strtolower($text);
                if (in_array($lower, $bannedCalls, true)) {
                    $foundBanned[] = "{$lower}() at line {$line}";
                }
                if (in_array($lower, $php8Functions, true)) {
                    $foundPhp8Syntax[] = "{$lower}() at line {$line}";
                }
            }
        }
    }

    $ctx->assertTrue(!$hasDollarRequest, 'Zero $_REQUEST usage');
    $ctx->assertTrue(!$foundBacktick, 'Zero backtick shell execution operators');
    $ctx->assertTrue(!$foundDollarCurly, 'Zero deprecated ${var} string interpolation');
    $ctx->assertSame([], $foundBanned, 'Zero banned shell/eval/obfuscation calls');
    $ctx->assertSame([], $foundPhp8Syntax, 'Zero PHP 8.0+ exclusive syntax or functions');
}
