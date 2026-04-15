#!/bin/bash
set -e

# ---------------------------------------------------------------
# Querymindr - One-Time Install Script
# Builds the app and installs it as a macOS background service
# that starts automatically at login — no Terminal needed.
# ---------------------------------------------------------------

ROOT_DIR="${ROOT_DIR:-/Volumes/Thunderbay}"
PORT="${PORT:-8080}"
INSTALL_DIR="$HOME/.file-finder"
PLIST_LABEL="com.musicsavvy.filefinder"
PLIST_FILE="$HOME/Library/LaunchAgents/$PLIST_LABEL.plist"
MENUBAR_PLIST_LABEL="com.musicsavvy.filefinder.menubar"
MENUBAR_PLIST_FILE="$HOME/Library/LaunchAgents/$MENUBAR_PLIST_LABEL.plist"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
NODE_BIN="$(which node)"
PNPM_BIN="$(which pnpm)"
SWIFT_BIN="$(which swiftc 2>/dev/null || echo "")"

echo ""
echo "╔══════════════════════════════════════╗"
echo "║   Querymindr - Install & Start       ║"
echo "╚══════════════════════════════════════╝"
echo ""
echo "Drive path : $ROOT_DIR"
echo "Port       : $PORT"
echo "Install dir: $INSTALL_DIR"
echo "Node       : $NODE_BIN"
echo ""

# Verify drive exists
if [ ! -d "$ROOT_DIR" ]; then
  echo "ERROR: Drive not found at $ROOT_DIR"
  echo "Make sure your Thunderbay drive is connected and try again."
  exit 1
fi

cd "$SCRIPT_DIR"

# ---------------------------------------------------------------
# Detect pre-built release vs. full source tree
# ---------------------------------------------------------------
if [ -f "$SCRIPT_DIR/server/index.mjs" ]; then
  PREBUILT=true
else
  PREBUILT=false
fi

if [ "$PREBUILT" = true ]; then
  echo "1/6  Using pre-built release (no build required)."
  echo "2/6  Skipped (pre-built)."
  echo "3/6  Skipped (pre-built)."
else
  # ---------------------------------------------------------------
  # Step 1: Install dependencies
  # ---------------------------------------------------------------
  echo "1/6  Installing dependencies (this takes a few minutes the first time)..."
  "$PNPM_BIN" install --filter @workspace/file-finder... --filter @workspace/api-server...
  echo "     Dependencies ready."

  # ---------------------------------------------------------------
  # Step 2: Build the frontend
  # ---------------------------------------------------------------
  echo "2/6  Building frontend..."
  NODE_ENV=production BASE_PATH=/file-finder/ "$PNPM_BIN" --filter @workspace/file-finder run build
  echo "     Frontend built."

  # ---------------------------------------------------------------
  # Step 3: Build the backend
  # ---------------------------------------------------------------
  echo "3/6  Building server..."
  "$PNPM_BIN" --filter @workspace/api-server run build
  echo "     Server built."
fi

# ---------------------------------------------------------------
# Step 4: Copy everything to the permanent install directory
# ---------------------------------------------------------------
echo "4/6  Installing to $INSTALL_DIR..."
mkdir -p "$INSTALL_DIR"

if [ "$PREBUILT" = true ]; then
  cp -r "$SCRIPT_DIR/server/"* "$INSTALL_DIR/"
  mkdir -p "$INSTALL_DIR/public"
  cp -r "$SCRIPT_DIR/public/"* "$INSTALL_DIR/public/"
else
  cp -r artifacts/api-server/dist/* "$INSTALL_DIR/"
  mkdir -p "$INSTALL_DIR/public"
  cp -r artifacts/file-finder/dist/public/* "$INSTALL_DIR/public/"
fi

echo "     Files installed."

# ---------------------------------------------------------------
# Step 5: Build menu bar app (requires Xcode Command Line Tools)
# ---------------------------------------------------------------
echo "5/6  Building menu bar app..."

if [ -z "$SWIFT_BIN" ]; then
  echo "     ⚠️  swiftc not found — skipping menu bar."
  echo "     To install: xcode-select --install, then re-run this script."
  MENUBAR_BUILT=false
else
  # Use xcrun to pick up the correct SDK automatically; suppress noisy module map
  # warnings from newer Xcode CLTs that don't affect the compiled binary.
  SDK_PATH="$(xcrun --sdk macosx --show-sdk-path 2>/dev/null || echo "")"
  if [ -n "$SDK_PATH" ]; then
    xcrun swiftc menu-bar/MenuBar.swift \
      -framework Cocoa \
      -framework Foundation \
      -sdk "$SDK_PATH" \
      -O \
      -o "$INSTALL_DIR/FileFinder-MenuBar" 2>/dev/null || true
  else
    swiftc menu-bar/MenuBar.swift \
      -framework Cocoa \
      -framework Foundation \
      -O \
      -o "$INSTALL_DIR/FileFinder-MenuBar" 2>/dev/null || true
  fi

  # Success is determined by whether the binary was actually produced,
  # not the exit code (newer CLTs emit module map warnings on exit).
  if [ -f "$INSTALL_DIR/FileFinder-MenuBar" ]; then
    MENUBAR_BUILT=true
    echo "     Menu bar app built."
  else
    MENUBAR_BUILT=false
    echo "     ⚠️  Menu bar build failed — skipping. The web app will still work."
  fi
fi

# ---------------------------------------------------------------
# Step 6: Install macOS launch agents (auto-start at login)
# ---------------------------------------------------------------
echo "6/6  Installing launch agents..."

# Stop existing services if running
launchctl unload "$PLIST_FILE" 2>/dev/null || true
launchctl unload "$MENUBAR_PLIST_FILE" 2>/dev/null || true

# Server launch agent
cat > "$PLIST_FILE" << PLISTEOF
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
PLISTEOF

launchctl load "$PLIST_FILE"

# Menu bar launch agent (only if built successfully)
if [ "$MENUBAR_BUILT" = true ]; then
  cat > "$MENUBAR_PLIST_FILE" << PLISTEOF
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
PLISTEOF

  launchctl load "$MENUBAR_PLIST_FILE"
  echo "     Menu bar agent installed."
fi

echo "     Launch agents installed."
echo ""
echo "Waiting for server to start..."
for i in {1..15}; do
  if curl -s "http://localhost:$PORT/api/file-finder/stats" > /dev/null 2>&1; then
    break
  fi
  sleep 1
done

echo ""
echo "╔══════════════════════════════════════╗"
echo "║   Querymindr installed!              ║"
echo "╚══════════════════════════════════════╝"
echo ""
echo "  App URL : http://localhost:$PORT/file-finder/"
echo ""
if [ "$MENUBAR_BUILT" = true ]; then
echo "  A magnifying glass icon 🔍 is now in your menu bar."
echo "  Click it to open the app or run a Quick Update."
echo ""
fi
echo "  The server runs automatically in the background."
echo "  No Terminal needed. It starts at every login."
echo ""
echo "  To stop the service:"
echo "    launchctl unload $PLIST_FILE"
echo ""
echo "  To start it again:"
echo "    launchctl load $PLIST_FILE"
echo ""

# Open the browser
open "http://localhost:$PORT/file-finder/"
