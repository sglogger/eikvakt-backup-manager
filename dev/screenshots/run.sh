#!/usr/bin/env bash
#
# Render icons and capture documentation screenshots.
#
# Needs the Docker dev environment running (docker compose up -d) with
# WordPress installed and the plugin active. Everything else runs in the
# official Playwright image; nothing is installed on the host.
#
#   dev/screenshots/run.sh            # icons + screenshots (en, de)
#   dev/screenshots/run.sh icons      # icons and banners only
#   dev/screenshots/run.sh smoke      # browser smoke test of the click paths
#
set -euo pipefail

HERE="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( cd "$HERE/../.." && pwd )"
IMAGE='mcr.microsoft.com/playwright:v1.63.0-noble'
cd "$ROOT"

WP_CONTAINER="$( docker compose ps -q wordpress )"
[ -n "$WP_CONTAINER" ] || { echo 'Start the dev environment first: docker compose up -d' >&2; exit 1; }

playwright() {
	docker run --rm --ipc=host -u "$(id -u):$(id -g)" -e HOME=/tmp \
		-v "$ROOT":/work -w /work/dev/screenshots "$@"
}

if [ ! -d "$HERE/node_modules/playwright" ]; then
	playwright "$IMAGE" npm install --no-audit --no-fund
fi

if [ "${1:-}" = 'smoke' ]; then
	playwright --network "container:$WP_CONTAINER" "$IMAGE" node smoke.mjs
	exit 0
fi

playwright "$IMAGE" node render-icons.mjs
[ "${1:-}" = 'icons' ] && exit 0

wp() { docker compose exec -T wpcli wp "$@"; }

# Ids for the restore screen and a report detail with a full log.
RESTORE_ID="$( wp eikvakt list --format=csv | awk -F, 'NR>1 && /incremental/ {print $1; exit}' )"
[ -n "$RESTORE_ID" ] || RESTORE_ID="$( wp eikvakt list --format=csv | awk -F, 'NR==2 {print $1}' )"
RUN_ID="$( wp db query "SELECT id FROM $(wp db prefix)ekbm_runs WHERE kind='backup' AND status='success' ORDER BY id DESC LIMIT 1" --skip-column-names | tr -d '\r' )"

for LOCALE in en de; do
	if [ "$LOCALE" = 'de' ]; then wp user meta update admin locale de_DE >/dev/null; else wp user meta delete admin locale >/dev/null 2>&1 || true; fi
	playwright --network "container:$WP_CONTAINER" \
		-e LOCALE="$LOCALE" -e RESTORE_ID="$RESTORE_ID" -e RUN_ID="$RUN_ID" \
		"$IMAGE" node capture.mjs
done

wp user meta delete admin locale >/dev/null 2>&1 || true
echo "Screenshots written to screenshots/en and screenshots/de."
