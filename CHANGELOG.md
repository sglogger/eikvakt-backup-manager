# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/). Mirror every entry into
`readme.txt` → `== Changelog ==`.

## [1.0.4] — 2026-10-25

The plugin is renamed from Afrit Backup Manager to **Eikvakt Backup Manager**
(slug `eikvakt-backup-manager`), following the WordPress.org review: "Afrit"
is the name of an existing Icelandic backup service. *Eikvakt* joins the
Norwegian *eik* (oak) and *vakt* (watch, guard) — the oak guard.

### Changed
- New name, slug, text domain, namespace (`EikvaktBackupManager`), prefix `ekbm_`/`EKBM_` (options, tables, hooks, constants, CSS classes, backup file names), WP-CLI command `wp eikvakt`, migration key prefix `EKBM1-`. Settings, backups and migration keys of the earlier name are not carried over.
- SFTP: phpseclib 3.0 → 4.0 (namespace `phpseclib4`; errors are exceptions now).

### Security
- Settings forms: `Admin::request()` sanitises the whole submission right after the capability and nonce checks; only passwords, passphrases, search/replace pairs and file rules are passed on as entered.

## [1.0.3] — 2026-10-19

### Removed
- The unused PHP 5 polyfill `paragonie/random_compat` (pulled in by phpseclib) is no longer installed (Composer `replace`). PHP 8.1+ has `random_bytes()` built in.

### Changed
- Packaging: build scripts, PHAR files and tool configurations that dependencies carry along are removed from the plugin ZIP. WordPress.org rejected the upload because of `vendor/paragonie/random_compat/build-phar.sh`.
- Packaging: `package-plugin.sh` and the release workflow fail the build if a file type WordPress.org refuses (`.sh`, `.phar`, `.exe`, `.bat`, `.py`, `.jar`, …) ever appears in the ZIP.

## [1.0.2] — 2026-10-19

### Changed
- The site-wide warning "backups can be downloaded over the internet" can be dismissed per user until the next self-test, disappears by itself once the test passes, and stays visible on the plugin's own screens (WordPress.org guideline 11).
- readme.txt: "What makes it different" and "Open source" sections.

### Added
- 64×64 app icon for the Dropbox App Console (not part of the plugin ZIP).
- `.gitattributes` (LF line endings) and `.editorconfig`.

## [1.0.1] — 2026-10-19

The name: *afrit* is Icelandic for "copy" — the root of *öryggisafrit*,
"safety copy", the Icelandic word for a backup. The plugin Description and
readme.txt now say so, as in the sibling plugin Vokull Security Center.

### Security
- Strict allow-list for SQL statements during restore; manipulated dumps abort before the live database is touched.
- Custom backup directories are validated; protection files never overwrite foreign files; uninstall deletes only marked directories.
- FTP/FTPS over cURL with certificate verification, no plain-text fallback, optional key pinning.
- Migration keys can no longer reach internal addresses unless explicitly allowed; HTTPS required otherwise.
- Ceiling for key-derivation cost read from encrypted files.
- Stricter, multi-file-type exposure self-test.

### Added
- Files tab with a clickable folder tree: explicit include/exclude per folder, most specific rule wins; patterns and size limit moved here from General.
- Privacy policy (PRIVACY.md), linked from readme.txt.
- WordPress Playground is detected: remote uploads are skipped with a clear explanation instead of failing (Playground cannot send binary data).

### Changed
- FTP/FTPS destination uses cURL instead of the PHP ftp extension.
- Delete, Remove, Cancel and Revoke are real buttons; removing a destination asks for confirmation.
- Admin assets are cache-busted by file time while SCRIPT_DEBUG is on.

## [1.0.0] — 2026-10-19

### Added
- Backups of the whole installation: core, plugins, themes, uploads, other wp-content files, database.
- Incremental file backups (full + incremental chains); selectable database tables.
- Destinations: this server, FTP/FTPS, SFTP (host-key pinning), Dropbox, Microsoft OneDrive — simultaneously, resumable.
- Dropbox without a developer app: built-in app with PKCE and code paste; own app optional.
- Schedules for files and database; automatic backups before core, plugin and theme updates.
- Retention by count, GFS rotation, maximum age, and days per location with per-destination override.
- "Keep" marks: chosen backups (and the chains they depend on) are exempt from all automatic deletion.
- Restore of individual components and tables, search & replace (serialisation-safe), safety backup, atomic database swap.
- One-click migration with migration keys; import through shared storage or upload; table prefix rewriting.
- Encryption (XChaCha20-Poly1305, PBKDF2-SHA256).
- Backup directory protection with daily HTTP exposure self-test; authenticated downloads only.
- Optional plugin password; sealed credentials.
- Reports with full logs; e-mail reports.
- One-click setup; WP-CLI commands.
