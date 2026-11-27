<?php
/**
 * FileDock — Single-File PHP File Manager
 *
 * Production-ready, zero-dependency file manager compatible with PHP 7.4 – 8.x.
 * Designed for shared hosting, VPS, and local environments using native PHP SPL and standard extensions.
 *
 * @package   FileDock
 * @version   1.0.0
 * @author    Mahyar Dayani <Mahyar.Dayani@Gmail.com>
 * @copyright 2026 Mahyar Dayani
 * @license   MIT https://opensource.org/licenses/MIT
 * @link      https://github.com/mahyardyn/file-dock
 */

declare(strict_types=1);

// ============================================================================
// CONFIG
// ============================================================================
$CONFIG = [
    // Set to false to disable login authentication (for local development only).
    'AUTH_ENABLED' => true,
    // Default bcrypt password hash for initial setup only. Default password: "admin"
    // Custom passwords are stored in .filedock-auth.php next to this script.
    'DEFAULT_PASSWORD_HASH' => '$2y$10$YxbKyw4gpR38J1jPNLPaLuyGDigce98mpM.QfXyG9V62NwvwCQT2u',
    // Root directory to jail all file operations into. Defaults to this script's directory.
    'ROOT_DIR' => __DIR__,
    // Default timezone for date formatting.
    'TIMEZONE' => 'UTC',
    // Enable guarded wp-cli ("wp ...") execution in Virtual Console (requires proc_open).
    'WP_CLI_ENABLED' => true,
    // Path or command name for the WP-CLI binary (e.g. 'wp' or '/usr/local/bin/wp').
    'WP_CLI_BIN' => 'wp',
];

// ============================================================================
// BOOTSTRAP & ENVIRONMENT
// ============================================================================
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
if (!empty($CONFIG['TIMEZONE'])) {
    @date_default_timezone_set((string) $CONFIG['TIMEZONE']);
}

/**
 * PathGuard strictly jails all filesystem paths within the configured root directory.
 */
final class PathGuard
{
    /** @var string */
    public const AUTH_FILENAME = '.filedock-auth.php';

    /** @var string */
    private string $rootDir;

    /**
     * Initialize PathGuard with a validated root directory.
     *
     * @param string $rootDir
     */
    public function __construct(string $rootDir)
    {
        $resolved = realpath($rootDir);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \RuntimeException('Configured ROOT_DIR does not exist or is not a directory.');
        }
        $this->rootDir = rtrim($resolved, DIRECTORY_SEPARATOR);
        if ($this->rootDir === '') {
            $this->rootDir = DIRECTORY_SEPARATOR;
        }
    }

    /**
     * UTF-8-safe replacement for PHP's locale-sensitive basename().
     *
     * @param string $path
     * @return string
     */
    public static function baseName(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $trimmed = rtrim($normalized, '/');
        if ($trimmed === '') {
            return '';
        }
        $pos = strrpos($trimmed, '/');
        return $pos === false ? $trimmed : substr($trimmed, $pos + 1);
    }

    /**
     * Determine whether an absolute path is or contains the running FileDock script.
     *
     * @param string $absPath
     * @return bool
     */
    public function isSelfOrContainsSelf(string $absPath): bool
    {
        $self = realpath(__FILE__);
        if ($self === false) {
            return false;
        }
        $resolved = realpath($absPath);
        $target = $resolved !== false ? $resolved : $absPath;
        return $target === $self || strpos($self, rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
    }

    /**
     * Guard against modifying, renaming, moving, or deleting the active FileDock script.
     *
     * @param string $absPath
     * @return void
     */
    public function assertNotSelf(string $absPath): void
    {
        if ($this->isSelfOrContainsSelf($absPath)) {
            throw new \InvalidArgumentException('Access denied: FileDock script cannot be modified, renamed, moved, or deleted.');
        }
    }

    /**
     * Return the resolved absolute root directory path.
     *
     * @return string
     */
    public function getRoot(): string
    {
        return $this->rootDir;
    }

    /**
     * Validate and resolve an existing relative path inside the root directory.
     *
     * @param string $relativePath
     * @return string
     */
    public function resolveExisting(string $relativePath): string
    {
        $clean = $this->sanitizeRelativeInput($relativePath);
        if ($clean === '') {
            return $this->rootDir;
        }

        $candidate = $this->rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
        $resolved = realpath($candidate);
        if ($resolved === false) {
            throw new \InvalidArgumentException('Path does not exist: ' . $clean);
        }

        if (!$this->isWithinRoot($resolved)) {
            throw new \InvalidArgumentException('Access denied: path is outside root directory.');
        }

        if (self::baseName($resolved) === self::AUTH_FILENAME) {
            throw new \InvalidArgumentException('Access denied: protected authentication file.');
        }

        return $resolved;
    }

    /**
     * Resolve a symlink path itself (without following it) or an existing path inside root.
     *
     * @param string $relativePath
     * @return string
     */
    public function resolveLinkOrExisting(string $relativePath): string
    {
        $clean = $this->sanitizeRelativeInput($relativePath);
        if ($clean === '') {
            return $this->rootDir;
        }

        $slashPos = strrpos($clean, '/');
        $parentRel = $slashPos === false ? '' : substr($clean, 0, $slashPos);
        $leafName = $slashPos === false ? $clean : substr($clean, $slashPos + 1);

        $parentAbs = $this->resolveExisting($parentRel);
        if (!is_dir($parentAbs)) {
            throw new \InvalidArgumentException('Parent path is not a directory.');
        }

        $safeLeaf = $this->validateBaseName($leafName);
        $candidate = $parentAbs . DIRECTORY_SEPARATOR . $safeLeaf;

        if (is_link($candidate)) {
            return $candidate;
        }

        return $this->resolveExisting($clean);
    }

    /**
     * Validate and build a target path for creating or placing an item inside an existing parent directory.
     *
     * @param string $parentRelativePath
     * @param string $name
     * @return string
     */
    public function resolveTarget(string $parentRelativePath, string $name): string
    {
        $parentAbs = $this->resolveExisting($parentRelativePath);
        if (!is_dir($parentAbs)) {
            throw new \InvalidArgumentException('Destination parent is not a directory.');
        }

        $safeName = $this->validateBaseName($name);
        $target = $parentAbs . DIRECTORY_SEPARATOR . $safeName;

        if (!$this->isWithinRoot($target)) {
            throw new \InvalidArgumentException('Access denied: target is outside root directory.');
        }

        return $target;
    }

    /**
     * Validate a single file or folder name (no slashes, no dot segments, no null bytes).
     *
     * @param string $name
     * @return string
     */
    public function validateBaseName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '' || $trimmed === '.' || $trimmed === '..') {
            throw new \InvalidArgumentException('Invalid file or folder name.');
        }
        if (strpos($trimmed, "\0") !== false || strpos($trimmed, '/') !== false || strpos($trimmed, '\\') !== false) {
            throw new \InvalidArgumentException('Name cannot contain slashes or null bytes.');
        }
        if ($trimmed === self::AUTH_FILENAME) {
            throw new \InvalidArgumentException('Access denied: protected authentication file.');
        }

        return $trimmed;
    }

    /**
     * Convert an absolute path inside root into a normalized forward-slash relative path.
     *
     * @param string $absPath
     * @return string
     */
    public function toRelative(string $absPath): string
    {
        $normalized = rtrim($absPath, DIRECTORY_SEPARATOR);
        if ($normalized === $this->rootDir) {
            return '';
        }

        $prefix = $this->rootDir === DIRECTORY_SEPARATOR
            ? DIRECTORY_SEPARATOR
            : $this->rootDir . DIRECTORY_SEPARATOR;

        if (strncmp($normalized, $prefix, strlen($prefix)) === 0) {
            $rel = substr($normalized, strlen($prefix));
            return str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        }

        return '';
    }

    /**
     * Check whether $childAbs is equal to or nested inside $parentAbs.
     *
     * @param string $childAbs
     * @param string $parentAbs
     * @return bool
     */
    public function isSameOrDescendant(string $childAbs, string $parentAbs): bool
    {
        $child = rtrim($childAbs, DIRECTORY_SEPARATOR);
        $parent = rtrim($parentAbs, DIRECTORY_SEPARATOR);
        if ($child === $parent) {
            return true;
        }
        $prefix = $parent === DIRECTORY_SEPARATOR ? DIRECTORY_SEPARATOR : $parent . DIRECTORY_SEPARATOR;
        return strncmp($child, $prefix, strlen($prefix)) === 0;
    }

    /**
     * Check whether an absolute path stays inside the jailed root directory.
     *
     * @param string $absPath
     * @return bool
     */
    private function isWithinRoot(string $absPath): bool
    {
        return $this->isSameOrDescendant($absPath, $this->rootDir);
    }

    /**
     * Normalize a relative path input and reject traversal or null bytes.
     *
     * @param string $relativePath
     * @return string
     */
    private function sanitizeRelativeInput(string $relativePath): string
    {
        if (strpos($relativePath, "\0") !== false) {
            throw new \InvalidArgumentException('Invalid null byte in path.');
        }
        $normalized = str_replace('\\', '/', trim($relativePath));
        $normalized = trim($normalized, '/');
        if ($normalized === '' || $normalized === '.') {
            return '';
        }

        $parts = explode('/', $normalized);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \InvalidArgumentException('Invalid path segment.');
            }
        }

        return implode('/', $parts);
    }
}

/**
 * AuthManager handles session lifecycle, single-field authentication, brute-force lockout, and CSRF protection.
 */
final class AuthManager
{
    /** @var string */
    private const BUILTIN_DEFAULT_HASH = '$2y$10$YxbKyw4gpR38J1jPNLPaLuyGDigce98mpM.QfXyG9V62NwvwCQT2u';

    /** @var int */
    private const MAX_FAILED_ATTEMPTS = 5;

    /** @var int */
    private const LOCKOUT_SECONDS = 300;

    /** @var int */
    private const MIN_PASSWORD_LENGTH = 8;

    /** @var bool */
    private bool $authEnabled;

    /** @var string */
    private string $defaultPasswordHash;

    /** @var string */
    private string $authFilePath;

    /** @var string */
    private string $lastError = '';

    /**
     * Initialize authentication manager and start hardened PHP session.
     *
     * @param bool $authEnabled
     * @param string $defaultPasswordHash
     * @param string $authFilePath
     */
    public function __construct(bool $authEnabled, string $defaultPasswordHash, string $authFilePath = '')
    {
        $this->authEnabled = $authEnabled;
        $this->defaultPasswordHash = $defaultPasswordHash;
        $this->authFilePath = $authFilePath !== ''
            ? $authFilePath
            : __DIR__ . DIRECTORY_SEPARATOR . PathGuard::AUTH_FILENAME;
        $this->startSession();
    }

    /**
     * Return whether authentication is enabled in configuration.
     *
     * @return bool
     */
    public function isAuthEnabled(): bool
    {
        return $this->authEnabled;
    }

    /**
     * Determine whether the current session is authenticated.
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        if (!$this->authEnabled) {
            return true;
        }

        return !empty($_SESSION['fm_authenticated']) && $_SESSION['fm_authenticated'] === true;
    }

    /**
     * Check whether the user must replace the built-in default password.
     *
     * @return bool
     */
    public function mustChangePassword(): bool
    {
        if (!$this->authEnabled) {
            return false;
        }

        return !empty($_SESSION['fm_must_change_password']) && $_SESSION['fm_must_change_password'] === true;
    }

    /**
     * Return the most recent authentication error message.
     *
     * @return string
     */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Verify password against active hash with brute-force lockout protection.
     *
     * @param string $password
     * @return bool
     */
    public function login(string $password): bool
    {
        $this->lastError = '';
        if (!$this->authEnabled) {
            return true;
        }

        $data = $this->readAuthData();
        $now = time();

        if ($data['locked_until'] > $now) {
            $remaining = $data['locked_until'] - $now;
            $this->lastError = 'Too many failed login attempts. Try again in ' . $this->formatDuration($remaining) . '.';
            return false;
        }

        if ($data['locked_until'] > 0 && $data['locked_until'] <= $now) {
            $data['failed_attempts'] = 0;
            $data['locked_until'] = 0;
        }

        $activeHash = $data['hash'] !== '' ? $data['hash'] : $this->defaultPasswordHash;
        if ($activeHash === '' || !password_verify($password, $activeHash)) {
            $attempts = $data['failed_attempts'] + 1;
            $lockedUntil = 0;
            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                $lockedUntil = $now + self::LOCKOUT_SECONDS;
                $this->lastError = 'Too many failed login attempts. Account locked for ' . $this->formatDuration(self::LOCKOUT_SECONDS) . '.';
            } else {
                $remainingAttempts = self::MAX_FAILED_ATTEMPTS - $attempts;
                $this->lastError = 'Invalid password. ' . $remainingAttempts . ' attempt' . ($remainingAttempts === 1 ? '' : 's') . ' remaining before temporary lockout.';
            }
            $this->writeAuthData($data['hash'], $attempts, $lockedUntil);
            return false;
        }

        if ($data['failed_attempts'] > 0 || $data['locked_until'] > 0) {
            if ($data['hash'] !== '' || is_file($this->authFilePath)) {
                $this->writeAuthData($data['hash'], 0, 0);
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['fm_authenticated'] = true;
        $_SESSION['fm_must_change_password'] = !$this->hasCustomPassword($data);
        $_SESSION['fm_csrf_token'] = bin2hex(random_bytes(32));

        return true;
    }

    /**
     * Validate and persist a new bcrypt password hash to the auth file.
     *
     * @param string $currentPassword
     * @param string $newPassword
     * @param string $confirmPassword
     * @return void
     */
    public function changePassword(string $currentPassword, string $newPassword, string $confirmPassword): void
    {
        if (!$this->authEnabled) {
            throw new \RuntimeException('Authentication is disabled.');
        }
        if (!$this->isAuthenticated()) {
            throw new \RuntimeException('Authentication required.');
        }

        $data = $this->readAuthData();
        if (!$this->mustChangePassword()) {
            $activeHash = $data['hash'] !== '' ? $data['hash'] : $this->defaultPasswordHash;
            if ($currentPassword === '' || $activeHash === '' || !password_verify($currentPassword, $activeHash)) {
                throw new \InvalidArgumentException('Current password is incorrect.');
            }
        }

        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('New password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
        }
        if (!hash_equals($newPassword, $confirmPassword)) {
            throw new \InvalidArgumentException('New password and confirmation do not match.');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($newHash) || $newHash === '') {
            throw new \RuntimeException('Failed to generate password hash.');
        }

        if (!$this->writeAuthData($newHash, 0, 0)) {
            throw new \RuntimeException(
                'Unable to write "' . PathGuard::baseName($this->authFilePath) . '" (directory is not writable). '
                . 'Set DEFAULT_PASSWORD_HASH manually in filedock.php to: ' . $newHash
            );
        }

        $_SESSION['fm_must_change_password'] = false;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Clear session data, expire session cookie, and destroy the session.
     *
     * @return void
     */
    public function logout(): void
    {
        $_SESSION = [];
        $sessionName = session_name();
        if (ini_get('session.use_cookies') && $sessionName !== false) {
            $params = session_get_cookie_params();
            setcookie($sessionName, '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => !empty($params['samesite']) ? $params['samesite'] : 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Return the active per-session CSRF token, generating one if needed.
     *
     * @return string
     */
    public function getCsrfToken(): string
    {
        if (empty($_SESSION['fm_csrf_token']) || !is_string($_SESSION['fm_csrf_token'])) {
            $_SESSION['fm_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['fm_csrf_token'];
    }

    /**
     * Validate a submitted CSRF token using timing-safe comparison.
     *
     * @param string $token
     * @return bool
     */
    public function verifyCsrf(string $token): bool
    {
        $sessionToken = $this->getCsrfToken();
        if ($token === '' || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Check whether a non-default password hash is configured.
     *
     * @param array{hash: string, failed_attempts: int, locked_until: int} $data
     * @return bool
     */
    private function hasCustomPassword(array $data): bool
    {
        if ($data['hash'] !== '') {
            return true;
        }

        return $this->defaultPasswordHash !== ''
            && !hash_equals(self::BUILTIN_DEFAULT_HASH, $this->defaultPasswordHash);
    }

    /**
     * Read stored password hash and lockout state from the auth file.
     *
     * @return array{hash: string, failed_attempts: int, locked_until: int}
     */
    private function readAuthData(): array
    {
        $defaults = [
            'hash' => '',
            'failed_attempts' => 0,
            'locked_until' => 0,
        ];

        if (!is_file($this->authFilePath) || !is_readable($this->authFilePath)) {
            return $defaults;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->authFilePath, true);
        }

        $loaded = @include $this->authFilePath;
        if (!is_array($loaded)) {
            return $defaults;
        }

        return [
            'hash' => isset($loaded['hash']) && is_string($loaded['hash']) ? $loaded['hash'] : '',
            'failed_attempts' => isset($loaded['failed_attempts']) ? max(0, (int) $loaded['failed_attempts']) : 0,
            'locked_until' => isset($loaded['locked_until']) ? max(0, (int) $loaded['locked_until']) : 0,
        ];
    }

    /**
     * Persist password hash and lockout counters to the auth file.
     *
     * @param string $hash
     * @param int $failedAttempts
     * @param int $lockedUntil
     * @return bool
     */
    private function writeAuthData(string $hash, int $failedAttempts, int $lockedUntil): bool
    {
        $dir = dirname($this->authFilePath);
        if (is_file($this->authFilePath)) {
            if (!is_writable($this->authFilePath)) {
                return false;
            }
        } elseif (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        $content = "<?php\nreturn [\n"
            . "    'hash' => " . var_export($hash, true) . ",\n"
            . "    'failed_attempts' => " . $failedAttempts . ",\n"
            . "    'locked_until' => " . $lockedUntil . ",\n"
            . "];\n";

        if (@file_put_contents($this->authFilePath, $content, LOCK_EX) === false) {
            return false;
        }

        @chmod($this->authFilePath, 0600);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->authFilePath, true);
        }

        return true;
    }

    /**
     * Format a duration in seconds as a human-readable minutes/seconds string.
     *
     * @param int $seconds
     * @return string
     */
    private function formatDuration(int $seconds): string
    {
        $seconds = max(1, $seconds);
        $mins = intdiv($seconds, 60);
        $secs = $seconds % 60;
        if ($mins > 0 && $secs > 0) {
            return $mins . 'm ' . $secs . 's';
        }
        if ($mins > 0) {
            return $mins . 'm';
        }

        return $secs . 's';
    }

    /**
     * Configure and start a hardened PHP session cookie.
     *
     * @return void
     */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('FM_SESSID');
        session_start();
    }
}

/**
 * FileSystemService performs all jailed directory, file, upload, download, and archive operations.
 */
final class FileSystemService
{
    /** @var int */
    private const MAX_EDIT_BYTES = 5242880; // 5 MB

    /** @var array<int, string> */
    private const CODE_EXTENSIONS = [
        'php',
        'phtml',
        'js',
        'mjs',
        'cjs',
        'ts',
        'tsx',
        'jsx',
        'css',
        'scss',
        'less',
        'html',
        'htm',
        'xml',
        'svg',
        'json',
        'yaml',
        'yml',
        'toml',
        'ini',
        'conf',
        'env',
        'sql',
        'sh',
        'bash',
        'zsh',
        'py',
        'rb',
        'go',
        'rs',
        'java',
        'c',
        'cpp',
        'h',
        'hpp',
        'cs',
        'vue',
        'svelte',
        'htaccess',
        'gitignore',
    ];

    /** @var array<int, string> */
    private const DOCUMENT_EXTENSIONS = [
        'txt',
        'md',
        'markdown',
        'log',
        'csv',
        'tsv',
        'rst',
        'tex',
        'pdf',
        'doc',
        'docx',
    ];

    /** @var array<int, string> */
    private const IMAGE_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'svg',
        'ico',
        'bmp',
        'avif',
    ];

    /** @var array<int, string> */
    private const ARCHIVE_EXTENSIONS = [
        'zip',
        'tar',
        'gz',
        'tgz',
        'bz2',
        'rar',
        '7z',
    ];

    /** @var PathGuard */
    private PathGuard $guard;

    /**
     * Initialize filesystem service with a PathGuard instance.
     *
     * @param PathGuard $guard
     */
    public function __construct(PathGuard $guard)
    {
        $this->guard = $guard;
    }

    /**
     * List directory contents, breadcrumbs, and metadata.
     *
     * @param string $relPath
     * @param bool $showHidden
     * @return array<string, mixed>
     */
    public function listDirectory(string $relPath, bool $showHidden): array
    {
        $absDir = $this->guard->resolveExisting($relPath);
        if (!is_dir($absDir)) {
            throw new \InvalidArgumentException('Requested path is not a directory.');
        }

        $normalizedRel = $this->guard->toRelative($absDir);
        $items = [];
        $iterator = new \DirectoryIterator($absDir);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }

            $name = $fileInfo->getFilename();
            if ($name === PathGuard::AUTH_FILENAME) {
                continue;
            }
            if (!$showHidden && strpos($name, '.') === 0) {
                continue;
            }

            $isDir = !$fileInfo->isLink() && $fileInfo->isDir();
            $itemAbs = $fileInfo->getPathname();
            $itemRel = $normalizedRel === '' ? $name : $normalizedRel . '/' . $name;
            $ext = $isDir ? '' : $this->extractExtension($name);

            $permsInt = @fileperms($itemAbs);
            $permsOctal = $permsInt !== false ? sprintf('%04o', $permsInt & 0777) : '0000';
            $permsString = $permsInt !== false ? $this->formatPermissions($permsInt) : '----------';
            $mtimeRaw = $fileInfo->isLink() ? @filemtime($itemAbs) : $fileInfo->getMTime();
            $mtime = $mtimeRaw !== false ? (int) $mtimeRaw : 0;
            $sizeRaw = $isDir ? 0 : ($fileInfo->isLink() ? @filesize($itemAbs) : $fileInfo->getSize());
            $size = $sizeRaw !== false ? (int) $sizeRaw : 0;

            $category = $this->categorizeItem($isDir, $ext);
            $isImage = !$isDir && in_array($ext, self::IMAGE_EXTENSIONS, true);
            $isZip = !$isDir && $ext === 'zip';
            $isEditable = !$isDir && $this->isEditableFile($name, $ext, $size);

            $items[] = [
                'name' => $name,
                'path' => $itemRel,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $size,
                'sizeFormatted' => $isDir ? '—' : $this->formatBytes($size),
                'mtime' => $mtime,
                'mtimeFormatted' => date('Y-m-d H:i', $mtime),
                'permsOctal' => $permsOctal,
                'permsString' => $permsString,
                'readable' => $fileInfo->isReadable(),
                'writable' => $fileInfo->isWritable(),
                'ext' => $ext,
                'category' => $category,
                'isImage' => $isImage,
                'isZip' => $isZip,
                'isEditable' => $isEditable,
            ];
        }

        usort($items, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strnatcasecmp($a['name'], $b['name']);
        });

        return [
            'currentPath' => $normalizedRel,
            'breadcrumbs' => $this->buildBreadcrumbs($normalizedRel),
            'items' => $items,
            'zipSupported' => class_exists('ZipArchive'),
        ];
    }

    /**
     * Create a new directory inside the specified parent path.
     *
     * @param string $parentRel
     * @param string $name
     * @return array<string, string>
     */
    public function createDirectory(string $parentRel, string $name): array
    {
        $target = $this->guard->resolveTarget($parentRel, $name);
        if (file_exists($target)) {
            throw new \RuntimeException('A file or folder with that name already exists.');
        }

        if (!@mkdir($target, 0755, false)) {
            throw new \RuntimeException('Failed to create folder. Check directory permissions.');
        }

        return ['path' => $this->guard->toRelative($target)];
    }

    /**
     * Create a new file inside the specified parent path.
     *
     * @param string $parentRel
     * @param string $name
     * @param string $content
     * @return array<string, string>
     */
    public function createFile(string $parentRel, string $name, string $content = ''): array
    {
        $target = $this->guard->resolveTarget($parentRel, $name);
        if (file_exists($target)) {
            throw new \RuntimeException('A file or folder with that name already exists.');
        }

        if (@file_put_contents($target, $content) === false) {
            throw new \RuntimeException('Failed to create file. Check directory permissions.');
        }

        return ['path' => $this->guard->toRelative($target)];
    }

    /**
     * Rename a file or directory within the same parent directory.
     *
     * @param string $relPath
     * @param string $newName
     * @return array<string, string>
     */
    public function renameItem(string $relPath, string $newName): array
    {
        $sourceAbs = $this->guard->resolveExisting($relPath);
        if ($sourceAbs === $this->guard->getRoot()) {
            throw new \InvalidArgumentException('Cannot rename the root directory.');
        }
        $this->guard->assertNotSelf($sourceAbs);

        $safeNewName = $this->guard->validateBaseName($newName);
        $parentAbs = dirname($sourceAbs);
        $targetAbs = $parentAbs . DIRECTORY_SEPARATOR . $safeNewName;
        $this->guard->assertNotSelf($targetAbs);

        if ($sourceAbs === $targetAbs) {
            return ['path' => $this->guard->toRelative($targetAbs)];
        }

        if (file_exists($targetAbs)) {
            throw new \RuntimeException('Target name already exists.');
        }

        if (!@rename($sourceAbs, $targetAbs)) {
            throw new \RuntimeException('Failed to rename item.');
        }

        return ['path' => $this->guard->toRelative($targetAbs)];
    }

    /**
     * Recursively delete one or more files or directories.
     *
     * @param array<int, string> $relPaths
     * @return array<string, int>
     */
    public function deleteItems(array $relPaths): array
    {
        if (empty($relPaths)) {
            throw new \InvalidArgumentException('No items selected for deletion.');
        }

        $deleted = 0;
        foreach ($relPaths as $relPath) {
            $abs = $this->guard->resolveLinkOrExisting((string) $relPath);
            if ($abs === $this->guard->getRoot()) {
                throw new \InvalidArgumentException('Cannot delete the root directory.');
            }
            $this->guard->assertNotSelf($abs);
            $this->deleteRecursive($abs);
            $deleted++;
        }

        return ['deleted' => $deleted];
    }

    /**
     * Recursively copy one or more items into a destination directory.
     *
     * @param array<int, string> $relPaths
     * @param string $destParentRel
     * @return array<string, int>
     */
    public function copyItems(array $relPaths, string $destParentRel): array
    {
        $destParentAbs = $this->resolveTransferDestination($relPaths, $destParentRel, 'No items selected to copy.');

        $copied = 0;
        foreach ($relPaths as $relPath) {
            $sourceAbs = $this->resolveTransferSource((string) $relPath, $destParentAbs, 'copy');
            $baseName = PathGuard::baseName($sourceAbs);
            $targetAbs = $destParentAbs . DIRECTORY_SEPARATOR . $baseName;

            if ($targetAbs === $sourceAbs) {
                $targetAbs = $this->generateUniqueCopyPath($destParentAbs, $baseName);
            } elseif (file_exists($targetAbs)) {
                throw new \RuntimeException('Destination already contains "' . $baseName . '".');
            }

            $this->copyRecursive($sourceAbs, $targetAbs);
            $copied++;
        }

        return ['copied' => $copied];
    }

    /**
     * Move one or more items into a destination directory.
     *
     * @param array<int, string> $relPaths
     * @param string $destParentRel
     * @return array<string, int>
     */
    public function moveItems(array $relPaths, string $destParentRel): array
    {
        $destParentAbs = $this->resolveTransferDestination($relPaths, $destParentRel, 'No items selected to move.');

        $moved = 0;
        foreach ($relPaths as $relPath) {
            $sourceAbs = $this->resolveTransferSource((string) $relPath, $destParentAbs, 'move');
            $this->guard->assertNotSelf($sourceAbs);
            $baseName = PathGuard::baseName($sourceAbs);
            $targetAbs = $destParentAbs . DIRECTORY_SEPARATOR . $baseName;

            if ($targetAbs === $sourceAbs) {
                continue;
            }
            if (file_exists($targetAbs)) {
                throw new \RuntimeException('Destination already contains "' . $baseName . '".');
            }

            if (!@rename($sourceAbs, $targetAbs)) {
                throw new \RuntimeException('Failed to move "' . $baseName . '".');
            }
            $moved++;
        }

        return ['moved' => $moved];
    }

    /**
     * Validate and resolve the destination directory for copy/move operations.
     *
     * @param array<int, string> $relPaths
     * @param string $destParentRel
     * @param string $emptyMessage
     * @return string
     */
    private function resolveTransferDestination(array $relPaths, string $destParentRel, string $emptyMessage): string
    {
        if (empty($relPaths)) {
            throw new \InvalidArgumentException($emptyMessage);
        }

        $destParentAbs = $this->guard->resolveExisting($destParentRel);
        if (!is_dir($destParentAbs)) {
            throw new \InvalidArgumentException('Destination must be an existing directory.');
        }

        return $destParentAbs;
    }

    /**
     * Validate and resolve a source item for copy/move operations.
     *
     * @param string $relPath
     * @param string $destParentAbs
     * @param string $verb
     * @return string
     */
    private function resolveTransferSource(string $relPath, string $destParentAbs, string $verb): string
    {
        $sourceAbs = $this->guard->resolveExisting($relPath);
        if ($sourceAbs === $this->guard->getRoot()) {
            throw new \InvalidArgumentException('Cannot ' . $verb . ' the root directory.');
        }
        if (is_dir($sourceAbs) && $this->guard->isSameOrDescendant($destParentAbs, $sourceAbs)) {
            throw new \InvalidArgumentException('Cannot ' . $verb . ' a folder into itself or a subfolder.');
        }

        return $sourceAbs;
    }

    /**
     * Change permissions of a file or directory using an octal mode string.
     *
     * @param string $relPath
     * @param string $modeOctal
     * @return array<string, string>
     */
    public function chmodItem(string $relPath, string $modeOctal): array
    {
        $abs = $this->guard->resolveExisting($relPath);
        $this->guard->assertNotSelf($abs);
        if (!preg_match('/^0?[0-7]{3}$/', trim($modeOctal))) {
            throw new \InvalidArgumentException('Invalid octal permission mode (expected e.g. 0755 or 644).');
        }

        $mode = intval(ltrim(trim($modeOctal), '0') ?: '0', 8);
        if (!@chmod($abs, $mode)) {
            throw new \RuntimeException('Failed to change permissions.');
        }

        clearstatcache(true, $abs);
        $newPerms = @fileperms($abs);

        return [
            'permsOctal' => $newPerms !== false ? sprintf('%04o', $newPerms & 0777) : sprintf('%04o', $mode),
            'permsString' => $newPerms !== false ? $this->formatPermissions($newPerms) : '----------',
        ];
    }

    /**
     * Read text file contents and metadata for the built-in editor.
     *
     * @param string $relPath
     * @return array<string, mixed>
     */
    public function readFileContent(string $relPath): array
    {
        $abs = $this->guard->resolveExisting($relPath);
        if (!is_file($abs) || !is_readable($abs)) {
            throw new \InvalidArgumentException('File does not exist or is not readable.');
        }

        $size = (int) filesize($abs);
        if ($size > self::MAX_EDIT_BYTES) {
            throw new \RuntimeException('File is too large to edit in browser (max ' . $this->formatBytes(self::MAX_EDIT_BYTES) . ').');
        }

        $content = @file_get_contents($abs);
        if ($content === false) {
            throw new \RuntimeException('Failed to read file contents.');
        }

        $encoding = 'UTF-8';
        if (function_exists('mb_detect_encoding')) {
            $detected = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'ASCII'], true);
            if ($detected !== false) {
                $encoding = $detected;
            }
        }

        return [
            'path' => $this->guard->toRelative($abs),
            'name' => PathGuard::baseName($abs),
            'content' => $content,
            'size' => $size,
            'sizeFormatted' => $this->formatBytes($size),
            'encoding' => $encoding,
            'writable' => is_writable($abs),
        ];
    }

    /**
     * Write updated text content to an existing writable file.
     *
     * @param string $relPath
     * @param string $content
     * @return array<string, mixed>
     */
    public function saveFileContent(string $relPath, string $content): array
    {
        $abs = $this->guard->resolveExisting($relPath);
        $this->guard->assertNotSelf($abs);
        if (!is_file($abs)) {
            throw new \InvalidArgumentException('Target is not a file.');
        }
        if (!is_writable($abs)) {
            throw new \RuntimeException('File is not writable.');
        }

        if (@file_put_contents($abs, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to save file.');
        }

        clearstatcache(true, $abs);
        $size = (int) filesize($abs);

        return [
            'path' => $this->guard->toRelative($abs),
            'size' => $size,
            'sizeFormatted' => $this->formatBytes($size),
        ];
    }

    /**
     * Process single or multi-file uploads into the target directory.
     *
     * @param string $destRel
     * @param array<string, mixed> $fileInput
     * @param bool $overwrite
     * @return array<string, mixed>
     */
    public function handleUpload(string $destRel, array $fileInput, bool $overwrite): array
    {
        $destAbs = $this->guard->resolveExisting($destRel);
        if (!is_dir($destAbs) || !is_writable($destAbs)) {
            throw new \RuntimeException('Upload destination is not a writable directory.');
        }

        $normalized = $this->normalizeUploadedFiles($fileInput);
        if (empty($normalized)) {
            throw new \InvalidArgumentException('No files were received.');
        }

        $uploaded = [];
        $errors = [];

        foreach ($normalized as $file) {
            $origName = (string) $file['name'];
            $errorCode = (int) $file['error'];
            $tmpName = (string) $file['tmp_name'];

            if ($errorCode !== UPLOAD_ERR_OK) {
                $errors[] = $origName . ': ' . $this->uploadErrorMessage($errorCode);
                continue;
            }

            try {
                $safeName = $this->guard->validateBaseName(PathGuard::baseName($origName));
            } catch (\InvalidArgumentException $e) {
                $errors[] = $origName . ': invalid filename.';
                continue;
            }

            $targetAbs = $destAbs . DIRECTORY_SEPARATOR . $safeName;
            if ($this->guard->isSelfOrContainsSelf($targetAbs)) {
                $errors[] = $safeName . ': cannot overwrite FileDock script.';
                continue;
            }
            if (is_link($targetAbs)) {
                $errors[] = $safeName . ': cannot overwrite symbolic link.';
                continue;
            }
            if (file_exists($targetAbs) && !$overwrite) {
                $errors[] = $safeName . ': already exists (overwrite disabled).';
                continue;
            }

            if (!is_uploaded_file($tmpName) || !@move_uploaded_file($tmpName, $targetAbs)) {
                $errors[] = $safeName . ': failed to move uploaded file.';
                continue;
            }

            $uploaded[] = $safeName;
        }

        return [
            'uploaded' => $uploaded,
            'errors' => $errors,
        ];
    }

    /**
     * Stream a file as a forced download.
     *
     * @param string $relPath
     * @return void
     */
    public function streamDownload(string $relPath): void
    {
        $abs = $this->guard->resolveExisting($relPath);
        if (!is_file($abs) || !is_readable($abs)) {
            http_response_code(404);
            echo 'File not found or not readable.';
            exit;
        }

        $filename = PathGuard::baseName($abs);
        $size = (int) filesize($abs);

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $asciiFallback = preg_replace('/[^\x20-\x7E]|["\\\\\/;]/', '_', $filename);
        if (!is_string($asciiFallback) || trim($asciiFallback, '_ ') === '') {
            $ext = $this->extractExtension($filename);
            $asciiFallback = $ext !== '' ? 'download.' . preg_replace('/[^a-z0-9]/i', '', $ext) : 'download';
        }
        $utf8Encoded = rawurlencode($filename);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $asciiFallback . '"; filename*=UTF-8\'\'' . $utf8Encoded);
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . $size);
        header('Cache-Control: private, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        $handle = fopen($abs, 'rb');
        if ($handle !== false) {
            while (!feof($handle)) {
                echo fread($handle, 65536);
                flush();
            }
            fclose($handle);
        }
        exit;
    }

    /**
     * Stream an image file inline for previewing.
     *
     * @param string $relPath
     * @return void
     */
    public function streamPreview(string $relPath): void
    {
        $abs = $this->guard->resolveExisting($relPath);
        if (!is_file($abs) || !is_readable($abs)) {
            http_response_code(404);
            exit;
        }

        $ext = $this->extractExtension(PathGuard::baseName($abs));
        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'bmp' => 'image/bmp',
            'avif' => 'image/avif',
        ];

        if (!isset($mimeMap[$ext])) {
            http_response_code(415);
            exit;
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: ' . $mimeMap[$ext]);
        header('Content-Length: ' . (int) filesize($abs));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        if ($ext === 'svg') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline';");
        }

        readfile($abs);
        exit;
    }

    /**
     * Compress selected files/folders into a .zip archive.
     *
     * @param array<int, string> $relPaths
     * @param string $destParentRel
     * @param string $archiveName
     * @return array<string, string>
     */
    public function compressZip(array $relPaths, string $destParentRel, string $archiveName): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('PHP ZipArchive extension is not available on this server.');
        }
        if (empty($relPaths)) {
            throw new \InvalidArgumentException('No items selected to compress.');
        }

        $safeName = $this->guard->validateBaseName($archiveName);
        if (strtolower(substr($safeName, -4)) !== '.zip') {
            $safeName .= '.zip';
        }

        $targetZip = $this->guard->resolveTarget($destParentRel, $safeName);
        if (file_exists($targetZip)) {
            throw new \RuntimeException('Archive "' . $safeName . '" already exists.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($targetZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create zip archive.');
        }

        try {
            foreach ($relPaths as $relPath) {
                $abs = $this->guard->resolveExisting((string) $relPath);
                $base = PathGuard::baseName($abs);

                if (is_dir($abs)) {
                    $this->addDirToZip($zip, $abs, $base);
                } else {
                    $zip->addFile($abs, $base);
                }
            }
        } finally {
            $zip->close();
        }

        return ['path' => $this->guard->toRelative($targetZip), 'name' => $safeName];
    }

    /**
     * Extract a .zip archive safely with Zip Slip validation.
     *
     * @param string $relPath
     * @param string $destParentRel
     * @return array<string, int>
     */
    public function extractZip(string $relPath, string $destParentRel): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('PHP ZipArchive extension is not available on this server.');
        }

        $zipAbs = $this->guard->resolveExisting($relPath);
        if (!is_file($zipAbs)) {
            throw new \InvalidArgumentException('Archive file does not exist.');
        }

        $destAbs = $this->guard->resolveExisting($destParentRel);
        if (!is_dir($destAbs) || !is_writable($destAbs)) {
            throw new \RuntimeException('Extraction destination is not writable.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipAbs) !== true) {
            throw new \RuntimeException('Unable to open zip archive.');
        }

        $count = $zip->numFiles;
        for ($i = 0; $i < $count; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false || !$this->isSafeZipEntry($entryName) || $this->zipEntryHitsSymlink($destAbs, $entryName)) {
                $zip->close();
                throw new \RuntimeException('Archive contains unsafe path traversal entries.');
            }
            $destEntry = $destAbs . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($entryName, '/'));
            if ($this->guard->isSelfOrContainsSelf($destEntry)) {
                $zip->close();
                throw new \InvalidArgumentException('Access denied: archive cannot overwrite FileDock script.');
            }
        }

        $ok = $zip->extractTo($destAbs);
        $zip->close();

        if (!$ok) {
            throw new \RuntimeException('Failed to extract archive.');
        }

        return ['extracted' => $count];
    }

    /**
     * Recursively add a directory tree to an open ZipArchive instance.
     *
     * @param \ZipArchive $zip
     * @param string $dirAbs
     * @param string $zipPrefix
     * @return void
     */
    private function addDirToZip(\ZipArchive $zip, string $dirAbs, string $zipPrefix): void
    {
        $zip->addEmptyDir($zipPrefix);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirAbs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $dirLen = strlen(rtrim($dirAbs, DIRECTORY_SEPARATOR)) + 1;
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            // Skip symlinks and protected auth file to prevent escaping ROOT_DIR
            if ($fileInfo->isLink() || $fileInfo->getFilename() === PathGuard::AUTH_FILENAME) {
                continue;
            }
            $fullPath = $fileInfo->getPathname();
            $subPath = str_replace(DIRECTORY_SEPARATOR, '/', substr($fullPath, $dirLen));
            $entryName = $zipPrefix . '/' . $subPath;

            if ($fileInfo->isDir()) {
                $zip->addEmptyDir($entryName);
            } else {
                $zip->addFile($fullPath, $entryName);
            }
        }
    }

    /**
     * Validate a zip entry path against Zip-Slip traversal and auth file access.
     *
     * @param string $entryName
     * @return bool
     */
    private function isSafeZipEntry(string $entryName): bool
    {
        if ($entryName === '' || strpos($entryName, "\0") !== false) {
            return false;
        }
        $normalized = str_replace('\\', '/', $entryName);
        if (strncmp($normalized, '/', 1) === 0 || preg_match('/^[A-Za-z]:/', $normalized) === 1) {
            return false;
        }
        $parts = explode('/', $normalized);
        foreach ($parts as $part) {
            if ($part === '..' || $part === PathGuard::AUTH_FILENAME) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether any existing path component for a zip entry inside $destAbs is a symlink.
     *
     * @param string $destAbs
     * @param string $entryName
     * @return bool
     */
    private function zipEntryHitsSymlink(string $destAbs, string $entryName): bool
    {
        $normalized = trim(str_replace('\\', '/', $entryName), '/');
        if ($normalized === '') {
            return false;
        }

        $current = rtrim($destAbs, DIRECTORY_SEPARATOR);
        foreach (explode('/', $normalized) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively delete a file, symlink, or directory tree.
     *
     * @param string $absPath
     * @return void
     */
    private function deleteRecursive(string $absPath): void
    {
        if (!is_dir($absPath) || is_link($absPath)) {
            if (!@unlink($absPath)) {
                throw new \RuntimeException('Failed to delete "' . PathGuard::baseName($absPath) . '".');
            }
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->getFilename() === PathGuard::AUTH_FILENAME) {
                continue;
            }
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                if (!@rmdir($path)) {
                    throw new \RuntimeException('Failed to remove directory "' . PathGuard::baseName($path) . '".');
                }
            } else {
                if (!@unlink($path)) {
                    throw new \RuntimeException('Failed to delete file "' . PathGuard::baseName($path) . '".');
                }
            }
        }

        if (!@rmdir($absPath)) {
            throw new \RuntimeException('Failed to remove directory "' . PathGuard::baseName($absPath) . '".');
        }
    }

    /**
     * Recursively copy a file or directory tree to a target path.
     *
     * @param string $sourceAbs
     * @param string $targetAbs
     * @return void
     */
    private function copyRecursive(string $sourceAbs, string $targetAbs): void
    {
        if (!is_dir($sourceAbs)) {
            if (!@copy($sourceAbs, $targetAbs)) {
                throw new \RuntimeException('Failed to copy "' . PathGuard::baseName($sourceAbs) . '".');
            }
            return;
        }

        if (!@mkdir($targetAbs, 0755, true) && !is_dir($targetAbs)) {
            throw new \RuntimeException('Failed to create destination directory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceAbs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $sourceLen = strlen(rtrim($sourceAbs, DIRECTORY_SEPARATOR)) + 1;
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            // Skip symlinks and protected auth file to prevent escaping ROOT_DIR
            if ($item->isLink() || $item->getFilename() === PathGuard::AUTH_FILENAME) {
                continue;
            }
            $subPath = substr($item->getPathname(), $sourceLen);
            $dest = $targetAbs . DIRECTORY_SEPARATOR . $subPath;
            if ($item->isDir()) {
                if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
                    throw new \RuntimeException('Failed to create subdirectory during copy.');
                }
            } else {
                if (!@copy($item->getPathname(), $dest)) {
                    throw new \RuntimeException('Failed to copy file "' . $item->getFilename() . '".');
                }
            }
        }
    }

    /**
     * Generate a non-colliding copy target path when a destination already exists.
     *
     * @param string $parentAbs
     * @param string $baseName
     * @return string
     */
    private function generateUniqueCopyPath(string $parentAbs, string $baseName): string
    {
        $dotPos = strrpos($baseName, '.');
        $hasExt = $dotPos !== false && $dotPos > 0;
        $stem = $hasExt ? substr($baseName, 0, $dotPos) : $baseName;
        $ext = $hasExt ? substr($baseName, $dotPos) : '';

        $index = 1;
        do {
            $suffix = $index === 1 ? ' (copy)' : ' (copy ' . $index . ')';
            $candidate = $parentAbs . DIRECTORY_SEPARATOR . $stem . $suffix . $ext;
            $index++;
        } while (file_exists($candidate));

        return $candidate;
    }

    /**
     * Build breadcrumb navigation segments for a relative path.
     *
     * @param string $relPath
     * @return array<int, array<string, string>>
     */
    private function buildBreadcrumbs(string $relPath): array
    {
        $crumbs = [['name' => 'Root', 'path' => '']];
        if ($relPath === '') {
            return $crumbs;
        }

        $parts = explode('/', $relPath);
        $acc = '';
        foreach ($parts as $part) {
            $acc = $acc === '' ? $part : $acc . '/' . $part;
            $crumbs[] = ['name' => $part, 'path' => $acc];
        }

        return $crumbs;
    }

    /**
     * Extract the lowercase file extension from a filename.
     *
     * @param string $filename
     * @return string
     */
    private function extractExtension(string $filename): string
    {
        $lower = strtolower($filename);
        if ($lower === '.htaccess' || $lower === '.gitignore' || $lower === '.env') {
            return ltrim($lower, '.');
        }
        $pos = strrpos($lower, '.');
        if ($pos === false || $pos === strlen($lower) - 1) {
            return '';
        }

        return substr($lower, $pos + 1);
    }

    /**
     * Classify a file into a UI category based on its extension.
     *
     * @param bool $isDir
     * @param string $ext
     * @return string
     */
    private function categorizeItem(bool $isDir, string $ext): string
    {
        if ($isDir) {
            return 'folder';
        }
        if ($ext === 'php' || $ext === 'phtml') {
            return 'php';
        }
        if (in_array($ext, ['js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'vue', 'svelte'], true)) {
            return 'script';
        }
        if (in_array($ext, ['css', 'scss', 'less'], true)) {
            return 'style';
        }
        if (in_array($ext, ['html', 'htm', 'xml'], true)) {
            return 'markup';
        }
        if (in_array($ext, ['json', 'yaml', 'yml', 'toml', 'ini', 'conf', 'env', 'htaccess', 'gitignore'], true)) {
            return 'config';
        }
        if ($ext === 'sql') {
            return 'database';
        }
        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return 'image';
        }
        if (in_array($ext, self::ARCHIVE_EXTENSIONS, true)) {
            return 'archive';
        }
        if (in_array($ext, self::CODE_EXTENSIONS, true)) {
            return 'code';
        }
        if (in_array($ext, self::DOCUMENT_EXTENSIONS, true)) {
            return 'document';
        }

        return 'generic';
    }

    /**
     * Determine whether a file extension is editable in the code editor.
     *
     * @param string $name
     * @param string $ext
     * @param int $size
     * @return bool
     */
    private function isEditableFile(string $name, string $ext, int $size): bool
    {
        if ($size > self::MAX_EDIT_BYTES) {
            return false;
        }
        if (in_array($ext, self::CODE_EXTENSIONS, true) || in_array($ext, self::DOCUMENT_EXTENSIONS, true)) {
            return $ext !== 'pdf' && $ext !== 'doc' && $ext !== 'docx';
        }
        $lower = strtolower($name);
        return in_array($lower, ['readme', 'license', 'makefile', 'dockerfile', 'procfile'], true);
    }

    /**
     * Format numeric file permissions into rwx string and octal representation.
     *
     * @param int $perms
     * @return string
     */
    private function formatPermissions(int $perms): string
    {
        if (($perms & 0x4000) === 0x4000) {
            $info = 'd';
        } elseif (($perms & 0xA000) === 0xA000) {
            $info = 'l';
        } else {
            $info = '-';
        }

        $info .= (($perms & 0x0100) ? 'r' : '-');
        $info .= (($perms & 0x0080) ? 'w' : '-');
        $info .= (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x') : (($perms & 0x0800) ? 'S' : '-'));

        $info .= (($perms & 0x0020) ? 'r' : '-');
        $info .= (($perms & 0x0010) ? 'w' : '-');
        $info .= (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x') : (($perms & 0x0400) ? 'S' : '-'));

        $info .= (($perms & 0x0004) ? 'r' : '-');
        $info .= (($perms & 0x0002) ? 'w' : '-');
        $info .= (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x') : (($perms & 0x0200) ? 'T' : '-'));

        return $info;
    }

    /**
     * Format a byte count into a human-readable size string.
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes / 1024.0;
        $idx = 0;
        while ($value >= 1024.0 && $idx < count($units) - 1) {
            $value /= 1024.0;
            $idx++;
        }

        return number_format($value, 1) . ' ' . $units[$idx];
    }

    /**
     * Normalize PHP $_FILES array structures into a flat list of file entries.
     *
     * @param array<string, mixed> $fileInput
     * @return array<int, array<string, mixed>>
     */
    private function normalizeUploadedFiles(array $fileInput): array
    {
        $normalized = [];
        if (!isset($fileInput['name'])) {
            return $normalized;
        }

        if (is_array($fileInput['name'])) {
            $count = count($fileInput['name']);
            for ($i = 0; $i < $count; $i++) {
                $normalized[] = [
                    'name' => $fileInput['name'][$i] ?? '',
                    'type' => $fileInput['type'][$i] ?? '',
                    'tmp_name' => $fileInput['tmp_name'][$i] ?? '',
                    'error' => $fileInput['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $fileInput['size'][$i] ?? 0,
                ];
            }
        } else {
            $normalized[] = $fileInput;
        }

        return $normalized;
    }

    /**
     * Map a PHP upload error code to a human-readable message.
     *
     * @param int $code
     * @return string
     */
    private function uploadErrorMessage(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'File exceeds maximum upload size.';
            case UPLOAD_ERR_PARTIAL:
                return 'File was only partially uploaded.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing temporary upload folder.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk.';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload stopped by a PHP extension.';
            default:
                return 'Unknown upload error.';
        }
    }
}

/**
 * ServerInfoService collects PHP, server, php.ini, disk, and extension diagnostics.
 */
final class ServerInfoService
{
    /**
     * Collect PHP runtime, php.ini limits, disk usage, and extension status.
     *
     * @param string $rootDir
     * @param bool   $wpCliEnabled
     * @return array<string, mixed>
     */
    public function getInfo(string $rootDir, bool $wpCliEnabled = true): array
    {
        $diskFree = @disk_free_space($rootDir);
        $diskTotal = @disk_total_space($rootDir);

        $extensions = [
            'zip' => extension_loaded('zip') && class_exists('ZipArchive'),
            'mbstring' => extension_loaded('mbstring'),
            'curl' => extension_loaded('curl'),
            'gd' => extension_loaded('gd'),
            'fileinfo' => extension_loaded('fileinfo'),
            'intl' => extension_loaded('intl'),
            'json' => extension_loaded('json'),
            'openssl' => extension_loaded('openssl'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'mysqli' => extension_loaded('mysqli'),
            'opcache' => extension_loaded('Zend OPcache') || extension_loaded('opcache') || function_exists('opcache_get_status'),
            'exif' => extension_loaded('exif'),
            'ionCube Loader' => extension_loaded('ionCube Loader'),
        ];

        $extList = [];
        foreach ($extensions as $name => $loaded) {
            $version = '';
            if ($loaded) {
                if ($name === 'ionCube Loader' && function_exists('ioncube_loader_version')) {
                    $version = (string) ioncube_loader_version();
                } elseif ($name === 'opcache') {
                    $v = phpversion('Zend OPcache');
                    $version = is_string($v) ? $v : (is_string(phpversion('opcache')) ? (string) phpversion('opcache') : '');
                } else {
                    $v = phpversion($name);
                    $version = is_string($v) ? $v : '';
                }
            }
            $extList[] = ['name' => $name, 'loaded' => $loaded, 'version' => $version];
        }

        $usedBytes = ($diskTotal !== false && $diskFree !== false) ? max(0.0, (float) $diskTotal - (float) $diskFree) : 0.0;
        $usedPercent = ($diskTotal !== false && (float) $diskTotal > 0.0)
            ? round(($usedBytes / (float) $diskTotal) * 100, 1)
            : 0.0;
        $forwardedProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            ? strtolower(trim((string) $_SERVER['HTTP_X_FORWARDED_PROTO']))
            : '';
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || $forwardedProto === 'https';

        $procOpenAvailable = function_exists('proc_open');
        if ($procOpenAvailable) {
            $disabled = (string) @ini_get('disable_functions');
            if ($disabled !== '') {
                $disabledList = array_map('trim', explode(',', strtolower($disabled)));
                $procOpenAvailable = !in_array('proc_open', $disabledList, true);
            }
        }

        return [
            'phpVersion' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => function_exists('php_uname') ? (php_uname('s') . ' ' . php_uname('r')) : PHP_OS,
            'serverSoftware' => isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : 'CLI / Unknown',
            'rootDir' => $rootDir,
            'serverTime' => date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ')',
            'https' => $isHttps,
            'procOpenAvailable' => $procOpenAvailable,
            'wpCliEnabled' => $wpCliEnabled,
            'limits' => [
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size' => (string) ini_get('post_max_size'),
                'memory_limit' => (string) ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time') . 's',
                'max_input_vars' => (string) ini_get('max_input_vars'),
            ],
            'disk' => [
                'free' => $diskFree !== false ? $this->formatBytes((float) $diskFree) : 'Unknown',
                'total' => $diskTotal !== false ? $this->formatBytes((float) $diskTotal) : 'Unknown',
                'usedPercent' => $usedPercent,
            ],
            'extensions' => $extList,
        ];
    }

    /**
     * Format a byte count into a human-readable size string.
     *
     * @param float $bytes
     * @return string
     */
    private function formatBytes(float $bytes): string
    {
        if ($bytes < 1024.0) {
            return (int) $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024.0;
        $idx = 0;
        while ($value >= 1024.0 && $idx < count($units) - 1) {
            $value /= 1024.0;
            $idx++;
        }

        return number_format($value, 2) . ' ' . $units[$idx];
    }
}

/**
 * VirtualConsoleService executes safe, pure-PHP virtual shell commands jailed to ROOT_DIR.
 * Uses only PHP SPL and PathGuard — zero OS shell execution functions.
 */
final class VirtualConsoleService
{
    /** @var int */
    private const MAX_READ_BYTES = 524288;

    /** @var int */
    private const MAX_FIND_RESULTS = 500;

    /** @var int */
    private const MAX_GREP_MATCHES = 200;

    /** @var int */
    private const WP_CLI_TIMEOUT_SEC = 30;

    /** @var PathGuard */
    private PathGuard $guard;

    /** @var FileSystemService */
    private FileSystemService $fs;

    /** @var bool */
    private bool $wpCliEnabled;

    /** @var string */
    private string $wpCliBin;

    /**
     * Initialize the jailed Virtual Console with PathGuard and FileSystemService.
     *
     * @param PathGuard $guard
     * @param FileSystemService $fs
     * @param bool $wpCliEnabled
     * @param string $wpCliBin
     */
    public function __construct(
        PathGuard $guard,
        FileSystemService $fs,
        bool $wpCliEnabled = false,
        string $wpCliBin = 'wp'
    ) {
        $this->guard = $guard;
        $this->fs = $fs;
        $this->wpCliEnabled = $wpCliEnabled;
        $this->wpCliBin = trim($wpCliBin) !== '' ? trim($wpCliBin) : 'wp';
    }

    /**
     * Execute a virtual console command line within the jailed root directory.
     *
     * @param string $commandLine
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    public function execute(string $commandLine, string $cwdRel): array
    {
        $safeCwd = $this->normalizeExistingCwd($cwdRel);
        $trimmed = trim($commandLine);
        if ($trimmed === '') {
            return $this->result('', $safeCwd, 0, false, false);
        }

        $tokens = $this->tokenize($trimmed);
        $cmd = strtolower((string) array_shift($tokens));

        switch ($cmd) {
            case 'clear':
                return $this->result('', $safeCwd, 0, true, false);

            case 'help':
                return $this->result($this->cmdHelp(), $safeCwd, 0, false, false);

            case 'pwd':
                $display = $safeCwd === '' ? '/' : '/' . $safeCwd;
                return $this->result($display, $safeCwd, 0, false, false);

            case 'cd':
                return $this->cmdCd($tokens, $safeCwd);

            case 'ls':
                return $this->cmdLs($tokens, $safeCwd, false);

            case 'll':
                return $this->cmdLs($tokens, $safeCwd, true);

            case 'cat':
                return $this->cmdCat($tokens, $safeCwd);

            case 'head':
                return $this->cmdHeadTail($tokens, $safeCwd, true);

            case 'tail':
                return $this->cmdHeadTail($tokens, $safeCwd, false);

            case 'mkdir':
                return $this->cmdMkdir($tokens, $safeCwd);

            case 'touch':
                return $this->cmdTouch($tokens, $safeCwd);

            case 'rm':
                return $this->cmdRm($tokens, $safeCwd);

            case 'cp':
                return $this->cmdCopyOrMove($tokens, $safeCwd, false);

            case 'mv':
                return $this->cmdCopyOrMove($tokens, $safeCwd, true);

            case 'chmod':
                return $this->cmdChmod($tokens, $safeCwd);

            case 'find':
                return $this->cmdFind($tokens, $safeCwd);

            case 'grep':
                return $this->cmdGrep($tokens, $safeCwd);

            case 'stat':
                return $this->cmdStat($tokens, $safeCwd);

            case 'df':
                return $this->cmdDf($tokens, $safeCwd);

            case 'php':
                return $this->cmdPhp($tokens, $safeCwd);

            case 'whoami':
                return $this->cmdWhoami($safeCwd);

            case 'zip':
                return $this->cmdZip($tokens, $safeCwd);

            case 'unzip':
                return $this->cmdUnzip($tokens, $safeCwd);

            case 'wp':
                return $this->cmdWp($tokens, $safeCwd);

            default:
                return $this->result(
                    $cmd . ': command not found. Type "help" for available commands.',
                    $safeCwd,
                    127,
                    false,
                    false
                );
        }
    }

    /**
     * Build a standardized Virtual Console response payload.
     *
     * @param string $output
     * @param string $cwd
     * @param int $exitCode
     * @param bool $clear
     * @param bool $mutated
     * @return array<string, mixed>
     */
    private function result(string $output, string $cwd, int $exitCode, bool $clear, bool $mutated): array
    {
        return [
            'output' => $output,
            'cwd' => $cwd,
            'exitCode' => $exitCode,
            'clear' => $clear,
            'mutated' => $mutated,
        ];
    }

    /**
     * Validate and normalize the current working directory relative path.
     *
     * @param string $cwdRel
     * @return string
     */
    private function normalizeExistingCwd(string $cwdRel): string
    {
        $clean = trim(str_replace('\\', '/', $cwdRel), '/');
        if ($clean === '') {
            return '';
        }
        $abs = $this->guard->resolveExisting($clean);
        if (!is_dir($abs)) {
            throw new \InvalidArgumentException('Working directory is not a directory.');
        }
        return $this->guard->toRelative($abs);
    }

    /**
     * Tokenize a command string supporting single quotes, double quotes, and backslash escapes.
     *
     * @param string $line
     * @return array<int, string>
     */
    private function tokenize(string $line): array
    {
        $tokens = [];
        $current = '';
        $inToken = false;
        $quote = '';
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];

            if ($quote !== '') {
                if ($ch === $quote) {
                    $quote = '';
                    continue;
                }
                if ($quote === '"' && $ch === '\\' && ($i + 1) < $len) {
                    $next = $line[$i + 1];
                    if ($next === '"' || $next === '\\' || $next === 'n' || $next === 't') {
                        if ($next === 'n') {
                            $current .= "\n";
                        } elseif ($next === 't') {
                            $current .= "\t";
                        } else {
                            $current .= $next;
                        }
                        $i++;
                        continue;
                    }
                }
                $current .= $ch;
                continue;
            }

            if ($ch === '\'' || $ch === '"') {
                $quote = $ch;
                $inToken = true;
                continue;
            }

            if ($ch === '\\' && ($i + 1) < $len) {
                $current .= $line[$i + 1];
                $inToken = true;
                $i++;
                continue;
            }

            if ($ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n") {
                if ($inToken) {
                    $tokens[] = $current;
                    $current = '';
                    $inToken = false;
                }
                continue;
            }

            $current .= $ch;
            $inToken = true;
        }

        if ($quote !== '') {
            throw new \InvalidArgumentException('Syntax error: unclosed quote.');
        }

        if ($inToken) {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * Resolve a user-supplied path relative to $cwdRel (or ROOT_DIR if leading slash) into normalized segments.
     *
     * @param string $cwdRel
     * @param string $argPath
     * @return string
     */
    private function resolveVirtualRel(string $cwdRel, string $argPath): string
    {
        $raw = str_replace('\\', '/', trim($argPath));
        if ($raw === '' || $raw === '.') {
            return $cwdRel;
        }
        if ($raw === '/' || $raw === '~') {
            return '';
        }

        if (strncmp($raw, '~/', 2) === 0) {
            $raw = substr($raw, 2);
            $base = [];
        } elseif ($raw[0] === '/') {
            $raw = ltrim($raw, '/');
            $base = [];
        } else {
            $base = $cwdRel === '' ? [] : explode('/', $cwdRel);
        }

        foreach (explode('/', $raw) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if (count($base) > 0) {
                    array_pop($base);
                }
                continue;
            }
            $base[] = $seg;
        }

        return implode('/', $base);
    }

    /**
     * Split a virtual relative path into [parentRel, baseName].
     *
     * @param string $relPath
     * @return array{0: string, 1: string}
     */
    private function splitParentAndName(string $relPath): array
    {
        $clean = trim($relPath, '/');
        if ($clean === '') {
            throw new \InvalidArgumentException('Operation not allowed on root directory.');
        }
        $pos = strrpos($clean, '/');
        if ($pos === false) {
            return ['', $clean];
        }
        return [substr($clean, 0, $pos), substr($clean, $pos + 1)];
    }

    /**
     * Execute the virtual cd command to change working directory.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdCd(array $tokens, string $cwdRel): array
    {
        $targetArg = count($tokens) > 0 ? $tokens[0] : '/';
        $targetRel = $this->resolveVirtualRel($cwdRel, $targetArg);
        $abs = $this->guard->resolveExisting($targetRel);
        if (!is_dir($abs)) {
            return $this->result('cd: not a directory: ' . $targetArg, $cwdRel, 1, false, false);
        }
        $nextCwd = $this->guard->toRelative($abs);
        return $this->result('', $nextCwd, 0, false, false);
    }

    /**
     * Execute the virtual ls/ll command to list directory entries.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @param bool $longDefault
     * @return array<string, mixed>
     */
    private function cmdLs(array $tokens, string $cwdRel, bool $longDefault): array
    {
        $showHidden = false;
        $longFormat = $longDefault;
        $targetArg = '';

        foreach ($tokens as $tok) {
            if ($tok !== '' && $tok[0] === '-' && strlen($tok) > 1) {
                $flags = substr($tok, 1);
                for ($i = 0; $i < strlen($flags); $i++) {
                    $f = $flags[$i];
                    if ($f === 'a') {
                        $showHidden = true;
                    } elseif ($f === 'l') {
                        $longFormat = true;
                    } elseif ($f === 'h') {
                        // Human-readable flag accepted for familiarity
                    } else {
                        return $this->result('ls: invalid option -- \'' . $f . '\'', $cwdRel, 1, false, false);
                    }
                }
            } elseif ($targetArg === '') {
                $targetArg = $tok;
            }
        }

        $targetRel = $this->resolveVirtualRel($cwdRel, $targetArg);
        $abs = $this->guard->resolveExisting($targetRel);

        if (is_file($abs)) {
            $info = new \SplFileInfo($abs);
            if (!$longFormat) {
                return $this->result($info->getFilename(), $cwdRel, 0, false, false);
            }
            $perms = $this->formatModeString((int) $info->getPerms(), false);
            $size = str_pad((string) $info->getSize(), 10, ' ', STR_PAD_LEFT);
            $mtime = date('Y-m-d H:i', (int) $info->getMTime());
            return $this->result($perms . ' ' . $size . ' ' . $mtime . ' ' . $info->getFilename(), $cwdRel, 0, false, false);
        }

        $listing = $this->fs->listDirectory($targetRel, $showHidden);
        $items = $listing['items'];
        if (count($items) === 0) {
            return $this->result('(empty directory)', $cwdRel, 0, false, false);
        }

        $lines = [];
        if ($longFormat) {
            foreach ($items as $item) {
                $isDir = ($item['type'] ?? '') === 'dir';
                $perms = (string) ($item['permsString'] ?? '----------');
                $sizeStr = $isDir ? '<DIR>' : (string) ($item['sizeFormatted'] ?? '0 B');
                $sizePad = str_pad($sizeStr, 10, ' ', STR_PAD_LEFT);
                $mtime = (string) ($item['mtimeFormatted'] ?? '');
                $name = (string) $item['name'] . ($isDir ? '/' : '');
                $lines[] = $perms . ' ' . $sizePad . ' ' . $mtime . ' ' . $name;
            }
        } else {
            foreach ($items as $item) {
                $isDir = ($item['type'] ?? '') === 'dir';
                $lines[] = (string) $item['name'] . ($isDir ? '/' : '');
            }
        }

        return $this->result(implode("\n", $lines), $cwdRel, 0, false, false);
    }

    /**
     * Execute the virtual cat command to output file contents.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdCat(array $tokens, string $cwdRel): array
    {
        if (count($tokens) === 0) {
            return $this->result('cat: missing file operand', $cwdRel, 1, false, false);
        }

        $outputs = [];
        foreach ($tokens as $arg) {
            $rel = $this->resolveVirtualRel($cwdRel, $arg);
            $abs = $this->guard->resolveExisting($rel);
            if (is_dir($abs)) {
                return $this->result('cat: ' . $arg . ': Is a directory', $cwdRel, 1, false, false);
            }
            if (!is_readable($abs)) {
                return $this->result('cat: ' . $arg . ': Permission denied', $cwdRel, 1, false, false);
            }
            $size = @filesize($abs);
            if ($size !== false && $size > self::MAX_READ_BYTES) {
                return $this->result(
                    'cat: ' . $arg . ': File exceeds 512 KB console limit (use head, tail, or the GUI editor).',
                    $cwdRel,
                    1,
                    false,
                    false
                );
            }
            $raw = @file_get_contents($abs);
            if ($raw === false) {
                return $this->result('cat: ' . $arg . ': Failed to read file', $cwdRel, 1, false, false);
            }
            if (strpos($raw, "\0") !== false) {
                return $this->result('cat: ' . $arg . ': Binary file not displayed', $cwdRel, 1, false, false);
            }
            $outputs[] = $raw;
        }

        return $this->result(implode("\n", $outputs), $cwdRel, 0, false, false);
    }

    /**
     * Execute the virtual head or tail command on a file.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @param bool $isHead
     * @return array<string, mixed>
     */
    private function cmdHeadTail(array $tokens, string $cwdRel, bool $isHead): array
    {
        $cmdName = $isHead ? 'head' : 'tail';
        $lineCount = 10;
        $files = [];

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if ($tok === '-n') {
                if (($i + 1) >= $count || !ctype_digit($tokens[$i + 1])) {
                    return $this->result($cmdName . ': option requires a positive integer argument -- \'n\'', $cwdRel, 1, false, false);
                }
                $lineCount = max(1, min(1000, (int) $tokens[$i + 1]));
                $i++;
            } elseif (preg_match('/^-([0-9]+)$/', $tok, $m) === 1) {
                $lineCount = max(1, min(1000, (int) $m[1]));
            } else {
                $files[] = $tok;
            }
        }

        if (count($files) === 0) {
            return $this->result($cmdName . ': missing file operand', $cwdRel, 1, false, false);
        }

        $rel = $this->resolveVirtualRel($cwdRel, $files[0]);
        $abs = $this->guard->resolveExisting($rel);
        if (!is_file($abs) || !is_readable($abs)) {
            return $this->result($cmdName . ': cannot open \'' . $files[0] . '\' for reading', $cwdRel, 1, false, false);
        }

        $lines = [];
        $fileObj = new \SplFileObject($abs, 'r');
        if ($isHead) {
            while (!$fileObj->eof() && count($lines) < $lineCount) {
                $line = $fileObj->fgets();
                if ($fileObj->eof() && $line === '') {
                    break;
                }
                if (strpos($line, "\0") !== false) {
                    return $this->result($cmdName . ': ' . $files[0] . ': Binary file not displayed', $cwdRel, 1, false, false);
                }
                $lines[] = rtrim($line, "\r\n");
            }
        } else {
            $ring = [];
            while (!$fileObj->eof()) {
                $line = $fileObj->fgets();
                if ($fileObj->eof() && $line === '') {
                    break;
                }
                if (strpos($line, "\0") !== false) {
                    return $this->result($cmdName . ': ' . $files[0] . ': Binary file not displayed', $cwdRel, 1, false, false);
                }
                $ring[] = rtrim($line, "\r\n");
                if (count($ring) > $lineCount) {
                    array_shift($ring);
                }
            }
            $lines = $ring;
        }

        return $this->result(implode("\n", $lines), $cwdRel, 0, false, false);
    }

    /**
     * Execute the virtual mkdir command to create directories.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdMkdir(array $tokens, string $cwdRel): array
    {
        $parents = false;
        $targets = [];
        foreach ($tokens as $tok) {
            if ($tok === '-p') {
                $parents = true;
            } else {
                $targets[] = $tok;
            }
        }

        if (count($targets) === 0) {
            return $this->result('mkdir: missing operand', $cwdRel, 1, false, false);
        }

        foreach ($targets as $arg) {
            $rel = $this->resolveVirtualRel($cwdRel, $arg);
            if ($rel === '') {
                return $this->result('mkdir: cannot create directory \'/\': File exists', $cwdRel, 1, false, false);
            }
            if (!$parents) {
                list($parentRel, $name) = $this->splitParentAndName($rel);
                $this->fs->createDirectory($parentRel, $name);
            } else {
                $this->ensureDirectoryPath($rel, 'mkdir: cannot create directory \'' . $arg . '\': Not a directory');
            }
        }

        return $this->result('', $cwdRel, 0, false, true);
    }

    /**
     * Execute the virtual touch command to create or update file timestamps.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdTouch(array $tokens, string $cwdRel): array
    {
        if (count($tokens) === 0) {
            return $this->result('touch: missing file operand', $cwdRel, 1, false, false);
        }

        foreach ($tokens as $arg) {
            $rel = $this->resolveVirtualRel($cwdRel, $arg);
            list($parentRel, $name) = $this->splitParentAndName($rel);
            $targetAbs = $this->guard->resolveTarget($parentRel, $name);
            $this->guard->assertNotSelf($targetAbs);
            if (file_exists($targetAbs)) {
                if (!@touch($targetAbs)) {
                    return $this->result('touch: failed to update timestamp for \'' . $arg . '\'', $cwdRel, 1, false, false);
                }
            } else {
                $this->fs->createFile($parentRel, $name, '');
            }
        }

        return $this->result('', $cwdRel, 0, false, true);
    }

    /**
     * Execute the virtual rm command to remove files or directories.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdRm(array $tokens, string $cwdRel): array
    {
        $recursive = false;
        $force = false;
        $targets = [];

        foreach ($tokens as $tok) {
            if ($tok !== '' && $tok[0] === '-' && strlen($tok) > 1) {
                $flags = substr($tok, 1);
                for ($i = 0; $i < strlen($flags); $i++) {
                    $f = $flags[$i];
                    if ($f === 'r' || $f === 'R') {
                        $recursive = true;
                    } elseif ($f === 'f') {
                        $force = true;
                    } else {
                        return $this->result('rm: invalid option -- \'' . $f . '\'', $cwdRel, 1, false, false);
                    }
                }
            } else {
                $targets[] = $tok;
            }
        }

        if (count($targets) === 0) {
            return $this->result('rm: missing operand', $cwdRel, 1, false, false);
        }

        $resolvedTargets = [];
        foreach ($targets as $arg) {
            $rel = $this->resolveVirtualRel($cwdRel, $arg);
            if ($rel === '') {
                return $this->result('rm: refusing to remove root directory', $cwdRel, 1, false, false);
            }
            try {
                $abs = $this->guard->resolveLinkOrExisting($rel);
            } catch (\Throwable $e) {
                if ($force) {
                    continue;
                }
                return $this->result('rm: cannot remove \'' . $arg . '\': No such file or directory', $cwdRel, 1, false, false);
            }

            if (!is_link($abs) && is_dir($abs) && !$recursive) {
                return $this->result('rm: cannot remove \'' . $arg . '\': Is a directory (use -r)', $cwdRel, 1, false, false);
            }
            $resolvedTargets[] = $this->guard->toRelative($abs);
        }

        if (count($resolvedTargets) === 0) {
            return $this->result('', $cwdRel, 0, false, false);
        }

        $this->fs->deleteItems($resolvedTargets);
        return $this->result('', $cwdRel, 0, false, true);
    }

    /**
     * Execute the virtual cp or mv command on files or directories.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @param bool $isMove
     * @return array<string, mixed>
     */
    private function cmdCopyOrMove(array $tokens, string $cwdRel, bool $isMove): array
    {
        $cmdName = $isMove ? 'mv' : 'cp';
        $args = [];
        foreach ($tokens as $tok) {
            if (!$isMove && ($tok === '-r' || $tok === '-R')) {
                continue;
            }
            $args[] = $tok;
        }

        if (count($args) < 2) {
            return $this->result($cmdName . ': missing file operand (usage: ' . $cmdName . ' <src> <dest>)', $cwdRel, 1, false, false);
        }

        $destArg = (string) array_pop($args);
        $destRel = $this->resolveVirtualRel($cwdRel, $destArg);

        $destExistsAsDir = false;
        try {
            $destAbs = $this->guard->resolveExisting($destRel);
            $destExistsAsDir = is_dir($destAbs);
        } catch (\Throwable $e) {
            $destExistsAsDir = false;
        }

        $srcRels = [];
        foreach ($args as $srcArg) {
            $rel = $this->resolveVirtualRel($cwdRel, $srcArg);
            if ($rel === '') {
                return $this->result($cmdName . ': cannot operate on root directory', $cwdRel, 1, false, false);
            }
            $abs = $this->guard->resolveExisting($rel);
            $srcRels[] = $this->guard->toRelative($abs);
        }

        if ($destExistsAsDir) {
            if ($isMove) {
                $this->fs->moveItems($srcRels, $destRel);
            } else {
                $this->fs->copyItems($srcRels, $destRel);
            }
            return $this->result('', $cwdRel, 0, false, true);
        }

        if (count($srcRels) > 1) {
            return $this->result($cmdName . ': target \'' . $destArg . '\' is not a directory', $cwdRel, 1, false, false);
        }

        $srcRel = $srcRels[0];
        $srcAbs = $this->guard->resolveExisting($srcRel);
        list($destParentRel, $destName) = $this->splitParentAndName($destRel);
        $destParentAbs = $this->guard->resolveExisting($destParentRel);

        if (is_dir($srcAbs) && $this->guard->isSameOrDescendant($destParentAbs, $srcAbs)) {
            return $this->result($cmdName . ': cannot ' . ($isMove ? 'move' : 'copy') . ' a directory into itself', $cwdRel, 1, false, false);
        }

        $targetAbs = $this->guard->resolveTarget($destParentRel, $destName);
        if (file_exists($targetAbs)) {
            return $this->result($cmdName . ': destination already exists: ' . $destArg, $cwdRel, 1, false, false);
        }

        if ($isMove) {
            if (!@rename($srcAbs, $targetAbs)) {
                return $this->result('mv: failed to move \'' . $srcRel . '\'', $cwdRel, 1, false, false);
            }
        } else {
            if (is_dir($srcAbs)) {
                $this->copyDirContents($srcAbs, $targetAbs);
            } elseif (!@copy($srcAbs, $targetAbs)) {
                return $this->result('cp: failed to copy \'' . $srcRel . '\'', $cwdRel, 1, false, false);
            }
        }

        return $this->result('', $cwdRel, 0, false, true);
    }

    /**
     * Recursively copy directory contents for virtual cp operations.
     *
     * @param string $sourceAbs
     * @param string $targetAbs
     * @return void
     */
    private function copyDirContents(string $sourceAbs, string $targetAbs): void
    {
        if (!@mkdir($targetAbs, 0755, true) && !is_dir($targetAbs)) {
            throw new \RuntimeException('Failed to create destination directory.');
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceAbs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $prefixLen = strlen($sourceAbs) + 1;
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            // Skip symlinks and protected auth file to prevent escaping ROOT_DIR
            if ($item->isLink() || $item->getFilename() === PathGuard::AUTH_FILENAME) {
                continue;
            }
            $subPath = substr($item->getPathname(), $prefixLen);
            $dest = $targetAbs . DIRECTORY_SEPARATOR . $subPath;
            if ($item->isDir()) {
                if (!is_dir($dest) && !@mkdir($dest, 0755)) {
                    throw new \RuntimeException('Failed to create directory during copy.');
                }
            } elseif (!@copy($item->getPathname(), $dest)) {
                throw new \RuntimeException('Failed to copy file.');
            }
        }
    }

    /**
     * Execute the virtual chmod command with octal permissions.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdChmod(array $tokens, string $cwdRel): array
    {
        if (count($tokens) < 2) {
            return $this->result('chmod: usage: chmod <octal_mode> <target...>', $cwdRel, 1, false, false);
        }
        $mode = array_shift($tokens);
        foreach ($tokens as $arg) {
            $rel = $this->resolveVirtualRel($cwdRel, $arg);
            $this->fs->chmodItem($rel, (string) $mode);
        }
        return $this->result('', $cwdRel, 0, false, true);
    }

    /**
     * Execute the virtual find command with optional name and type filters.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdFind(array $tokens, string $cwdRel): array
    {
        $startArg = '.';
        $namePattern = '';
        $typeFilter = '';

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if ($tok === '-name' && ($i + 1) < $count) {
                $namePattern = $tokens[++$i];
            } elseif ($tok === '-type' && ($i + 1) < $count) {
                $typeFilter = $tokens[++$i];
                if ($typeFilter !== 'f' && $typeFilter !== 'd') {
                    return $this->result('find: unknown argument to -type: ' . $typeFilter, $cwdRel, 1, false, false);
                }
            } elseif ($tok !== '' && $tok[0] !== '-') {
                $startArg = $tok;
            }
        }

        $startRel = $this->resolveVirtualRel($cwdRel, $startArg);
        $startAbs = $this->guard->resolveExisting($startRel);

        if (is_file($startAbs)) {
            return $this->result('/' . $this->guard->toRelative($startAbs), $cwdRel, 0, false, false);
        }

        $results = [];
        // CATCH_GET_CHILD prevents unreadable subdirectories from aborting the search
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($startAbs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            // Skip symlinks and protected auth file to prevent escaping ROOT_DIR
            if ($item->isLink() || $item->getFilename() === PathGuard::AUTH_FILENAME) {
                continue;
            }
            $isDir = $item->isDir();
            if ($typeFilter === 'f' && $isDir) {
                continue;
            }
            if ($typeFilter === 'd' && !$isDir) {
                continue;
            }
            if ($namePattern !== '' && !fnmatch($namePattern, $item->getFilename())) {
                continue;
            }
            $rel = $this->guard->toRelative($item->getPathname());
            $results[] = '/' . $rel . ($isDir ? '/' : '');
            if (count($results) >= self::MAX_FIND_RESULTS) {
                $results[] = '... (truncated at ' . self::MAX_FIND_RESULTS . ' results)';
                break;
            }
        }

        if (count($results) === 0) {
            return $this->result('(no matches)', $cwdRel, 0, false, false);
        }

        return $this->result(implode("\n", $results), $cwdRel, 0, false, false);
    }

    /**
     * Execute the virtual grep command to search text patterns in files.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdGrep(array $tokens, string $cwdRel): array
    {
        $ignoreCase = false;
        $showLineNum = false;
        $recursive = false;
        $positional = [];

        foreach ($tokens as $tok) {
            if ($tok !== '' && $tok[0] === '-' && strlen($tok) > 1) {
                $flags = substr($tok, 1);
                for ($i = 0; $i < strlen($flags); $i++) {
                    $f = $flags[$i];
                    if ($f === 'i') {
                        $ignoreCase = true;
                    } elseif ($f === 'n') {
                        $showLineNum = true;
                    } elseif ($f === 'r' || $f === 'R') {
                        $recursive = true;
                    } else {
                        return $this->result('grep: invalid option -- \'' . $f . '\'', $cwdRel, 1, false, false);
                    }
                }
            } else {
                $positional[] = $tok;
            }
        }

        if (count($positional) < 2) {
            return $this->result('grep: usage: grep [-inr] <pattern> <path>', $cwdRel, 1, false, false);
        }

        $pattern = $positional[0];
        if ($pattern === '') {
            return $this->result('grep: pattern cannot be empty', $cwdRel, 1, false, false);
        }

        $targetRel = $this->resolveVirtualRel($cwdRel, $positional[1]);
        $targetAbs = $this->guard->resolveExisting($targetRel);

        $filesToSearch = [];
        if (is_file($targetAbs)) {
            $filesToSearch[] = $targetAbs;
        } elseif ($recursive) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($targetAbs, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($it as $item) {
                /** @var \SplFileInfo $item */
                // Skip symlinks so grep never reads files outside ROOT_DIR
                if ($item->isLink()) {
                    continue;
                }
                if ($item->isFile() && $item->getFilename() !== PathGuard::AUTH_FILENAME) {
                    $filesToSearch[] = $item->getPathname();
                }
            }
        } else {
            return $this->result('grep: ' . $positional[1] . ': Is a directory (use -r)', $cwdRel, 1, false, false);
        }

        $matches = [];
        $multiFile = count($filesToSearch) > 1;
        foreach ($filesToSearch as $fileAbs) {
            $size = @filesize($fileAbs);
            if ($size === false || $size > self::MAX_READ_BYTES) {
                continue;
            }
            $raw = @file_get_contents($fileAbs);
            if ($raw === false || strpos($raw, "\0") !== false) {
                continue;
            }
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $raw));
            $relLabel = '/' . $this->guard->toRelative($fileAbs);
            foreach ($lines as $idx => $line) {
                if ($ignoreCase) {
                    $found = function_exists('mb_stripos')
                        ? (mb_stripos($line, $pattern, 0, 'UTF-8') !== false)
                        : (stripos($line, $pattern) !== false);
                } else {
                    $found = strpos($line, $pattern) !== false;
                }
                if ($found) {
                    $prefix = $multiFile ? $relLabel . ':' : '';
                    if ($showLineNum) {
                        $prefix .= ($idx + 1) . ':';
                    }
                    $matches[] = $prefix . $line;
                    if (count($matches) >= self::MAX_GREP_MATCHES) {
                        $matches[] = '... (truncated at ' . self::MAX_GREP_MATCHES . ' matches)';
                        return $this->result(implode("\n", $matches), $cwdRel, 0, false, false);
                    }
                }
            }
        }

        if (count($matches) === 0) {
            return $this->result('(no matches)', $cwdRel, 1, false, false);
        }

        return $this->result(implode("\n", $matches), $cwdRel, 0, false, false);
    }

    /**
     * Execute the virtual stat command to inspect file metadata.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdStat(array $tokens, string $cwdRel): array
    {
        if (count($tokens) === 0) {
            return $this->result('stat: missing operand', $cwdRel, 1, false, false);
        }

        $rel = $this->resolveVirtualRel($cwdRel, $tokens[0]);
        $abs = $this->guard->resolveExisting($rel);
        $info = new \SplFileInfo($abs);
        $perms = (int) $info->getPerms();
        $octal = sprintf('%04o', $perms & 0777);
        $isDir = $info->isDir();
        $type = $isDir ? 'directory' : 'regular file';
        $displayPath = $rel === '' ? '/' : '/' . $this->guard->toRelative($abs);

        $lines = [
            '  File: ' . $displayPath,
            '  Type: ' . $type,
            '  Size: ' . ($isDir ? '0' : (string) $info->getSize()) . ' bytes',
            'Access: (' . $octal . '/' . $this->formatModeString($perms, $isDir) . ')',
            'Modify: ' . date('Y-m-d H:i:s', (int) $info->getMTime()),
            'Change: ' . date('Y-m-d H:i:s', (int) $info->getCTime()),
        ];

        return $this->result(implode("\n", $lines), $cwdRel, 0, false, false);
    }

    /**
     * Execute the virtual df command to report jailed filesystem disk space.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdDf(array $tokens, string $cwdRel): array
    {
        $root = $this->guard->getRoot();
        $free = @disk_free_space($root);
        $total = @disk_total_space($root);

        if ($free === false || $total === false || $total <= 0) {
            return $this->result('df: unable to determine disk space', $cwdRel, 1, false, false);
        }

        $used = max(0.0, (float) $total - (float) $free);
        $pct = (int) round(($used / (float) $total) * 100);
        $human = in_array('-h', $tokens, true);

        $totalStr = $human ? $this->formatHumanBytes((float) $total) : (string) (int) $total;
        $usedStr = $human ? $this->formatHumanBytes($used) : (string) (int) $used;
        $freeStr = $human ? $this->formatHumanBytes((float) $free) : (string) (int) $free;

        $header = sprintf('%-16s %12s %12s %12s %6s', 'Filesystem', 'Size', 'Used', 'Avail', 'Use%');
        $row = sprintf('%-16s %12s %12s %12s %5d%%', 'ROOT_DIR', $totalStr, $usedStr, $freeStr, $pct);

        return $this->result($header . "\n" . $row, $cwdRel, 0, false, false);
    }

    /**
     * Execute the virtual php -v command to display the PHP version.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdPhp(array $tokens, string $cwdRel): array
    {
        if (count($tokens) === 1 && ($tokens[0] === '-v' || $tokens[0] === '--version')) {
            return $this->result(
                'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ') on ' . PHP_OS_FAMILY,
                $cwdRel,
                0,
                false,
                false
            );
        }

        return $this->result('php: only "php -v" is supported in the Pure-PHP Virtual Console.', $cwdRel, 1, false, false);
    }

    /**
     * Execute the virtual whoami command to report the active script user.
     *
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdWhoami(string $cwdRel): array
    {
        $user = get_current_user();
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid((int) posix_geteuid());
            if (is_array($pw) && isset($pw['name']) && (string) $pw['name'] !== '') {
                $user = (string) $pw['name'];
            }
        }
        return $this->result($user !== '' ? $user : 'unknown', $cwdRel, 0, false, false);
    }

    /**
     * Execute the virtual zip command to create a zip archive.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdZip(array $tokens, string $cwdRel): array
    {
        if (count($tokens) < 2) {
            return $this->result('zip: usage: zip <archive.zip> <target...>', $cwdRel, 1, false, false);
        }
        $archiveArg = array_shift($tokens);
        $archiveRel = $this->resolveVirtualRel($cwdRel, (string) $archiveArg);
        list($destParentRel, $archiveName) = $this->splitParentAndName($archiveRel);

        $srcRels = [];
        foreach ($tokens as $arg) {
            $rel = $this->resolveVirtualRel($cwdRel, $arg);
            $abs = $this->guard->resolveExisting($rel);
            $srcRels[] = $this->guard->toRelative($abs);
        }

        $res = $this->fs->compressZip($srcRels, $destParentRel, $archiveName);
        return $this->result('Created archive: ' . (string) ($res['name'] ?? $archiveName), $cwdRel, 0, false, true);
    }

    /**
     * Execute the virtual unzip command with Zip-Slip protection.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdUnzip(array $tokens, string $cwdRel): array
    {
        if (count($tokens) === 0) {
            return $this->result('unzip: usage: unzip <archive.zip> [dest_dir]', $cwdRel, 1, false, false);
        }
        $archiveRel = $this->resolveVirtualRel($cwdRel, $tokens[0]);
        $destRel = count($tokens) >= 2 ? $this->resolveVirtualRel($cwdRel, $tokens[1]) : $cwdRel;

        if ($destRel !== '') {
            try {
                $destAbs = $this->guard->resolveExisting($destRel);
                if (!is_dir($destAbs)) {
                    return $this->result('unzip: destination is not a directory: ' . $tokens[1], $cwdRel, 1, false, false);
                }
            } catch (\InvalidArgumentException $e) {
                $this->ensureDirectoryPath($destRel, 'unzip: destination path is not a directory: ' . $tokens[1]);
            }
        }

        $res = $this->fs->extractZip($archiveRel, $destRel);
        $count = isset($res['extracted']) ? (int) $res['extracted'] : 0;
        return $this->result('Extracted ' . $count . ' item(s).', $cwdRel, 0, false, true);
    }

    /**
     * Execute a guarded WP-CLI command within the current jailed directory using array-form proc_open.
     *
     * @param array<int, string> $tokens
     * @param string $cwdRel
     * @return array<string, mixed>
     */
    private function cmdWp(array $tokens, string $cwdRel): array
    {
        if (!$this->wpCliEnabled) {
            return $this->result('wp: WP-CLI execution is disabled in $CONFIG.', $cwdRel, 1, false, false);
        }

        if (!$this->isProcOpenAvailable()) {
            return $this->result(
                'wp: proc_open() is disabled on this server (php.ini disable_functions).',
                $cwdRel,
                1,
                false,
                false
            );
        }

        if (count($tokens) === 0) {
            $tokens = ['--info'];
        }

        // Block subcommands and flags that execute arbitrary PHP/shell, open interactive prompts, or escape ROOT_DIR
        $blockedSubcommands = ['eval', 'eval-file', 'shell'];
        $positional = [];
        foreach ($tokens as $tok) {
            $lower = strtolower($tok);
            if (
                $lower === '--exec' || strncmp($lower, '--exec=', 7) === 0 ||
                $lower === '--require' || strncmp($lower, '--require=', 10) === 0 ||
                $lower === '--path' || strncmp($lower, '--path=', 7) === 0 ||
                $lower === '--ssh' || strncmp($lower, '--ssh=', 6) === 0 ||
                $lower === '--config' || strncmp($lower, '--config=', 9) === 0
            ) {
                return $this->result('wp: flag "' . $tok . '" is blocked for security.', $cwdRel, 1, false, false);
            }
            if ($tok !== '' && $tok[0] !== '-') {
                $positional[] = $lower;
            }
        }

        // Check non-flag tokens so space-separated global flags (e.g. --user admin eval) cannot bypass filters
        foreach ($positional as $idx => $pos) {
            if (in_array($pos, $blockedSubcommands, true)) {
                return $this->result('wp: subcommand "' . $pos . '" is blocked for security.', $cwdRel, 1, false, false);
            }
            if ($pos === 'db' && isset($positional[$idx + 1]) && $positional[$idx + 1] === 'cli') {
                return $this->result('wp: interactive subcommand "db cli" is not supported in the web console.', $cwdRel, 1, false, false);
            }
        }

        $bin = $this->resolveWpCliBinary();
        $cwdAbs = $this->guard->resolveExisting($cwdRel);
        // Array-form proc_open bypasses /bin/sh so metacharacters cannot inject shell commands
        $cmdArgs = array_merge([$bin], $tokens);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($cmdArgs, $descriptors, $pipes, $cwdAbs);
        if (!is_resource($process)) {
            return $this->result('wp: failed to launch WP-CLI process (' . $bin . ').', $cwdRel, 1, false, false);
        }

        @fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $timedOut = false;

        while (true) {
            $chunkOut = stream_get_contents($pipes[1], 8192);
            if (is_string($chunkOut) && $chunkOut !== '' && strlen($stdout) < self::MAX_READ_BYTES) {
                $stdout .= $chunkOut;
            }
            $chunkErr = stream_get_contents($pipes[2], 8192);
            if (is_string($chunkErr) && $chunkErr !== '' && strlen($stderr) < self::MAX_READ_BYTES) {
                $stderr .= $chunkErr;
            }

            $status = proc_get_status($process);
            if (!is_array($status) || empty($status['running'])) {
                break;
            }

            if ((microtime(true) - $start) >= self::WP_CLI_TIMEOUT_SEC) {
                $timedOut = true;
                @proc_terminate($process);
                break;
            }
            usleep(50000);
        }

        // Drain any remaining buffered output after process exit
        $restOut = stream_get_contents($pipes[1]);
        if (is_string($restOut) && $restOut !== '' && strlen($stdout) < self::MAX_READ_BYTES) {
            $stdout .= $restOut;
        }
        $restErr = stream_get_contents($pipes[2]);
        if (is_string($restErr) && $restErr !== '' && strlen($stderr) < self::MAX_READ_BYTES) {
            $stderr .= $restErr;
        }

        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $exitCode = proc_close($process);
        if (isset($status['exitcode']) && is_int($status['exitcode']) && $status['exitcode'] !== -1) {
            $exitCode = $status['exitcode'];
        }

        if ($timedOut) {
            return $this->result('wp: command timed out after ' . self::WP_CLI_TIMEOUT_SEC . 's.', $cwdRel, 124, false, false);
        }

        $combined = trim($stdout);
        if (trim($stderr) !== '') {
            $combined = $combined !== '' ? $combined . "\n" . trim($stderr) : trim($stderr);
        }

        if ($exitCode === 127 && $combined === '') {
            $combined = 'wp: WP-CLI binary ("' . $bin . '") not found on this server.';
        }

        return $this->result($combined, $cwdRel, (int) $exitCode, false, $exitCode === 0);
    }

    /**
     * Check whether proc_open() is callable and not disabled in php.ini.
     *
     * @return bool
     */
    private function isProcOpenAvailable(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = (string) @ini_get('disable_functions');
        if ($disabled === '') {
            return true;
        }
        $list = array_map('trim', explode(',', strtolower($disabled)));
        return !in_array('proc_open', $list, true);
    }

    /**
     * Resolve and verify the configured WP-CLI executable path.
     *
     * @return string
     */
    private function resolveWpCliBinary(): string
    {
        if ($this->wpCliBin !== 'wp') {
            return $this->wpCliBin;
        }
        $candidates = ['/usr/local/bin/wp', '/usr/bin/wp'];
        foreach ($candidates as $path) {
            if (@is_file($path) && @is_executable($path)) {
                return $path;
            }
        }
        return 'wp';
    }

    /**
     * Recursively ensure each directory segment exists within PathGuard.
     *
     * @param string $relPath
     * @param string $notDirMessage
     * @return void
     */
    private function ensureDirectoryPath(string $relPath, string $notDirMessage): void
    {
        $walkRel = '';
        foreach (explode('/', $relPath) as $seg) {
            $safeName = $this->guard->validateBaseName($seg);
            $targetAbs = $this->guard->resolveTarget($walkRel, $safeName);
            if (!file_exists($targetAbs)) {
                $this->fs->createDirectory($walkRel, $safeName);
            } elseif (!is_dir($targetAbs)) {
                throw new \InvalidArgumentException($notDirMessage);
            }
            $walkRel = $walkRel === '' ? $safeName : $walkRel . '/' . $safeName;
        }
    }

    /**
     * Return the Virtual Console help text listing supported commands.
     *
     * @return string
     */
    private function cmdHelp(): string
    {
        return implode("\n", [
            'FileDock Virtual Console (Pure-PHP, Jailed to ROOT_DIR)',
            '',
            'Navigation & Inspection:',
            '  pwd                          Print current virtual directory',
            '  cd [dir]                     Change directory (/, .., relative paths)',
            '  ls [-a] [-l] [path]          List directory contents',
            '  ll [-a] [path]               Long directory listing (alias for ls -l)',
            '  stat <path>                  Display file or directory metadata',
            '  find [path] [-name glob] [-type f|d]  Search files/directories',
            '  grep [-inr] <pattern> <path> Search text inside files',
            '',
            'File Reading:',
            '  cat <file...>                Print file contents (up to 512 KB)',
            '  head [-n N] <file>           Print first N lines (default 10)',
            '  tail [-n N] <file>           Print last N lines (default 10)',
            '',
            'File Operations:',
            '  mkdir [-p] <dir...>          Create directories',
            '  touch <file...>              Create empty file or update mtime',
            '  cp [-r] <src> <dest>         Copy file or directory',
            '  mv <src> <dest>              Move or rename file/directory',
            '  rm [-r] [-f] <target...>     Remove files or directories',
            '  chmod <octal> <target...>    Change permissions (e.g. chmod 0644 file.txt)',
            '  zip <archive.zip> <src...>   Create ZIP archive',
            '  unzip <archive.zip> [dest]   Extract ZIP archive',
            '',
            'System & Console:',
            '  df [-h]                      Show ROOT_DIR disk space',
            '  php -v                       Show PHP runtime version',
            '  whoami                       Show PHP process user',
            '  wp <args...>                 Run WP-CLI in current directory (if enabled)',
            '  clear                        Clear console output',
            '  help                         Show this help message',
        ]);
    }

    /**
     * Format a numeric file mode into an ls-style rwx permission string.
     *
     * @param int $perms
     * @param bool $isDir
     * @return string
     */
    private function formatModeString(int $perms, bool $isDir): string
    {
        $str = $isDir ? 'd' : '-';
        $str .= (($perms & 0x0100) !== 0) ? 'r' : '-';
        $str .= (($perms & 0x0080) !== 0) ? 'w' : '-';
        $str .= (($perms & 0x0040) !== 0) ? 'x' : '-';
        $str .= (($perms & 0x0020) !== 0) ? 'r' : '-';
        $str .= (($perms & 0x0010) !== 0) ? 'w' : '-';
        $str .= (($perms & 0x0008) !== 0) ? 'x' : '-';
        $str .= (($perms & 0x0004) !== 0) ? 'r' : '-';
        $str .= (($perms & 0x0002) !== 0) ? 'w' : '-';
        $str .= (($perms & 0x0001) !== 0) ? 'x' : '-';
        return $str;
    }

    /**
     * Format a byte count into a compact human-readable string.
     *
     * @param float $bytes
     * @return string
     */
    private function formatHumanBytes(float $bytes): string
    {
        if ($bytes < 1024.0) {
            return (int) $bytes . 'B';
        }
        $units = ['K', 'M', 'G', 'T'];
        $value = $bytes / 1024.0;
        $idx = 0;
        while ($value >= 1024.0 && $idx < count($units) - 1) {
            $value /= 1024.0;
            $idx++;
        }
        return number_format($value, 1) . $units[$idx];
    }
}

/**
 * FileManagerController dispatches HTTP requests and renders the UI.
 */
final class FileManagerController
{
    /** @var AuthManager */
    private AuthManager $auth;

    /** @var PathGuard */
    private PathGuard $guard;

    /** @var FileSystemService */
    private FileSystemService $fs;

    /** @var ServerInfoService */
    private ServerInfoService $serverInfo;

    /** @var VirtualConsoleService */
    private VirtualConsoleService $console;

    /** @var bool */
    private bool $wpCliEnabled;

    /**
     * Initialize controller dependencies from the application configuration.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $defaultHash = (string) ($config['DEFAULT_PASSWORD_HASH'] ?? ($config['PASSWORD_HASH'] ?? ''));
        $this->auth = new AuthManager(
            !empty($config['AUTH_ENABLED']),
            $defaultHash
        );
        $this->guard = new PathGuard((string) ($config['ROOT_DIR'] ?? __DIR__));
        $this->fs = new FileSystemService($this->guard);
        $this->serverInfo = new ServerInfoService();
        $this->wpCliEnabled = !empty($config['WP_CLI_ENABLED']);
        $this->console = new VirtualConsoleService(
            $this->guard,
            $this->fs,
            $this->wpCliEnabled,
            (string) ($config['WP_CLI_BIN'] ?? 'wp')
        );
    }

    /**
     * Route incoming HTTP GET and POST requests.
     *
     * @return void
     */
    public function handleRequest(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        if ($method === 'POST') {
            $this->handlePost();
            return;
        }

        $action = isset($_GET['action']) ? (string) $_GET['action'] : '';

        if (!$this->auth->isAuthenticated()) {
            $this->renderLoginPage('');
            return;
        }

        if ($action === 'download' || $action === 'preview') {
            $this->handleStreamAction($action === 'preview');
            return;
        }

        $this->renderAppPage();
    }

    /**
     * Handle authenticated GET file download or preview streaming.
     *
     * @param bool $isPreview
     * @return void
     */
    private function handleStreamAction(bool $isPreview): void
    {
        if ($this->auth->mustChangePassword()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Password change required before accessing files.';
            return;
        }

        $path = isset($_GET['path']) ? (string) $_GET['path'] : '';
        try {
            if ($isPreview) {
                $this->fs->streamPreview($path);
            } else {
                $this->fs->streamDownload($path);
            }
        } catch (\InvalidArgumentException $e) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            echo $e->getMessage();
        } catch (\RuntimeException $e) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            echo $e->getMessage();
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            echo 'An unexpected error occurred.';
        }
    }

    /**
     * Authenticate, verify CSRF, and dispatch POST API actions.
     *
     * @return void
     */
    private function handlePost(): void
    {
        if (isset($_POST['fm_login_submit'])) {
            $this->handleLoginPost();
            return;
        }

        header('Content-Type: application/json; charset=utf-8');

        if (!$this->auth->isAuthenticated()) {
            $this->jsonError('Authentication required.', 401);
            return;
        }

        $payload = $this->readPostPayload();
        $action = isset($payload['action']) && is_string($payload['action']) ? $payload['action'] : '';
        $csrf = isset($payload['csrf_token']) && is_string($payload['csrf_token']) ? $payload['csrf_token'] : '';

        if (!$this->auth->verifyCsrf($csrf)) {
            $this->jsonError('Invalid or expired CSRF token. Refresh the page.', 403);
            return;
        }

        if ($this->auth->mustChangePassword() && $action !== 'change_password' && $action !== 'logout') {
            $this->jsonError('Password change required before performing this action.', 403);
            return;
        }

        try {
            switch ($action) {
                case 'logout':
                    $this->auth->logout();
                    $this->jsonSuccess(['loggedOut' => true]);
                    break;

                case 'change_password':
                    $currentPassword = isset($payload['currentPassword']) ? (string) $payload['currentPassword'] : '';
                    $newPassword = isset($payload['newPassword']) ? (string) $payload['newPassword'] : '';
                    $confirmPassword = isset($payload['confirmPassword']) ? (string) $payload['confirmPassword'] : '';
                    $this->auth->changePassword($currentPassword, $newPassword, $confirmPassword);
                    $this->jsonSuccess([
                        'changed' => true,
                        'mustChangePassword' => $this->auth->mustChangePassword(),
                        'csrfToken' => $this->auth->getCsrfToken(),
                    ]);
                    break;

                case 'list':
                    $path = isset($payload['path']) ? (string) $payload['path'] : '';
                    $showHidden = !empty($payload['showHidden']);
                    $this->jsonSuccess($this->fs->listDirectory($path, $showHidden));
                    break;

                case 'mkdir':
                    $path = isset($payload['path']) ? (string) $payload['path'] : '';
                    $name = isset($payload['name']) ? (string) $payload['name'] : '';
                    $this->jsonSuccess($this->fs->createDirectory($path, $name));
                    break;

                case 'mkfile':
                    $path = isset($payload['path']) ? (string) $payload['path'] : '';
                    $name = isset($payload['name']) ? (string) $payload['name'] : '';
                    $content = isset($payload['content']) ? (string) $payload['content'] : '';
                    $this->jsonSuccess($this->fs->createFile($path, $name, $content));
                    break;

                case 'rename':
                    $path = isset($payload['path']) ? (string) $payload['path'] : '';
                    $newName = isset($payload['newName']) ? (string) $payload['newName'] : '';
                    $this->jsonSuccess($this->fs->renameItem($path, $newName));
                    break;

                case 'delete':
                    $paths = isset($payload['paths']) && is_array($payload['paths']) ? $payload['paths'] : [];
                    $this->jsonSuccess($this->fs->deleteItems($paths));
                    break;

                case 'copy':
                    $paths = isset($payload['paths']) && is_array($payload['paths']) ? $payload['paths'] : [];
                    $dest = isset($payload['destination']) ? (string) $payload['destination'] : '';
                    $this->jsonSuccess($this->fs->copyItems($paths, $dest));
                    break;

                case 'move':
                    $paths = isset($payload['paths']) && is_array($payload['paths']) ? $payload['paths'] : [];
                    $dest = isset($payload['destination']) ? (string) $payload['destination'] : '';
                    $this->jsonSuccess($this->fs->moveItems($paths, $dest));
                    break;

                case 'chmod':
                    $path = isset($payload['path']) ? (string) $payload['path'] : '';
                    $mode = isset($payload['mode']) ? (string) $payload['mode'] : '';
                    $this->jsonSuccess($this->fs->chmodItem($path, $mode));
                    break;

                case 'read':
                    $path = isset($payload['path']) ? (string) $payload['path'] : '';
                    $this->jsonSuccess($this->fs->readFileContent($path));
                    break;

                case 'save':
                    $path = isset($payload['path']) ? (string) $payload['path'] : '';
                    $content = isset($payload['content']) ? (string) $payload['content'] : '';
                    $this->jsonSuccess($this->fs->saveFileContent($path, $content));
                    break;

                case 'upload':
                    $path = isset($payload['path']) ? (string) $payload['path'] : '';
                    $overwrite = !empty($payload['overwrite']) && $payload['overwrite'] !== '0' && $payload['overwrite'] !== 'false';
                    $files = isset($_FILES['files']) && is_array($_FILES['files']) ? $_FILES['files'] : [];
                    $this->jsonSuccess($this->fs->handleUpload($path, $files, $overwrite));
                    break;

                case 'zip':
                    $paths = isset($payload['paths']) && is_array($payload['paths']) ? $payload['paths'] : [];
                    $dest = isset($payload['destination']) ? (string) $payload['destination'] : '';
                    $name = isset($payload['name']) ? (string) $payload['name'] : '';
                    $this->jsonSuccess($this->fs->compressZip($paths, $dest, $name));
                    break;

                case 'unzip':
                    $path = isset($payload['path']) ? (string) $payload['path'] : '';
                    $dest = isset($payload['destination']) ? (string) $payload['destination'] : '';
                    $this->jsonSuccess($this->fs->extractZip($path, $dest));
                    break;

                case 'server_info':
                    $this->jsonSuccess($this->serverInfo->getInfo($this->guard->getRoot(), $this->wpCliEnabled));
                    break;

                case 'terminal':
                    $command = isset($payload['command']) ? (string) $payload['command'] : '';
                    $cwd = isset($payload['cwd']) ? (string) $payload['cwd'] : '';
                    $this->jsonSuccess($this->console->execute($command, $cwd));
                    break;

                default:
                    $this->jsonError('Unknown action.', 400);
                    break;
            }
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $this->jsonError($e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->jsonError('An unexpected internal error occurred.', 500);
        }
    }

    /**
     * Process login form submission and redirect or render errors.
     *
     * @return void
     */
    private function handleLoginPost(): void
    {
        $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
        if (!$this->auth->verifyCsrf($csrf)) {
            $this->renderLoginPage('Invalid security token. Please try again.');
            return;
        }

        $pass = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if ($this->auth->login($pass)) {
            header('Location: ' . $this->getSelfUrl());
            exit;
        }

        $err = $this->auth->getLastError();
        $this->renderLoginPage($err !== '' ? $err : 'Invalid password.');
    }

    /**
     * Read request parameters from JSON body or form POST data.
     *
     * @return array<string, mixed>
     */
    private function readPostPayload(): array
    {
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
        if (strpos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return $_POST;
    }

    /**
     * Send a standardized JSON success response and terminate.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    private function jsonSuccess(array $data): void
    {
        // Substitute invalid UTF-8 sequences so binary/non-UTF-8 output never breaks JSON encoding
        echo json_encode(
            ['ok' => true, 'data' => $data],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * Send a standardized JSON error response with HTTP status and terminate.
     *
     * @param string $message
     * @param int $status
     * @return void
     */
    private function jsonError(string $message, int $status = 400): void
    {
        http_response_code($status);
        echo json_encode(
            ['ok' => false, 'error' => $message],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * Return the current script URL path without query parameters.
     *
     * @return string
     */
    private function getSelfUrl(): string
    {
        $script = !empty($_SERVER['SCRIPT_NAME'])
            ? (string) $_SERVER['SCRIPT_NAME']
            : (isset($_SERVER['PHP_SELF']) ? (string) $_SERVER['PHP_SELF'] : 'filedock.php');
        $clean = strtok($script, '?');
        return is_string($clean) && $clean !== '' ? $clean : 'filedock.php';
    }

    /**
     * Return shared base CSS tokens, reset, and form primitives for Login and App views.
     *
     * @return string
     */
    private function getBaseCss(): string
    {
        return <<<'CSS'
:root {
    color-scheme: light dark;
    --bg: light-dark(#f6f7f9, #0c0e11);
    --surface: light-dark(#ffffff, #14171c);
    --surface-2: light-dark(#eef0f3, #1c2028);
    --surface-hover: light-dark(#f3f5f8, #1a1e25);
    --border: light-dark(#dfe3e8, #272c36);
    --border-strong: light-dark(#c4cbd5, #3a4150);
    --text: light-dark(#111317, #f0f2f5);
    --text-2: light-dark(#3b4252, #c5ccd6);
    --muted: light-dark(#646b78, #8a93a2);
    --accent: light-dark(#181c22, #f0f2f5);
    --accent-soft: color-mix(in srgb, var(--accent) 12%, transparent);
    --primary-bg: light-dark(#181c22, #f0f2f5);
    --primary-hover: light-dark(#262b34, #dbe0e6);
    --primary-fg: light-dark(#ffffff, #111317);
    --danger: light-dark(#be123c, #fb7185);
    --danger-btn: #be123c;
    --danger-soft: light-dark(rgba(190, 18, 60, 0.08), rgba(251, 113, 133, 0.12));
    --danger-soft-hover: light-dark(rgba(190, 18, 60, 0.14), rgba(251, 113, 133, 0.20));
    --danger-bg: light-dark(#fff1f2, rgba(136, 19, 55, 0.28));
    --danger-border: light-dark(#fecdd3, rgba(251, 113, 133, 0.32));
    --danger-text: light-dark(#be123c, #fda4af);
    --ok: light-dark(#0f766e, #2dd4bf);
    --warning: light-dark(#b45309, #fbbf24);
    --warning-soft: light-dark(rgba(217, 119, 6, 0.11), rgba(245, 158, 11, 0.15));
    --shadow-sm: light-dark(0 1px 2px rgba(17, 19, 23, 0.05), 0 1px 2px rgba(0, 0, 0, 0.35));
    --shadow-md: light-dark(0 8px 24px -6px rgba(17, 19, 23, 0.12), 0 10px 28px -6px rgba(0, 0, 0, 0.55));
    --shadow-lg: light-dark(0 20px 48px -12px rgba(17, 19, 23, 0.2), 0 24px 56px -12px rgba(0, 0, 0, 0.72));
    --font-sans: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    --radius: 10px;
    --radius-sm: 8px;
}
:root[data-theme="light"] { color-scheme: light; }
:root[data-theme="dark"] { color-scheme: dark; }

*, *::before, *::after { box-sizing: border-box; }
html { scrollbar-gutter: stable; }
html, body { margin: 0; padding: 0; }
body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--font-sans);
    font-size: 14px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    overflow-x: clip;
}
button, input, textarea, select { font-family: inherit; font-size: inherit; color: inherit; }
input[type="checkbox"] {
    accent-color: var(--accent);
    width: 16px;
    height: 16px;
    margin: 0;
    cursor: pointer;
    flex-shrink: 0;
}
:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

.brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
.brand-badge {
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    border-radius: var(--radius-sm);
    background: #181c22;
    color: #ffffff;
    display: grid;
    place-items: center;
    box-shadow: var(--shadow-sm);
}
.field {
    margin-block-end: 14px;
    & label { display: block; font-size: 12px; font-weight: 500; color: var(--muted); margin-block-end: 6px; }
}
.input {
    width: 100%;
    min-height: 38px;
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-strong);
    background: var(--surface);
    color: var(--text);
    font-size: 14px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    &:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--accent-soft);
        outline: none;
    }
}
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 36px;
    padding: 7px 12px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s ease, border-color 0.15s ease, opacity 0.15s ease;
    &:disabled { opacity: 0.5; cursor: not-allowed; }
}
.btn-primary { background: var(--primary-bg); border-color: var(--primary-bg); color: var(--primary-fg); }
.btn-danger { background: var(--danger-btn); border-color: var(--danger-btn); color: #fff; }
.btn-soft-danger { background: var(--danger-soft); border-color: var(--danger-border); color: var(--danger); }
.btn-active { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
.btn-icon { min-width: 36px; min-height: 36px; padding: 7px; }
.btn-sm { min-height: 30px; padding: 4px 10px; font-size: 12px; }
.alert-error {
    margin-block-end: 14px;
    padding: 10px 12px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--danger-border);
    background: var(--danger-bg);
    color: var(--danger-text);
    font-size: 13px;
    overflow-wrap: anywhere;
    user-select: text;
}
.gh-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-block-start: 12px;
    padding-inline: 2px;
}
.gh-footer.center { justify-content: center; }
.gh-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    border-radius: 6px;
    color: var(--muted);
    font-size: 12px;
    text-decoration: none;
    transition: color 0.15s ease, background 0.15s ease;
}
.muted { color: var(--muted); }
.text-danger { color: var(--danger); }
.mono { font-family: var(--font-mono); }
.tabular { font-variant-numeric: tabular-nums; }
.hidden { display: none !important; }
/* Keep SVG sprite container out of layout without display:none for Safari/WebKit <use> compatibility */
.svg-sprite { position: absolute; width: 0; height: 0; overflow: hidden; pointer-events: none; }
.m-0 { margin: 0; }
.mt-8 { margin-block-start: 8px; }
.mt-12 { margin-block-start: 12px; }
.mb-12 { margin-block-end: 12px; }
.fw-600 { font-weight: 600; }
.hint-text { margin: 0; font-size: 12px; color: var(--muted); }

@media (hover: hover) {
    .btn:hover:not(:disabled) { background: var(--surface-2); border-color: var(--border-strong); }
    .btn-primary:hover:not(:disabled) { background: var(--primary-hover); border-color: var(--primary-hover); }
    .btn-danger:hover:not(:disabled) { background: var(--danger-btn); border-color: var(--danger-btn); opacity: 0.9; }
    .btn-soft-danger:hover:not(:disabled) { background: var(--danger-soft-hover); border-color: var(--danger); }
    .gh-link:hover { color: var(--text); background: var(--surface-2); }
}
@media (max-width: 47.99rem) {
    .input { font-size: 16px; }
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
        scroll-behavior: auto !important;
    }
}
CSS;
    }

    /**
     * Render shared <head> metadata, favicon, and zero-FOUC theme initializer.
     *
     * @param string $title
     * @return string
     */
    private function getHeadPrefixHtml(string $title): string
    {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        return str_replace(
            '__TITLE__',
            $escapedTitle,
            <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#f6f7f9" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0c0e11" media="(prefers-color-scheme: dark)">
<title>__TITLE__</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='5' fill='%23181c22'/%3E%3Cpath d='M2.5 5.5A2.5 2.5 0 0 1 5 3h5l2 2.2h7a2.5 2.5 0 0 1 2.5 2.5v10.8A2.5 2.5 0 0 1 19 21H5a2.5 2.5 0 0 1-2.5-2.5V5.5z' fill='%23ffffff'/%3E%3Crect x='13' y='12.5' width='5.5' height='4.5' rx='1.2' fill='%23181c22'/%3E%3C/svg%3E">
<script>(function(){var t=localStorage.getItem('fm_theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t);}})();</script>
HTML
        );
    }

    /**
     * Render shared FileDock brand badge SVG.
     *
     * @return string
     */
    private function getBrandBadgeHtml(): string
    {
        return '<div class="brand-badge" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M2.5 5.5A2.5 2.5 0 0 1 5 3h5l2 2.2h7a2.5 2.5 0 0 1 2.5 2.5v10.8A2.5 2.5 0 0 1 19 21H5a2.5 2.5 0 0 1-2.5-2.5V5.5z" fill="currentColor"/><rect x="13" y="12.5" width="5.5" height="4.5" rx="1.2" fill="#181c22"/></svg></div>';
    }

    /**
     * Render shared GitHub repository footer link.
     *
     * @return string
     */
    private function getGithubLinkHtml(): string
    {
        return '<a class="gh-link" href="https://github.com/mahyardyn/file-dock" target="_blank" rel="noopener noreferrer"><svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5C5.65.5.5 5.65.5 12c0 5.08 3.29 9.39 7.86 10.91.58.11.79-.25.79-.56 0-.28-.01-1.02-.02-2-3.2.69-3.88-1.54-3.88-1.54-.52-1.33-1.28-1.68-1.28-1.68-1.05-.72.08-.71.08-.71 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.71 1.26 3.37.96.1-.75.4-1.26.73-1.55-2.55-.29-5.24-1.28-5.24-5.68 0-1.26.45-2.28 1.19-3.09-.12-.29-.52-1.46.11-3.04 0 0 .97-.31 3.18 1.18a11.1 11.1 0 0 1 5.79 0c2.21-1.49 3.18-1.18 3.18-1.18.63 1.58.23 2.75.11 3.04.74.81 1.19 1.83 1.19 3.09 0 4.41-2.69 5.39-5.25 5.67.41.36.78 1.06.78 2.14 0 1.54-.01 2.79-.01 3.17 0 .31.21.68.8.56A11.51 11.51 0 0 0 23.5 12C23.5 5.65 18.35.5 12 .5Z"/></svg><span>FileDock v1.0.0 &middot; MIT</span></a>';
    }

    /**
     * Render the standalone login page.
     *
     * @param string $errorMessage
     * @return void
     */
    private function renderLoginPage(string $errorMessage): void
    {
        $csrf = htmlspecialchars($this->auth->getCsrfToken(), ENT_QUOTES, 'UTF-8');
        $selfUrl = htmlspecialchars($this->getSelfUrl(), ENT_QUOTES, 'UTF-8');
        $errorHtml = $errorMessage !== ''
            ? '<div class="alert-error" role="alert">' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';

        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        $html = <<<'HTML'
__HEAD_PREFIX__
<style>
__BASE_CSS__
body {
    min-height: 100dvh;
    display: grid;
    place-items: center;
    padding-block: clamp(12px, 4vw, 24px);
    padding-inline: max(clamp(12px, 4vw, 24px), env(safe-area-inset-left, 0px)) max(clamp(12px, 4vw, 24px), env(safe-area-inset-right, 0px));
}
.login-wrap { width: min(380px, 100%); }
.login-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: clamp(18px, 5vw, 28px);
    box-shadow: var(--shadow-md);
}
.brand { margin-block-end: 6px; }
h1 { margin: 0; font-size: 18px; font-weight: 650; letter-spacing: -0.015em; }
.subtitle { margin: 0 0 20px; font-size: 13px; color: var(--muted); }
.login-btn { width: 100%; min-height: 42px; margin-block-start: 4px; font-size: 14px; font-weight: 600; }
</style>
</head>
<body>
<div class="login-wrap">
<main class="login-card">
    <div class="brand">
        __BRAND_BADGE__
        <h1>FileDock</h1>
    </div>
    <p class="subtitle">Sign in to access your server workspace.</p>
    __ERROR_HTML__
    <form method="post" action="__SELF_URL__">
        <input type="hidden" name="csrf_token" value="__CSRF__">
        <input type="hidden" name="fm_login_submit" value="1">
        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" class="input" autocomplete="current-password" required autofocus>
        </div>
        <button type="submit" class="btn btn-primary login-btn">Sign In</button>
    </form>
</main>
<footer class="gh-footer center">
    __GITHUB_LINK__
</footer>
</div>
</body>
</html>
HTML;
        echo strtr($html, [
            '__HEAD_PREFIX__' => $this->getHeadPrefixHtml('Sign In — FileDock'),
            '__BASE_CSS__' => $this->getBaseCss(),
            '__BRAND_BADGE__' => $this->getBrandBadgeHtml(),
            '__ERROR_HTML__' => $errorHtml,
            '__SELF_URL__' => $selfUrl,
            '__CSRF__' => $csrf,
            '__GITHUB_LINK__' => $this->getGithubLinkHtml(),
        ]);
    }

    /**
     * Render the single-file FileDock application interface.
     *
     * @return void
     */
    private function renderAppPage(): void
    {
        $authEnabled = $this->auth->isAuthEnabled();
        $mustChangePassword = $this->auth->mustChangePassword();
        $zipSupported = class_exists('ZipArchive');

        $initialListing = null;
        if (!$mustChangePassword) {
            try {
                $initialListing = $this->fs->listDirectory('', false);
            } catch (\Throwable $e) {
                $initialListing = null;
            }
        }

        $bootstrap = [
            'csrfToken' => $this->auth->getCsrfToken(),
            'authEnabled' => $authEnabled,
            'mustChangePassword' => $mustChangePassword,
            'zipSupported' => $zipSupported,
            'selfUrl' => $this->getSelfUrl(),
            'initialListing' => $initialListing,
        ];
        $bootstrapJson = json_encode(
            $bootstrap,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES
        );

        $authHiddenClass = $authEnabled ? '' : ' hidden';
        $zipBadgeClass = $zipSupported ? ' hidden' : '';

        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        $html = <<<'HTML'
__HEAD_PREFIX__
<style>
__BASE_CSS__
body:has(dialog[open]) { overflow: hidden; }
.skip-link {
    position: absolute;
    inset-inline-start: -9999px;
    inset-block-start: 8px;
    z-index: 100;
    padding: 8px 14px;
    border-radius: var(--radius-sm);
    background: var(--primary-bg);
    color: var(--primary-fg);
    font-weight: 600;
    text-decoration: none;
    &:focus { inset-inline-start: 12px; }
}
.app-shell {
    max-width: 1320px;
    margin-inline: auto;
    padding-block: 10px calc(20px + env(safe-area-inset-bottom, 0px));
    padding-inline: max(clamp(10px, 2.5vw, 20px), env(safe-area-inset-left, 0px)) max(clamp(10px, 2.5vw, 20px), env(safe-area-inset-right, 0px));
}
body:has(.bulk-bar.visible) .app-shell { padding-block-end: calc(76px + env(safe-area-inset-bottom, 0px)); }

.meta-count, .col-size, .col-mtime, .col-perms, .mobile-meta, .perms-btn, .bulk-count, .progress-label, .editor-gutter, .editor-meta, .kv-val {
    font-variant-numeric: tabular-nums;
}

/* Smart Mobile-First Header */
.topbar {
    position: relative;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-block-end: 8px;
}
.brand-title { font-size: clamp(15px, 2.5vw, 17px); font-weight: 650; letter-spacing: -0.015em; margin: 0; }
.brand-sub { display: none; font-size: 12px; color: var(--muted); align-items: center; gap: 6px; }
.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text-2);
}
.badge-warning { background: var(--warning-soft); color: var(--warning); border-color: transparent; }

.toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; width: 100%; }
.primary-actions { display: flex; align-items: center; gap: 6px; width: 100%; }
.toolbar-sep { display: none; width: 1px; height: 20px; background: var(--border); margin-inline: 2px; flex-shrink: 0; }
.search-wrap {
    position: relative;
    display: flex;
    align-items: center;
    flex: 1 1 auto;
    min-width: 0;
    & svg { position: absolute; inset-inline-start: 10px; color: var(--muted); pointer-events: none; }
}
.search-input {
    width: 100%;
    min-height: 36px;
    padding-block: 6px;
    padding-inline: 32px 26px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-strong);
    background: var(--surface);
    font-size: 13px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    &:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); outline: none; }
}
.kbd-hint {
    position: absolute;
    inset-inline-end: 8px;
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--muted);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 0 5px;
    pointer-events: none;
}

.dropdown-wrap, .account-wrap { position: relative; }
.dropdown-menu, .secondary-tools {
    position: absolute;
    inset-inline-end: 0;
    inset-block-start: calc(100% + 6px);
    z-index: 45;
    padding: 6px;
    border-radius: var(--radius);
    background: var(--surface);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-md);
    display: none;
    flex-direction: column;
    gap: 2px;
    &.open { display: flex; }
}
.dropdown-menu { min-width: 185px; }
.secondary-tools {
    min-width: 210px;
    & .btn { justify-content: flex-start; border-color: transparent; background: transparent; width: 100%; }
    & .btn-active { background: var(--accent-soft); color: var(--accent); }
    & .menu-item-danger { color: var(--danger); }
}

/* Main Panel & Breadcrumbs */
.panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    &[aria-busy="true"] tbody { opacity: 0.55; pointer-events: none; }
}
.breadcrumbs-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 6px 10px;
    min-height: 38px;
    padding: 5px 10px;
    border-block-end: 1px solid var(--border);
    background: var(--surface-2);
}
.breadcrumbs { display: flex; flex-wrap: wrap; align-items: center; gap: 3px; font-size: 13px; flex: 1 1 auto; min-width: 0; }
.crumb-btn {
    border: 1px solid transparent;
    background: transparent;
    color: var(--muted);
    padding: 4px 8px;
    min-height: 30px;
    line-height: 1.3;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    &.current { color: var(--text); font-weight: 600; background: var(--surface); border-color: var(--border); }
}
.crumb-sep { color: var(--muted); user-select: none; }
.meta-count { font-size: 12px; color: var(--muted); }

/* File Table */
.table-wrap { width: 100%; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th, td {
    padding: 7px 10px;
    text-align: start;
    border-block-end: 1px solid var(--border);
    vertical-align: middle;
}
th {
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted);
    background: var(--surface);
    white-space: nowrap;
}
tbody tr:last-child td { border-block-end: 0; }
tbody tr.selected, tbody tr.row-active { background: var(--accent-soft); }
.sort-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 0;
    background: transparent;
    padding: 1px 0;
    min-height: 28px;
    font: inherit;
    color: inherit;
    text-transform: inherit;
    letter-spacing: inherit;
    cursor: pointer;
}
.col-check { width: 38px; text-align: center; }
.col-size, .col-mtime, .col-perms { display: none; white-space: nowrap; color: var(--text-2); font-size: 13px; }
.col-actions { width: 48px; text-align: end; padding-inline-end: 8px; }
.name-cell { display: flex; align-items: center; gap: 9px; min-width: 0; }
.name-main { min-width: 0; flex: 1 1 auto; }
.icon-wrap {
    width: 28px;
    height: 28px;
    border-radius: var(--radius-sm);
    display: grid;
    place-items: center;
    flex-shrink: 0;
    color: light-dark(#475569, #94a3b8);
    background: color-mix(in srgb, currentColor 13%, transparent);
    &.icon-folder   { color: light-dark(#b45309, #f59e0b); }
    &.icon-php      { color: light-dark(#4f46e5, #818cf8); }
    &.icon-script   { color: light-dark(#a16207, #facc15); }
    &.icon-style    { color: light-dark(#0284c7, #38bdf8); }
    &.icon-markup   { color: light-dark(#c2410c, #fb923c); }
    &.icon-config   { color: light-dark(#0f766e, #2dd4bf); }
    &.icon-database { color: light-dark(#0e7490, #22d3ee); }
    &.icon-image    { color: light-dark(#9333ea, #c084fc); }
    &.icon-archive  { color: light-dark(#be123c, #fb7185); }
    &.icon-code     { color: light-dark(#0f766e, #2dd4bf); }
}
.item-link {
    border: 0;
    background: transparent;
    padding: 0;
    font-weight: 500;
    color: var(--text);
    cursor: pointer;
    text-align: start;
    unicode-bidi: plaintext;
    word-break: break-word;
    overflow-wrap: anywhere;
}
.mobile-meta {
    margin-block-start: 2px;
    font-size: 11px;
    color: var(--muted);
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 3px 8px;
}
.mobile-meta-item { display: inline-flex; align-items: center; white-space: nowrap; }
.perms-btn {
    border: 1px solid var(--border);
    background: var(--surface-2);
    border-radius: 6px;
    padding: 1px 6px;
    font-family: var(--font-mono);
    font-size: 11px;
    cursor: pointer;
}
.row-menu-btn {
    min-width: 34px;
    min-height: 34px;
    padding: 5px;
    border-radius: var(--radius-sm);
    border: 1px solid transparent;
    background: transparent;
    color: var(--muted);
    display: inline-grid;
    place-items: center;
    cursor: pointer;
}
.empty-state { padding: 40px 16px; text-align: center; color: var(--muted); }
.skel-row { height: 22px; border-radius: 6px; background: var(--surface-2); }

/* Footer Shortcut Hint */
.footer-hints {
    display: none;
    align-items: center;
    gap: 10px;
    font-size: 12px;
    color: var(--muted);
    & kbd {
        font-family: var(--font-mono);
        font-size: 11px;
        padding: 1px 5px;
        border: 1px solid var(--border);
        border-radius: 4px;
        background: var(--surface);
    }
}

/* Single Shared Context Menu (Desktop Floating + Mobile Bottom Sheet) */
.ctx-backdrop {
    position: fixed;
    inset: 0;
    z-index: 65;
    background: rgba(9, 11, 15, 0.45);
    display: none;
    &.open { display: block; }
}
.ctx-menu {
    position: fixed;
    z-index: 70;
    inset-inline: 0;
    inset-block-end: 0;
    padding: 8px 8px calc(10px + env(safe-area-inset-bottom, 0px));
    background: var(--surface);
    border-block-start: 1px solid var(--border);
    border-radius: 16px 16px 0 0;
    box-shadow: var(--shadow-lg);
    display: none;
    flex-direction: column;
    gap: 2px;
    max-height: 75dvh;
    overflow-y: auto;
    &.open { display: flex; }
}
.menu-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 40px;
    padding: 8px 12px;
    border: 0;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
    text-align: start;
    text-decoration: none;
    cursor: pointer;
    &.danger { color: var(--danger); }
    &:disabled { opacity: 0.45; cursor: not-allowed; }
}
.menu-sep { border: 0; border-block-start: 1px solid var(--border); margin: 4px 2px; }

/* Sticky Two-Row Mobile / Floating Desktop Bulk Bar */
.bulk-bar {
    position: fixed;
    z-index: 40;
    inset-inline: 0;
    inset-block-end: 0;
    display: none;
    flex-direction: column;
    gap: 6px;
    padding: 6px 8px calc(6px + env(safe-area-inset-bottom, 0px));
    background: var(--surface);
    border-block-start: 1px solid var(--border-strong);
    box-shadow: var(--shadow-lg);
    &.visible { display: flex; }
}
.bulk-head { display: flex; align-items: center; justify-content: space-between; width: 100%; }
.bulk-actions { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; width: 100%; }
.bulk-clear-btn { min-height: 28px; padding: 3px 8px; }
.bulk-count { font-size: 12px; font-weight: 600; color: var(--accent); padding-inline: 4px; white-space: nowrap; }
.bulk-sep { display: none; width: 1px; height: 18px; background: var(--border); flex-shrink: 0; margin-inline: 2px; }

/* Native <dialog> Modals & Drawer */
dialog {
    margin: auto;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    color: var(--text);
    box-shadow: var(--shadow-lg);
    width: min(460px, calc(100vw - 24px));
    max-height: min(90dvh, 860px);
    overflow: hidden;
    overscroll-behavior: contain;
    &::backdrop { background: rgba(9, 11, 15, 0.62); }
    &[open] { display: flex; flex-direction: column; }
    &.dialog-lg { width: min(760px, calc(100vw - 24px)); }
    &.dialog-xl { width: min(1040px, calc(100vw - 24px)); height: min(86dvh, 780px); }
    &.dialog-drawer {
        margin: 0 0 0 auto;
        width: min(440px, 100vw);
        height: 100dvh;
        max-height: 100dvh;
        border-radius: 0;
        border-inline-start: 1px solid var(--border);
    }
}
@media (prefers-reduced-motion: no-preference) {
    dialog {
        opacity: 0;
        transform: scale(0.97);
        transition: opacity 0.18s cubic-bezier(0.16, 1, 0.3, 1), transform 0.18s cubic-bezier(0.16, 1, 0.3, 1);
        &[open] {
            opacity: 1;
            transform: scale(1);
            @starting-style { opacity: 0; transform: scale(0.97); }
        }
        &.closing { opacity: 0; transform: scale(0.97); }
        &.dialog-drawer {
            transform: translateX(24px);
            &[open] {
                transform: translateX(0);
                @starting-style { opacity: 0; transform: translateX(24px); }
            }
            &.closing { opacity: 0; transform: translateX(24px); }
        }
    }
    @media (max-width: 47.99rem) {
        dialog.sheet-mobile {
            transform: translateY(20px);
            &[open] {
                transform: translateY(0);
                @starting-style { opacity: 0; transform: translateY(20px); }
            }
            &.closing { opacity: 0; transform: translateY(20px); }
        }
    }
}
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 16px;
    border-block-end: 1px solid var(--border);
    flex-shrink: 0;
}
.header-row { display: flex; align-items: center; gap: 8px; min-width: 0; }
.modal-title { margin: 0; font-size: 15px; font-weight: 650; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.modal-body { padding: 16px; overflow: auto; flex: 1 1 auto; }
.dlg-pad { padding: 12px; }
.modal-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 16px;
    border-block-start: 1px solid var(--border);
    background: var(--surface-2);
    flex-shrink: 0;
}

/* Chmod */
.chmod-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-block-end: 12px; }
.chmod-col {
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 8px 10px;
    margin: 0;
    & legend { font-size: 12px; font-weight: 600; color: var(--muted); padding: 0 4px; }
}
.chmod-check { display: flex; align-items: center; gap: 7px; font-size: 13px; padding: 3px 0; cursor: pointer; }
.chmod-preview { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 12px; border-radius: var(--radius-sm); background: var(--surface-2); }
.chmod-octal { width: 88px; text-align: center; }

/* Upload */
.upload-zone {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    border: 2px dashed var(--border-strong);
    border-radius: var(--radius);
    padding: 26px 16px;
    text-align: center;
    cursor: pointer;
    background: var(--surface-2);
    transition: border-color 0.15s ease, background 0.15s ease;
    &.dragover { border-color: var(--accent); background: var(--accent-soft); }
}
.upload-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--accent-soft);
    color: var(--accent);
    display: grid;
    place-items: center;
    margin-block-end: 4px;
}
.progress-wrap { margin-block-start: 12px; display: none; &.active { display: block; } }
.progress-track { height: 7px; border-radius: 999px; background: var(--surface-2); overflow: hidden; }
.progress-fill { height: 100%; width: 0%; background: var(--accent); transition: width 0.12s linear; }
.progress-label { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); margin-block-start: 5px; }
.drop-overlay {
    position: fixed;
    inset: 0;
    z-index: 90;
    background: var(--accent-soft);
    display: none;
    place-items: center;
    pointer-events: none;
    &.active { display: grid; }
}
.drop-overlay-card { padding: 22px 28px; border-radius: var(--radius); background: var(--surface); border: 2px dashed var(--accent); font-weight: 600; box-shadow: var(--shadow-lg); }

/* Editor & Preview */
.editor-body { padding: 0; display: flex; flex-direction: column; overflow: hidden; }
.editor-container { display: flex; flex: 1 1 auto; min-height: 0; background: var(--bg); }
.editor-gutter {
    width: 50px;
    padding: 12px 8px;
    border-inline-end: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--muted);
    font-family: var(--font-mono);
    font-size: 13px;
    line-height: 1.55;
    text-align: end;
    user-select: none;
    overflow: hidden;
    white-space: pre;
}
.editor-textarea {
    flex: 1 1 auto;
    border: 0;
    resize: none;
    padding: 12px 14px;
    background: transparent;
    font-family: var(--font-mono);
    font-size: 13px;
    line-height: 1.55;
    outline: none;
    tab-size: 4;
}
.editor-meta { margin-inline-end: auto; font-size: 12px; color: var(--muted); }
.preview-stage {
    display: grid;
    place-items: center;
    min-height: 200px;
    max-height: 62dvh;
    background: var(--surface-2);
    border-radius: var(--radius-sm);
    padding: 12px;
    overflow: auto;
    & img { max-width: 100%; max-height: 58dvh; border-radius: 6px; object-fit: contain; }
}

/* Server Info Drawer */
.drawer-section {
    margin-block-end: 18px;
    & h3 { margin: 0 0 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
}
.kv-list { border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; }
.kv-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    padding: 8px 12px;
    font-size: 13px;
    border-block-end: 1px solid var(--border);
    &:last-child { border-block-end: 0; }
}
.kv-key { color: var(--muted); flex-shrink: 0; }
.kv-val { font-family: var(--font-mono); font-size: 12px; text-align: end; word-break: break-word; }
.disk-bar { height: 8px; border-radius: 999px; background: var(--surface-2); overflow: hidden; margin: 0 12px 10px; }
.disk-fill { height: 100%; border-radius: 999px; background: var(--accent); transition: width 0.25s ease; }
.disk-fill.warn { background: var(--warning); }
.disk-fill.crit { background: var(--danger); }
.ext-grid { display: flex; flex-wrap: wrap; gap: 6px; }
.ext-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    font-size: 12px;
    font-weight: 500;
}
.ext-ver { font-family: var(--font-mono); font-size: 11px; color: var(--muted); }
.dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.dot-ok { background: var(--ok); }
.dot-err { background: var(--danger); }

/* Terminal */
.terminal-shell {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 300px;
    background: #0c0e11;
    color: #e4e7ec;
    border: 1px solid #272c36;
    border-radius: var(--radius-sm);
    overflow: hidden;
    font-family: var(--font-mono);
}
.terminal-output {
    flex: 1 1 auto;
    overflow-y: auto;
    padding: 12px;
    font-size: 13px;
    line-height: 1.5;
    user-select: text;
    scrollbar-width: thin;
    scrollbar-color: #3a4150 transparent;
}
.terminal-banner { color: #8a93a2; margin-block-end: 8px; font-size: 12px; }
.terminal-entry { margin-block-end: 8px; }
.terminal-cmd-prompt { color: #2dd4bf; font-weight: 600; margin-inline-end: 8px; }
.terminal-stdout { white-space: pre-wrap; word-break: break-word; }
.terminal-stderr { white-space: pre-wrap; word-break: break-word; color: #fca5a5; }
.terminal-input-bar { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-block-start: 1px solid #272c36; background: #14171c; }
.terminal-prompt-label { color: #2dd4bf; font-size: 13px; font-weight: 600; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.terminal-input { flex: 1 1 auto; min-width: 0; background: transparent; border: 0; color: #f0f2f5; font-size: 13px; outline: none; }

/* Sonner-style Toasts promoted to Browser Top Layer via Popover API (above all <dialog> modals without z-index) */
.toast-stack {
    position: fixed;
    inset-block-start: auto;
    inset-inline: 10px;
    inset-block-end: calc(14px + env(safe-area-inset-bottom, 0px));
    max-width: calc(100vw - 20px);
    margin: 0;
    padding: 0;
    border: 0;
    background: transparent;
    overflow: visible;
    display: none;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
    &.open, &:popover-open { display: flex; }
    @media (min-width: 30rem) { inset-inline-start: auto; inset-inline-end: 18px; max-width: 360px; }
}
.toast {
    pointer-events: auto;
    padding: 10px 12px;
    border-radius: var(--radius);
    background: var(--surface);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-md);
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: toastIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    &.leaving { animation: toastOut 0.16s ease forwards; }
}
.toast-ico { flex-shrink: 0; display: grid; place-items: center; color: var(--ok); }
.toast.error .toast-ico { color: var(--danger); }
.toast-msg { flex: 1; min-width: 0; word-break: break-word; }
.toast-x {
    flex-shrink: 0;
    width: 26px;
    height: 26px;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: var(--muted);
    display: grid;
    place-items: center;
    cursor: pointer;
}
@keyframes toastIn { from { opacity: 0; transform: translateY(8px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
@keyframes toastOut { from { opacity: 1; transform: translateY(0) scale(1); } to { opacity: 0; transform: translateY(6px) scale(0.97); } }

@media (max-width: 47.99rem) {
    .kbd-hint { display: none; }
    .search-input, .terminal-input { font-size: 16px; }
    .account-wrap, .account-menu { display: contents; }
    #btn-account { display: none; }
    dialog.sheet-mobile {
        margin: auto 0 0;
        width: 100%;
        max-width: 100%;
        border-radius: 16px 16px 0 0;
        border-block-end: 0;
        padding-bottom: env(safe-area-inset-bottom, 0px);
    }
}
@media (min-width: 48rem) {
    .app-shell { padding-block-start: clamp(12px, 2vw, 18px); }
    .topbar { margin-block-end: 10px; }
    .brand-sub { display: flex; }
    .menu-toggle, .mobile-meta, .bulk-clear-label { display: none; }
    .toolbar { width: auto; flex-wrap: nowrap; }
    .primary-actions { width: auto; }
    .toolbar-sep, .bulk-sep { display: block; }
    .search-input { width: 200px; }
    .secondary-tools {
        display: flex;
        position: static;
        flex-direction: row;
        align-items: center;
        padding: 0;
        border: 0;
        background: transparent;
        box-shadow: none;
        min-width: 0;
        gap: 6px;
        & > .btn, .account-wrap > .btn {
            width: 36px;
            min-width: 36px;
            padding: 7px;
            justify-content: center;
            border-color: var(--border);
            background: var(--surface);
        }
        & > .btn.btn-active { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
        & .tool-label, & .menu-sep { display: none; }
    }
    .breadcrumbs-bar { min-height: 44px; padding: 7px 12px; }
    .icon-wrap { width: 30px; height: 30px; }
    th, td { padding: 9px 12px; }
    .col-size, .col-mtime, .col-perms { display: table-cell; }
    .col-size { width: 105px; }
    .col-mtime { width: 155px; }
    .col-perms { width: 95px; }
    .perms-btn { padding: 2px 7px; font-size: 12px; }
    .footer-hints { display: inline-flex; }
    .ctx-backdrop { background: transparent; }
    .ctx-menu {
        inset-inline: auto;
        inset-block-end: auto;
        min-width: 200px;
        max-width: 240px;
        padding: 6px;
        border: 1px solid var(--border);
        border-radius: var(--radius);
    }
    .menu-item { min-height: 34px; padding: 6px 10px; }
    .bulk-bar {
        flex-direction: row;
        align-items: center;
        inset-inline: auto;
        inset-inline-start: 50%;
        inset-block-end: 16px;
        transform: translateX(-50%);
        border: 1px solid var(--border-strong);
        border-radius: 12px;
        padding: 7px 10px;
    }
    .bulk-head, .bulk-actions { display: contents; }
    .bulk-clear-btn { min-width: 30px; min-height: 30px; padding: 4px; }
}
@media (hover: hover) {
    .crumb-btn:hover { background: var(--surface); color: var(--text); }
    .sort-btn:hover, .row-menu-btn:hover, .toast-x:hover { color: var(--text); }
    .row-menu-btn:hover, .toast-x:hover { background: var(--surface-2); }
    tbody tr:hover { background: var(--surface-hover); }
    .item-link:hover { color: var(--accent); text-decoration: underline; }
    .perms-btn:hover { border-color: var(--accent); color: var(--accent); }
    .menu-item:hover:not(:disabled) { background: var(--surface-2); }
    .menu-item.danger:hover:not(:disabled) { background: var(--danger-soft); }
    .upload-zone:hover { border-color: var(--accent); background: var(--accent-soft); }
}
</style>
</head>
<body>
<svg class="svg-sprite" aria-hidden="true">
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></symbol>
    <symbol id="i-upload" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></symbol>
    <symbol id="i-more" viewBox="0 0 24 24"><circle cx="5" cy="12" r="2" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="2" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="2" fill="currentColor" stroke="none"/></symbol>
    <symbol id="i-eye" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></symbol>
    <symbol id="i-terminal" viewBox="0 0 24 24"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></symbol>
    <symbol id="i-server" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><line x1="6" y1="7" x2="6.01" y2="7"/><line x1="6" y1="17" x2="6.01" y2="17"/></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></symbol>
    <symbol id="i-lock" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></symbol>
    <symbol id="i-folder" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></symbol>
    <symbol id="i-php" viewBox="0 0 24 24"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/><line x1="14" y1="4" x2="10" y2="20"/></symbol>
    <symbol id="i-script" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M10 13l-2 2 2 2"/><path d="M14 13l2 2-2 2"/></symbol>
    <symbol id="i-style" viewBox="0 0 24 24"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.7-.7 1.7-1.6 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.7 1.7-1.7H16c3.3 0 6-2.7 6-6 0-4.9-4.5-8.5-10-8.5Z"/></symbol>
    <symbol id="i-code" viewBox="0 0 24 24"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></symbol>
    <symbol id="i-config" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c.26.6.85 1 1.51 1H21a2 2 0 0 1 0 4h-.09c-.66 0-1.25.4-1.51 1z"/></symbol>
    <symbol id="i-database" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></symbol>
    <symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></symbol>
    <symbol id="i-archive" viewBox="0 0 24 24"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></symbol>
    <symbol id="i-document" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></symbol>
    <symbol id="i-generic" viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></symbol>
    <symbol id="i-edit" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></symbol>
    <symbol id="i-rename" viewBox="0 0 24 24"><path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></symbol>
    <symbol id="i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></symbol>
    <symbol id="i-move" viewBox="0 0 24 24"><polyline points="5 9 2 12 5 15"/><polyline points="9 5 12 2 15 5"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/></symbol>
    <symbol id="i-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="i-alert" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></symbol>
    <symbol id="i-dots" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.75" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.75" fill="currentColor" stroke="none"/><circle cx="12" cy="19" r="1.75" fill="currentColor" stroke="none"/></symbol>
    <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></symbol>
    <symbol id="i-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></symbol>
</svg>
<noscript><style>#app-shell,.bulk-bar{display:none}</style><div style="max-width:480px;margin:48px auto;padding:20px 24px;background:var(--surface,#fff);border:1px solid var(--border,#d8dee6);border-radius:12px;text-align:center;font-family:system-ui,sans-serif;"><h2 style="margin:0 0 8px;font-size:16px;">JavaScript Required</h2><p style="margin:0;font-size:13px;color:var(--muted,#57606a);">FileDock requires JavaScript to manage files. Please enable JavaScript in your browser and reload the page.</p></div></noscript>
<a href="#main-content" class="skip-link">Skip to content</a>
<div id="app-shell" class="app-shell">
    <header class="topbar">
        <div class="brand">
            __BRAND_BADGE__
            <div>
                <h1 class="brand-title">FileDock</h1>
                <div class="brand-sub">
                    <span>Single-file workspace</span>
                    <span id="zip-status-badge" class="badge badge-warning__ZIP_BADGE_CLASS__">zip ext missing</span>
                </div>
            </div>
        </div>

        <div class="toolbar">
            <div class="primary-actions">
                <div class="search-wrap">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><use href="#i-search"/></svg>
                    <input type="search" id="search-input" class="search-input" placeholder="Filter..." aria-label="Filter files">
                    <span class="kbd-hint" aria-hidden="true">/</span>
                </div>
                <div class="dropdown-wrap">
                    <button type="button" id="btn-new" class="btn" aria-haspopup="menu" aria-expanded="false">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><use href="#i-plus"/></svg>
                        <span>New</span>
                    </button>
                    <div id="new-menu" class="dropdown-menu" role="menu">
                        <button type="button" class="menu-item" role="menuitem" id="btn-new-file">New File</button>
                        <button type="button" class="menu-item" role="menuitem" id="btn-new-folder">New Folder</button>
                    </div>
                </div>
                <button type="button" id="btn-upload" class="btn btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-upload"/></svg>
                    <span>Upload</span>
                </button>
                <button type="button" id="btn-more" class="btn btn-icon menu-toggle" aria-haspopup="menu" aria-expanded="false" aria-label="More tools">
                    <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-more"/></svg>
                </button>
            </div>
            <span class="toolbar-sep" aria-hidden="true"></span>
            <div id="secondary-tools" class="secondary-tools">
                <button type="button" id="btn-hidden" class="btn" title="Toggle hidden files" aria-label="Toggle hidden files" aria-pressed="false">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-eye"/></svg>
                    <span class="tool-label">Hidden Files</span>
                </button>
                <button type="button" id="btn-terminal" class="btn" title="Virtual Console (Ctrl+`)" aria-label="Virtual Console">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-terminal"/></svg>
                    <span class="tool-label">Virtual Console</span>
                </button>
                <button type="button" id="btn-server-info" class="btn" title="Server &amp; PHP Info" aria-label="Server &amp; PHP Info">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-server"/></svg>
                    <span class="tool-label">Server Info</span>
                </button>
                <button type="button" id="btn-theme" class="btn" title="Switch theme" aria-label="Switch theme"></button>
                <div class="account-wrap__AUTH_HIDDEN__">
                    <hr class="menu-sep">
                    <button type="button" id="btn-account" class="btn" title="Account" aria-label="Account" aria-haspopup="menu" aria-expanded="false">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-shield"/></svg>
                    </button>
                    <div id="account-menu" class="dropdown-menu account-menu" role="menu">
                        <button type="button" id="btn-password" class="btn menu-item" role="menuitem">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-lock"/></svg>
                            <span>Change Password</span>
                        </button>
                        <button type="button" id="btn-logout" class="btn menu-item danger menu-item-danger" role="menuitem">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-logout"/></svg>
                            <span>Sign Out</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main id="main-content" tabindex="-1">
    <section id="file-panel" class="panel">
        <div class="breadcrumbs-bar">
            <nav id="breadcrumbs" class="breadcrumbs" aria-label="Breadcrumbs"><button type="button" class="crumb-btn current" data-crumb="" aria-current="page">Root</button></nav>
            <div id="meta-count" class="meta-count" aria-live="polite">Loading...</div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col" class="col-check"><input type="checkbox" id="select-all" aria-label="Select all"></th>
                        <th scope="col" data-sort="name" aria-sort="ascending"><button type="button" class="sort-btn" data-sort="name">Name <span class="sort-ind" id="sort-name" aria-hidden="true"></span></button></th>
                        <th scope="col" class="col-size" data-sort="size" aria-sort="none"><button type="button" class="sort-btn" data-sort="size">Size <span class="sort-ind" id="sort-size" aria-hidden="true"></span></button></th>
                        <th scope="col" class="col-mtime" data-sort="mtime" aria-sort="none"><button type="button" class="sort-btn" data-sort="mtime">Modified <span class="sort-ind" id="sort-mtime" aria-hidden="true"></span></button></th>
                        <th scope="col" class="col-perms" data-sort="perms" aria-sort="none"><button type="button" class="sort-btn" data-sort="perms">Permissions <span class="sort-ind" id="sort-perms" aria-hidden="true"></span></button></th>
                        <th scope="col" class="col-actions"><span class="hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="file-tbody">
                    <tr><td colspan="6"><div class="skel-row"></div></td></tr>
                </tbody>
            </table>
        </div>
    </section>
    </main>

    <footer class="gh-footer">
        __GITHUB_LINK__
        <div class="footer-hints" aria-hidden="true">
            <span><kbd>/</kbd> Filter</span>
            <span><kbd>Ctrl+`</kbd> Console</span>
        </div>
    </footer>
</div>

<div id="bulk-bar" class="bulk-bar" role="region" aria-label="Bulk actions" aria-hidden="true" inert>
    <div class="bulk-head">
        <span id="bulk-count" class="bulk-count">0 selected</span>
        <span class="bulk-sep" aria-hidden="true"></span>
        <button type="button" id="bulk-clear" class="btn btn-sm bulk-clear-btn" aria-label="Clear selection" title="Clear selection">
            <span class="bulk-clear-label">Clear</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><use href="#i-x"/></svg>
        </button>
    </div>
    <div class="bulk-actions">
        <button type="button" id="bulk-move" class="btn btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-move"/></svg>
            <span>Move</span>
        </button>
        <button type="button" id="bulk-copy" class="btn btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-copy"/></svg>
            <span>Copy</span>
        </button>
        <button type="button" id="bulk-zip" class="btn btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-archive"/></svg>
            <span>Zip</span>
        </button>
        <button type="button" id="bulk-delete" class="btn btn-sm btn-soft-danger">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-trash"/></svg>
            <span>Delete</span>
        </button>
    </div>
</div>

<div id="ctx-backdrop" class="ctx-backdrop"></div>
<div id="ctx-menu" class="ctx-menu" role="menu" aria-label="Item actions"></div>

<div id="drop-overlay" class="drop-overlay"><div class="drop-overlay-card">Drop files to upload to current folder</div></div>

<dialog id="modal-prompt" class="sheet-mobile" aria-labelledby="prompt-title">
    <div class="modal-header">
        <h2 id="prompt-title" class="modal-title">Action</h2>
        <button type="button" class="btn btn-icon" data-close="modal-prompt" aria-label="Close"></button>
    </div>
    <form id="prompt-form">
        <div class="modal-body">
            <div class="field">
                <label id="prompt-label" for="prompt-input">Name</label>
                <input type="text" id="prompt-input" class="input" required>
            </div>
            <p id="prompt-hint" class="hint-text"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" data-close="modal-prompt">Cancel</button>
            <button type="submit" id="prompt-submit" class="btn btn-primary">Confirm</button>
        </div>
    </form>
</dialog>

<dialog id="modal-password" class="sheet-mobile" aria-labelledby="password-title">
    <div class="modal-header">
        <h2 id="password-title" class="modal-title">Change Password</h2>
        <button type="button" id="password-close-x" class="btn btn-icon" data-close="modal-password" aria-label="Close"></button>
    </div>
    <form id="password-form">
        <div class="modal-body">
            <p id="password-notice" class="hint-text mb-12 hidden"></p>
            <div id="password-error" class="alert-error hidden" role="alert"></div>
            <div id="password-current-wrap" class="field">
                <label for="password-current">Current Password</label>
                <input type="password" id="password-current" class="input" autocomplete="current-password">
            </div>
            <div class="field">
                <label for="password-new">New Password</label>
                <input type="password" id="password-new" class="input" autocomplete="new-password" minlength="8" required>
            </div>
            <div class="field">
                <label for="password-confirm">Confirm New Password</label>
                <input type="password" id="password-confirm" class="input" autocomplete="new-password" minlength="8" required>
            </div>
            <p class="hint-text">Minimum 8 characters.</p>
        </div>
        <div class="modal-footer">
            <button type="button" id="password-cancel-btn" class="btn" data-close="modal-password">Cancel</button>
            <button type="button" id="password-logout-btn" class="btn hidden">Logout</button>
            <button type="submit" id="password-submit-btn" class="btn btn-primary">Save Password</button>
        </div>
    </form>
</dialog>

<dialog id="modal-delete" class="sheet-mobile" aria-labelledby="delete-title">
    <div class="modal-header">
        <h2 id="delete-title" class="modal-title">Confirm Deletion</h2>
        <button type="button" class="btn btn-icon" data-close="modal-delete" aria-label="Close"></button>
    </div>
    <div class="modal-body"><p id="delete-message" class="m-0"></p></div>
    <div class="modal-footer">
        <button type="button" class="btn" data-close="modal-delete">Cancel</button>
        <button type="button" id="delete-confirm" class="btn btn-danger">Delete Permanently</button>
    </div>
</dialog>

<dialog id="modal-chmod" class="sheet-mobile" aria-labelledby="chmod-title">
    <div class="modal-header">
        <h2 id="chmod-title" class="modal-title">Change Permissions</h2>
        <button type="button" class="btn btn-icon" data-close="modal-chmod" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <p id="chmod-target-name" class="m-0 mb-12 fw-600"></p>
        <div class="chmod-grid">
            <fieldset class="chmod-col"><legend>Owner</legend><label class="chmod-check"><input type="checkbox" data-bit="256"> Read (4)</label><label class="chmod-check"><input type="checkbox" data-bit="128"> Write (2)</label><label class="chmod-check"><input type="checkbox" data-bit="64"> Exec (1)</label></fieldset>
            <fieldset class="chmod-col"><legend>Group</legend><label class="chmod-check"><input type="checkbox" data-bit="32"> Read (4)</label><label class="chmod-check"><input type="checkbox" data-bit="16"> Write (2)</label><label class="chmod-check"><input type="checkbox" data-bit="8"> Exec (1)</label></fieldset>
            <fieldset class="chmod-col"><legend>Other</legend><label class="chmod-check"><input type="checkbox" data-bit="4"> Read (4)</label><label class="chmod-check"><input type="checkbox" data-bit="2"> Write (2)</label><label class="chmod-check"><input type="checkbox" data-bit="1"> Exec (1)</label></fieldset>
        </div>
        <div class="chmod-preview">
            <label for="chmod-octal-input">Octal Mode</label>
            <input type="text" id="chmod-octal-input" class="input mono chmod-octal" maxlength="4">
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn" data-close="modal-chmod">Cancel</button>
        <button type="button" id="chmod-save" class="btn btn-primary">Apply Permissions</button>
    </div>
</dialog>

<dialog id="modal-upload" class="sheet-mobile" aria-labelledby="upload-title">
    <div class="modal-header">
        <h2 id="upload-title" class="modal-title">Upload Files</h2>
        <button type="button" class="btn btn-icon" data-close="modal-upload" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <button type="button" id="upload-zone" class="upload-zone">
            <span class="upload-icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><use href="#i-upload"/></svg>
            </span>
            <span class="fw-600">Click to choose files or drag &amp; drop</span>
            <span class="hint-text">Uploading to: <strong id="upload-dest-label">Root</strong></span>
        </button>
        <input type="file" id="upload-file-input" multiple class="hidden">
        <label class="chmod-check mt-12"><input type="checkbox" id="upload-overwrite"> Overwrite existing files</label>
        <div id="upload-queue-info" class="hint-text mt-8"></div>
        <div id="upload-progress-wrap" class="progress-wrap">
            <div class="progress-track"><div id="upload-progress-fill" class="progress-fill"></div></div>
            <div class="progress-label"><span id="upload-progress-status">Uploading...</span><span id="upload-progress-pct">0%</span></div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn" data-close="modal-upload">Close</button>
        <button type="button" id="upload-start-btn" class="btn btn-primary" disabled>Start Upload</button>
    </div>
</dialog>

<dialog id="modal-editor" class="dialog-xl" aria-labelledby="editor-title">
    <div class="modal-header">
        <h2 id="editor-title" class="modal-title">Edit File</h2>
        <button type="button" class="btn btn-icon" data-close="modal-editor" aria-label="Close"></button>
    </div>
    <div class="modal-body editor-body">
        <div class="editor-container">
            <div id="editor-gutter" class="editor-gutter" aria-hidden="true">1</div>
            <textarea id="editor-textarea" class="editor-textarea" spellcheck="false" aria-label="File content editor"></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <div id="editor-meta" class="editor-meta"></div>
        <button type="button" class="btn" data-close="modal-editor">Close</button>
        <button type="button" id="editor-save-exit-btn" class="btn">Save &amp; Exit</button>
        <button type="button" id="editor-save-btn" class="btn btn-primary" title="Save (Ctrl+S)" aria-keyshortcuts="Control+S Meta+S">Save</button>
    </div>
</dialog>

<dialog id="modal-preview" class="dialog-lg" aria-labelledby="preview-title">
    <div class="modal-header">
        <h2 id="preview-title" class="modal-title">Image Preview</h2>
        <button type="button" class="btn btn-icon" data-close="modal-preview" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <div class="preview-stage"><img id="preview-img" src="" alt="Preview"></div>
        <div id="preview-meta" class="hint-text tabular mt-8"></div>
    </div>
    <div class="modal-footer">
        <a id="preview-download" class="btn" href="#" download>Download</a>
        <button type="button" class="btn btn-primary" data-close="modal-preview">Close</button>
    </div>
</dialog>

<dialog id="drawer-server" class="dialog-drawer" aria-labelledby="drawer-server-title">
    <div class="modal-header">
        <h2 id="drawer-server-title" class="modal-title">Server &amp; PHP Environment</h2>
        <button type="button" class="btn btn-icon" data-close="drawer-server" aria-label="Close"></button>
    </div>
    <div id="server-info-body" class="modal-body"><p class="muted m-0">Loading diagnostics...</p></div>
</dialog>

<dialog id="modal-terminal" class="dialog-xl" aria-labelledby="terminal-title">
    <div class="modal-header">
        <div class="header-row">
            <h2 id="terminal-title" class="modal-title">Virtual Console</h2>
            <span id="terminal-cwd-badge" class="badge mono">/</span>
        </div>
        <div class="header-row">
            <button type="button" id="terminal-clear-btn" class="btn btn-sm">Clear</button>
            <button type="button" class="btn btn-icon" data-close="modal-terminal" aria-label="Close"></button>
        </div>
    </div>
    <div class="modal-body dlg-pad">
        <div class="terminal-shell">
            <div id="terminal-output" class="terminal-output" aria-live="polite"><div class="terminal-banner">FileDock Pure-PHP Virtual Console (jailed to ROOT_DIR). Type &quot;help&quot; for commands.</div></div>
            <form id="terminal-form" class="terminal-input-bar">
                <span id="terminal-prompt" class="terminal-prompt-label">/$</span>
                <input type="text" id="terminal-input" class="terminal-input" placeholder="Enter command (ls -la, pwd, help)..." autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" aria-label="Virtual console command">
                <button type="submit" id="terminal-run-btn" class="btn btn-primary btn-sm">Run</button>
            </form>
        </div>
    </div>
</dialog>

<div id="toast-stack" class="toast-stack" popover="manual" aria-live="polite"></div>

<script>
(() => {
    'use strict';
    const BOOTSTRAP = __BOOTSTRAP_JSON__;
    const el = (id) => document.getElementById(id);

    const ICON_MAP = { markup: 'code', preview: 'eye', chmod: 'lock', unzip: 'upload' };
    const ICON_SET = new Set(['folder', 'php', 'script', 'style', 'config', 'database', 'code', 'image', 'archive', 'document', 'generic', 'edit', 'eye', 'rename', 'copy', 'move', 'upload', 'download', 'lock', 'trash', 'check', 'alert', 'x', 'dots', 'sun', 'moon']);
    const icon = (name, size = 15) => {
        const id = ICON_MAP[name] || (ICON_SET.has(name) ? name : 'generic');
        return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-${id}"/></svg>`;
    };
    const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
    async function withLoadingBtn(btn, loadingText, fn, onError = (err) => toast(err.message, 'error')) {
        // Prevent re-entrancy (e.g. rapid Ctrl+S) from overwriting the original label
        if (btn.disabled) return;
        const prev = btn.textContent;
        btn.disabled = true;
        btn.textContent = loadingText;
        try {
            await fn();
        } catch (err) {
            onError(err);
        } finally {
            btn.disabled = false;
            btn.textContent = prev;
        }
    }

    const state = {
        currentPath: '',
        items: [],
        breadcrumbs: [],
        showHidden: false,
        sortKey: 'name',
        sortDir: 'asc',
        searchQuery: '',
        selected: new Set(),
        zipSupported: Boolean(BOOTSTRAP.zipSupported),
        pendingUploadFiles: [],
        editorPath: '',
        editorDirty: false,
        chmodPath: '',
        deleteTargets: [],
        promptCallback: null,
        ctxItem: null,
        theme: localStorage.getItem('fm_theme') || 'auto',
        terminalCwd: '',
        terminalHistory: [],
        terminalHistoryIdx: -1,
        terminalDraft: '',
    };

    const escapeHtml = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    const isDarkEffective = () => state.theme === 'dark' || (state.theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    function applyTheme(theme) {
        state.theme = theme;
        if (theme === 'auto') {
            localStorage.removeItem('fm_theme');
            document.documentElement.removeAttribute('data-theme');
        } else {
            localStorage.setItem('fm_theme', theme);
            document.documentElement.setAttribute('data-theme', theme);
        }
        const btn = el('btn-theme');
        const darkNow = isDarkEffective();
        const label = darkNow ? 'Light Mode' : 'Dark Mode';
        btn.innerHTML = `${icon(darkNow ? 'sun' : 'moon', 15)}<span class="tool-label">${label}</span>`;
        btn.title = `Switch to ${label}`;
        btn.setAttribute('aria-label', `Switch to ${label}`);
        const lightColor = '#f6f7f9';
        const darkColor = '#0c0e11';
        document.querySelectorAll('meta[name="theme-color"]').forEach((m) => {
            if (theme === 'auto') {
                m.setAttribute('content', m.media.includes('dark') ? darkColor : lightColor);
            } else {
                m.setAttribute('content', darkNow ? darkColor : lightColor);
            }
        });
    }

    function dismissToast(node) {
        if (!node || !node.isConnected || node.classList.contains('leaving')) return;
        const removeNode = () => {
            if (!node.isConnected) return;
            node.remove();
            const stack = el('toast-stack');
            if (stack && stack.children.length === 0) {
                stack.classList.remove('open');
                if (typeof stack.hidePopover === 'function' && stack.matches(':popover-open')) {
                    try { stack.hidePopover(); } catch (_) {}
                }
            }
        };
        node.classList.add('leaving');
        node.addEventListener('animationend', removeNode, { once: true });
        setTimeout(removeNode, 220);
    }

    function toast(message, type = 'success') {
        const stack = el('toast-stack');
        const node = document.createElement('div');
        node.className = 'toast ' + type;
        node.setAttribute('role', type === 'error' ? 'alert' : 'status');
        node.innerHTML = `<span class="toast-ico">${icon(type === 'error' ? 'alert' : 'check', 16)}</span><span class="toast-msg">${escapeHtml(message)}</span><button type="button" class="toast-x" aria-label="Dismiss notification">${icon('x', 14)}</button>`;
        node.querySelector('button').addEventListener('click', () => dismissToast(node));
        stack.appendChild(node);
        // Re-promote toast stack to the top of Browser Top Layer above any open <dialog>, with class fallback
        if (typeof stack.showPopover === 'function') {
            try {
                if (stack.matches(':popover-open')) stack.hidePopover();
                stack.showPopover();
            } catch (_) { stack.classList.add('open'); }
        } else {
            stack.classList.add('open');
        }
        if (type !== 'error') setTimeout(() => dismissToast(node), 3600);
    }

    async function api(action, body = {}) {
        const res = await fetch(BOOTSTRAP.selfUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action, csrf_token: BOOTSTRAP.csrfToken }, body)),
        });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error((json && json.error) ? json.error : 'Request failed');
        return json.data;
    }

    // Native <dialog> helpers with smooth close transition
    function openDialog(id, focusEl = null, onFocus = null) {
        const dlg = el(id);
        dlg.classList.remove('closing');
        if (!dlg.open) dlg.showModal();
        requestAnimationFrame(() => {
            if (focusEl) focusEl.focus();
            if (typeof onFocus === 'function') onFocus();
        });
    }

    function closeDialog(id) {
        if (id === 'modal-password' && BOOTSTRAP.mustChangePassword) return false;
        if (id === 'modal-editor' && state.editorDirty) {
            if (!window.confirm('You have unsaved changes. Discard them and close the editor?')) return false;
            state.editorDirty = false;
        }
        const dlg = el(id);
        if (!dlg || !dlg.open) return true;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            dlg.close();
            return true;
        }
        dlg.classList.add('closing');
        const finish = () => {
            dlg.classList.remove('closing');
            if (dlg.open) dlg.close();
        };
        dlg.addEventListener('transitionend', finish, { once: true });
        setTimeout(finish, 200);
        return true;
    }

    function applyListingData(data) {
        state.currentPath = data.currentPath;
        state.breadcrumbs = data.breadcrumbs;
        state.items = data.items;
        state.zipSupported = Boolean(data.zipSupported);
        state.selected.clear();
        const targetHash = state.currentPath ? '#' + encodeURIComponent(state.currentPath) : '';
        if (window.location.hash !== targetHash) {
            history.replaceState(null, '', targetHash || window.location.pathname + window.location.search);
        }
        renderAll();
    }

    // Directory listing & rendering
    async function loadDirectory(path = state.currentPath) {
        const panel = el('file-panel');
        panel.setAttribute('aria-busy', 'true');
        el('meta-count').textContent = 'Loading...';
        try {
            const data = await api('list', { path, showHidden: state.showHidden });
            applyListingData(data);
        } catch (err) {
            el('meta-count').textContent = 'Failed to load';
            if (state.items.length === 0) {
                el('file-tbody').innerHTML = `<tr><td colspan="6" class="empty-state text-danger">Failed to load directory: ${escapeHtml(err.message)} <button type="button" class="btn btn-sm" id="retry-load-btn">Retry</button></td></tr>`;
                const r = el('retry-load-btn');
                if (r) r.addEventListener('click', () => loadDirectory(path));
            }
            toast(err.message, 'error');
        } finally {
            panel.removeAttribute('aria-busy');
        }
    }

    function getFilteredSortedItems() {
        const q = state.searchQuery.trim().toLowerCase();
        const list = state.items.filter((i) => !q || i.name.toLowerCase().includes(q));
        list.sort((a, b) => {
            if (a.type !== b.type) return a.type === 'dir' ? -1 : 1;
            let cmp = 0;
            if (state.sortKey === 'name') cmp = a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });
            else if (state.sortKey === 'size') cmp = a.size - b.size;
            else if (state.sortKey === 'mtime') cmp = a.mtime - b.mtime;
            else if (state.sortKey === 'perms') cmp = a.permsOctal.localeCompare(b.permsOctal);
            return state.sortDir === 'asc' ? cmp : -cmp;
        });
        return list;
    }

    function renderAll() {
        el('breadcrumbs').innerHTML = state.breadcrumbs.map((c, idx) => {
            const cur = idx === state.breadcrumbs.length - 1;
            const sep = idx > 0 ? '<span class="crumb-sep" aria-hidden="true">/</span>' : '';
            return `${sep}<button type="button" class="crumb-btn${cur ? ' current' : ''}" data-crumb="${escapeHtml(c.path)}"${cur ? ' aria-current="page"' : ''}>${escapeHtml(c.name)}</button>`;
        }).join('');

        ['name', 'size', 'mtime', 'perms'].forEach((k) => {
            const ind = el('sort-' + k);
            const active = state.sortKey === k;
            ind.textContent = active ? (state.sortDir === 'asc' ? '▲' : '▼') : '';
            ind.closest('th').setAttribute('aria-sort', active ? (state.sortDir === 'asc' ? 'ascending' : 'descending') : 'none');
        });

        renderTable();
        updateBulkBar();
    }

    function renderTable() {
        const tbody = el('file-tbody');
        const visible = getFilteredSortedItems();
        el('meta-count').textContent = plural(visible.length, 'item');
        el('select-all').checked = visible.length > 0 && visible.every((i) => state.selected.has(i.path));

        if (visible.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="empty-state">${state.items.length === 0 ? 'This folder is empty.' : 'No files or folders match your filter.'}</td></tr>`;
            return;
        }

        tbody.innerHTML = visible.map((item) => {
            const isSel = state.selected.has(item.path);
            const p = escapeHtml(item.path);
            const n = escapeHtml(item.name);
            const sizeMeta = item.type === 'dir' ? '' : `<span class="mobile-meta-item">${escapeHtml(item.sizeFormatted)}</span>`;
            return `<tr data-path="${p}" class="${isSel ? 'selected' : ''}">
                <td class="col-check"><input type="checkbox" class="row-check" data-path="${p}" ${isSel ? 'checked' : ''} aria-label="Select ${n}"></td>
                <td>
                    <div class="name-cell">
                        <span class="icon-wrap icon-${escapeHtml(item.category)}" aria-hidden="true">${icon(item.category, 16)}</span>
                        <div class="name-main">
                            <button type="button" class="item-link" data-open="${p}"><bdi>${n}</bdi></button>
                            <div class="mobile-meta">
                                ${sizeMeta}
                                <span class="mobile-meta-item"><button type="button" class="perms-btn" data-chmod="${p}" aria-label="Permissions ${escapeHtml(item.permsOctal)}">${escapeHtml(item.permsOctal)}</button></span>
                                <span class="mobile-meta-item">${escapeHtml(item.mtimeFormatted)}</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td class="col-size">${escapeHtml(item.sizeFormatted)}</td>
                <td class="col-mtime">${escapeHtml(item.mtimeFormatted)}</td>
                <td class="col-perms"><button type="button" class="perms-btn" data-chmod="${p}" title="${escapeHtml(item.permsString)}" aria-label="Change permissions for ${n}, currently ${escapeHtml(item.permsOctal)}">${escapeHtml(item.permsOctal)}</button></td>
                <td class="col-actions"><button type="button" class="row-menu-btn" data-row-menu="${p}" aria-haspopup="menu" aria-label="Actions for ${n}">${icon('dots', 16)}</button></td>
            </tr>`;
        }).join('');
    }

    function updateBulkBar() {
        const bar = el('bulk-bar');
        const count = state.selected.size;
        el('bulk-count').textContent = count + ' selected';
        bar.classList.toggle('visible', count > 0);
        bar.inert = count === 0;
        bar.setAttribute('aria-hidden', count === 0 ? 'true' : 'false');
        el('bulk-zip').disabled = !state.zipSupported;
    }

    // Shared Context Menu (Desktop right-click + ⋮ button + Mobile Bottom Sheet)
    function closeContextMenu() {
        el('ctx-menu').classList.remove('open');
        el('ctx-backdrop').classList.remove('open');
        document.querySelectorAll('tr.row-active').forEach((r) => r.classList.remove('row-active'));
        state.ctxItem = null;
    }

    function openContextMenu(item, x, y, anchorEl = null) {
        closeMenus();
        state.ctxItem = item;
        const menu = el('ctx-menu');
        const rows = [];
        if (item.type === 'dir') rows.push(`<button type="button" class="menu-item" role="menuitem" data-ctx="open">${icon('folder')} Open</button>`);
        if (item.isEditable) rows.push(`<button type="button" class="menu-item" role="menuitem" data-ctx="edit">${icon('edit')} Edit</button>`);
        if (item.isImage) rows.push(`<button type="button" class="menu-item" role="menuitem" data-ctx="preview">${icon('preview')} Preview</button>`);
        rows.push(`<button type="button" class="menu-item" role="menuitem" data-ctx="rename">${icon('rename')} Rename</button>`);
        rows.push('<hr class="menu-sep">');
        rows.push(`<button type="button" class="menu-item" role="menuitem" data-ctx="copy">${icon('copy')} Copy</button>`);
        rows.push(`<button type="button" class="menu-item" role="menuitem" data-ctx="move">${icon('move')} Move</button>`);
        if (state.zipSupported) rows.push(`<button type="button" class="menu-item" role="menuitem" data-ctx="compress">${icon('archive')} Compress</button>`);
        if (item.isZip && state.zipSupported) rows.push(`<button type="button" class="menu-item" role="menuitem" data-ctx="unzip">${icon('unzip')} Extract</button>`);
        if (item.type === 'file') rows.push(`<a class="menu-item" role="menuitem" href="${escapeHtml(BOOTSTRAP.selfUrl)}?action=download&amp;path=${encodeURIComponent(item.path)}" data-ctx="download">${icon('download')} Download</a>`);
        rows.push('<hr class="menu-sep">');
        rows.push(`<button type="button" class="menu-item" role="menuitem" data-ctx="chmod">${icon('chmod')} Permissions</button>`);
        rows.push(`<button type="button" class="menu-item danger" role="menuitem" data-ctx="delete">${icon('trash')} Delete</button>`);

        menu.innerHTML = rows.join('');
        menu.style.left = '';
        menu.style.top = '';
        el('ctx-backdrop').classList.add('open');
        menu.classList.add('open');

        const activeRow = document.querySelector(`tr[data-path="${CSS.escape(item.path)}"]`);
        if (activeRow) activeRow.classList.add('row-active');

        if (window.matchMedia('(min-width: 48rem)').matches) {
            const rect = menu.getBoundingClientRect();
            const pad = 8;
            let left = anchorEl ? anchorEl.getBoundingClientRect().right - rect.width : x;
            let top = anchorEl ? anchorEl.getBoundingClientRect().bottom + 4 : y;
            if (left + rect.width > window.innerWidth - pad) left = window.innerWidth - rect.width - pad;
            if (left < pad) left = pad;
            if (top + rect.height > window.innerHeight - pad) top = Math.max(pad, (anchorEl ? anchorEl.getBoundingClientRect().top - rect.height - 4 : y - rect.height));
            menu.style.left = left + 'px';
            menu.style.top = top + 'px';
        }
        const firstItem = menu.querySelector('.menu-item');
        if (firstItem) firstItem.focus();
    }

    function closeMenus() {
        el('new-menu').classList.remove('open');
        el('btn-new').setAttribute('aria-expanded', 'false');
        el('secondary-tools').classList.remove('open');
        el('btn-more').setAttribute('aria-expanded', 'false');
        el('account-menu').classList.remove('open');
        el('btn-account').setAttribute('aria-expanded', 'false');
    }

    // Prompts, Delete, Chmod, Password
    function showPrompt({ title, label, value = '', hint = '', submitText = 'Confirm', required = true, onConfirm }) {
        el('prompt-title').textContent = title;
        el('prompt-label').textContent = label;
        el('prompt-input').value = value;
        el('prompt-input').required = required;
        el('prompt-hint').textContent = hint;
        el('prompt-submit').textContent = submitText;
        state.promptCallback = onConfirm;
        openDialog('modal-prompt', el('prompt-input'), () => el('prompt-input').select());
    }

    function promptTransfer(mode, paths) {
        const isMove = mode === 'move';
        showPrompt({
            title: `${isMove ? 'Move' : 'Copy'} ${plural(paths.length, 'item')}`,
            label: 'Destination relative path (empty for Root)',
            value: state.currentPath,
            hint: 'Example: assets/images (leave blank for Root)',
            submitText: isMove ? 'Move Here' : 'Copy Here',
            required: false,
            onConfirm: async (dest) => {
                const res = await api(mode, { paths, destination: dest === '/' ? '' : dest });
                toast(`${isMove ? 'Moved' : 'Copied'} ${plural(isMove ? res.moved : res.copied, 'item')}`);
                loadDirectory(state.currentPath);
            },
        });
    }

    function promptZip(paths) {
        showPrompt({
            title: 'Create Zip Archive',
            label: 'Archive filename',
            value: paths.length === 1 ? paths[0].split('/').pop() + '.zip' : 'archive.zip',
            submitText: 'Create Zip',
            onConfirm: async (name) => {
                const res = await api('zip', { paths, destination: state.currentPath, name });
                toast(`Created ${res.name}`);
                loadDirectory(state.currentPath);
            },
        });
    }

    function openDeleteModal(paths) {
        state.deleteTargets = paths;
        el('delete-message').textContent = paths.length === 1
            ? `Are you sure you want to permanently delete "${paths[0]}"?`
            : `Are you sure you want to permanently delete ${plural(paths.length, 'selected item')}?`;
        openDialog('modal-delete');
    }

    function syncChmodCheckboxes(modeInt) {
        document.querySelectorAll('#modal-chmod input[data-bit]').forEach((cb) => {
            const bit = parseInt(cb.dataset.bit, 10);
            cb.checked = (modeInt & bit) === bit;
        });
    }

    function openChmodModal(item) {
        state.chmodPath = item.path;
        el('chmod-target-name').textContent = `${item.name} (${item.permsString})`;
        syncChmodCheckboxes(parseInt(item.permsOctal, 8) || 0);
        el('chmod-octal-input').value = item.permsOctal;
        openDialog('modal-chmod');
    }

    function setPasswordError(msg) {
        const box = el('password-error');
        box.textContent = msg || '';
        box.classList.toggle('hidden', !msg);
    }

    function openPasswordModal(forced = false) {
        setPasswordError('');
        ['password-current', 'password-new', 'password-confirm'].forEach((id) => {
            el(id).value = '';
            el(id).removeAttribute('aria-invalid');
        });
        el('password-title').textContent = forced ? 'Set New Password' : 'Change Password';
        el('password-notice').textContent = forced ? 'You signed in with the default password. Set a new password (at least 8 characters) to continue.' : '';
        el('password-notice').classList.toggle('hidden', !forced);
        el('password-current-wrap').classList.toggle('hidden', forced);
        el('password-current').required = !forced;
        el('password-close-x').classList.toggle('hidden', forced);
        el('password-cancel-btn').classList.toggle('hidden', forced);
        el('password-logout-btn').classList.toggle('hidden', !forced);
        el('password-submit-btn').textContent = forced ? 'Set New Password' : 'Save Password';
        openDialog('modal-password', el(forced ? 'password-new' : 'password-current'));
    }

    // Editor & Preview
    let gutterRaf = 0;
    function updateEditorGutter() {
        const text = el('editor-textarea').value;
        let lines = 1;
        for (let i = 0; i < text.length; i++) if (text.charCodeAt(i) === 10) lines++;
        el('editor-gutter').textContent = Array.from({ length: lines }, (_, i) => i + 1).join('\n');
    }

    async function openEditor(path) {
        try {
            const data = await api('read', { path });
            state.editorPath = data.path;
            state.editorDirty = false;
            el('editor-title').textContent = data.name;
            const ta = el('editor-textarea');
            ta.value = data.content;
            ta.readOnly = !data.writable;
            ta.setSelectionRange(0, 0);
            ta.scrollTop = 0;
            el('editor-gutter').scrollTop = 0;
            el('editor-save-btn').disabled = !data.writable;
            el('editor-save-exit-btn').disabled = !data.writable;
            el('editor-meta').textContent = `${data.sizeFormatted} • ${data.encoding}` + (data.writable ? '' : ' • Read-only');
            updateEditorGutter();
            openDialog('modal-editor', ta, () => {
                ta.setSelectionRange(0, 0);
                ta.scrollTop = 0;
                el('editor-gutter').scrollTop = 0;
            });
        } catch (err) { toast(err.message, 'error'); }
    }

    async function saveEditor(closeAfter = false) {
        if (!state.editorPath || el('editor-textarea').readOnly) return;
        const saveBtn = el('editor-save-btn');
        const exitBtn = el('editor-save-exit-btn');
        // Prevent concurrent saves across Save and Save & Exit
        if (saveBtn.disabled || exitBtn.disabled) return;
        const activeBtn = closeAfter ? exitBtn : saveBtn;
        const otherBtn = closeAfter ? saveBtn : exitBtn;
        otherBtn.disabled = true;
        try {
            await withLoadingBtn(activeBtn, 'Saving...', async () => {
                const data = await api('save', { path: state.editorPath, content: el('editor-textarea').value });
                state.editorDirty = false;
                const fileName = state.editorPath.split('/').pop();
                toast(`Saved ${fileName} · ${data.sizeFormatted}`);
                loadDirectory(state.currentPath);
                if (closeAfter) closeDialog('modal-editor');
            });
        } finally {
            otherBtn.disabled = el('editor-textarea').readOnly;
        }
    }

    function openPreview(item) {
        const url = `${BOOTSTRAP.selfUrl}?action=preview&path=${encodeURIComponent(item.path)}&t=${Date.now()}`;
        el('preview-title').textContent = item.name;
        el('preview-download').href = `${BOOTSTRAP.selfUrl}?action=download&path=${encodeURIComponent(item.path)}`;
        el('preview-meta').textContent = `Size: ${item.sizeFormatted} • Loading dimensions...`;
        const img = el('preview-img');
        img.alt = item.name;
        img.src = '';
        img.onload = () => { el('preview-meta').textContent = `Dimensions: ${img.naturalWidth} × ${img.naturalHeight} px • Size: ${item.sizeFormatted}`; };
        img.onerror = () => { el('preview-meta').textContent = `Failed to load image preview • Size: ${item.sizeFormatted}`; };
        img.src = url;
        openDialog('modal-preview');
    }

    // Upload
    function setUploadQueue(files) {
        state.pendingUploadFiles = files;
        el('upload-start-btn').disabled = files.length === 0;
        const total = files.reduce((a, f) => a + f.size, 0);
        el('upload-queue-info').textContent = files.length ? `${plural(files.length, 'file')} ready (${(total / 1024).toFixed(1)} KB)` : '';
    }

    function openUploadModal(preselected = null) {
        el('upload-dest-label').textContent = state.currentPath || 'Root';
        el('upload-progress-wrap').classList.remove('active');
        el('upload-progress-fill').style.width = '0%';
        setUploadQueue(preselected && preselected.length ? Array.from(preselected) : []);
        openDialog('modal-upload');
    }

    function startUpload() {
        if (!state.pendingUploadFiles.length) return;
        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('csrf_token', BOOTSTRAP.csrfToken);
        fd.append('path', state.currentPath);
        fd.append('overwrite', el('upload-overwrite').checked ? '1' : '0');
        state.pendingUploadFiles.forEach((f) => fd.append('files[]', f));

        const xhr = new XMLHttpRequest();
        const btn = el('upload-start-btn');
        el('upload-progress-wrap').classList.add('active');
        btn.disabled = true;
        btn.textContent = 'Uploading...';

        xhr.upload.onprogress = (e) => {
            if (!e.lengthComputable) return;
            const pct = Math.round((e.loaded / e.total) * 100);
            el('upload-progress-fill').style.width = pct + '%';
            el('upload-progress-pct').textContent = pct + '%';
            el('upload-progress-status').textContent = `Uploaded ${(e.loaded / 1024).toFixed(0)} / ${(e.total / 1024).toFixed(0)} KB`;
        };
        xhr.onload = () => {
            btn.textContent = 'Start Upload';
            try {
                const res = JSON.parse(xhr.responseText);
                if (!res.ok) { toast(res.error || 'Upload failed', 'error'); btn.disabled = !state.pendingUploadFiles.length; return; }
                if (res.data.uploaded && res.data.uploaded.length) toast(`Uploaded ${plural(res.data.uploaded.length, 'file')}`);
                (res.data.errors || []).forEach((m) => toast(m, 'error'));
                setUploadQueue([]);
                closeDialog('modal-upload');
                loadDirectory(state.currentPath);
            } catch (_) { toast('Invalid server response during upload.', 'error'); btn.disabled = !state.pendingUploadFiles.length; }
        };
        xhr.onerror = () => { toast('Network error during upload.', 'error'); btn.textContent = 'Start Upload'; btn.disabled = !state.pendingUploadFiles.length; };
        xhr.open('POST', BOOTSTRAP.selfUrl, true);
        xhr.send(fd);
    }

    // Server Info Drawer
    async function openServerInfo() {
        openDialog('drawer-server');
        const body = el('server-info-body');
        body.innerHTML = '<p class="muted m-0">Loading diagnostics...</p>';
        try {
            const info = await api('server_info');
            const loadedCount = info.extensions.filter((e) => e.loaded).length;
            const extsHtml = info.extensions.map((e) => {
                const ver = e.version ? `<span class="ext-ver">${escapeHtml(e.version)}</span>` : '';
                return `<span class="ext-pill"><span class="dot ${e.loaded ? 'dot-ok' : 'dot-err'}" aria-hidden="true"></span><span>${escapeHtml(e.name)}</span>${ver}</span>`;
            }).join('');
            const kv = (rows, extra = '') => `<div class="kv-list">${rows.map(([k, v]) => `<div class="kv-row"><span class="kv-key">${escapeHtml(k)}</span><span class="kv-val">${escapeHtml(v)}</span></div>`).join('')}${extra}</div>`;
            const pct = Math.min(100, Math.max(0, Number(info.disk.usedPercent) || 0));
            const fillCls = pct >= 90 ? ' crit' : (pct >= 80 ? ' warn' : '');
            const diskBar = `<div class="disk-bar" aria-label="Disk usage ${pct}%"><div class="disk-fill${fillCls}" style="width:${pct}%"></div></div>`;
            const wpCliStatus = !info.wpCliEnabled ? 'Disabled in config' : (info.procOpenAvailable ? 'Available' : 'Disabled');
            body.innerHTML = `
                <div class="drawer-section"><h3>Runtime Environment</h3>${kv([['PHP Version', info.phpVersion], ['SAPI', info.sapi], ['Operating System', info.os], ['Server Software', info.serverSoftware], ['Root Directory', info.rootDir || '—'], ['Server Time', info.serverTime || '—'], ['HTTPS', info.https ? 'Active' : 'Inactive'], ['proc_open / WP-CLI', wpCliStatus]])}</div>
                <div class="drawer-section"><h3>php.ini Limits</h3>${kv([['upload_max_filesize', info.limits.upload_max_filesize], ['post_max_size', info.limits.post_max_size], ['memory_limit', info.limits.memory_limit], ['max_execution_time', info.limits.max_execution_time], ['max_input_vars', info.limits.max_input_vars]])}</div>
                <div class="drawer-section"><h3>Disk Space</h3>${kv([['Free Space', info.disk.free], ['Total Space', `${info.disk.total} (${pct}% used)`]], diskBar)}</div>
                <div class="drawer-section"><h3>Extensions (${loadedCount}/${info.extensions.length} Loaded)</h3><div class="ext-grid">${extsHtml}</div></div>
            `;
        } catch (err) {
            body.innerHTML = `<p class="text-danger">${escapeHtml(err.message)}</p>`;
        }
    }

    // Virtual Console
    const TERMINAL_COMMANDS = ['pwd', 'ls', 'll', 'cd', 'cat', 'head', 'tail', 'mkdir', 'touch', 'rm', 'cp', 'mv', 'chmod', 'find', 'grep', 'stat', 'df', 'php', 'whoami', 'zip', 'unzip', 'wp', 'clear', 'help'];
    const TERMINAL_BANNER = el('terminal-output').innerHTML;
    function updateTerminalPrompt() {
        const d = state.terminalCwd ? '/' + state.terminalCwd : '/';
        el('terminal-prompt').textContent = d + '$';
        el('terminal-cwd-badge').textContent = d;
    }
    function openTerminal() {
        state.terminalCwd = state.currentPath || '';
        updateTerminalPrompt();
        openDialog('modal-terminal', el('terminal-input'));
        el('terminal-output').scrollTop = el('terminal-output').scrollHeight;
    }
    function appendTerminalEntry(promptLabel, cmd, outputText, isErr) {
        const out = el('terminal-output');
        const entry = document.createElement('div');
        entry.className = 'terminal-entry';
        entry.innerHTML = `<div><span class="terminal-cmd-prompt">${escapeHtml(promptLabel)}</span>${escapeHtml(cmd)}</div>`;
        if (outputText !== '') {
            const b = document.createElement('div');
            b.className = isErr ? 'terminal-stderr' : 'terminal-stdout';
            b.textContent = outputText;
            entry.appendChild(b);
        }
        out.appendChild(entry);
        out.scrollTop = out.scrollHeight;
    }
    async function runTerminalCommand(raw) {
        const cmd = raw.trim();
        if (!cmd) return;
        if (state.terminalHistory[state.terminalHistory.length - 1] !== cmd) {
            state.terminalHistory.push(cmd);
            if (state.terminalHistory.length > 100) state.terminalHistory.shift();
        }
        state.terminalHistoryIdx = -1;
        state.terminalDraft = '';
        const input = el('terminal-input');
        const btn = el('terminal-run-btn');
        const promptBefore = (state.terminalCwd ? '/' + state.terminalCwd : '/') + '$';
        input.value = '';
        input.disabled = btn.disabled = true;
        try {
            const res = await api('terminal', { command: cmd, cwd: state.terminalCwd });
            if (res.clear) el('terminal-output').innerHTML = TERMINAL_BANNER;
            else appendTerminalEntry(promptBefore, cmd, res.output || '', Number(res.exitCode) !== 0);
            if (typeof res.cwd === 'string') { state.terminalCwd = res.cwd; updateTerminalPrompt(); }
            if (res.mutated) loadDirectory(state.currentPath);
        } catch (err) {
            appendTerminalEntry(promptBefore, cmd, err.message || 'Command failed.', true);
        } finally {
            input.disabled = btn.disabled = false;
            input.focus();
        }
    }

    // Event Wiring (Delegated)
    function initEvents() {
        applyTheme(state.theme);
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (state.theme === 'auto') applyTheme('auto');
        });
        document.querySelectorAll('dialog [data-close]').forEach((b) => { if (!b.innerHTML.trim()) b.innerHTML = icon('x', 16); });

        el('btn-theme').addEventListener('click', () => {
            applyTheme(isDarkEffective() ? 'light' : 'dark');
        });
        el('btn-new').addEventListener('click', (e) => {
            e.stopPropagation();
            const open = el('new-menu').classList.toggle('open');
            el('btn-new').setAttribute('aria-expanded', String(open));
            el('secondary-tools').classList.remove('open');
            el('account-menu').classList.remove('open');
        });
        el('btn-more').addEventListener('click', (e) => {
            e.stopPropagation();
            const open = el('secondary-tools').classList.toggle('open');
            el('btn-more').setAttribute('aria-expanded', String(open));
            el('new-menu').classList.remove('open');
        });
        el('btn-account').addEventListener('click', (e) => {
            e.stopPropagation();
            const open = el('account-menu').classList.toggle('open');
            el('btn-account').setAttribute('aria-expanded', String(open));
            el('new-menu').classList.remove('open');
        });
        el('btn-new-file').addEventListener('click', () => {
            closeMenus();
            showPrompt({ title: 'Create New File', label: 'File name', submitText: 'Create File', onConfirm: async (name) => { await api('mkfile', { path: state.currentPath, name }); toast(`Created ${name}`); loadDirectory(state.currentPath); } });
        });
        el('btn-new-folder').addEventListener('click', () => {
            closeMenus();
            showPrompt({ title: 'Create New Folder', label: 'Folder name', submitText: 'Create Folder', onConfirm: async (name) => { await api('mkdir', { path: state.currentPath, name }); toast(`Created folder ${name}`); loadDirectory(state.currentPath); } });
        });
        el('btn-upload').addEventListener('click', () => openUploadModal());
        el('btn-terminal').addEventListener('click', () => { closeMenus(); openTerminal(); });
        el('btn-server-info').addEventListener('click', () => { closeMenus(); openServerInfo(); });
        el('btn-hidden').addEventListener('click', () => {
            state.showHidden = !state.showHidden;
            el('btn-hidden').classList.toggle('btn-active', state.showHidden);
            el('btn-hidden').setAttribute('aria-pressed', String(state.showHidden));
            closeMenus();
            loadDirectory(state.currentPath);
        });
        el('btn-password').addEventListener('click', () => { closeMenus(); openPasswordModal(false); });
        const doLogout = async () => {
            try { await api('logout'); window.location.reload(); }
            catch (err) { toast(err.message, 'error'); }
        };
        el('btn-logout').addEventListener('click', doLogout);
        el('password-logout-btn').addEventListener('click', doLogout);

        el('search-input').addEventListener('input', (e) => { state.searchQuery = e.target.value; renderTable(); });
        el('breadcrumbs').addEventListener('click', (e) => { const b = e.target.closest('[data-crumb]'); if (b) loadDirectory(b.dataset.crumb); });
        document.querySelector('thead').addEventListener('click', (e) => {
            const b = e.target.closest('.sort-btn');
            if (!b) return;
            const k = b.dataset.sort;
            state.sortDir = state.sortKey === k ? (state.sortDir === 'asc' ? 'desc' : 'asc') : 'asc';
            state.sortKey = k;
            renderAll();
        });
        el('select-all').addEventListener('change', (e) => {
            getFilteredSortedItems().forEach((i) => e.target.checked ? state.selected.add(i.path) : state.selected.delete(i.path));
            renderTable();
            updateBulkBar();
        });

        // Table delegation: checkbox, open, chmod, ⋮ button, and right-click
        const tbody = el('file-tbody');
        tbody.addEventListener('click', (e) => {
            const chk = e.target.closest('.row-check');
            if (chk) {
                chk.checked ? state.selected.add(chk.dataset.path) : state.selected.delete(chk.dataset.path);
                renderTable();
                updateBulkBar();
                return;
            }
            const openBtn = e.target.closest('[data-open]');
            if (openBtn) {
                const item = state.items.find((i) => i.path === openBtn.dataset.open);
                if (!item) return;
                if (item.type === 'dir') loadDirectory(item.path);
                else if (item.isImage) openPreview(item);
                else if (item.isEditable) openEditor(item.path);
                else window.location.href = `${BOOTSTRAP.selfUrl}?action=download&path=${encodeURIComponent(item.path)}`;
                return;
            }
            const chmodBtn = e.target.closest('[data-chmod]');
            if (chmodBtn) {
                const item = state.items.find((i) => i.path === chmodBtn.dataset.chmod);
                if (item) openChmodModal(item);
                return;
            }
            const menuBtn = e.target.closest('[data-row-menu]');
            if (menuBtn) {
                e.stopPropagation();
                const item = state.items.find((i) => i.path === menuBtn.dataset.rowMenu);
                if (item) openContextMenu(item, 0, 0, menuBtn);
            }
        });
        tbody.addEventListener('contextmenu', (e) => {
            const tr = e.target.closest('tr[data-path]');
            if (!tr) return;
            const item = state.items.find((i) => i.path === tr.dataset.path);
            if (!item) return;
            e.preventDefault();
            openContextMenu(item, e.clientX, e.clientY);
        });

        // Shared Context Menu execution
        el('ctx-menu').addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-ctx]');
            const item = state.ctxItem;
            if (!btn || !item) return;
            const act = btn.dataset.ctx;
            closeContextMenu();
            if (act === 'open') loadDirectory(item.path);
            else if (act === 'edit') openEditor(item.path);
            else if (act === 'preview') openPreview(item);
            else if (act === 'rename') showPrompt({ title: 'Rename Item', label: 'New name', value: item.name, submitText: 'Rename', onConfirm: async (newName) => { await api('rename', { path: item.path, newName }); toast(`Renamed to ${newName}`); loadDirectory(state.currentPath); } });
            else if (act === 'copy') promptTransfer('copy', [item.path]);
            else if (act === 'move') promptTransfer('move', [item.path]);
            else if (act === 'compress') promptZip([item.path]);
            else if (act === 'chmod') openChmodModal(item);
            else if (act === 'delete') openDeleteModal([item.path]);
            else if (act === 'unzip') {
                try { const r = await api('unzip', { path: item.path, destination: state.currentPath }); toast(`Extracted ${plural(r.extracted, 'item')}`); loadDirectory(state.currentPath); }
                catch (err) { toast(err.message, 'error'); }
            }
        });
        el('ctx-backdrop').addEventListener('click', closeContextMenu);
        window.addEventListener('scroll', (e) => {
            if (!state.ctxItem) return;
            if (e.target instanceof Node && el('ctx-menu').contains(e.target)) return;
            closeContextMenu();
        }, true);

        // Bulk actions
        el('bulk-clear').addEventListener('click', () => { state.selected.clear(); renderTable(); updateBulkBar(); });
        el('bulk-delete').addEventListener('click', () => openDeleteModal(Array.from(state.selected)));
        el('bulk-move').addEventListener('click', () => promptTransfer('move', Array.from(state.selected)));
        el('bulk-copy').addEventListener('click', () => promptTransfer('copy', Array.from(state.selected)));
        el('bulk-zip').addEventListener('click', () => promptZip(Array.from(state.selected)));

        // Forms & Modals
        el('prompt-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const inp = el('prompt-input');
            const val = inp.value.trim();
            if ((inp.required && !val) || !state.promptCallback) return;
            await withLoadingBtn(el('prompt-submit'), 'Working...', async () => {
                await state.promptCallback(val);
                closeDialog('modal-prompt');
            });
        });

        el('delete-confirm').addEventListener('click', () => withLoadingBtn(el('delete-confirm'), 'Deleting...', async () => {
            const r = await api('delete', { paths: state.deleteTargets });
            toast(`${plural(r.deleted, 'item')} deleted`);
            closeDialog('modal-delete');
            loadDirectory(state.currentPath);
        }));

        el('password-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            setPasswordError('');
            const cur = el('password-current'), nw = el('password-new'), cf = el('password-confirm');
            [cur, nw, cf].forEach((i) => i.removeAttribute('aria-invalid'));
            if (!BOOTSTRAP.mustChangePassword && cur.value === '') { cur.setAttribute('aria-invalid', 'true'); setPasswordError('Current password is required.'); cur.focus(); return; }
            if (nw.value.length < 8) { nw.setAttribute('aria-invalid', 'true'); setPasswordError('New password must be at least 8 characters.'); nw.focus(); return; }
            if (nw.value !== cf.value) { cf.setAttribute('aria-invalid', 'true'); setPasswordError('New password and confirmation do not match.'); cf.focus(); return; }
            await withLoadingBtn(el('password-submit-btn'), 'Saving...', async () => {
                const res = await api('change_password', { currentPassword: cur.value, newPassword: nw.value, confirmPassword: cf.value });
                const wasForced = BOOTSTRAP.mustChangePassword;
                BOOTSTRAP.mustChangePassword = Boolean(res && res.mustChangePassword);
                if (res && res.csrfToken) BOOTSTRAP.csrfToken = res.csrfToken;
                closeDialog('modal-password');
                toast('Password updated');
                if (wasForced) loadDirectory(window.location.hash ? decodeURIComponent(window.location.hash.slice(1)) : '');
            }, (err) => setPasswordError(err.message));
        });

        el('modal-chmod').addEventListener('change', (e) => {
            if (!e.target.matches('input[data-bit]')) return;
            let mode = 0;
            document.querySelectorAll('#modal-chmod input[data-bit]').forEach((cb) => { if (cb.checked) mode += parseInt(cb.dataset.bit, 10); });
            el('chmod-octal-input').value = '0' + mode.toString(8).padStart(3, '0');
        });
        el('chmod-octal-input').addEventListener('input', (e) => {
            const raw = e.target.value.trim();
            if (/^0?[0-7]{3}$/.test(raw)) syncChmodCheckboxes(parseInt(raw, 8));
        });
        el('chmod-save').addEventListener('click', () => withLoadingBtn(el('chmod-save'), 'Applying...', async () => {
            const mode = el('chmod-octal-input').value.trim();
            await api('chmod', { path: state.chmodPath, mode });
            toast(`Permissions set to ${mode}`);
            closeDialog('modal-chmod');
            loadDirectory(state.currentPath);
        }));

        // Upload & Drag/Drop
        const zone = el('upload-zone'), fi = el('upload-file-input');
        zone.addEventListener('click', () => fi.click());
        fi.addEventListener('change', () => setUploadQueue(Array.from(fi.files)));
        zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
        zone.addEventListener('drop', (e) => { e.preventDefault(); zone.classList.remove('dragover'); if (e.dataTransfer && e.dataTransfer.files.length) setUploadQueue(Array.from(e.dataTransfer.files)); });
        el('upload-start-btn').addEventListener('click', startUpload);

        let dragCount = 0;
        window.addEventListener('dragenter', (e) => { if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) { dragCount++; el('drop-overlay').classList.add('active'); } });
        window.addEventListener('dragleave', () => { dragCount = Math.max(0, dragCount - 1); if (!dragCount) el('drop-overlay').classList.remove('active'); });
        window.addEventListener('dragover', (e) => e.preventDefault());
        window.addEventListener('drop', (e) => { e.preventDefault(); dragCount = 0; el('drop-overlay').classList.remove('active'); if (e.dataTransfer && e.dataTransfer.files.length && !el('modal-upload').open) openUploadModal(e.dataTransfer.files); });

        // Editor
        const ta = el('editor-textarea');
        ta.addEventListener('input', () => { state.editorDirty = true; if (!gutterRaf) gutterRaf = requestAnimationFrame(() => { gutterRaf = 0; updateEditorGutter(); }); });
        ta.addEventListener('scroll', () => { el('editor-gutter').scrollTop = ta.scrollTop; });
        ta.addEventListener('keydown', (e) => {
            if (e.key === 'Tab' && !e.shiftKey) {
                e.preventDefault();
                const s = ta.selectionStart, end = ta.selectionEnd;
                ta.value = ta.value.substring(0, s) + '    ' + ta.value.substring(end);
                ta.selectionStart = ta.selectionEnd = s + 4;
                state.editorDirty = true;
                updateEditorGutter();
            }
        });
        el('editor-save-btn').addEventListener('click', () => saveEditor(false));
        el('editor-save-exit-btn').addEventListener('click', () => saveEditor(true));

        // Terminal
        el('terminal-clear-btn').addEventListener('click', () => { el('terminal-output').innerHTML = TERMINAL_BANNER; el('terminal-input').focus(); });
        el('terminal-output').addEventListener('click', () => { if (!window.getSelection || !window.getSelection().toString()) el('terminal-input').focus(); });
        el('terminal-form').addEventListener('submit', (e) => { e.preventDefault(); runTerminalCommand(el('terminal-input').value); });
        el('terminal-input').addEventListener('keydown', (e) => {
            const inp = el('terminal-input');
            if (e.key === 'ArrowUp') {
                if (!state.terminalHistory.length) return;
                e.preventDefault();
                if (state.terminalHistoryIdx === -1) { state.terminalDraft = inp.value; state.terminalHistoryIdx = state.terminalHistory.length - 1; }
                else if (state.terminalHistoryIdx > 0) state.terminalHistoryIdx--;
                inp.value = state.terminalHistory[state.terminalHistoryIdx];
            } else if (e.key === 'ArrowDown') {
                if (state.terminalHistoryIdx === -1) return;
                e.preventDefault();
                if (state.terminalHistoryIdx < state.terminalHistory.length - 1) inp.value = state.terminalHistory[++state.terminalHistoryIdx];
                else { state.terminalHistoryIdx = -1; inp.value = state.terminalDraft; }
            } else if (e.key === 'Tab') {
                e.preventDefault();
                const parts = inp.value.split(/\s+/);
                if (parts.length <= 1) {
                    const m = TERMINAL_COMMANDS.filter((c) => c.startsWith(parts[0] || ''));
                    if (m.length === 1) inp.value = m[0] + ' ';
                } else if (state.terminalCwd === state.currentPath) {
                    const m = state.items.map((i) => i.name + (i.type === 'dir' ? '/' : '')).filter((n) => n.startsWith(parts[parts.length - 1]));
                    if (m.length === 1) { parts[parts.length - 1] = m[0]; inp.value = parts.join(' '); }
                }
            }
        });

        // Native <dialog> close buttons, guarded backdrop clicks, and cancel
        document.addEventListener('click', (e) => {
            const closeBtn = e.target.closest('[data-close]');
            if (closeBtn) { closeDialog(closeBtn.dataset.close); return; }
            if (!e.target.closest('.dropdown-wrap') && !e.target.closest('.account-wrap') && !e.target.closest('#btn-more') && !e.target.closest('#secondary-tools')) closeMenus();
        });
        document.querySelectorAll('dialog').forEach((dlg) => {
            let pointerDownOnBackdrop = false;
            dlg.addEventListener('mousedown', (e) => { pointerDownOnBackdrop = (e.target === dlg); });
            dlg.addEventListener('click', (e) => {
                if (e.target === dlg && pointerDownOnBackdrop) closeDialog(dlg.id);
                pointerDownOnBackdrop = false;
            });
            dlg.addEventListener('cancel', (e) => {
                e.preventDefault();
                closeDialog(dlg.id);
            });
        });

        document.addEventListener('keydown', (e) => {
            const anyOpen = document.querySelector('dialog[open]');
            if (e.key === 'Escape') { closeMenus(); closeContextMenu(); }
            else if (((e.ctrlKey || e.metaKey) && e.code === 'Backquote') || (e.altKey && !e.ctrlKey && !e.metaKey && e.code === 'KeyT')) {
                e.preventDefault();
                if (el('modal-terminal').open) closeDialog('modal-terminal');
                else if (!anyOpen) openTerminal();
            } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                if (el('modal-editor').open) { e.preventDefault(); saveEditor(); }
            } else if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !anyOpen) {
                const ae = document.activeElement;
                if (!ae || (!['INPUT', 'TEXTAREA', 'SELECT'].includes(ae.tagName) && !ae.isContentEditable)) {
                    e.preventDefault();
                    el('search-input').focus();
                }
            }
        });
    }

    initEvents();
    const initialPath = window.location.hash ? decodeURIComponent(window.location.hash.slice(1)) : '';
    if (BOOTSTRAP.mustChangePassword) openPasswordModal(true);
    else if (!initialPath && BOOTSTRAP.initialListing) applyListingData(BOOTSTRAP.initialListing);
    else loadDirectory(initialPath);
})();
</script>
</body>
</html>
HTML;
        echo strtr($html, [
            '__HEAD_PREFIX__' => $this->getHeadPrefixHtml('FileDock'),
            '__BASE_CSS__' => $this->getBaseCss(),
            '__BRAND_BADGE__' => $this->getBrandBadgeHtml(),
            '__GITHUB_LINK__' => $this->getGithubLinkHtml(),
            '__BOOTSTRAP_JSON__' => $bootstrapJson,
            '__AUTH_HIDDEN__' => $authHiddenClass,
            '__ZIP_BADGE_CLASS__' => $zipBadgeClass,
        ]);
    }
}
$controller = new FileManagerController($CONFIG);
$controller->handleRequest();
