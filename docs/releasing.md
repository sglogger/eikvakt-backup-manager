# Releasing

1. **Version** in three places that must agree (both the script and CI refuse
   otherwise): `Version:` header and `EKBM_VERSION` in
   `eikvakt-backup-manager.php`, `Stable tag:` in `readme.txt`. Bump
   `EKBM_DATA_VERSION` only when options or the schema need migrating
   (`Installer::maybe_migrate()`); bump `EKBM_FORMAT_VERSION` only when the
   on-disk backup format changes incompatibly.
2. **Changelog**: `CHANGELOG.md` (Keep a Changelog) and mirror into readme.txt
   `== Changelog ==`. Update `Tested up to`.
3. **QA**: PHPUnit, PHPCS, the end-to-end checklist in [testing](testing.md),
   Plugin Check on the built ZIP.
4. **Build** locally: `./package-plugin.sh [-o DIR] [--keep-stage]`.
   - allow-list staging (`eikvakt-backup-manager.php uninstall.php index.php readme.txt LICENSE.txt composer.json includes admin assets`)
   - `composer install --no-dev` in the stage (local Composer or `composer:2` in Docker); `paragonie/random_compat` is `replace`d in composer.json — a PHP 5 polyfill phpseclib requires, useless on PHP 8.1, and the source of a `build-phar.sh` WordPress.org refuses
   - prunes vendor tests/docs, build scripts, PHAR files and tool configs, `.po/.pot/.mo`, the 1024 px and 64 px icons
   - fails on file types WordPress.org refuses (`.sh`, `.phar`, `.exe`, …) anywhere in the ZIP
   - fails on any dev file (`docker-compose`, `local_wp_*`, `db_data`, `tests`, `dev`, `docs`, …) or missing runtime dependency (phpseclib)
5. **Publish**: push to `main`. `auto-tag.yml` creates tag `v<version>` and a
   GitHub release with the same ZIP; `deploy-to-wordpress-org.yml` syncs trunk,
   the tag and `.wordpress-org/` assets (icons, banners, screenshots) to SVN
   (`SVN_USERNAME` / `SVN_PASSWORD` secrets; supports a dry run).

Store assets in `.wordpress-org/` are regenerated with `dev/screenshots/run.sh`.
