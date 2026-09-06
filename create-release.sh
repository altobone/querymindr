#!/bin/bash
set -e

# ---------------------------------------------------------------
# Creates a slim querymindr-release.zip (~20MB) containing only
# the pre-built app files — no source code or node_modules.
# Run this in Replit, then download querymindr-release.zip.
# ---------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RELEASE_DIR="$SCRIPT_DIR/querymindr-release"
ZIP_FILE="$SCRIPT_DIR/querymindr-release.tar.gz"

echo ""
echo "╔══════════════════════════════════════╗"
echo "║   Querymindr - Create Release Zip   ║"
echo "╚══════════════════════════════════════╝"
echo ""

cd "$SCRIPT_DIR"

echo "0/4  Removing prior installer files..."
      "$SCRIPT_DIR"/querymindr-release*.tar.gz
echo "     Done."

echo "1/4  Building frontend..."
NODE_ENV=production BASE_PATH=/querymindr/ pnpm --filter @workspace/file-finder run build
echo "     Done."

echo "2/4  Building server..."
pnpm --filter @workspace/api-server run build
echo "     Done."

echo "3/4  Assembling release package..."
rm -rf "$RELEASE_DIR"
mkdir -p "$RELEASE_DIR/server"
mkdir -p "$RELEASE_DIR/public"
mkdir -p "$RELEASE_DIR/menu-bar"

cp -r artifacts/api-server/dist/* "$RELEASE_DIR/server/"
cp -r artifacts/file-finder/dist/public/* "$RELEASE_DIR/public/"
cp menu-bar/MenuBar.swift "$RELEASE_DIR/menu-bar/"
cp install-querymindr.sh "$RELEASE_DIR/"
cp build-pkg.sh "$RELEASE_DIR/"
echo "     Done."

echo "4/4  Packaging..."
rm -f "$ZIP_FILE"
cd "$SCRIPT_DIR"
tar --exclude="*.DS_Store" -czf querymindr-release.tar.gz querymindr-release/
rm -rf "$RELEASE_DIR"

SIZE=$(du -sh "$SCRIPT_DIR/querymindr-release.tar.gz" | cut -f1)
echo "     Done — $SIZE"
echo ""
echo "╔══════════════════════════════════════╗"
echo "║  querymindr-release.tar.gz is ready ║"
echo "╚══════════════════════════════════════╝"
echo ""
echo "  Download querymindr-release.tar.gz from Replit."
echo "  Extract and install:"
echo ""
echo "    cd ~/Downloads && tar -xzf querymindr-release.tar.gz"
echo "    cd querymindr-release && bash install-querymindr.sh"
echo ""
