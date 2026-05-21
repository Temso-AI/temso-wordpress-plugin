#!/usr/bin/env bash
#
# Sync readme.txt with the current release state.
#
# - Bumps `Stable tag:` to match TEMSO_VERSION (single source of truth).
# - Mirrors the latest CHANGELOG.md block into readme.txt's
#   `== Changelog ==` section, flattening Conventional Commit categories
#   (Features stay plain, Bug Fixes get a `Fix:` prefix) and stripping
#   commit-SHA links.
#
# Invoked by .github/workflows/ci.yml right after release-please opens or
# updates its release PR, so the readme in the release commit is consistent
# with the version bump and CHANGELOG.md. Idempotent — safe to re-run; only
# rewrites the file when the result differs.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
README="$ROOT/readme.txt"
CHANGELOG="$ROOT/CHANGELOG.md"
PLUGIN="$ROOT/temso-ai.php"

VERSION="$(grep -oE "define\(\s*'TEMSO_VERSION',\s*'[^']+'" "$PLUGIN" | grep -oE "[0-9][0-9A-Za-z.\-]*")"
if [[ -z "$VERSION" ]]; then
	echo "sync-wp-readme: could not read TEMSO_VERSION from $PLUGIN" >&2
	exit 1
fi

# Pull the topmost release block from CHANGELOG.md — everything after the
# first `## [x.y.z]` heading, up to (but not including) the next one.
LATEST="$(awk '
	/^## \[/ {
		if (seen) exit
		seen = 1
		next
	}
	seen { print }
' "$CHANGELOG")"

# Flatten the Conventional Commit subsections into WP-readme bullets:
#  - `### Features`        → bullets stay as-is
#  - `### Bug Fixes`       → bullets get `Fix: ` prefix
#  - `### Performance ...` → bullets get `Perf: ` prefix
#  - other `### Foo`       → bullets get `Foo: ` prefix
# Commit-SHA links like ` ([abc1234](https://...))` are stripped.
ENTRY_BODY="$(printf '%s\n' "$LATEST" | awk '
	function strip_sha(s,   re) {
		re = " \\(\\[[a-f0-9]+\\]\\(https?://[^)]+\\)\\)"
		gsub(re, "", s)
		return s
	}
	/^### / {
		kind = $0
		sub(/^### +/, "", kind)
		if (kind == "Features")  { prefix = "" }
		else if (kind == "Bug Fixes") { prefix = "Fix: " }
		else if (kind ~ /^Performance/) { prefix = "Perf: " }
		else { prefix = kind ": " }
		next
	}
	/^\* / {
		line = strip_sha($0)
		sub(/^\* +/, "", line)
		printf("* %s%s\n", prefix, line)
		next
	}
' )"

if [[ -z "$ENTRY_BODY" ]]; then
	echo "sync-wp-readme: no bullets extracted from CHANGELOG.md for $VERSION — leaving readme.txt alone" >&2
	exit 0
fi

NEW_ENTRY="= ${VERSION} =
${ENTRY_BODY}"

# Apply the two edits via a Python helper so the changelog insertion is
# regex-safe (entries can contain characters that confuse sed).
TMP_ENTRY="$(mktemp)"
trap 'rm -f "$TMP_ENTRY"' EXIT
printf '%s\n' "$NEW_ENTRY" > "$TMP_ENTRY"

python3 - "$README" "$VERSION" "$TMP_ENTRY" <<'PY'
import re, sys

readme_path, version, entry_path = sys.argv[1], sys.argv[2], sys.argv[3]
text = open(readme_path).read()
entry = open(entry_path).read().rstrip() + "\n"

# 1) Stable tag.
text, n = re.subn(r'(?m)^Stable tag:\s*.*$', f'Stable tag: {version}', text, count=1)
if n == 0:
    sys.exit("sync-wp-readme: no `Stable tag:` line in readme.txt")

# 2) Inject the new = version = block under `== Changelog ==`.
m = re.search(r'(?m)^== Changelog ==\s*\n+', text)
if not m:
    sys.exit("sync-wp-readme: no `== Changelog ==` section in readme.txt")
insert_at = m.end()

# Replace an existing block for this version if present (idempotent re-runs),
# otherwise prepend a fresh one above the previous releases.
existing = re.compile(
    r'(?ms)^= ' + re.escape(version) + r' =\n.*?(?=^= [\w.\-]+ =|^== |\Z)'
)
if existing.search(text):
    text = existing.sub(entry + "\n", text, count=1)
else:
    text = text[:insert_at] + entry + "\n" + text[insert_at:]

open(readme_path, 'w').write(text)
PY

echo "sync-wp-readme: readme.txt synced to $VERSION"
