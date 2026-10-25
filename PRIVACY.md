# Privacy Policy — Eikvakt Backup Manager

_Last updated: 19 October 2026_

Eikvakt Backup Manager is a free, open-source WordPress plugin (GPL-2.0-or-later)
by Steven Glogger. This policy explains what the plugin does with data. The
short version: **the author collects nothing.** The plugin runs entirely on
your own WordPress server, and backups go only to the places you choose.

## 1. Who is responsible

The plugin is software you install and run yourself. The author does not
operate any server, service or account for it and has no access to your
website, your backups or your storage accounts.

For the personal data contained in your website and its backups, **you** —
the operator of the WordPress site — are the controller under the GDPR and
similar laws. This policy describes how the plugin handles that data on your
behalf.

Author: Steven Glogger — https://www.glogger.ch — questions and reports:
https://github.com/sglogger/eikvakt-backup-manager/issues

## 2. What the author receives

Nothing. The plugin contains:

- no analytics, telemetry, tracking or "phone home" requests,
- no advertising and no third-party scripts,
- no licence checks or accounts (there is no paid version),
- no relay or proxy server of the author: all connections go directly from
  your server to the destinations you configured.

## 3. What the plugin processes on your server

To do its job, the plugin reads and copies:

- the files of your WordPress installation that you select (WordPress core,
  plugins, themes, uploads, other files), and
- your WordPress database (all or selected tables) — which typically contains
  personal data such as user accounts, e-mail addresses, comments or orders.

It stores locally, inside your WordPress installation:

- the backup archives, in a protected directory that is not reachable over the
  web (outside the web root where possible; otherwise locked down and tested
  daily);
- its settings, a log of each backup/restore run (time, size, result, file and
  table counts, destination names), and a catalogue of known backups;
- credentials for your storage destinations (FTP/SFTP passwords or keys,
  Dropbox/OneDrive tokens, the optional encryption passphrase) — **encrypted**
  with your site's secret keys from `wp-config.php`.

Backups can optionally be encrypted (XChaCha20-Poly1305, key derived from your
passphrase). Encrypted backups cannot be read without the passphrase — by
anyone, including the author.

## 4. Where data is sent — only where you send it

Data leaves your server only to destinations **you** add and connect:

| Destination | What is sent | Operated by |
|---|---|---|
| Dropbox | backup files and their names, OAuth requests | Dropbox, Inc. — [privacy policy](https://www.dropbox.com/privacy) |
| Microsoft OneDrive | backup files and their names, OAuth requests | Microsoft — [privacy statement](https://privacy.microsoft.com/privacystatement) |
| FTP, FTPS, SFTP | backup files and their names, your login | the server you specify |
| Another WordPress site (migration) | the backup you chose to migrate | your own other site |

Additionally:

- **E-mail reports** (if enabled) are sent through your WordPress site's normal
  mail system to the addresses you enter. They contain the site address,
  status, time, size and destination names of a run — not backup contents.
- **Loopback requests**: the plugin sends requests from your server to your own
  site to run backups in the background and to test that the backup directory
  cannot be downloaded. These never leave your server's own address.

## 5. Dropbox in detail

When you connect Dropbox, you authorise the app **"Eikvakt Backup Manager"** (or
your own Dropbox app, if you choose that option):

- **Access is limited to one folder**: `Apps/Eikvakt Backup Manager` in your
  Dropbox. The app cannot see or change any other file in your Dropbox.
- **Permissions requested**: `files.metadata.read`, `files.metadata.write`,
  `files.content.read`, `files.content.write` — to upload backups, list them,
  download them for a restore, and delete backups that your retention rules
  have expired.
- **Sign-in** uses OAuth 2 with PKCE. Your Dropbox password is never seen by
  the plugin or the author. The resulting tokens are stored only on your
  WordPress server, encrypted.
- **Nothing is shared with the author**: the app key in the plugin is public
  and only identifies the app; it gives no access to anyone's data.
- **Revoking access**: Dropbox → Settings → Apps (Connected apps) → Eikvakt
  Backup Manager → Disconnect; and/or remove the destination in the plugin.
  Backups already stored in the app folder stay there until you delete them.

The same principles apply to Microsoft OneDrive (app folder only, permissions
`Files.ReadWrite.AppFolder` and `offline_access`, using your own Microsoft app
registration).

## 6. Retention and deletion

- You decide how long backups are kept (number of backups, rotation, days per
  location). The plugin deletes older backups from your server and your
  destinations according to these rules; backups you mark "Keep" are never
  deleted automatically.
- Run logs older than one year are deleted automatically.
- Removing a destination deletes its stored credentials from your site.
- When the plugin is deleted, settings and logs are removed only if you enabled
  that option, and local backups only if you enabled that separately. Backups on
  remote destinations are never deleted by uninstalling.

## 7. Your responsibilities as site operator

Because backups contain the personal data of your site, please consider:

- mentioning in your own privacy policy that you keep backups and where (for
  example "stored encrypted in Dropbox"),
- enabling backup encryption, especially for cloud destinations,
- choosing retention periods that match your legal obligations (e.g. to delete
  personal data after a certain time — remember it also lives in older
  backups),
- concluding a data processing agreement with your storage provider where your
  law requires it.

## 8. Changes

Changes to this policy are published in this file in the plugin's repository:
https://github.com/sglogger/eikvakt-backup-manager/blob/main/PRIVACY.md
