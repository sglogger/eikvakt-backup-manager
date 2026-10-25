# WordPress.org assets

Synced to the plugin's SVN `assets/` directory by
`.github/workflows/deploy-to-wordpress-org.yml`. Never part of the plugin ZIP.

| File | Size | Source |
|---|---|---|
| `icon-128x128.png`, `icon-256x256.png` | 128², 256² | rendered from `assets/logos/eikvakt-backup-manager-icon.svg` |
| `banner-772x250.png`, `banner-1544x500.png` | 772×250, 1544×500 | rendered (`dev/screenshots/render-icons.mjs`) |
| `screenshot-1.png` … `screenshot-9.png` | 1360 wide | English screens, captions in readme.txt `== Screenshots ==` |

Regenerate everything with `dev/screenshots/run.sh` (Docker dev environment running).
