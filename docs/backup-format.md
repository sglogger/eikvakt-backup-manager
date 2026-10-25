# Backup format

Format version 1 (`EKBM_FORMAT_VERSION`). A restore refuses newer versions.

## A backup set

```
<id>.manifest.json          JSON, never encrypted, no secrets — written last
<id>.index.gz[.enc]         file index (sets with file components)
<id>.files-001.zip[.enc]    ZIP parts with the archived files
<id>.files-002.zip[.enc]
<id>.db.sql.gz[.enc]        SQL dump (sets with the database component)
```

`<id>` = `<site-slug>_<yyyymmdd-hhmmss UTC>_<f|i|p|d>_<12 hex random>`, e.g.
`example-com_20261019-020000_i_3fa9c2d81b07`. The random part makes file names
unguessable; the letter is the type. Ids are validated with
`Manifest::is_valid_id()` before they touch a path or remote object name.

## Manifest

```json
{
  "format": 1,
  "id": "example-com_20261019-020000_i_3fa9c2d81b07",
  "type": "incremental",                 // full | incremental | partial | database
  "parent": "example-com_20261018-020000_i_…",
  "chain": "example-com_20261013-020000_f_…",
  "created": 1792375200, "trigger": "schedule", "label": "",
  "components": ["core","plugins","themes","uploads","content"],
  "tables": [],
  "only": ["content/plugins/akismet"],   // partial backups limited to folders
  "encrypted": true, "enc_files": false,
  "files": [{"name": "…index.gz", "kind": "index", "size": 48519}, …],
  "stats": {"files": 3895, "changed": 2, "deleted": 1, "bytes": 300012, "rows": 0},
  "site": {"home": "…", "siteurl": "…", "abspath": "…", "content_dir": "…",
           "table_prefix": "wp_", "wp_version": "…", "key": "<storage key>", …},
  "complete": true
}
```

## Keep marks

Whether a set is marked "keep" is stored in the catalog entry
(`catalog.json` → `{"manifest": …, "locations": […], "keep": true}`), not in the
manifest file: marking never rewrites or re-uploads anything. `Catalog::get()`
and `all()` copy the flag into `Manifest::$data['keep']` for the policy code;
`Catalog::put()` strips it again. A catalog rebuilt from scratch starts with no
marks.

## Archive paths

Every file is stored under one of two logical roots so a restore works when
wp-content lives elsewhere on the target:

* `root/<path>` — relative to ABSPATH (wp-content excluded)
* `content/<path>` — relative to WP_CONTENT_DIR

`Components::classify()` derives the component from the path alone:
`content/plugins/`, `content/mu-plugins/` → plugins; `content/themes/` → themes;
`content/uploads/` → uploads; other `content/` → content; `root/` → core.

## File index

gzip, one line per file: `path TAB size TAB mtime TAB holder`, first line
`#ekbm-index 1`. *holder* is the id of the set that contains the file's content.

## Chains

* A **full** backup indexes and archives everything; `holder` = its own id.
* An **incremental** backup indexes everything again but archives only files
  whose size or mtime differ from the previous index; unchanged files keep the
  previous holder, deleted files are absent from the index.
* Restoring any set needs its index and the parts of every holder it names —
  never more. `File_Index::diff()` is the single function implementing this.
* The newest index of a chain is cached unencrypted in `.work/index-cache/` so
  the next incremental can diff without downloading or decrypting anything.

## SQL dump

```
-- ekbm-dump 1 prefix=wp_
/*!40101 SET NAMES utf8mb4 */;
SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
DROP TABLE IF EXISTS `wp_options`;
CREATE TABLE `wp_options` (…);
INSERT INTO `wp_options` VALUES (…),(…);      ≤ 1 MB per statement
```

Exactly one statement per line (values are escaped by the driver, so newlines
appear as `\n`). Each request appends its own gzip member. Rows are read with
keyset pagination on integer primary keys, OFFSET otherwise. Views, triggers
and routines are not dumped. The plugin's own tables are never dumped.

## Encryption

`Encryptor`, file extension `.enc`:

```
header  "EKBMENC1" | kdf u8 (2 = PBKDF2-SHA256) | salt 16 | iterations u32 | reserved u32
chunk   final u8 | nonce 24 | length u32 | XChaCha20-Poly1305 ciphertext (≤ 1 MiB + 16)
AD      sha256(header) | chunk index u64 | final u8
```

* Key: PBKDF2-HMAC-SHA256, 600 000 iterations, per-file salt.
* Independent chunks → resumable without persisting cipher state.
* The AD binds header, position and final flag: reordering, dropping,
  duplicating, swapping between files and truncation are all detected.
* `Encryptor::verify()` checks a passphrase against the first chunk.
* What is encrypted: the dump whenever encryption is on; index and ZIP parts
  additionally with "also encrypt file archives". The manifest never.
