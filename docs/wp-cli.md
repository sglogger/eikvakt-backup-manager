# WP-CLI

All commands run jobs synchronously, without the web time budget.

```sh
wp eikvakt backup [--components=database,core,plugins,themes,uploads,content] [--type=full|auto|partial|database] [--label=<text>]
wp eikvakt list [--format=table|csv|json|yaml]
wp eikvakt restore <id> [--components=…] [--tables=a,b] [--replace='old=>new|old2=>new2'] [--passphrase=…] [--delete-extra] [--wp-config] [--yes]
wp eikvakt migrate <EKBM1-key>
wp eikvakt keep <id> [--remove]
wp eikvakt prune
wp eikvakt rescan
wp eikvakt status
```

Examples:

```sh
# Nightly from system cron instead of WP-Cron:
wp eikvakt backup --components=core,plugins,themes,uploads,content --type=auto
wp eikvakt backup --components=database --type=database

# Move a site:
wp eikvakt migrate EKBM1-eyJ1Ijoi…
wp eikvakt restore example-com_20261019-020000_f_3fa9c2d81b07 --replace='https://old.example=>https://new.example' --yes
```

`wp eikvakt keep` marks a backup (and thereby its chain) so retention never
deletes it; `wp eikvakt list` shows the mark in the `keep` column.

`wp eikvakt status` re-runs the exposure self-test and warns if backups are
downloadable over HTTP.
