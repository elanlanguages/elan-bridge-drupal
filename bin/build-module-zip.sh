#!/usr/bin/env bash

set -euo pipefail

SLUG="elan_bridge"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="$(sed -n -E 's/^version:[[:space:]]*([^[:space:]]+).*/\1/p' "$SLUG.info.yml" | head -1)"
if [[ -z "${VERSION}" ]]; then
  echo "error: could not read version from $SLUG.info.yml" >&2
  exit 1
fi

if [[ "${1:-}" == "--expect" ]]; then
  EXPECT="${2:-}"
  if [[ "${VERSION}" != "${EXPECT}" ]]; then
    echo "error: module version '${VERSION}' does not match tag '${EXPECT}'" >&2
    exit 1
  fi
fi

BUILD="$ROOT/build"
DIST="$ROOT/dist"
rm -rf "$BUILD" "$DIST"
mkdir -p "$BUILD/$SLUG" "$DIST"

rsync -a \
  --exclude '.git' \
  --exclude '.github' \
  --exclude 'vendor' \
  --exclude 'tests' \
  --exclude 'bin' \
  --exclude 'build' \
  --exclude 'dist' \
  --exclude 'composer.lock' \
  --exclude 'phpcs.xml.dist' \
  --exclude 'phpunit.xml.dist' \
  --exclude '.gitignore' \
  --exclude '*.log' \
  --exclude '.DS_Store' \
  ./ "$BUILD/$SLUG/"

( cd "$BUILD" && zip -rq "$DIST/$SLUG.zip" "$SLUG" -x '*.DS_Store' )
cp "$DIST/$SLUG.zip" "$DIST/$SLUG-$VERSION.zip"
rm -rf "$BUILD"

HAS_COMPOSER=false
while IFS= read -r ENTRY; do
  case "$ENTRY" in
    "$SLUG/composer.json")
      HAS_COMPOSER=true
      ;;
    */vendor/*|*/.git/*|*/composer.lock)
      echo "error: release archive contains forbidden path '$ENTRY'" >&2
      exit 1
      ;;
  esac
done < <(unzip -Z1 "$DIST/$SLUG.zip")

if [[ "$HAS_COMPOSER" != true ]]; then
  echo "error: release archive does not contain $SLUG/composer.json" >&2
  exit 1
fi

echo "built $DIST/$SLUG.zip (version ${VERSION})"
