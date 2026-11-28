<div align="center">

# FileDock

**Single-file PHP file manager for shared hosting, VPS, and local servers.**

[![PHP 7.4–8.x](https://badgen.net/badge/PHP/7.4-8.x/4F5B93)](https://www.php.net/)
[![Zero Dependencies](https://badgen.net/badge/Dependencies/Zero/0F766E)](#why-filedock)
[![Single File](https://badgen.net/badge/Single-File/~265%20KB/111317)](#quick-start)
[![MIT License](https://badgen.net/badge/License/MIT/646B78)](LICENSE)

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://github.com/user-attachments/assets/36ac7656-4052-4a0a-b94e-fae917b287d8">
  <source media="(prefers-color-scheme: light)" srcset="https://github.com/user-attachments/assets/94c59a9d-6b50-43ba-a366-1aa0c72cf938">
  <img alt="FileDock workspace preview" src="https://github.com/user-attachments/assets/94c59a9d-6b50-43ba-a366-1aa0c72cf938" width="100%">
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

1. **Get `filedock.php`:** Download it from the [Latest Release](https://github.com/mahyardyn/file-dock/releases/latest) and upload it to your web root (e.g., `public_html/`), or fetch it directly via terminal:

   ```bash
   curl -fsSLO https://raw.githubusercontent.com/mahyardyn/file-dock/main/filedock.php
   ```

2. **Open in Browser:** Visit `https://your-domain.com/filedock.php`.

3. **Sign In & Set Password:** Log in with the default password `admin`. FileDock will prompt you to set a new password (minimum 8 characters) and store its bcrypt hash in `.filedock-auth.php` (`0600`).

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
| <kbd>/</kbd> | File Browser | Focus filter input |
| <kbd>Right-Click</kbd> / <kbd>⋮</kbd> | File Browser | Open context menu (Bottom Sheet on mobile) |
| <kbd>Ctrl</kbd> + <kbd>&#96;</kbd> / <kbd>Cmd</kbd> + <kbd>&#96;</kbd> / <kbd>Alt</kbd> + <kbd>T</kbd> | Global | Toggle Virtual Console |
| <kbd>Ctrl</kbd> + <kbd>S</kbd> / <kbd>Cmd</kbd> + <kbd>S</kbd> | Code Editor | Save active file |
| <kbd>Tab</kbd> | Editor / Console | Insert 4 spaces / Autocomplete |
| <kbd>ArrowUp</kbd> / <kbd>ArrowDown</kbd> | Virtual Console | Navigate command history |
| <kbd>Esc</kbd> | Global | Close active modal, drawer, or menu |

---

## License

[MIT](LICENSE) © 2026 Mahyar Dayani

---

## Changelog

### v1.1.0

- **Archives:** Add extraction and compression for `.tar`, `.tar.gz`, `.tgz`, and `.tar.bz2`.
- **Editor:** Add read-only viewer mode for non-editable and large text files.
- **UX & Auth:** Improve dialog transitions and mobile keyboard handling, fix iOS Safari focus auto-zoom, and fix 7-day sliding-expiration sessions.

### v1.0.0

- Initial release of FileDock (`filedock.php`) with `PathGuard`, `AuthManager`, `FileSystemService`, `ServerInfoService`, `VirtualConsoleService`, and responsive Auto/Light/Dark UI.
