<div align="center">

# FileDock

**Single-file PHP file manager for shared hosting, VPS, and local servers.**

[![PHP 7.4–8.x](https://img.shields.io/badge/PHP-7.4--8.x-4F5B93?style=flat-square)](https://www.php.net/)
[![Zero Dependencies](https://img.shields.io/badge/Dependencies-Zero-0F766E?style=flat-square)](#why-filedock)
[![Single File](https://img.shields.io/badge/Single--File-~246%20KB-111317?style=flat-square)](#quick-start)
[![MIT License](https://img.shields.io/badge/License-MIT-646B78?style=flat-square)](LICENSE)

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/user-attachments/assets/962a2e0e-e452-4d4b-a68d-ce7058827e71">
  <source media="(prefers-color-scheme: light)" srcset="https://github.com/user-attachments/assets/c78629e4-5f7f-44a1-8aa5-0ad6830d4129">
  <img alt="FileDock workspace preview" src="https://github.com/user-attachments/assets/c78629e4-5f7f-44a1-8aa5-0ad6830d4129" width="100%">
</picture>

</div>

---

## Why FileDock

Many single-file PHP file managers get blocked on upload or flagged during hosting security scans with errors like `Upload failed: BAILOUT malware detected` from **Imunify360**, **CXS**, or **ClamAV** because they rely on `eval()`, `exec()`, `shell_exec()`, `base64_decode()`, obfuscated payloads, or external CDNs.

FileDock is written in strict-typed PHP 7.4–8.x (`declare(strict_types=1);`) using native SPL iterators and `ZipArchive`, with all HTML, CSS, JavaScript, and SVG icons self-contained in a single readable `filedock.php` file. It is built to pass server-side, Wordfence, and VirusTotal checks cleanly without triggering heuristic false positives.

---

## Key Features

- **File Management & Context Menu:** Breadcrumb navigation, live filter (`/`), column sorting, hidden dotfiles toggle, unified right-click context menu on desktop, `⋮` Bottom Sheet on mobile, recursive `copy`/`move`, visual `rwx` + octal `chmod`, and bulk `Move` / `Copy` / `Zip` / `Delete`.
- **Transfers & Archives:** Drag-and-drop multi-file upload with `XMLHttpRequest` progress, overwrite toggle, streaming downloads, and Zip / Zip-Slip-safe Unzip via `ZipArchive`.
- **Code Editor & Media Preview:** Line-numbered editor with 4-space `Tab` indentation, unsaved-change guard on close, `Save` / `Save & Exit` (`Ctrl+S`/`Cmd+S`), and inline preview for `jpg`, `jpeg`, `png`, `gif`, `webp`, `svg`, `ico`, `bmp`, and `avif`.
- **Pure-PHP Virtual Console (Built-in Terminal):** Works even when `exec()`/`shell_exec()` are disabled on the server — jailed SPL console (`pwd`, `ls`, `ll`, `cd`, `cat`, `head`, `tail`, `mkdir`, `touch`, `rm`, `cp`, `mv`, `chmod`, `find`, `grep`, `stat`, `df`, `php`, `whoami`, `zip`, `unzip`, `clear`, `help`) with command history, tab completion, and optional guarded `wp-cli` (`wp ...`).
- **Server Info Drawer:** Runtime PHP version, SAPI, OS, `ROOT_DIR`, server time, HTTPS and `proc_open` status, `php.ini` limits, disk usage bar, and loaded extension versions.
- **Responsive UI:** Baseline `light-dark()` Auto/Light/Dark themes without FOUC and full keyboard navigation.

---

## Quick Start

Download `filedock.php` and upload it to your target directory:

```bash
curl -fsSLO https://raw.githubusercontent.com/mahyardyn/file-dock/main/filedock.php
```

1. Open `https://your-domain.com/path/to/filedock.php` in your browser.
2. Sign in with the default password `admin` — FileDock will prompt you to set a new password (minimum 8 characters) on first login and store its bcrypt hash in `.filedock-auth.php` (`0600`).
3. To pre-configure a password hash or adjust defaults before uploading, edit `$CONFIG` at the top of `filedock.php`:

```php
$CONFIG = [
    'AUTH_ENABLED'          => true,
    'DEFAULT_PASSWORD_HASH' => '$2y$10$YxbKyw4gpR38J1jPNLPaLuyGDigce98mpM.QfXyG9V62NwvwCQT2u',
    'ROOT_DIR'              => __DIR__,
    'TIMEZONE'              => 'UTC',
    'WP_CLI_ENABLED'        => true,
    'WP_CLI_BIN'            => 'wp',
];
```

Generate a custom bcrypt hash locally:

```bash
php -r "echo password_hash('your-strong-password', PASSWORD_BCRYPT) . PHP_EOL;"
```

---

## Custom Filename

You can rename `filedock.php` to any filename (such as `ops-panel.php` or `files-8f2a.php`) without changing any configuration. It resolves its active URL from `$_SERVER['SCRIPT_NAME']`, while `.filedock-auth.php` remains hidden and blocked from listing, reading, downloading, or archiving by `PathGuard`.

---

## Security Overview

- **`PathGuard` Root Jailing:** Normalizes `ROOT_DIR` with `realpath()`, keeps all resolved paths inside `ROOT_DIR`, rejects null bytes and traversal segments, blocks copying/moving a directory into itself, and hides `.filedock-auth.php`.
- **`AuthManager` Hardening:** Uses `password_verify()` (bcrypt), a 5-attempt / 300-second lockout, `FM_SESSID` cookies (`HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, `session.use_strict_mode = 1`), and `session_regenerate_id(true)` on login.
- **CSRF & Zip-Slip Protection:** Validates per-session 64-character CSRF tokens with `hash_equals()` on every state-changing request, and inspects every `ZipArchive` entry (`isSafeZipEntry()` + symlink checks) before extraction.
- **No Shell Execution Functions:** Zero `eval`, `exec`, `shell_exec`, `system`, `passthru`, `popen`, `base64_decode`, `gzinflate`, or `$_REQUEST`. Optional `wp-cli` uses array-form `proc_open()` (no `/bin/sh`), blocks `--exec`/`--require`/`--path`/`--ssh`/`--config`/`eval`/`eval-file`/`shell`/`db cli`, and enforces a 30-second timeout.
- **Safe Output Headers:** Serves downloads and previews with `X-Content-Type-Options: nosniff` and applies a restrictive `Content-Security-Policy` on inline SVG previews.

> [!WARNING]
> Delete `filedock.php` and `.filedock-auth.php` when maintenance is finished, or restrict access by IP / HTTP Basic Auth.

---

## Keyboard Shortcuts

| Shortcut | Context | Action |
| :--- | :--- | :--- |
| `/` | File Browser | Focus filter input |
| `Right-Click` / `⋮` | File Browser | Open context menu (Bottom Sheet on mobile) |
| <kbd>Ctrl</kbd> + <kbd>&#96;</kbd> / <kbd>Cmd</kbd> + <kbd>&#96;</kbd> / <kbd>Alt</kbd> + <kbd>T</kbd> | Global | Toggle Virtual Console |
| `Ctrl + S` / `Cmd + S` | Code Editor | Save active file |
| `Tab` | Editor / Console | Insert 4 spaces / Autocomplete |
| `ArrowUp` / `ArrowDown` | Virtual Console | Navigate command history |
| `Esc` | Global | Close active modal, drawer, or menu |

---

## License

[MIT](LICENSE) © 2026 Mahyar Dayani
