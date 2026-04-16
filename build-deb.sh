#!/bin/bash
set -e

# ---------------------------------------------------------------
# Querymindr — Build Linux .deb Installer Package
#
# Run this on a Linux machine (or in the Replit environment)
# from inside the querymindr-release folder to produce
# Querymindr.deb — a double-clickable installer for
# Ubuntu, Debian, and most Debian-based Linux distributions.
#
# Prerequisites:
#   - dpkg-deb (usually pre-installed on Debian/Ubuntu)
#
# Output: Querymindr_1.0_amd64.deb  (~50 MB, self-contained)
# ---------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PKG_NAME="querymindr-linux"
PKG_VERSION="1.0"
MAINTAINER="Music Savvy <support@musicsavvy.com>"
DESCRIPTION="Local file search for large external drives"
INSTALL_PREFIX="/usr/local/lib/querymindr"
NODE_VERSION="22.13.1"

# Detect architecture
ARCH_RAW="$(uname -m)"
if [ "$ARCH_RAW" = "aarch64" ] || [ "$ARCH_RAW" = "arm64" ]; then
  DEB_ARCH="arm64"
  NODE_ARCH="arm64"
else
  DEB_ARCH="amd64"
  NODE_ARCH="x64"
fi

NODE_TARBALL="node-v${NODE_VERSION}-linux-${NODE_ARCH}.tar.gz"
NODE_URL="https://nodejs.org/dist/v${NODE_VERSION}/${NODE_TARBALL}"
OUTPUT="$SCRIPT_DIR/${PKG_NAME}_${PKG_VERSION}_${DEB_ARCH}.deb"
STAGING="$SCRIPT_DIR/.deb-staging"

echo ""
echo "╔══════════════════════════════════════╗"
echo "║   Querymindr — Build .deb Installer  ║"
echo "╚══════════════════════════════════════╝"
echo ""
echo "  Architecture : $ARCH_RAW ($DEB_ARCH)"
echo "  Node.js      : v$NODE_VERSION ($NODE_ARCH)"
echo "  Output       : $OUTPUT"
echo ""

# ---------------------------------------------------------------
# Check tools
# ---------------------------------------------------------------
if ! command -v dpkg-deb &>/dev/null; then
  echo "ERROR: dpkg-deb not found."
  echo "Install with: sudo apt-get install dpkg"
  exit 1
fi

if [ ! -f "$SCRIPT_DIR/server/index.mjs" ]; then
  echo "ERROR: server/index.mjs not found."
  echo "Run this script from inside the querymindr-release folder."
  exit 1
fi

# ---------------------------------------------------------------
# Prepare staging directory
# ---------------------------------------------------------------
echo "1/5  Preparing staging area..."
rm -rf "$STAGING" "$OUTPUT"
mkdir -p "$STAGING$INSTALL_PREFIX/server"
mkdir -p "$STAGING$INSTALL_PREFIX/public"
mkdir -p "$STAGING/DEBIAN"
echo "     Done."

# ---------------------------------------------------------------
# Copy pre-built app files
# ---------------------------------------------------------------
echo "2/5  Copying app files..."
cp -r "$SCRIPT_DIR/server/"* "$STAGING$INSTALL_PREFIX/server/"
cp -r "$SCRIPT_DIR/public/"* "$STAGING$INSTALL_PREFIX/public/"
echo "     Done."

# ---------------------------------------------------------------
# Download and bundle Node.js binary
# ---------------------------------------------------------------
echo "3/5  Bundling Node.js v$NODE_VERSION ($NODE_ARCH)..."
CACHE_DIR="$HOME/.querymindr-build-cache"
mkdir -p "$CACHE_DIR"
CACHED_TARBALL="$CACHE_DIR/$NODE_TARBALL"

if [ ! -f "$CACHED_TARBALL" ]; then
  echo "     Downloading from nodejs.org (~40 MB, cached for future builds)..."
  curl -L --progress-bar "$NODE_URL" -o "$CACHED_TARBALL"
else
  echo "     Using cached Node.js binary."
fi

NODE_BIN_NAME="node-v${NODE_VERSION}-linux-${NODE_ARCH}"
tar -xzf "$CACHED_TARBALL" -C "$CACHE_DIR" "${NODE_BIN_NAME}/bin/node"
cp "$CACHE_DIR/${NODE_BIN_NAME}/bin/node" "$STAGING$INSTALL_PREFIX/node"
chmod +x "$STAGING$INSTALL_PREFIX/node"
rm -rf "$CACHE_DIR/$NODE_BIN_NAME"
echo "     Done."

# ---------------------------------------------------------------
# Write DEBIAN/control
# ---------------------------------------------------------------
echo "4/5  Writing package metadata and scripts..."

cat > "$STAGING/DEBIAN/control" << CONTROL
Package: $PKG_NAME
Version: $PKG_VERSION
Architecture: $DEB_ARCH
Maintainer: $MAINTAINER
Description: $DESCRIPTION
 Querymindr indexes every file on your external drives into a fast local
 database, then lets you search any file in seconds — by word, partial name,
 or plain language. Includes a duplicate file finder to reclaim drive space.
 Optionally integrates with Anthropic Claude for AI-powered search.
 Everything runs on your machine. Your files never leave your computer.
CONTROL

# ---------------------------------------------------------------
# Write DEBIAN/postinst  (runs as root after installation)
# ---------------------------------------------------------------
cat > "$STAGING/DEBIAN/postinst" << 'POSTINST'
#!/bin/bash
set -e

SRC_DIR="/usr/local/lib/querymindr"
NODE_BIN="$SRC_DIR/node"
PORT="8080"
SERVICE_NAME="querymindr"

# Find the currently logged-in non-root user
CURRENT_USER="${SUDO_USER:-}"
if [ -z "$CURRENT_USER" ] || [ "$CURRENT_USER" = "root" ]; then
  CURRENT_USER=$(logname 2>/dev/null || who | awk 'NR==1{print $1}')
fi
if [ -z "$CURRENT_USER" ] || [ "$CURRENT_USER" = "root" ]; then
  echo "WARNING: Could not detect current user. Please run: sudo dpkg -i Querymindr.deb"
  exit 0
fi

USER_HOME=$(getent passwd "$CURRENT_USER" | cut -d: -f6)
INSTALL_DIR="$USER_HOME/.querymindr"

# ------------------------------------------------------------------
# Install app files to user home
# ------------------------------------------------------------------
mkdir -p "$INSTALL_DIR/public"
cp -r "$SRC_DIR/server/"* "$INSTALL_DIR/"
cp -r "$SRC_DIR/public/"* "$INSTALL_DIR/public/"
chown -R "$CURRENT_USER" "$INSTALL_DIR"

# ------------------------------------------------------------------
# Migrate existing database from old file-finder location if present
# ------------------------------------------------------------------
OLD_DB="$USER_HOME/.config/file-finder/file-finder.db"
NEW_CONFIG_DIR="$USER_HOME/.config/querymindr"
NEW_DB="$NEW_CONFIG_DIR/querymindr.db"
if [ -f "$OLD_DB" ]; then
  OLD_SIZE=$(stat -c%s "$OLD_DB" 2>/dev/null || echo "0")
  NEW_SIZE=$(stat -c%s "$NEW_DB" 2>/dev/null || echo "0")
  if [ "$OLD_SIZE" -gt "$NEW_SIZE" ]; then
    mkdir -p "$NEW_CONFIG_DIR"
    cp "$OLD_DB" "$NEW_DB"
    [ -f "${OLD_DB}-wal" ] && cp "${OLD_DB}-wal" "${NEW_DB}-wal"
    [ -f "${OLD_DB}-shm" ] && cp "${OLD_DB}-shm" "${NEW_DB}-shm"
    chown -R "$CURRENT_USER" "$NEW_CONFIG_DIR"
  fi
fi

# ------------------------------------------------------------------
# Write systemd service file
# ------------------------------------------------------------------
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"

cat > "$SERVICE_FILE" << SERVICE
[Unit]
Description=Querymindr local file search server
After=network.target

[Service]
Type=simple
User=$CURRENT_USER
WorkingDirectory=$INSTALL_DIR
ExecStart=$NODE_BIN $INSTALL_DIR/index.mjs
Environment=PORT=$PORT
Environment=NODE_ENV=production
Restart=always
RestartSec=5
StandardOutput=append:$INSTALL_DIR/server.log
StandardError=append:$INSTALL_DIR/server-error.log

[Install]
WantedBy=multi-user.target
SERVICE

# ------------------------------------------------------------------
# Enable and start the service
# ------------------------------------------------------------------
systemctl daemon-reload
systemctl enable "$SERVICE_NAME" 2>/dev/null || true
systemctl restart "$SERVICE_NAME" 2>/dev/null || systemctl start "$SERVICE_NAME" 2>/dev/null || true

# ------------------------------------------------------------------
# Wait for server to start and open browser
# ------------------------------------------------------------------
sleep 4
for i in $(seq 1 15); do
  if curl -s "http://localhost:$PORT/api/querymindr/stats" > /dev/null 2>&1; then
    break
  fi
  sleep 1
done

sudo -u "$CURRENT_USER" xdg-open "http://localhost:$PORT/querymindr/" 2>/dev/null || true

echo ""
echo "  Querymindr installed!"
echo "  Open your browser to: http://localhost:$PORT/querymindr/"
echo ""

exit 0
POSTINST

# ---------------------------------------------------------------
# Write DEBIAN/prerm  (runs before uninstall)
# ---------------------------------------------------------------
cat > "$STAGING/DEBIAN/prerm" << 'PRERM'
#!/bin/bash
systemctl stop querymindr 2>/dev/null || true
systemctl disable querymindr 2>/dev/null || true
exit 0
PRERM

chmod 755 "$STAGING/DEBIAN/postinst"
chmod 755 "$STAGING/DEBIAN/prerm"

echo "     Done."

# ---------------------------------------------------------------
# Build the .deb package
# ---------------------------------------------------------------
echo "5/5  Building .deb package..."
dpkg-deb --build --root-owner-group "$STAGING" "$OUTPUT"
rm -rf "$STAGING"

SIZE=$(du -sh "$OUTPUT" | cut -f1)
echo ""
echo "╔══════════════════════════════════════╗"
echo "║  Querymindr.deb is ready  ($SIZE)     ║"
echo "╚══════════════════════════════════════╝"
echo ""
echo "  Share $(basename "$OUTPUT") with Linux users."
echo "  They install it with: sudo dpkg -i $(basename "$OUTPUT")"
echo "  Or double-click it in a file manager."
echo ""
