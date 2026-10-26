#!/usr/bin/env bash
# Builds an installable zip (linguaflowai.zip) for the PrestaShop module manager.
set -euo pipefail

cd "$(dirname "$0")"
BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT

mkdir -p "$BUILD_DIR/linguaflowai"
# Package only versioned runtime files; ignored backups, credentials and local tools never enter the zip.
git ls-files -z -- linguaflowai.php composer.json composer.lock vendor src controllers views config upgrade logo.png README.md SECURITY.md \
  | tar --exclude='*/.env*' --exclude='*.pem' --exclude='*.key' --exclude='*.log' --null -T - -cf - \
  | tar -xf - -C "$BUILD_DIR/linguaflowai"
if command -v composer >/dev/null; then
  composer dump-autoload --no-dev --optimize --no-interaction --working-dir="$BUILD_DIR/linguaflowai"
fi

rm -f linguaflowai.zip
(cd "$BUILD_DIR" && zip -qr - linguaflowai) > linguaflowai.zip
echo "Built $(pwd)/linguaflowai.zip"
