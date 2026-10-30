=== Eikvakt Backup Manager ===
Contributors: glogger
Tags: backup, restore, migration, incremental backup, cloud backup
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Incremental backups, restores and migrations of the whole WordPress installation, to this server, FTP, SFTP, Dropbox and OneDrive at once.

== Description ==

Eikvakt Backup Manager ("Eikvakt" is Norwegian for "oak guard") backs up your complete WordPress installation — core, plugins, themes, uploads, every other file and the database — restores any part of it on its own, and moves the site to a new domain or host.

**Every feature is included. There is no PRO version, no upsell and no account to create. All free.**

**What makes it different**

* **Everything free, for good.** Incremental backups, encryption, every storage destination, scheduling, retention rules, migration and WP-CLI are all in this plugin. No add-ons, no licence, no account, and no server of ours in between.
* **Security first.** Backups are kept out of reach of the web and the plugin proves it with a daily HTTP self-test of every archive type. Restores only accept the plugin's own SQL format and swap the database in atomically. FTPS certificates are verified, the SFTP host key is pinned, and stored credentials are encrypted.
* **Built for shared hosting.** Every job runs in short, resumable steps, driven by background requests, WP-Cron and the admin screen alike, so large sites back up without ever hitting a time limit.
* **Precise control.** A clickable folder tree with "most specific rule wins", database tables one by one, retention in days per location, and "Keep" marks for backups that must never be deleted.

**Set up in one click**

One button applies safe defaults and runs the first backup right away: daily incremental file backups with a full backup every seventh run, a daily database backup, a backup before every update, sensible retention, and an e-mail when something goes wrong. Everything can be fine-tuned later.

**Incremental backups**

After the first full backup, only files that changed are saved. Backups finish faster, use a fraction of the storage and put far less load on the server. Restoring an incremental backup automatically combines it with the full backup and every incremental before it — you simply pick the point in time.

**Backups before updates**

Before WordPress, a plugin or a theme is updated — by hand, in bulk or automatically in the background — the part that is about to change is backed up, together with the database. A broken update is rolled back in a minute.

**Many destinations at once**

Every backup is stored on this server and copied to all active destinations at the same time: FTP, FTPS (certificate verified or key-pinned), SFTP (password or key, with host-key pinning), Dropbox and Microsoft OneDrive. Dropbox connects in three clicks — no developer app needed: press Connect, allow access, paste the code. If one destination is down, the others still receive their copy. Large backups are split into parts and transferred in resumable chunks.

**Choose exactly which folders**

A clickable folder tree on the Files tab: untick a folder to leave it out, tick one to include it — even inside an excluded folder, or a cache folder that is skipped by default. The most specific choice wins. Patterns such as `*.log` and a maximum file size complement it.

**Flexible scheduling**

File and database backups have their own schedules: every few hours, daily, weekly or monthly, at the time you choose, in your site's timezone.

**Retention rules**

Keep a number of backups, or a daily/weekly/monthly rotation (grandfather-father-son). Set separately how many days backups stay on this server and on the remote destinations, with an override per destination. A full backup is never deleted while incrementals depend on it, and the newest backup is always kept.

Mark any backup as **Keep** — for example the last one before a relaunch — and no automatic rule will ever delete it, on this server or on any destination. Marking an incremental backup also keeps the backups it builds on.

**Restore exactly what you need**

Restore the database, WordPress core, plugins, themes, uploads or other files separately — or just a few database tables. The database is imported into temporary tables and swapped in at once only when everything succeeded, so a failed restore leaves the site untouched. Optionally, the current state is backed up first.

**One-click migration**

Move or copy the site to a new domain or host: create a migration key on the old site, paste it on the new one, and the backup is transferred server to server. During the restore, the old address and paths are replaced with the new ones — safely, including serialised and JSON data — and a different table prefix is handled automatically. Migrate everything or only selected parts such as the uploads or the database.

**Encryption**

Encrypt the database — and optionally the file archives — with a passphrase (XChaCha20-Poly1305, key derived with PBKDF2-SHA256). Encrypted backups are unreadable without the passphrase, wherever they are stored, and any tampering is detected.

**Secure by design**

* Backups are stored outside the web root whenever the server allows it; otherwise in a randomly named, locked-down directory with unguessable file names.
* A daily self-test requests a probe file from the backup directory over HTTP and raises an alarm on every admin screen if the web server would serve it (for example on nginx), with the fix.
* Backups can only be downloaded through the admin screen while signed in — never by URL.
* Stored credentials (FTP/SFTP passwords, keys, cloud tokens, the passphrase) are sealed with your site's secret keys.
* An optional extra password locks the whole plugin, on top of administrator rights.

**Detailed reports**

Every backup, restore and migration is recorded: when it ran, what triggered it, what it included, its size, where it was stored and whether it succeeded — with the complete log. E-mail reports always, only on problems, or never.

**Open source**

The complete source code, the developer documentation and the issue tracker are on GitHub: https://github.com/sglogger/eikvakt-backup-manager. The plugin ships no minified or compiled code.

**WP-CLI**

`wp eikvakt backup`, `restore`, `list`, `keep`, `migrate`, `prune`, `rescan` and `status`.

**About the name**

"Eikvakt" (say "ayk-vahkt") joins two Norwegian words: *eik*, oak, and *vakt*, watch or guard — the oak guard. Oak for what lasts, a guard for what keeps watch. Which is fairly close to the entire job description: watch over a good copy of your site, and give it back the moment it is needed.

== External services ==

This plugin connects to the following third-party services **only for storage destinations that an administrator has added and connected**. Nothing is sent anywhere until then.

**Dropbox** (Dropbox, Inc.) — to store backup files in the administrator's Dropbox, in the app folder Apps/Eikvakt Backup Manager only. By default the connection uses the plugin's Dropbox app (listed in Dropbox under "Connected apps"); administrators can use their own Dropbox app instead. Data sent: the backup files (encrypted if encryption is enabled), their file names, and OAuth requests to authorise and refresh access. Access tokens are stored only on the site; the plugin author receives nothing and runs no server in between. Endpoints: www.dropbox.com, api.dropboxapi.com, content.dropboxapi.com.
Terms of service: https://www.dropbox.com/terms — Privacy policy: https://www.dropbox.com/privacy

**Microsoft OneDrive / Microsoft Graph** (Microsoft Corporation) — to store backup files in the app folder of the administrator's own OneDrive, using the administrator's own Microsoft Entra app registration. Data sent: the backup files (encrypted if encryption is enabled), their file names, and OAuth requests to authorise and refresh access. Endpoints: login.microsoftonline.com, graph.microsoft.com and the upload/download URLs Graph returns.
Terms of use: https://www.microsoft.com/servicesagreement — Privacy statement: https://privacy.microsoft.com/privacystatement

**FTP, FTPS and SFTP servers** — backup files are sent to the server, folder and account the administrator enters. These are the administrator's own servers, not a service of the plugin author.

**Migration between two sites** — when an administrator imports a migration key, the target site downloads the backup directly from the source site that created the key. No third party is involved.

The plugin also sends requests to its own site (loopback requests) to run backups in the background and to test that the backup directory cannot be downloaded over the web.

The plugin author collects no data and operates no server for this plugin. Privacy policy: https://github.com/sglogger/eikvakt-backup-manager/blob/main/PRIVACY.md

== Installation ==

1. Install the plugin through Plugins → Add New, or upload the ZIP.
2. Activate it.
3. Open **Backup Manager** in the admin menu and press **Set up and run the first backup**.
4. Optional: add an off-site destination under Backup Manager → Settings → Storage.

Requirements: PHP 8.1+ with the zip extension (standard on virtually every host). The FTP/FTPS destination uses cURL (standard as well); SFTP, Dropbox and OneDrive need no extra extensions.

== Frequently Asked Questions ==

= Is there a paid version? =

No. Every feature described here is in this plugin, free, and will stay that way.

= Can someone download my backups from the internet? =

Not by URL. Backups are kept outside the web root where possible, otherwise in a protected directory with random names, and the plugin tests daily that the web server refuses to serve them. Downloads go only through the admin screen, for signed-in administrators (and the plugin password, if set).

= My backup is large. Will it time out? =

No. Backups and restores run in short steps (20 seconds by default) that continue in the background, so they never hit the server's time limit. Large archives are split into parts, and uploads resume where they stopped.

= I forgot the plugin password. =

Add `define( 'EKBM_DISABLE_PASSWORD', true );` to wp-config.php, set a new password under Settings → Security, then remove the line again.

= How do I make sure a particular backup is never deleted? =

Press **Keep** next to it on the Backups screen (or run `wp eikvakt keep <id>`). Kept backups are exempt from every retention rule and do not count towards the limits. To delete a kept backup, remove the mark first.

= I lost the encryption passphrase. =

Then the encrypted backups cannot be restored — by anyone. That is the purpose of encryption. Keep the passphrase in a password manager.

= Does a restore overwrite wp-config.php? =

Not unless you tick the option. wp-config.php holds the database credentials of the server it is on. The plugin's own files and settings are never overwritten by a restore either.

= Does it work in WordPress Playground? =

Backups and restores on the site itself do. Uploads to Dropbox, OneDrive, FTP or SFTP do not: Playground's browser sandbox cannot send binary data such as backup archives, and has no FTP/SSH connections. The plugin detects Playground, says so on the Storage tab and keeps the backups on the site. Test remote destinations on a real server.

= Does it work on multisite? =

Backups cover the whole network installation. Restoring individual sites of a network is not supported.

== Screenshots ==

1. Dashboard with one-click setup, status tiles and live progress.
2. Backup list with full, incremental, database and partial backups.
3. Restoring selected parts with search and replace for a new domain.
4. Migration between two sites with a migration key.
5. Reports with the complete log of every run.
6. Storage destinations: this server, FTP, SFTP, Dropbox and OneDrive.
7. Schedules for files and database, and backups before updates.
8. Retention per location.
9. Security: backup directory self-test and plugin password.

== Changelog ==

= 1.0.5 =
* Security: uploads, migration imports and manifests only accept a backup set's own file names; nothing else (such as a .php file) can be placed in the backup directory.
* Security: the FTPS connection test no longer logs in to an untrusted server to read its key; user name and password only go to a verified or pinned server.
* Security: a restore protects wp-config.php and this plugin by their real location on disk, whatever path spelling a backup uses.
* Security: a database restore only accepts table definitions with known options and storage engines and rows of plain values; a manipulated dump can no longer reach other tables.
* Changed: a backup folder that may be web-served but has no known web address is shown as "not verified" instead of "outside the web root".

= 1.0.4 =
* Changed: the plugin is now called Eikvakt Backup Manager (slug eikvakt-backup-manager). Settings, backups and migration keys of the earlier name are not carried over.
* Changed: SFTP uses phpseclib 4.
* Security: submitted settings forms are sanitised as a whole before any handler sees them.

= 1.0.3 =
* Changed: smaller, cleaner package — an unused PHP 5 compatibility library and build files of bundled libraries are no longer included.

= 1.0.2 =
* Changed: the warning about web-accessible backups can be dismissed until the next self-test and disappears once the problem is fixed.
* Changed: readme explains what sets the plugin apart and where the source code lives.

= 1.0.1 =
* Security: restores accept only the plugin's own SQL statement format; manipulated dumps abort before the live database is touched.
* Security: custom backup directories are validated and protection files never overwrite existing files.
* Security: FTPS verifies the server certificate (or a pinned key) and never falls back to plain FTP.
* Security: migration keys only reach public HTTPS sources unless a private network is explicitly allowed.
* Security: limits on key-derivation cost from encrypted files; stricter backup-directory self-test across file types.
* New: Files tab with a clickable folder tree to include or exclude any folder.
* New: privacy policy; WordPress Playground is detected and explained.
* Changed: FTP/FTPS uses cURL; delete actions are proper buttons.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.5 =
Security release: stricter checks for imported backups, restores and database dumps, and a safer FTPS connection test. Recommended for everyone.

= 1.0.4 =
New name and slug: Eikvakt Backup Manager. phpseclib 4 for SFTP.

= 1.0.3 =
Packaging clean-up, no functional changes. Safe to update.

= 1.0.2 =
Smaller admin notice improvements. Safe to update.

= 1.0.1 =
Security hardening of restore, FTPS, migration and the backup directory. Recommended for all users.

= 1.0.0 =
First release.
