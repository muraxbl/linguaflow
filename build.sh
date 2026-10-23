#!/usr/bin/env bash
# Builds an installable zip (linguaflowai.zip) with the production dependencies bundled.
set -euo pipefail

cd "$(dirname "$0")"
BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT

mkdir -p "$BUILD_DIR/linguaflowai"
tar --exclude='./.git' --exclude='./.github' --exclude='./vendor' --exclude='./*.zip' --exclude='./build.sh' \
    --exclude='./.env' --exclude='./.env.*' -cf - . | tar -xf - -C "$BUILD_DIR/linguaflowai"
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$BUILD_DIR/linguaflowai"

rm -f linguaflowai.zip
(cd "$BUILD_DIR" && zip -qr - linguaflowai) > linguaflowai.zip
echo "Built $(pwd)/linguaflowai.zip"
