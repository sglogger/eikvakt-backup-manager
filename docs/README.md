# Eikvakt Backup Manager — developer documentation

For people working on the plugin's code. User-facing documentation is the
plugin's own screens and `readme.txt`.

| Document | Read it when you… |
|---|---|
| [Architecture](architecture.md) | need the big picture: components, data, request flow, scheduling, retention, reports |
| [Job engine](job-engine.md) | touch anything that runs in the background, or add a job type |
| [Backup format](backup-format.md) | change what is written to disk: manifest, index, chains, archives, dump, encryption |
| [Storage drivers](storage-drivers.md) | fix a destination or add a new one |
| [Restore and migration](restore-and-migration.md) | work on restore, search & replace or migration |
| [Security](security.md) | review or change anything that guards backups or credentials |
| [Hooks](hooks.md) | extend the plugin from outside |
| [WP-CLI](wp-cli.md) | script backups or debug from the shell |
| [Development environment](development.md) | set up Docker, the two sites and the storage servers |
| [Testing](testing.md) | run or extend unit, browser and end-to-end tests |
| [Releasing](releasing.md) | build the ZIP and publish |
| [Translations](translations.md) | add or update strings and languages |

Screenshots of every screen, in English and German, are in
[`../screenshots`](../screenshots).
