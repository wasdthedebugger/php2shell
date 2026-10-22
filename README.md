# PHP2Shell Fixer

A guided helper for **PHP2Shell** ([CVE-2026-109618](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w),
[CVE-2026-109619](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp)), two critical PHP bugs fixed on
21 October 2026.

**The real fix is updating PHP** to 8.5.12, 8.4.27, 8.3.36, 8.2.35 or newer. Until you can, this tool applies the temporary
settings [php.net recommends](https://www.php.net/archive/2026.php#2026-10-21-5).

## Run it

Straight from the web (Linux / macOS):

<img width="799" height="454" alt="Screenshot 2026-10-22 at 3 37 02 PM" src="https://github.com/user-attachments/assets/db1be0a8-a99f-4ff6-9e7f-bde1c667d7ef" />

```sh
curl -fsSL https://wasdthedebugger.github.io/php2shell/fix | php
```

Add `sudo` if PHP's config needs admin rights to edit, or `-- --check` to only look:

```sh
curl -fsSL https://wasdthedebugger.github.io/php2shell/fix | sudo php
curl -fsSL https://wasdthedebugger.github.io/php2shell/fix | php -- --check
```

Or download it first (also works on Windows, where you use `curl.exe`):

```sh
curl -fsSLo php2shell.php https://wasdthedebugger.github.io/php2shell/fix
php php2shell.php            # guided wizard
php php2shell.php --check    # look only, change nothing
php php2shell.php --undo     # restore a backup
```

One file, nothing to install: it runs on the PHP you already have (5.6+), on Linux, macOS or Windows.

Run it from your project folder: it also scans the project for settings that would undo the fixes.
If PHP's config needs admin rights to edit, use `sudo php php2shell.php`, or on Windows an Administrator prompt.

The wizard asks how your site runs PHP, finds its config file, checks whether your version is patched, then asks about each
fix and shows a preview. Nothing is changed until you say yes. Originals are backed up to `~/.php2shell/backups/` first.

## Docs

https://wasdthedebugger.github.io/php2shell/ has a page for each fix and setup. The site is the plain HTML in `docs/`,
served by GitHub Pages. The tool links to it; set `P2S_DOCS_URL` at the top of `php2shell.php` to `''` to use the local
`docs/` folder instead.

> **Note:** the short link serves `docs/fix`, which is a copy of `php2shell.php`. After changing the script, update the
> copy too: `cp php2shell.php docs/fix`
