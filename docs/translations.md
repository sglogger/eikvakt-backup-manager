# Translations

* Source strings are English, text domain `eikvakt-backup-manager`.
* No `load_plugin_textdomain()`: WordPress loads translations just in time,
  and WordPress.org delivers them to `wp-content/languages/plugins/`.
* No `.po/.mo` files ship in the ZIP (`package-plugin.sh` fails if one does).

## Files

| File | Purpose |
|---|---|
| `languages/eikvakt-backup-manager.pot` | template, regenerated before each release |
| `dev/translations/eikvakt-backup-manager-de_DE.po` | German (informal "du", WordPress de_DE glossary), to import into translate.wordpress.org |

## Regenerate the template

```sh
docker compose exec wpcli wp i18n make-pot wp-content/plugins/eikvakt-backup-manager \
  wp-content/plugins/eikvakt-backup-manager/languages/eikvakt-backup-manager.pot \
  --exclude=vendor,local_wp_core,local_wp_target,db_data,dev,tests,docs,dist,node_modules
```

Then merge into the German file (`msgmerge --update dev/translations/eikvakt-backup-manager-de_DE.po languages/eikvakt-backup-manager.pot`) and translate new entries.

## Test German locally

```sh
docker compose exec wpcli wp language core install de_DE
docker compose exec wpcli wp i18n make-mo wp-content/plugins/eikvakt-backup-manager/dev/translations/eikvakt-backup-manager-de_DE.po wp-content/languages/plugins/
docker compose exec wpcli wp user meta update admin locale de_DE
```

## Rules for new strings

* `printf`/`sprintf` with placeholders, never concatenation; numbered
  placeholders when there are several.
* A `/* translators: */` comment above every string with placeholders.
* `_n()` for plurals — with the number placeholder in the singular too.
* Strings for JavaScript are passed through `wp_localize_script()`.
