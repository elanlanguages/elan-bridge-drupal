#!/usr/bin/env bash

set -euo pipefail

CORE_CONSTRAINT="${1:-}"
DRUSH_CONSTRAINT="${2:-}"
if [[ -z "$CORE_CONSTRAINT" || -z "$DRUSH_CONSTRAINT" ]]; then
  echo "usage: $0 <drupal-core-constraint> <drush-constraint>" >&2
  exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/elan-bridge-composer-smoke.XXXXXX")"
SOURCE="$WORK/connector"
SITE="$WORK/site"

cleanup() {
  case "$WORK" in
    */elan-bridge-composer-smoke.*)
      chmod -R u+w -- "$WORK" 2>/dev/null || true
      rm -rf -- "$WORK"
      ;;
    *)
      echo "warning: refusing to remove unexpected work directory '$WORK'" >&2
      ;;
  esac
}
trap cleanup EXIT

mkdir -p "$SOURCE"
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
  "$ROOT/" "$SOURCE/"

composer create-project \
  "drupal/recommended-project:$CORE_CONSTRAINT" \
  "$SITE" \
  --no-interaction \
  --prefer-dist \
  --no-progress

REPOSITORY="$(printf \
  '{"type":"path","url":"%s","options":{"symlink":false,"versions":{"elan/elan-bridge-drupal":"0.1.0"}}}' \
  "$SOURCE")"
composer --working-dir="$SITE" config repositories.elan-bridge "$REPOSITORY"
composer --working-dir="$SITE" require \
  'elan/elan-bridge-drupal:^0.1' \
  "drush/drush:$DRUSH_CONSTRAINT" \
  --with-all-dependencies \
  --no-interaction \
  --prefer-dist \
  --no-progress

MODULE="$SITE/web/modules/contrib/elan_bridge"
if [[ ! -f "$MODULE/elan_bridge.info.yml" ]]; then
  echo "error: Composer did not install the module at $MODULE" >&2
  exit 1
fi
if [[ -e "$MODULE/vendor" ]]; then
  echo "error: installed module contains a nested vendor directory" >&2
  exit 1
fi

composer --working-dir="$SITE" show --locked drupal/key >/dev/null
composer --working-dir="$SITE" show --locked drupal/tmgmt >/dev/null

mkdir -p "$SITE/web/sites/default/files"
DRUSH="$SITE/vendor/bin/drush"
"$DRUSH" --root="$SITE/web" site:install minimal \
  --db-url='sqlite://sites/default/files/.ht.sqlite' \
  --site-name='ELAN Composer smoke test' \
  --account-name=admin \
  --account-pass=admin \
  --yes
"$DRUSH" --root="$SITE/web" pm:enable elan_bridge --yes
"$DRUSH" --root="$SITE/web" pm:list --status=enabled --type=module \
  --field=name | grep -Fx elan_bridge >/dev/null

echo "Composer installation smoke test passed for Drupal $CORE_CONSTRAINT"
