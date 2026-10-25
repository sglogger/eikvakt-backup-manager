#!/usr/bin/env bash
#
# Build the distributable plugin ZIP from this repository.
#
# This is the local twin of .github/workflows/auto-tag.yml: same version
# cross-check, same allow list, same pruning, same refusal to ship dev
# scaffolding. A ZIP built here should be byte-for-byte equivalent in content
# to the one the release workflow attaches to a tag — so it can be handed to a
# site, uploaded to WordPress.org, or checked with Plugin Check without any
# "but CI does it differently" caveat.
#
# Nothing in the working tree is modified: staging happens in a temporary
# directory and vendor/ is installed there, so the dev dependencies in the
# repository's own vendor/ stay where they are.
#
# Usage: ./package-plugin.sh [-o OUTPUT] [--keep-stage] [-h]

set -euo pipefail

SLUG='eikvakt-backup-manager'
ROOT="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" && pwd )"
OUTPUT=''
KEEP_STAGE=0

usage() {
	cat <<-USAGE
		Usage: ${0##*/} [-o OUTPUT] [--keep-stage]

		  -o, --output OUTPUT   Write the ZIP here (file path, or a directory).
		                        Default: dist/${SLUG}-<version>.zip
		      --keep-stage      Leave the staged plugin tree in place and print
		                        its path, for inspecting what would ship.
		  -h, --help            Show this text.
	USAGE
}

while [ $# -gt 0 ]; do
	case "$1" in
		-o|--output) OUTPUT="${2:-}"; shift 2 ;;
		--keep-stage) KEEP_STAGE=1; shift ;;
		-h|--help) usage; exit 0 ;;
		*) printf 'Unknown option: %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
	esac
done

cd "$ROOT"

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mwarning:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

# -----------------------------------------------------------------------------
# Version
#
# It lives in three places that must never drift apart: the plugin header, the
# runtime constant, and readme.txt's Stable tag — which is the one WordPress.org
# serves as the current release. Disagreement is a release bug, so stop here
# rather than build a ZIP that lies about which version it is.
# -----------------------------------------------------------------------------

HEADER=$(grep -E '^\s*\*\s*Version:' "$SLUG.php" | head -n1 | awk -F: '{print $2}' | xargs)
CONST=$(grep -E "define\(\s*'EKBM_VERSION'" "$SLUG.php" | sed -E "s/.*'([0-9.]+)'.*/\1/")
STABLE=$(grep -E '^Stable tag:' readme.txt | awk -F: '{print $2}' | xargs)

[ -n "$HEADER" ] || die "could not find the Version header in $SLUG.php"
[ "$HEADER" = "$CONST" ]  || die "Version header ($HEADER) and EKBM_VERSION ($CONST) disagree"
[ "$HEADER" = "$STABLE" ] || die "Version header ($HEADER) and readme.txt Stable tag ($STABLE) disagree"

VERSION="$HEADER"
say "Packaging $SLUG $VERSION"

# -----------------------------------------------------------------------------
# Stage
#
# Deliberately an ALLOW list, not an exclude list. With excludes, every file
# added to the repository later — a doc, a scratch script, a new tool config —
# silently ends up in the shipped plugin. With an allow list it cannot:
# anything not named here is simply never copied.
# -----------------------------------------------------------------------------

STAGE_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}-package.XXXXXX")
STAGE="$STAGE_ROOT/$SLUG"
cleanup() { [ "$KEEP_STAGE" -eq 1 ] || rm -rf "$STAGE_ROOT"; }
trap cleanup EXIT

mkdir -p "$STAGE"

# composer.json ships on purpose: vendor/ is a Composer-built tree and part of
# the plugin, so the manifest describing it has to travel with it — Plugin
# Check flags a vendor/ directory whose composer.json is missing. composer.lock
# is staged only long enough to pin the install, then removed: it names dev
# packages that are not in the ZIP.
say 'Staging plugin files'
for path in \
	"$SLUG.php" \
	uninstall.php \
	index.php \
	readme.txt \
	LICENSE.txt \
	composer.json
do
	[ -f "$path" ] || die "required file $path is missing"
	cp "$path" "$STAGE/"
done

for dir in includes admin assets; do
	[ -d "$dir" ] || die "required directory $dir is missing"
	cp -R "$dir" "$STAGE/"
done

# -----------------------------------------------------------------------------
# Runtime dependencies
#
# Installed into the staging directory, never into the repository's own
# vendor/, which holds the dev toolchain this script must not disturb.
# -----------------------------------------------------------------------------

[ -f composer.lock ] || die 'composer.lock is missing — the install would not be reproducible'
cp composer.lock "$STAGE/"

COMPOSER_ARGS='install --no-dev --no-interaction --no-progress --optimize-autoloader --classmap-authoritative --prefer-dist'

if command -v composer >/dev/null 2>&1; then
	say 'Installing runtime dependencies (composer)'
	( cd "$STAGE" && composer $COMPOSER_ARGS )
elif command -v docker >/dev/null 2>&1; then
	say 'Installing runtime dependencies (composer:2 in Docker — no local composer found)'
	docker run --rm \
		-u "$(id -u):$(id -g)" \
		-v "$STAGE":/app \
		-e COMPOSER_HOME=/tmp/composer \
		composer:2 $COMPOSER_ARGS
else
	die 'neither composer nor docker is available, and vendor/ ships with this plugin'
fi

rm -f "$STAGE/composer.lock"

# A dev package in the ZIP is a supply-chain surprise for whoever installs it.
[ -f "$STAGE/vendor/autoload.php" ] || die 'vendor/autoload.php missing after install'
for d in phpunit squizlabs wp-coding-standards phpcompatibility dealerdirect; do
	[ ! -d "$STAGE/vendor/$d" ] || die "dev package vendor/$d present — refusing to ship"
done

# ...and a missing runtime one is worse: the feature it backs would fail at the
# moment a user reaches for it rather than at build time.
for f in \
	vendor/phpseclib/phpseclib/phpseclib/Net/SFTP.php \
	vendor/phpseclib/phpseclib/phpseclib/Crypt/PublicKeyLoader.php
do
	[ -f "$STAGE/$f" ] || die "runtime dependency $f missing after install"
done

# -----------------------------------------------------------------------------
# Translations
#
# None are bundled, deliberately. A plugin hosted on WordPress.org gets its
# translations from translate.wordpress.org, which generates and delivers them
# per locale through the ordinary update system. Shipping .po/.mo files
# alongside that only duplicates it, so the ZIP carries none — the guard below
# fails the build if any reappear.
# -----------------------------------------------------------------------------

# -----------------------------------------------------------------------------
# Prune
# -----------------------------------------------------------------------------

say 'Pruning'

# Third-party packages ship their own test suites and docs. None of it is
# needed at runtime and it is a meaningful share of the ZIP.
#
# The underscore-prefixed names are kept from the sibling plugin's script on
# purpose: a test or demo directory in a dependency can hold a PHP file that
# answers requests on its own, outside WordPress — exactly what the review
# guidelines are about — so none of them may reach the ZIP.
find "$STAGE/vendor" -type d \
	\( -name tests -o -name test -o -name _test -o -name _tests \
	   -o -name doc -o -name docs \
	   -o -name examples -o -name .github -o -name bin \) \
	-prune -exec rm -rf {} + 2>/dev/null || true

# Build scripts, archives and tool configs that dependencies carry along.
# WordPress.org refuses plugin ZIPs containing executable scripts such as
# *.sh or *.phar ("unexpected files"), and none of this runs in a plugin.
find "$STAGE/vendor" -type f \( -name '*.sh' -o -name '*.phar' -o -name '*.phar.*' \
	-o -name 'psalm*.xml' -o -name 'psalm-*.php' -o -name 'phpstan*' -o -name 'phpunit*' \
	-o -name '*.yml' -o -name '*.yaml' -o -name '*.dist' -o -name 'Makefile' \
	-o -name '.editorconfig' -o -name '.gitattributes' -o -name '.gitignore' \) \
	-delete 2>/dev/null || true

find "$STAGE" \( -name '*.md' -o -name '*.po' -o -name '*.pot' -o -name '.DS_Store' \
                 -o -name '.gitignore' -o -name '.gitattributes' \
                 -o -name 'phpunit.xml*' -o -name '.editorconfig' \) \
	-delete 2>/dev/null || true

# The 1024px master and the 64px Dropbox branding icon are source assets for
# store and app-console listings, not runtime ones: nothing in the plugin
# references them.
rm -f "$STAGE/assets/logos/"*-1024x1024.png "$STAGE/assets/logos/"*-64x64.png

# -----------------------------------------------------------------------------
# Verify
#
# A ZIP that carries dev scaffolding is a packaging bug. Fail rather than ship.
# -----------------------------------------------------------------------------

say 'Verifying the staged tree'

ANYWHERE='docker-compose|CLAUDE\.md|/\.env|local_wp_core|local_wp_target|db_data|node_modules|/\.git|/_?tests?/|/steven/|\.pot?$|\.mo$|\.zip$|\.Dockerfile$'
if find "$STAGE" | grep -Ei "$ANYWHERE"; then
	die 'development files found in the staged plugin (listed above)'
fi

# File types the WordPress.org upload refuses, anywhere in the tree.
FORBIDDEN_TYPES='\.(sh|phar|exe|bat|cmd|ps1|py|rb|pl|jar|so|dll|dylib|bin|com|msi)$'
if find "$STAGE" -type f | grep -Ei "$FORBIDDEN_TYPES"; then
	die 'files of a type WordPress.org does not accept are in the staged plugin (listed above)'
fi

# Tooling config is only forbidden at the plugin root: a vendored package
# legitimately carries its own composer.json, and deleting files from inside a
# dependency is how you break it.
for unwanted in composer.lock phpunit.xml.dist phpcs.xml.dist docker-compose.yml-example .env.example README.md CHANGELOG.md; do
	[ ! -e "$STAGE/$unwanted" ] || die "$unwanted must not ship inside the plugin"
done

for unwanted in tests dev docs .github .wordpress-org steven local_wp_core local_wp_target db_data screenshots languages .phpunit.cache; do
	[ ! -e "$STAGE/$unwanted" ] || die "directory $unwanted must not ship inside the plugin"
done

# A WordPress plugin is identified by its header; without this the ZIP is not
# installable at all.
grep -q '^\s*\*\s*Plugin Name:' "$STAGE/$SLUG.php" \
	|| die 'plugin header missing from the staged bootstrap'

[ -f "$STAGE/composer.json" ] || die 'composer.json missing — vendor/ ships, so its manifest must too'

# -----------------------------------------------------------------------------
# Zip
# -----------------------------------------------------------------------------

if [ -z "$OUTPUT" ]; then
	OUTPUT="$ROOT/dist/$SLUG-$VERSION.zip"
elif [ -d "$OUTPUT" ]; then
	OUTPUT="${OUTPUT%/}/$SLUG-$VERSION.zip"
fi

mkdir -p "$(dirname "$OUTPUT")"
rm -f "$OUTPUT"

say 'Building the ZIP'
command -v zip >/dev/null 2>&1 || die 'zip is not installed'
( cd "$STAGE_ROOT" && zip -rqX "$OUTPUT" "$SLUG" -x '*.DS_Store' )

printf '\n\033[1;32m✓\033[0m %s\n' "$OUTPUT"
printf '  %s files, %s\n' \
	"$(unzip -l "$OUTPUT" | tail -n1 | awk '{print $2}')" \
	"$(du -h "$OUTPUT" | cut -f1 | xargs)"

if [ "$KEEP_STAGE" -eq 1 ]; then
	printf '  staged tree kept at %s\n' "$STAGE"
fi
