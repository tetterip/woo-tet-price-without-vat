#!/usr/bin/env bash
set -euo pipefail

SLUG="woo-tet-price-without-vat"
PLUGIN_FILE="${SLUG}.php"

VERSION=$(grep -m1 "Version:" "$PLUGIN_FILE" | sed 's/.*Version: *//' | tr -d '[:space:]')

if [ -z "$VERSION" ]; then
    echo "Error: could not read version from $PLUGIN_FILE" >&2
    exit 1
fi

mkdir -p dist
OUTPUT="dist/${SLUG}-v${VERSION}.zip"

git archive --format=zip --prefix="${SLUG}/" HEAD -o "$OUTPUT"

echo "Built: $OUTPUT"
