#!/usr/bin/env bash
#
# Prepare a release: bump composer.json version and date the CHANGELOG entry.
#
# Run this ON your release/x.x.x branch (the one Tower's Git-flow creates),
# AFTER you've written the release notes under "## <version> - Unreleased"
# and BEFORE you finish the release in Tower.
#
# It only edits files. It does NOT commit, tag, or push — Tower's "finish
# release" does that (merging into dev-craft-5 + craft-5 and tagging).
#
# Usage:
#   ./scripts/prepare-release.sh            # version inferred from branch name
#   ./scripts/prepare-release.sh 1.2.0      # explicit version

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

# ---------------------------------------------------------------------------
# Resolve the version
# ---------------------------------------------------------------------------
VERSION="${1:-}"

if [[ -z "$VERSION" ]]; then
    BRANCH="$(git branch --show-current)"
    if [[ "$BRANCH" =~ ^release/(.+)$ ]]; then
        VERSION="${BASH_REMATCH[1]}"
    else
        echo "Error: not on a release/* branch and no version was given." >&2
        echo "Usage: $0 [version]   e.g. $0 1.2.0" >&2
        exit 1
    fi
fi

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "Error: '$VERSION' is not a valid semver version (expected e.g. 1.2.0)." >&2
    exit 1
fi

TODAY="$(date +%F)"
VERSION_RE="${VERSION//./\\.}"

# ---------------------------------------------------------------------------
# Validate preconditions BEFORE touching anything (so we never half-apply)
# ---------------------------------------------------------------------------
if ! grep -qE '^[[:space:]]*"version":' composer.json; then
    echo "Error: no \"version\" field found in composer.json." >&2
    exit 1
fi

if ! grep -qE "^## +${VERSION_RE} +- +Unreleased[[:space:]]*$" CHANGELOG.md; then
    echo "Error: no '## ${VERSION} - Unreleased' heading found in CHANGELOG.md." >&2
    echo "       Add your release notes under that heading first." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Apply
# ---------------------------------------------------------------------------
echo "Preparing release ${VERSION} (${TODAY})"

VERSION="$VERSION" perl -i -pe \
    'if (!$done && s/("version":\s*")[^"]*(")/$1 . $ENV{VERSION} . $2/e) { $done = 1 }' \
    composer.json
echo "  composer.json  -> version ${VERSION}"

VERSION="$VERSION" TODAY="$TODAY" perl -i -pe \
    's/^(## +\Q$ENV{VERSION}\E +- +)Unreleased[ \t]*$/$1 . $ENV{TODAY}/e' \
    CHANGELOG.md
echo "  CHANGELOG.md   -> ${VERSION} dated ${TODAY}"

echo ""
echo "Done. Review the diff, commit on this release branch, then finish the"
echo "release in Tower (merges into dev-craft-5 + craft-5, tags ${VERSION})."
echo "Pushing the tag triggers the GitHub Release + Packagist update."
