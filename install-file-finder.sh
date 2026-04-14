#!/bin/bash
set -e

# ---------------------------------------------------------------
# File Finder - One-Time Install Script
# Builds the app and installs it as a macOS background service
# that starts automatically at login — no Terminal needed.
# ---------------------------------------------------------------

ROOT_DIR="${ROOT_DIR:-/Volumes/Thunderbay}"
PORT="${PORT:-8080}"
INSTALL_DIR="$HOME/.file-finder"
PLIST_LABEL="com.musicsavvy.filefinder"
PLIST_FILE="$HOME/Library/LaunchAgents/$PLIST_LABEL.plist"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
NODE_BIN="$(which node)"
PNPM_BIN="$(which pnpm)"

echo ""
echo "╔══════════════════════════════════════╗"
echo "║   File Finder - Install & Start      ║"
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
# Step 1: Install dependencies
# ---------------------------------------------------------------
echo "1/5  Installing dependencies (this takes a few minutes the first time)..."
"$PNPM_BIN" install --filter @workspace/file-finder... --filter @workspace/api-server...
echo "     Dependencies ready."

# ---------------------------------------------------------------
# Step 2: Build the frontend
# ---------------------------------------------------------------
echo "2/5  Building frontend..."
NODE_ENV=production BASE_PATH=/file-finder/ "$PNPM_BIN" --filter @workspace/file-finder run build
echo "     Frontend built."

# ---------------------------------------------------------------
# Step 3: Build the backend
# ---------------------------------------------------------------
echo "3/5  Building server..."
"$PNPM_BIN" --filter @workspace/api-server run build
echo "     Server built."

# ---------------------------------------------------------------
# Step 4: Copy everything to the permanent install directory
# ---------------------------------------------------------------
echo "4/5  Installing to $INSTALL_DIR..."
mkdir -p "$INSTALL_DIR"

# Copy server dist
cp -r artifacts/api-server/dist/* "$INSTALL_DIR/"

# Copy frontend build into public/ subdirectory (served by the server)
mkdir -p "$INSTALL_DIR/public"
cp -r artifacts/file-finder/dist/public/* "$INSTALL_DIR/public/"

echo "     Files installed."

# ---------------------------------------------------------------
# Step 4: Install macOS launch agent (auto-start at login)
# ---------------------------------------------------------------
echo "5/5  Installing launch agent..."

# Stop existing service if running
launchctl unload "$PLIST_FILE" 2>/dev/null || true

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

# Load and start the service
launchctl load "$PLIST_FILE"

echo "     Launch agent installed."
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
echo "║   Installation complete!             ║"
echo "╚══════════════════════════════════════╝"
echo ""
echo "  App URL : http://localhost:$PORT/file-finder/"
echo ""
echo "  The server now runs automatically in the background."
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
