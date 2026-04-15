#!/bin/bash
set -e

# ---------------------------------------------------------------
# Querymindr - Linux Install Script
# Builds the app and installs it as a systemd user service
# that starts automatically at login — no Terminal needed.
# ---------------------------------------------------------------

ROOT_DIR="${ROOT_DIR:-}"
PORT="${PORT:-8080}"
INSTALL_DIR="$HOME/.file-finder"
SERVICE_NAME="file-finder"
SERVICE_DIR="$HOME/.config/systemd/user"
SERVICE_FILE="$SERVICE_DIR/$SERVICE_NAME.service"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
NODE_BIN="$(which node)"
PNPM_BIN="$(which pnpm)"

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

# Require a drive path
if [ -z "$ROOT_DIR" ]; then
  echo "ERROR: No drive path specified."
  echo ""
  echo "Pass the path to the drive you want to index:"
  echo "  ROOT_DIR=/media/yourname/drivename bash install-linux.sh"
  exit 1
fi

# Verify drive exists
if [ ! -d "$ROOT_DIR" ]; then
  echo "ERROR: Directory not found: $ROOT_DIR"
  echo "Make sure your drive is connected and mounted, then try again."
  exit 1
fi

# Verify systemd is available
if ! command -v systemctl &> /dev/null; then
  echo "ERROR: systemctl not found. This script requires systemd."
  echo "On Debian/Ubuntu: your system should already have it."
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

cp -r artifacts/api-server/dist/* "$INSTALL_DIR/"

mkdir -p "$INSTALL_DIR/public"
cp -r artifacts/file-finder/dist/public/* "$INSTALL_DIR/public/"

echo "     Files installed."

# ---------------------------------------------------------------
# Step 5: Install systemd user service (auto-start at login)
# ---------------------------------------------------------------
echo "5/5  Installing systemd service..."

mkdir -p "$SERVICE_DIR"

# Stop existing service if running
systemctl --user stop "$SERVICE_NAME" 2>/dev/null || true
systemctl --user disable "$SERVICE_NAME" 2>/dev/null || true

cat > "$SERVICE_FILE" << SERVICEEOF
[Unit]
Description=Querymindr Server
After=network.target

[Service]
Type=simple
ExecStart=$NODE_BIN $INSTALL_DIR/index.mjs
Environment=PORT=$PORT
Environment=ROOT_DIR=$ROOT_DIR
Environment=NODE_ENV=production
WorkingDirectory=$INSTALL_DIR
Restart=always
RestartSec=5
StandardOutput=append:$INSTALL_DIR/server.log
StandardError=append:$INSTALL_DIR/server-error.log

[Install]
WantedBy=default.target
SERVICEEOF

systemctl --user daemon-reload
systemctl --user enable "$SERVICE_NAME"
systemctl --user start "$SERVICE_NAME"

# Allow the service to run even when not logged in (headless/server setups)
loginctl enable-linger "$USER" 2>/dev/null || true

echo "     Service installed and started."
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
echo "  The server runs automatically in the background."
echo "  It starts at every login (or boot if linger is enabled)."
echo ""
echo "  To check status:    systemctl --user status $SERVICE_NAME"
echo "  To stop:            systemctl --user stop $SERVICE_NAME"
echo "  To start again:     systemctl --user start $SERVICE_NAME"
echo "  To view logs:       journalctl --user -u $SERVICE_NAME -f"
echo ""

# Open browser if a display is available
if [ -n "$DISPLAY" ] || [ -n "$WAYLAND_DISPLAY" ]; then
  xdg-open "http://localhost:$PORT/file-finder/" 2>/dev/null || true
else
  echo "  No display detected — open the URL above in your browser."
fi
