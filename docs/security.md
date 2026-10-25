# Security

![Security](../screenshots/en/11-settings-security.png)

## Backups are never reachable by URL

Layers, in `Paths`:

1. **Location.** Default is `dirname(ABSPATH)/ekbm-backups-<key>` when that is
   writable, allowed by `open_basedir`, and positively *not* under
   `DOCUMENT_ROOT`; otherwise `uploads/ekbm-backups-<key>` with a random key.
2. **Deny files** rewritten before every job: `.htaccess` (Apache 2.2/2.4,
   LiteSpeed, `Options -Indexes`), `web.config` (IIS), `index.php`, `index.html`.
3. **Unguessable names**: random directory key and a random component in every set id.
4. **Self-test** (`Paths::check_exposure()`): writes a probe file with a random
   token, requests it over HTTP, records `outside|protected|exposed|unknown`.
   Runs on setup, on saving General settings, daily by cron and on demand. An
   `exposed` result shows an error notice on **every** admin screen, with the
   nginx `location … { deny all; }` block to add.
5. **Downloads** only via `admin-post.php?action=ekbm_download`: capability,
   nonce bound to the set, password gate, file must belong to that set's
   manifest. Streamed with `readfile()`.

## Entry points and their guards

| Entry point | Guard |
|---|---|
| Admin screens, POST actions | `manage_options`, nonce, password gate |
| `wp_ajax_ekbm_status`, `wp_ajax_ekbm_upload` | `manage_options`, nonce, password gate; upload names must match a set id |
| `admin_post_ekbm_download` | as above + set membership |
| `admin_post` (OAuth callback) | `manage_options`, single-use `state` bound to user, 15 min |
| `wp_ajax(_nopriv)_ekbm_tick` | HMAC with a 256-bit secret from the work dir; can only advance the queued job |
| `wp_ajax(_nopriv)_ekbm_migrate` | 256-bit token, stored hashed, 24 h, bound to one set and its chain; uniform 403 + delay on failure |

## Secrets at rest

`Secret_Cipher`: AES-256-GCM, key `HKDF(wp_salt('secure_auth'))`. Used for
FTP/SFTP passwords and keys, OAuth refresh/access tokens, the encryption
passphrase, OAuth PKCE verifiers and freshly created migration keys (shown
once). Rotating the salts invalidates them — by design.

## Plugin password

`Password_Gate`: `password_hash()`; unlock cookie = `expiry|HMAC(user, session
token, expiry)`, `HttpOnly`, `SameSite=Strict`, admin path only, cleared on
logout; 5 wrong attempts → 15-minute lockout per user. Changing/removing needs
the current password. Recovery: `EKBM_DISABLE_PASSWORD`.

## Untrusted input in backups

A backup may come from another site. Therefore: set ids and file names are
validated by pattern before use; `unserialize()` never instantiates objects;
archive entries are written only below `root/`/`content/` targets
(`File_Scanner::real_path()`), never with `../`; SQL from a dump only runs
against temporary tables until the swap; the dump format is restricted to
`DROP`/`CREATE`/`INSERT` lines for the selected tables.

## External review (19 October 2026) and fixes

| ID | Finding | Fix |
|---|---|---|
| F1 | SQL importer executed lines outside the dump format | `Sql_Importer` accepts only `DROP TABLE IF EXISTS`, `CREATE TABLE … (…)` (no `DATA/INDEX DIRECTORY`) and `INSERT INTO … VALUES (…)` exactly as our dumper writes them, always rewritten onto the mapped temporary table; header lines are skipped, anything else aborts the restore before the swap. 13 negative unit tests; verified with a manipulated dump. |
| F2 | A custom backup path could overwrite site files | `Paths::validate_custom_dir()` refuses WordPress, wp-content, uploads, plugins, themes, their parents, code folders and non-empty foreign folders (canonical paths, symlinks resolved). `protect()` never replaces a file it did not write; uninstall deletes only directories carrying our marker file. |
| F3 | FTPS without certificate check, silent plain-text fallback | FTP driver rewritten on cURL: `CURLUSESSL_ALL` (no fallback), CA + host-name verification with WordPress's CA bundle, optional public-key pin for self-signed servers (shown by "Test" for comparison, never trusted automatically). |
| F4 | SSRF through migration keys | Pulls use `reject_unsafe_urls` and no redirects; plain http and private/loopback/link-local targets or non-standard ports only with an explicit "private network" opt-in (`--allow-private`). |
| F5 | Unbounded PBKDF2 iterations from the file header | Header values above 5 000 000 are rejected before key derivation; unit test with a forged header. |
| F6 | Loopback TLS off; exposure test too optimistic | Loopbacks and the probe follow core's `https_local_ssl_verify` filter (WordPress convention for requests to itself). The probe now tests `.zip`, `.gz`, `.enc`, `.json` and `.txt`; only 401/403/404/410 count as protected, anything else (200 without the token, redirects, errors) is `unknown`. |

## Verifying

`docs/testing.md` lists the HTTP probes used to check that archives, catalog,
directory listing and the runner key all answer 403 from outside, and that the
public AJAX endpoints refuse unauthenticated requests.
