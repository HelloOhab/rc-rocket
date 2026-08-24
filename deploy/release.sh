#!/usr/bin/env bash
# Build a release zip and refresh the manifest.
#   ./deploy/release.sh 0.6.0
set -euo pipefail

VERSION="${1:?usage: release.sh <version>}"
BASE="https://updates.youragency.com/rc-rocket"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dist"

# The version in the header is the source of truth; refuse to ship a mismatch.
HEADER=$(grep -oP '(?<=Version:           )[0-9.]+' "$ROOT/rc-rocket.php")
if [ "$HEADER" != "$VERSION" ]; then
  echo "rc-rocket.php says $HEADER, you asked for $VERSION. Fix the header first." >&2
  exit 1
fi

# Never ship a build that fails its own tests.
for f in "$ROOT"/tests/run*.php; do
  php "$f" >/dev/null || { echo "Tests failed: $f" >&2; exit 1; }
done

npm --prefix "$ROOT" run build

rm -rf "$OUT" && mkdir -p "$OUT/rc-rocket"
cd "$ROOT"
find . -type f \
  -not -path './node_modules/*' -not -path './dist/*' -not -path './.git/*' \
  -not -name 'package-lock.json' \
  -exec cp --parents {} "$OUT/rc-rocket/" \;

cd "$OUT" && zip -rq "rc-rocket-$VERSION.zip" rc-rocket && rm -rf rc-rocket

sed -e "s|\"version\": \"[^\"]*\"|\"version\": \"$VERSION\"|" \
    -e "s|rc-rocket-[0-9.]*\.zip|rc-rocket-$VERSION.zip|" \
    "$ROOT/deploy/rc-rocket.json" > "$OUT/rc-rocket.json"

echo "Built $OUT/rc-rocket-$VERSION.zip"
echo "Upload both files to $BASE/ and every site will offer the update within six hours."
