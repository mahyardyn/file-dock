<?php
declare(strict_types=1);

/**
 * Direct Unit & Security Tests for PathGuard and FileSystemService.
 */
function runUnitTests(TestContext $ctx, string $sourceFile, string $tempDir): void
{
    $ctx->beginGate('2. Unit & Security Tests (PathGuard & FileSystemService)');

    $source = file_get_contents($sourceFile);
    if (!is_string($source)) {
        $ctx->fail('Load filedock.php for unit testing');
        return;
    }

    // Extract class definitions without executing FileManagerController->handleRequest()
    $marker = '$controller = new FileManagerController($CONFIG);';
    $pos = strpos($source, $marker);
    $ctx->assertTrue($pos !== false, 'Locate controller bootstrap marker for isolated class loading');
    if ($pos === false) {
        return;
    }

    $classesFile = $tempDir . DIRECTORY_SEPARATOR . 'filedock_classes.php';
    file_put_contents($classesFile, substr($source, 0, $pos));
    require_once $classesFile;

    $rootDir = $tempDir . DIRECTORY_SEPARATOR . 'unit_root';
    $outsideDir = $tempDir . DIRECTORY_SEPARATOR . 'outside_dir';
    mkdir($rootDir, 0755, true);
    mkdir($outsideDir, 0755, true);
    file_put_contents($outsideDir . DIRECTORY_SEPARATOR . 'secret.txt', 'TOP_SECRET');

    $guard = new PathGuard($rootDir);
    $fs = new FileSystemService($guard);

    // --- PathGuard tests ---
    $ctx->assertSame(realpath($rootDir), $guard->getRoot(), 'PathGuard::getRoot() normalizes root via realpath()');
    $ctx->assertSame(realpath($rootDir), $guard->resolveExisting(''), 'PathGuard::resolveExisting("") resolves to root');

    $ctx->assertThrows(
        InvalidArgumentException::class,
        function () use ($guard): void {
            $guard->resolveExisting('../outside_dir/secret.txt');
        },
        'PathGuard blocks ../ traversal'
    );

    $ctx->assertThrows(
        InvalidArgumentException::class,
        function () use ($guard): void {
            $guard->resolveExisting("foo\0bar");
        },
        'PathGuard blocks null byte injection'
    );

    $ctx->assertThrows(
        InvalidArgumentException::class,
        function () use ($guard): void {
            $guard->resolveExisting(PathGuard::AUTH_FILENAME);
        },
        'PathGuard blocks access to .filedock-auth.php'
    );

    // Symlink pointing outside root
    $symlinkPath = $rootDir . DIRECTORY_SEPARATOR . 'escape_link';
    if (@symlink($outsideDir, $symlinkPath)) {
        $ctx->assertThrows(
            InvalidArgumentException::class,
            function () use ($guard): void {
                $guard->resolveExisting('escape_link');
            },
            'PathGuard blocks symlink pointing outside root'
        );
        @unlink($symlinkPath);
    } else {
        $ctx->pass('Symlink test skipped (symlinks unsupported in environment)');
    }

    // validateBaseName tests
    foreach (['', '.', '..', 'a/b', 'a\\b', "a\0b", PathGuard::AUTH_FILENAME] as $badName) {
        $label = 'validateBaseName rejects ' . json_encode($badName);
        $ctx->assertThrows(
            InvalidArgumentException::class,
            function () use ($guard, $badName): void {
                $guard->validateBaseName($badName);
            },
            $label
        );
    }

    $ctx->assertSame('report (1).txt', $guard->validateBaseName('report (1).txt'), 'validateBaseName accepts spaces & parens');
    $ctx->assertSame('.hidden_cfg', $guard->validateBaseName('.hidden_cfg'), 'validateBaseName accepts dotfile');

    // --- FileSystemService CRUD tests ---
    $fs->createDirectory('', 'docs');
    $fs->createFile('docs', 'hello.txt', 'hello world');
    $fs->createFile('', '.secret_dotfile', 'hidden');

    $readRes = $fs->readFileContent('docs/hello.txt');
    $ctx->assertSame('hello world', $readRes['content'], 'FileSystemService::readFileContent reads created file');

    $fs->saveFileContent('docs/hello.txt', 'updated content');
    $readUpdated = $fs->readFileContent('docs/hello.txt');
    $ctx->assertSame('updated content', $readUpdated['content'], 'FileSystemService::saveFileContent persists updates');

    $listVisible = $fs->listDirectory('', false);
    $visibleNames = array_column($listVisible['items'], 'name');
    $ctx->assertTrue(!in_array('.secret_dotfile', $visibleNames, true), 'listDirectory hides dotfiles when showHidden=false');

    $listAll = $fs->listDirectory('', true);
    $allNames = array_column($listAll['items'], 'name');
    $ctx->assertTrue(in_array('.secret_dotfile', $allNames, true), 'listDirectory includes dotfiles when showHidden=true');

    $fs->renameItem('docs/hello.txt', 'renamed.txt');
    $ctx->assertTrue(is_file($rootDir . '/docs/renamed.txt'), 'renameItem renames file');

    // Copy & move self/descendant protection
    $fs->createDirectory('docs', 'nested');
    $ctx->assertThrows(
        InvalidArgumentException::class,
        function () use ($fs): void {
            $fs->copyItems(['docs'], 'docs');
        },
        'copyItems blocks copying directory into itself'
    );
    $ctx->assertThrows(
        InvalidArgumentException::class,
        function () use ($fs): void {
            $fs->copyItems(['docs'], 'docs/nested');
        },
        'copyItems blocks copying directory into its descendant'
    );
    $ctx->assertThrows(
        InvalidArgumentException::class,
        function () use ($fs): void {
            $fs->moveItems(['docs'], 'docs');
        },
        'moveItems blocks moving directory into itself'
    );
    $ctx->assertThrows(
        InvalidArgumentException::class,
        function () use ($fs): void {
            $fs->moveItems(['docs'], 'docs/nested');
        },
        'moveItems blocks moving directory into its descendant'
    );

    // Valid copy & move
    $fs->createDirectory('', 'dest');
    $copyRes = $fs->copyItems(['docs/renamed.txt'], 'dest');
    $ctx->assertSame(1, $copyRes['copied'], 'copyItems copies file to destination');
    $ctx->assertTrue(is_file($rootDir . '/dest/renamed.txt'), 'Copied file exists in destination');

    $fs->createDirectory('', 'moved_dest');
    $moveRes = $fs->moveItems(['dest/renamed.txt'], 'moved_dest');
    $ctx->assertSame(1, $moveRes['moved'], 'moveItems moves file to destination');
    $ctx->assertTrue(!file_exists($rootDir . '/dest/renamed.txt') && is_file($rootDir . '/moved_dest/renamed.txt'), 'Moved file removed from source and present in target');

    // chmod
    $chmodRes = $fs->chmodItem('moved_dest/renamed.txt', '0640');
    $ctx->assertSame('0640', $chmodRes['permsOctal'], 'chmodItem sets octal mode 0640');
    $ctx->assertThrows(
        InvalidArgumentException::class,
        function () use ($fs): void {
            $fs->chmodItem('moved_dest/renamed.txt', '999');
        },
        'chmodItem rejects invalid octal mode'
    );

    // Delete
    $delRes = $fs->deleteItems(['moved_dest/renamed.txt']);
    $ctx->assertSame(1, $delRes['deleted'], 'deleteItems deletes file');

    // Zip & Unzip
    $zipRes = $fs->compressZip(['docs'], '', 'bundle.zip');
    $ctx->assertSame('bundle.zip', $zipRes['name'] ?? null, 'compressZip creates archive');
    $fs->createDirectory('', 'unzip_out');
    $unzipRes = $fs->extractZip('bundle.zip', 'unzip_out');
    $ctx->assertTrue($unzipRes['extracted'] > 0 && is_file($rootDir . '/unzip_out/docs/renamed.txt'), 'extractZip extracts archive cleanly');

    // Zip Slip protection
    $zipSlipCases = [
        'traversal' => '../evil_slip.txt',
        'absolute' => '/abs_evil_slip.txt',
        'windows' => 'C:\\win_evil_slip.txt',
        'authfile' => PathGuard::AUTH_FILENAME,
    ];
    foreach ($zipSlipCases as $caseName => $entryName) {
        $malZipPath = $rootDir . DIRECTORY_SEPARATOR . "mal_{$caseName}.zip";
        $z = new \ZipArchive();
        if ($z->open($malZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            $z->addFromString($entryName, 'pwned');
            $z->close();
        }
        $ctx->assertThrows(
            RuntimeException::class,
            function () use ($fs, $caseName): void {
                $fs->extractZip("mal_{$caseName}.zip", 'unzip_out');
            },
            "extractZip blocks Zip Slip entry ({$caseName}: {$entryName})"
        );
    }
    $ctx->assertTrue(!file_exists($tempDir . '/evil_slip.txt'), 'Zip Slip did not write outside root');
}
