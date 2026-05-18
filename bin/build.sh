#!/usr/bin/env bash
#
# Package the runnable plugin into a distributable zip.
#
# Produces, under dist/:
#   temso-ai-<version>.zip  - versioned, for handing directly to a client
#   temso-ai.zip            - stable name; this is the asset attached to a
#                             GitHub release, which Temso_Updater downloads
#
# The zip's top-level folder is `temso/` so WordPress installs it under the
# correct plugin slug. File selection honors .distignore (rsync ignores its
# blank/`#` lines), so the shipped package is just the runnable plugin.

set -euo pipefail

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

rsync -a --exclude-from="$ROOT/.distignore" \
	--exclude='/build' --exclude='/dist' \
	./ "$STAGE/"

VERSIONED="$DIST/$SLUG-$VERSION.zip"
( cd "$BUILD_DIR" && zip -rqX "$VERSIONED" "$SLUG" )
cp "$VERSIONED" "$DIST/$SLUG.zip"

echo "Built:"
echo "  $VERSIONED"
echo "  $DIST/$SLUG.zip"
