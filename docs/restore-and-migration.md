# Restore and migration

![Restore](../screenshots/en/03-restore.png)

## Restore phases

`fetch → decrypt → plan → fetch → decrypt → files → [prune] → database → replace → swap`

| Phase | What happens |
|---|---|
| fetch | Missing set files are downloaded from a destination into the job's work dir (`.part` + rename). First pass: index and dump; second pass: the archive parts `plan` asked for. |
| decrypt | `.enc` files → `plain-<name>` in the work dir, resumable. Wrong passphrase → job fails with a clear message. |
| plan | Reads the index, keeps paths of the selected components, drops protected ones, writes one extraction list per holder set. |
| files | Extracts each entry to `<target>.ekbm-<rand>`, renames into place, restores mtime (so the next incremental does not re-archive everything). |
| prune | Optional: scans the selected components and deletes files not in the index; limited to the `only` folders of partial backups. |
| database | `Sql_Importer` streams the dump into temporary tables `afbt<tag>_<target name>`. |
| replace | Search & replace in the temporary tables; table-prefix fix-ups. |
| swap | Copies this site's `ekbm_*` options into the new options table, then one `RENAME TABLE` swaps all tables atomically; old tables are dropped. |

Never overwritten by a restore: this plugin's own directory and settings, and
`wp-config.php` unless explicitly selected.

If anything fails before the swap, `cleanup()` drops the temporary tables: the
live database is untouched.

## Search & replace

`Search_Replace` (pure, unit-tested):

* expands each pair with its JSON-escaped variant (`https:\/\/…`), longest first;
* recurses into serialised data — `unserialize()` with `allowed_classes => false`,
  so no object from a backup is ever instantiated; objects survive unchanged;
* returns untouched values byte-identical (no float re-serialisation drift);
* falls back to plain replacement for malformed serialised strings.

Tables are processed in batches of 500 rows (keyset on single-column primary
keys, OFFSET otherwise), only text-like columns, `UPDATE` only when a value
changed.

## Table prefix changes

`Sql_Importer::map_tables()` maps `<source prefix><name>` → `<target prefix><name>`.
`Restore_Job::fix_prefix()` renames `<old>user_roles` in options and
`<old>capabilities`, `<old>user_level`, … in usermeta.

## Migration

![Migration](../screenshots/en/04-migration.png)

Three ways to bring a set to another site, all ending in a normal restore with
the old URL/paths pre-filled for replacement:

1. **Migration key** (server to server). The source creates a key for a set
   whose files are local: `EKBM1-` + base64url JSON `{u: admin-ajax URL, t: 256-bit token, b: backup id}`.
   Only `sha256(token)` is stored on the source, with a 24-hour expiry. The target's
   `Pull_Job` calls `admin-ajax.php?action=ekbm_migrate` with `op=list` (chain
   manifests) and `op=chunk&name&offset` (8 MB chunks), verifies sizes, writes
   the manifests last and adds the sets to its catalog.
2. **Shared storage**: same destination configured on both sites → *Rescan all
   locations* on the target.
3. **Upload**: files downloaded from the source are uploaded on the Backups
   screen in 8 MB chunks (`wp_ajax_ekbm_upload`); the manifest goes last.

Selective migration = choosing components/tables on the Restore screen.
