# Testing

## Unit tests (PHPUnit 10)

```sh
docker run --rm -v "$PWD":/app -w /app php:8.3-cli-alpine php vendor/bin/phpunit --testdox
```

WordPress-free classes only (`tests/bootstrap.php` stubs the two WP functions
they touch):

| Test | Covers |
|---|---|
| `ComponentsTest` | path → component, sanitising |
| `ManifestTest` | id format and validation, file bookkeeping, JSON round trip |
| `FileIndexTest` | full vs. incremental diff, holders, deletions |
| `EncryptorTest` | round trip across chunks and resumed steps, empty file, wrong passphrase, tampering, truncation |
| `SearchReplaceTest` | JSON-escaped, serialised, nested, objects not instantiated, byte-identical untouched values |
| `ScheduleTest` | daily/weekly/monthly/interval, DST, month clamping |
| `RetentionPolicyTest` | whole chains, newest kept, max age, per-location days, keep marks (chains, standalone, locations) |
| `FileRulesTest` | patterns vs. folder rules, include inside exclude, explicit include of default-excluded folders, prefix boundaries, size limit, sanitising |
| `SqlImporterTest` | prefix mapping, table rewriting, names inside values untouched |

## Coding standards

```sh
docker run --rm -v "$PWD":/app -w /app php:8.3-cli-alpine php -d memory_limit=1G vendor/bin/phpcs
```

## Plugin Check (WordPress.org)

Build the ZIP and check it on a clean site — the result must be "No errors found":

```sh
./package-plugin.sh
# on a throwaway WordPress with the plugin-check plugin:
wp plugin install dist/eikvakt-backup-manager-<version>.zip --activate
wp plugin check eikvakt-backup-manager
```

## Browser smoke test (Playwright)

```sh
dev/screenshots/run.sh smoke
```

Starts a backup from the dashboard and follows the live progress, checks that
a kept backup cannot be deleted and that Keep/Unkeep work, sets the
plugin password, checks the lock screen hides downloads, rejects a wrong
password, unlocks, removes the password.

## End-to-end checklist (Docker)

Run before a release; each line was verified for 1.0.0.

- [ ] `wp eikvakt backup` — full backup of everything; `unzip -l` shows root/ and content/ paths, dump starts with `-- ekbm-dump 1`.
- [ ] File chain: `--type=auto` twice with a modified, a new and a deleted file → incremental contains exactly the 2 changed files, `stats.deleted = 1`.
- [ ] Damage the site (delete a plugin, change files and posts, add junk), restore the incremental with `--delete-extra` and the database → posts, blog name, plugin, file checksums back; junk removed.
- [ ] Encryption on + SFTP + FTP: one backup lands encrypted on both; a broken destination only causes a warning.
- [ ] Queue path only (no CLI): part size 10 MB, time budget 5 s, no local copy → loopbacks finish the job; report shows both destinations.
- [ ] Restore from remote-only encrypted set: wrong passphrase fails cleanly, right one restores.
- [ ] Migration key from :9190 to :9192 → home is the target URL, prefix `wpt_` works (admin can log in), target keeps its own settings and wp-config.php, no old URL in the page.
- [ ] `wp plugin install akismet --version=<old> --force; wp plugin update akismet` → partial pre-update backup (plugin + database); restoring it with `--delete-extra` downgrades only that plugin.
- [ ] `wp eikvakt keep <old incremental>`, retention set to 1 chain / 1 database / 1 partial, `wp eikvakt prune` → the marked incremental and its full backup survive, everything else old is gone; deleting the kept set (or its full backup) on the Backups screen is refused.
- [ ] From the host, the archive, `catalog.json`, the directory and `.work/runner.key` all return 403.
- [ ] Without login: download URL → 400, `ekbm_tick` with a wrong key → 403, `ekbm_migrate` with a wrong token (incl. `../` names) → 403, status/upload AJAX → 400.
- [ ] `debug.log` stays empty while clicking through every screen.
