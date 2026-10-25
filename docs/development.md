# Development environment

Only Docker is needed on the host. PHP, Composer, WP-CLI and Playwright all run
in containers.

## Start

```sh
cp docker-compose.yml-example docker-compose.yml   # gitignored
cp .env.example .env                               # gitignored; ports & credentials
docker compose up -d --build
```

Then install both sites and activate the plugin (commands in the main README).

## Services

| Service | Purpose |
|---|---|
| `db` | MariaDB 11; `dev/mariadb-init/` creates the second database `wordpress_target` on first start |
| `wordpress` | Source site on `WP_PORT` (9190); repo mounted as the plugin |
| `wordpress-target` | Migration target on `WP_TARGET_PORT` (9192), table prefix `wpt_` |
| `wpcli`, `wpcli-target` | WP-CLI per site, uid 33 |
| `ftp` | FTP/FTPS server — host `ftp`, user/password from `.env`, use folder `backups` |
| `sftp` | SFTP server — host `sftp`, port 22, folder `upload/backups` |
| `mailpit` | catches every e-mail, UI on `MAILPIT_PORT` |
| `phpmyadmin` | on `PMA_PORT` |

`dev/docker/*.Dockerfile` add the PHP `ftp` extension to the official
WordPress and WP-CLI images (most hosts have it; the images do not).

## Dev-only must-use plugins (`dev/mu-plugins/`, never shipped)

| File | Why |
|---|---|
| `dev-loopback.php` | Inside Docker, `localhost:9190` is not the web server. Rewrites requests to the published ports to the service names, so loopbacks, the exposure test and migrations between the two sites work. |
| `dev-ekbm-excludes.php` | The repo is mounted as the plugin directory and contains `local_wp_core/`, `db_data/`, `vendor/`, … — excluded from test backups. |
| `dev-mailpit.php` | Routes `wp_mail()` to Mailpit. |

All three only act when `WP_ENVIRONMENT_TYPE` is `local`.

## Gitignored local state

`docker-compose.yml`, `.env`, `local_wp_core/`, `local_wp_target/`, `db_data/`,
`vendor/`, `dist/`, `node_modules/`. Delete `db_data/` and both `local_wp_*`
directories for a clean slate.

## Useful commands

```sh
docker compose exec wpcli wp eikvakt status
docker compose exec wpcli wp eikvakt backup --label=test
docker compose exec wpcli sh -c 'ls -la wp-content/uploads/ekbm-backups-*'
docker compose exec sftp ls -la /home/backup/upload/backups
tail -f local_wp_core/wp-content/debug.log
```

Stress the resumable paths: set *Archive part size* to 10 MB and *Work per
request* to 5 s (Settings → General) and watch a backup in the dashboard.
