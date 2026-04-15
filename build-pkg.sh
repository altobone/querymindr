#!/bin/bash
set -e

# ---------------------------------------------------------------
# Querymindr — Build macOS Installer Package
#
# Run this on your Mac (inside the querymindr-release folder)
# to produce Querymindr.pkg — a double-clickable installer that
# requires no Terminal from end users.
#
# Prerequisites (on your Mac):
#   - Xcode Command Line Tools: xcode-select --install
#   - Node.js (LTS): nodejs.org
#
# Output: Querymindr.pkg  (~50 MB, self-contained)
# ---------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
STAGING="$SCRIPT_DIR/.pkg-staging"
OUTPUT="$SCRIPT_DIR/Querymindr.pkg"
COMPONENT_PKG="$SCRIPT_DIR/Querymindr-component.pkg"
PKG_ID="com.musicsavvy.querymindr"
PKG_VERSION="1.0"
INSTALL_PREFIX="/usr/local/lib/querymindr"
NODE_VERSION="22.13.1"

# Detect architecture for the correct Node.js binary
ARCH="$(uname -m)"
if [ "$ARCH" = "arm64" ]; then
  NODE_ARCH="arm64"
else
  NODE_ARCH="x64"
fi
NODE_TARBALL="node-v${NODE_VERSION}-darwin-${NODE_ARCH}.tar.gz"
NODE_URL="https://nodejs.org/dist/v${NODE_VERSION}/${NODE_TARBALL}"

echo ""
echo "╔══════════════════════════════════════╗"
echo "║   Querymindr — Build .pkg Installer  ║"
echo "╚══════════════════════════════════════╝"
echo ""
echo "  Architecture : $ARCH"
echo "  Node.js      : v$NODE_VERSION ($NODE_ARCH)"
echo "  Output       : $OUTPUT"
echo ""

# ---------------------------------------------------------------
# Check tools
# ---------------------------------------------------------------
if ! command -v pkgbuild &>/dev/null; then
  echo "ERROR: pkgbuild not found."
  echo "Install Xcode Command Line Tools first:  xcode-select --install"
  exit 1
fi

if ! command -v productbuild &>/dev/null; then
  echo "ERROR: productbuild not found."
  echo "Install Xcode Command Line Tools first:  xcode-select --install"
  exit 1
fi

# ---------------------------------------------------------------
# Clean staging area
# ---------------------------------------------------------------
echo "1/6  Preparing staging area..."
rm -rf "$STAGING" "$COMPONENT_PKG" "$OUTPUT"
mkdir -p "$STAGING/payload$INSTALL_PREFIX/server"
mkdir -p "$STAGING/payload$INSTALL_PREFIX/public"
mkdir -p "$STAGING/payload$INSTALL_PREFIX/menu-bar"
mkdir -p "$STAGING/scripts"
mkdir -p "$STAGING/resources"
echo "     Done."

# ---------------------------------------------------------------
# Copy pre-built app files
# ---------------------------------------------------------------
echo "2/6  Copying app files..."

if [ ! -f "$SCRIPT_DIR/server/index.mjs" ]; then
  echo "ERROR: server/index.mjs not found. Run this script from inside the querymindr-release folder."
  exit 1
fi

cp -r "$SCRIPT_DIR/server/"*    "$STAGING/payload$INSTALL_PREFIX/server/"
cp -r "$SCRIPT_DIR/public/"*    "$STAGING/payload$INSTALL_PREFIX/public/"
cp    "$SCRIPT_DIR/menu-bar/MenuBar.swift" "$STAGING/payload$INSTALL_PREFIX/menu-bar/"

echo "     Done."

# ---------------------------------------------------------------
# Download and bundle Node.js binary
# ---------------------------------------------------------------
echo "3/6  Bundling Node.js v$NODE_VERSION ($NODE_ARCH)..."
CACHE_DIR="$HOME/.querymindr-build-cache"
mkdir -p "$CACHE_DIR"
CACHED_TARBALL="$CACHE_DIR/$NODE_TARBALL"

if [ ! -f "$CACHED_TARBALL" ]; then
  echo "     Downloading from nodejs.org (~40 MB, cached for future builds)..."
  curl -L --progress-bar "$NODE_URL" -o "$CACHED_TARBALL"
else
  echo "     Using cached Node.js binary."
fi

# Extract just the node binary from the tarball
NODE_BIN_NAME="node-v${NODE_VERSION}-darwin-${NODE_ARCH}"
tar -xzf "$CACHED_TARBALL" -C "$CACHE_DIR" "${NODE_BIN_NAME}/bin/node"
cp "$CACHE_DIR/${NODE_BIN_NAME}/bin/node" "$STAGING/payload$INSTALL_PREFIX/node"
chmod +x "$STAGING/payload$INSTALL_PREFIX/node"
rm -rf "$CACHE_DIR/$NODE_BIN_NAME"
echo "     Done."

# ---------------------------------------------------------------
# Write postinstall script
# ---------------------------------------------------------------
echo "4/6  Writing installer scripts..."

cat > "$STAGING/scripts/postinstall" << 'POSTINSTALL'
#!/bin/bash
set -e

SRC_DIR="/usr/local/lib/querymindr"
NODE_BIN="$SRC_DIR/node"
PORT="8080"
ROOT_DIR="/Volumes/Thunderbay"
PLIST_LABEL="com.musicsavvy.querymindr"
MENUBAR_PLIST_LABEL="com.musicsavvy.querymindr.menubar"

# Find the currently logged-in user (installer runs as root)
CURRENT_USER=$(stat -f "%Su" /dev/console 2>/dev/null || echo "$SUDO_USER")
if [ -z "$CURRENT_USER" ] || [ "$CURRENT_USER" = "root" ]; then
  CURRENT_USER=$(id -un)
fi
USER_HOME=$(dscl . -read "/Users/$CURRENT_USER" NFSHomeDirectory 2>/dev/null | awk '{print $2}')
if [ -z "$USER_HOME" ]; then
  USER_HOME="/Users/$CURRENT_USER"
fi

INSTALL_DIR="$USER_HOME/.querymindr"
PLIST_FILE="$USER_HOME/Library/LaunchAgents/$PLIST_LABEL.plist"
MENUBAR_PLIST_FILE="$USER_HOME/Library/LaunchAgents/$MENUBAR_PLIST_LABEL.plist"

# ------------------------------------------------------------------
# Install app files to user home
# ------------------------------------------------------------------
mkdir -p "$INSTALL_DIR/public"
cp -r "$SRC_DIR/server/"* "$INSTALL_DIR/"
cp -r "$SRC_DIR/public/"* "$INSTALL_DIR/public/"

# Fix permissions so the user owns their install
chown -R "$CURRENT_USER" "$INSTALL_DIR"

# ------------------------------------------------------------------
# Migrate existing database from old location (pre-rename)
# Copies ~/.config/file-finder/file-finder.db → ~/.config/querymindr/querymindr.db
# only if the new DB doesn't already exist, preserving the full index.
# ------------------------------------------------------------------
OLD_DB="$USER_HOME/.config/file-finder/file-finder.db"
NEW_CONFIG_DIR="$USER_HOME/.config/querymindr"
NEW_DB="$NEW_CONFIG_DIR/querymindr.db"
if [ -f "$OLD_DB" ]; then
  OLD_SIZE=$(stat -f%z "$OLD_DB" 2>/dev/null || echo "0")
  NEW_SIZE=$(stat -f%z "$NEW_DB" 2>/dev/null || echo "0")
  # Migrate if old DB is larger (has real data) and new DB is absent or empty/smaller
  if [ "$OLD_SIZE" -gt "$NEW_SIZE" ]; then
    mkdir -p "$NEW_CONFIG_DIR"
    cp "$OLD_DB" "$NEW_DB"
    chown -R "$CURRENT_USER" "$NEW_CONFIG_DIR"
  fi
fi

# ------------------------------------------------------------------
# Build menu bar app (requires Xcode CLT)
# ------------------------------------------------------------------
MENUBAR_BUILT=false
SWIFT_BIN=$(which swiftc 2>/dev/null || echo "")
if [ -n "$SWIFT_BIN" ]; then
  SDK_PATH=$(xcrun --sdk macosx --show-sdk-path 2>/dev/null || echo "")
  if [ -n "$SDK_PATH" ]; then
    xcrun swiftc "$SRC_DIR/menu-bar/MenuBar.swift" \
      -framework Cocoa -framework Foundation \
      -sdk "$SDK_PATH" -O \
      -o "$INSTALL_DIR/FileFinder-MenuBar" 2>/dev/null || true
  else
    swiftc "$SRC_DIR/menu-bar/MenuBar.swift" \
      -framework Cocoa -framework Foundation \
      -O -o "$INSTALL_DIR/FileFinder-MenuBar" 2>/dev/null || true
  fi
  if [ -f "$INSTALL_DIR/FileFinder-MenuBar" ]; then
    chown "$CURRENT_USER" "$INSTALL_DIR/FileFinder-MenuBar"
    MENUBAR_BUILT=true
  fi
fi

# ------------------------------------------------------------------
# Write launchd plist for main server
# ------------------------------------------------------------------
mkdir -p "$USER_HOME/Library/LaunchAgents"

# Stop any previous version (both new and old pre-rename names)
USER_UID="$(id -u "$CURRENT_USER")"
OLD_PLIST="$USER_HOME/Library/LaunchAgents/com.musicsavvy.filefinder.plist"
OLD_MENUBAR_PLIST="$USER_HOME/Library/LaunchAgents/com.musicsavvy.filefinder.menubar.plist"
launchctl asuser "$USER_UID" launchctl unload "$PLIST_FILE"         2>/dev/null || true
launchctl asuser "$USER_UID" launchctl unload "$MENUBAR_PLIST_FILE" 2>/dev/null || true
launchctl asuser "$USER_UID" launchctl unload "$OLD_PLIST"          2>/dev/null || true
launchctl asuser "$USER_UID" launchctl unload "$OLD_MENUBAR_PLIST"  2>/dev/null || true
# Brief pause to let old processes release the port
sleep 2

cat > "$PLIST_FILE" << PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>$PLIST_LABEL</string>
    <key>ProgramArguments</key>
    <array>
        <string>$NODE_BIN</string>
        <string>$INSTALL_DIR/index.mjs</string>
    </array>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PORT</key>
        <string>$PORT</string>
        <key>ROOT_DIR</key>
        <string>$ROOT_DIR</string>
        <key>NODE_ENV</key>
        <string>production</string>
    </dict>
    <key>WorkingDirectory</key>
    <string>$INSTALL_DIR</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>$INSTALL_DIR/server.log</string>
    <key>StandardErrorPath</key>
    <string>$INSTALL_DIR/server-error.log</string>
</dict>
</plist>
PLIST

chown "$CURRENT_USER" "$PLIST_FILE"
launchctl asuser "$(id -u "$CURRENT_USER")" launchctl load "$PLIST_FILE"

# ------------------------------------------------------------------
# Write launchd plist for menu bar (if built)
# ------------------------------------------------------------------
if [ "$MENUBAR_BUILT" = true ]; then
  launchctl asuser "$(id -u "$CURRENT_USER")" launchctl unload "$MENUBAR_PLIST_FILE" 2>/dev/null || true

  cat > "$MENUBAR_PLIST_FILE" << MBPLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>$MENUBAR_PLIST_LABEL</string>
    <key>ProgramArguments</key>
    <array>
        <string>$INSTALL_DIR/FileFinder-MenuBar</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>$INSTALL_DIR/menubar.log</string>
    <key>StandardErrorPath</key>
    <string>$INSTALL_DIR/menubar-error.log</string>
</dict>
</plist>
MBPLIST

  chown "$CURRENT_USER" "$MENUBAR_PLIST_FILE"
  launchctl asuser "$(id -u "$CURRENT_USER")" launchctl load "$MENUBAR_PLIST_FILE"
fi

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

su - "$CURRENT_USER" -c "open 'http://localhost:$PORT/querymindr/'" 2>/dev/null || true

exit 0
POSTINSTALL

chmod +x "$STAGING/scripts/postinstall"

# ---------------------------------------------------------------
# Write installer wizard HTML pages
# ---------------------------------------------------------------
cat > "$STAGING/resources/welcome.html" << 'WELCOMEHTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: -apple-system, sans-serif; font-size: 14px; color: #1d1d1f; line-height: 1.6; margin: 0; padding: 20px 24px; }
  h1 { font-size: 20px; font-weight: 700; margin: 0 0 12px; }
  p { margin: 0 0 10px; color: #3a3a3c; }
  ol { margin: 10px 0 14px 0; padding-left: 22px; color: #3a3a3c; }
  ol li { margin-bottom: 8px; }
  .note { background: #f5f5f7; border-radius: 8px; padding: 12px 14px; font-size: 13px; color: #6e6e73; margin-top: 4px; }
</style>
</head>
<body>
  <h1>Welcome to Querymindr</h1>
  <p>Querymindr is a local search tool for large external drives. It indexes your files and lets you find anything in seconds — entirely on your Mac, with nothing sent to the internet.</p>
  <p><strong>After installation:</strong></p>
  <ol>
    <li>Querymindr opens in your browser automatically.</li>
    <li>A welcome screen walks you through indexing your drive and optional AI setup.</li>
    <li>A <strong>magnifying glass icon</strong> appears in your menu bar for quick access (requires Xcode Command Line Tools).</li>
  </ol>
  <div class="note">Querymindr runs as a background service and starts automatically every time you log in. No Terminal needed.</div>
</body>
</html>
WELCOMEHTML

cat > "$STAGING/resources/readme.html" << 'READMEHTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: -apple-system, sans-serif; font-size: 14px; color: #1d1d1f; line-height: 1.6; margin: 0; padding: 20px 24px; }
  h2 { font-size: 16px; font-weight: 600; margin: 16px 0 6px; }
  p, li { color: #3a3a3c; margin: 0 0 8px; }
  ul { padding-left: 18px; }
  code { font-family: monospace; background: #f5f5f7; padding: 1px 5px; border-radius: 4px; font-size: 13px; }
</style>
</head>
<body>
  <h2>What gets installed</h2>
  <ul>
    <li>App files → <code>/usr/local/lib/querymindr/</code></li>
    <li>Your personal data → <code>~/.querymindr/</code></li>
    <li>Launch agent → <code>~/Library/LaunchAgents/</code></li>
  </ul>
  <h2>How to open the app</h2>
  <p>Open your browser and go to: <code>http://localhost:8080/querymindr/</code></p>
  <p>Bookmark this address. The app is only accessible from your own Mac.</p>
  <h2>Stopping and starting</h2>
  <ul>
    <li>To stop: <code>launchctl unload ~/Library/LaunchAgents/com.musicsavvy.querymindr.plist</code></li>
    <li>To restart: <code>launchctl load ~/Library/LaunchAgents/com.musicsavvy.querymindr.plist</code></li>
  </ul>
  <h2>Your drive path</h2>
  <p>Querymindr defaults to <code>/Volumes/Thunderbay</code>. To change it, open the app and go to <strong>Settings → Index Engine</strong>.</p>
</body>
</html>
READMEHTML

echo "     Done."

# ---------------------------------------------------------------
# Write Distribution.xml (installer wizard config)
# ---------------------------------------------------------------
cat > "$STAGING/Distribution.xml" << DISTXML
<?xml version="1.0" encoding="utf-8"?>
<installer-gui-script minSpecVersion="1">
    <title>Querymindr</title>
    <welcome    file="welcome.html" mime-type="text/html" />
    <readme     file="readme.html"  mime-type="text/html" />
    <options customize="never" require-scripts="true" rootVolumeOnly="true" />
    <domains enable_localSystem="true" />
    <pkg-ref id="$PKG_ID" />
    <choices-outline>
        <line choice="default">
            <line choice="$PKG_ID" />
        </line>
    </choices-outline>
    <choice id="default" />
    <choice id="$PKG_ID" visible="false">
        <pkg-ref id="$PKG_ID" />
    </choice>
    <pkg-ref id="$PKG_ID" version="$PKG_VERSION" onConclusion="none">Querymindr-component.pkg</pkg-ref>
</installer-gui-script>
DISTXML

# ---------------------------------------------------------------
# Build packages
# ---------------------------------------------------------------
echo "5/6  Building component package..."
pkgbuild \
  --root    "$STAGING/payload" \
  --scripts "$STAGING/scripts" \
  --identifier "$PKG_ID" \
  --version "$PKG_VERSION" \
  --install-location "/" \
  "$COMPONENT_PKG"
echo "     Done."

echo "6/6  Building final installer..."
productbuild \
  --distribution "$STAGING/Distribution.xml" \
  --resources    "$STAGING/resources" \
  --package-path "$(dirname "$COMPONENT_PKG")" \
  "$OUTPUT"
echo "     Done."

# ---------------------------------------------------------------
# Cleanup
# ---------------------------------------------------------------
rm -rf "$STAGING" "$COMPONENT_PKG"

SIZE=$(du -sh "$OUTPUT" | cut -f1)
echo ""
echo "╔══════════════════════════════════════╗"
echo "║   Querymindr.pkg is ready  ($SIZE)    ║"
echo "╚══════════════════════════════════════╝"
echo ""
echo "  Share Querymindr.pkg with anyone."
echo "  They double-click it, click Install — done."
echo "  No Terminal. No Node.js required."
echo ""
