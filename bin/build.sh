#!/usr/bin/env bash
#
# Package the runnable plugin into a distributable zip.
#
# Default (GitHub distribution), produces under dist/:
#   temso-ai-<version>.zip  - versioned, for handing directly to a client
#   temso-ai.zip            - stable name; this is the asset attached to a
#                             GitHub release, which Temso_Updater downloads
#
# With --wporg (WordPress.org submission), produces under dist/:
#   temso-ai-<version>-wporg.zip - same package, minus
#       includes/github-distribution.php, so TEMSO_GH_REPO is never defined and
#       the self-updater stays inert (WP core is the only update source, as the
#       .org guidelines require). Upload this for review; after approval its
#       contents go to SVN trunk + tags/<version>.
#
# The zip's top-level folder is `temso-ai/` so WordPress installs it under the
# correct plugin slug. File selection honors .distignore (rsync ignores its
# blank/`#` lines), so the shipped package is just the runnable plugin.

set -euo pipefail

WPORG=0
if [[ "${1:-}" == "--wporg" ]]; then
	WPORG=1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SLUG="temso-ai"

VERSION="$(grep -oE "define\(\s*'TEMSO_VERSION',\s*'[^']+'" temso-ai.php | grep -oE "[0-9][0-9A-Za-z.\-]*")"
if [[ -z "${VERSION:-}" ]]; then
	echo "build: could not read TEMSO_VERSION from temso-ai.php" >&2
	exit 1
fi

BUILD_DIR="$ROOT/build"
STAGE="$BUILD_DIR/$SLUG"
DIST="$ROOT/dist"

rm -rf "$BUILD_DIR" "$DIST"
mkdir -p "$STAGE" "$DIST"

WPORG_EXCLUDE=()
if [[ "$WPORG" -eq 1 ]]; then
	# Ship no self-update code at all on wordpress.org: drop the GitHub marker
	# (so TEMSO_GH_REPO stays undefined) and the updater class itself. Both are
	# guarded with file_exists/class_exists so their absence is harmless.
	WPORG_EXCLUDE=(
		--exclude='/includes/github-distribution.php'
		--exclude='/includes/class-temso-updater.php'
	)
fi

rsync -a --exclude-from="$ROOT/.distignore" \
	--exclude='/build' --exclude='/dist' \
	"${WPORG_EXCLUDE[@]}" \
	./ "$STAGE/"

# Keep readme.txt's Stable tag in lockstep with TEMSO_VERSION (the single
# source of truth, bumped by release-please). The wordpress.org readme header
# can't carry release-please's HTML-comment markers, so the value is synced
# here instead of annotated in-file.
sed -i.bak -E "s/^Stable tag:.*/Stable tag: $VERSION/" "$STAGE/readme.txt"
rm -f "$STAGE/readme.txt.bak"

if [[ "$WPORG" -eq 1 ]]; then
	WPORG_ZIP="$DIST/$SLUG-$VERSION-wporg.zip"
	( cd "$BUILD_DIR" && zip -rqX "$WPORG_ZIP" "$SLUG" )
	echo "Built (wordpress.org submission):"
	echo "  $WPORG_ZIP"
	exit 0
fi

VERSIONED="$DIST/$SLUG-$VERSION.zip"
( cd "$BUILD_DIR" && zip -rqX "$VERSIONED" "$SLUG" )
cp "$VERSIONED" "$DIST/$SLUG.zip"

echo "Built:"
echo "  $VERSIONED"
echo "  $DIST/$SLUG.zip"
