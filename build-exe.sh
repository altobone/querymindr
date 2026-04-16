#!/bin/bash
set -e

# ---------------------------------------------------------------
# Querymindr — Build Windows .exe Installer
#
# Run this on Linux or in the Replit environment from inside
# the querymindr-release folder to produce:
#   Querymindr-windows-setup.exe
#
# Windows users double-click it to install — no Terminal needed.
# The server starts automatically at every login via Task Scheduler.
#
# Output: Querymindr-windows-setup.exe (~55 MB, self-contained)
# ---------------------------------------------------------------

# If running on Replit (nix available) re-invoke through nix-shell
# so makensis and unzip are accessible without manual installation.
if [ -z "$_QUERYMINDR_NIX_SHELL" ] && command -v nix-shell &>/dev/null; then
  if ! command -v makensis &>/dev/null || ! command -v unzip &>/dev/null; then
    export _QUERYMINDR_NIX_SHELL=1
    exec nix-shell -p nsis -p unzip --run "bash $0 $*"
  fi
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
APP_VERSION="1.0"
NODE_VERSION="22.13.1"
NODE_ARCH="x64"
NODE_ZIP="node-v${NODE_VERSION}-win-${NODE_ARCH}.zip"
NODE_URL="https://nodejs.org/dist/v${NODE_VERSION}/${NODE_ZIP}"
OUTPUT="$SCRIPT_DIR/Querymindr-install-windows.exe"
STAGING="$SCRIPT_DIR/.exe-staging"
APP_DIR="$STAGING/app"

echo ""
echo "╔══════════════════════════════════════╗"
echo "║  Querymindr — Build Windows .exe     ║"
echo "╚══════════════════════════════════════╝"
echo ""
echo "  Node.js : v$NODE_VERSION ($NODE_ARCH)"
echo "  Output  : $OUTPUT"
echo ""

# ---------------------------------------------------------------
# Check tools
# ---------------------------------------------------------------
if ! command -v makensis &>/dev/null; then
  echo "ERROR: makensis not found. Install with: nix-env -iA nixpkgs.nsis"
  exit 1
fi
if ! command -v unzip &>/dev/null; then
  echo "ERROR: unzip not found. Install with: nix-env -iA nixpkgs.unzip"
  exit 1
fi
if [ ! -f "$SCRIPT_DIR/server/index.mjs" ]; then
  echo "ERROR: server/index.mjs not found."
  echo "Run this script from inside the querymindr-release folder."
  exit 1
fi

# ---------------------------------------------------------------
# Prepare staging area
# ---------------------------------------------------------------
echo "1/5  Preparing staging area..."
rm -rf "$STAGING" "$OUTPUT"
mkdir -p "$APP_DIR/public"
echo "     Done."

# ---------------------------------------------------------------
# Copy app files
# ---------------------------------------------------------------
echo "2/5  Copying app files..."
cp -r "$SCRIPT_DIR/server/"* "$APP_DIR/"
cp -r "$SCRIPT_DIR/public/"* "$APP_DIR/public/"
echo "     Done."

# ---------------------------------------------------------------
# Download and bundle Windows Node.js binary
# ---------------------------------------------------------------
echo "3/5  Bundling Node.js v$NODE_VERSION (Windows $NODE_ARCH)..."
CACHE_DIR="$HOME/.querymindr-build-cache"
mkdir -p "$CACHE_DIR"
CACHED_ZIP="$CACHE_DIR/$NODE_ZIP"

if [ ! -f "$CACHED_ZIP" ]; then
  echo "     Downloading from nodejs.org (~27 MB, cached for future builds)..."
  curl -L --progress-bar "$NODE_URL" -o "$CACHED_ZIP"
else
  echo "     Using cached Windows Node.js binary."
fi

NODE_DIR_NAME="node-v${NODE_VERSION}-win-${NODE_ARCH}"
unzip -q "$CACHED_ZIP" "${NODE_DIR_NAME}/node.exe" -d "$CACHE_DIR"
cp "$CACHE_DIR/${NODE_DIR_NAME}/node.exe" "$APP_DIR/node.exe"
rm -rf "$CACHE_DIR/$NODE_DIR_NAME"
echo "     Done."

# ---------------------------------------------------------------
# Generate NSIS file list (explicit per-file, cross-platform safe)
# ---------------------------------------------------------------
generate_file_section() {
  local base_dir="$1"
  local prev_dir=""

  find "$base_dir" -type f | sort | while IFS= read -r filepath; do
    rel="${filepath#$base_dir/}"
    dir=$(dirname "$rel")

    # Convert Linux path to Windows-style
    if [ "$dir" = "." ]; then
      win_dir='$INSTDIR'
    else
      win_dir='$INSTDIR\'"$(echo "$dir" | tr '/' '\\')"
    fi

    if [ "$win_dir" != "$prev_dir" ]; then
      echo "  SetOutPath \"$win_dir\""
      prev_dir="$win_dir"
    fi

    echo "  File \"$filepath\""
  done
}

# ---------------------------------------------------------------
# Write NSIS installer script
# ---------------------------------------------------------------
echo "4/5  Writing installer script..."

NSIS_SCRIPT="$STAGING/Querymindr.nsi"
FILE_SECTION=$(generate_file_section "$APP_DIR")

cat > "$NSIS_SCRIPT" << NSIEOF
Unicode True

!define APP_NAME      "Querymindr"
!define APP_VERSION   "$APP_VERSION"
!define APP_URL       "http://localhost:8080/querymindr/"
!define TASK_NAME     "Querymindr Server"
!define UNINSTALL_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\Querymindr"

Name            "\${APP_NAME} \${APP_VERSION}"
OutFile         "$OUTPUT"
InstallDir      "\$LOCALAPPDATA\Querymindr"
RequestExecutionLevel user
SetCompressor   lzma

!include "MUI2.nsh"

!define MUI_ABORTWARNING
!define MUI_WELCOMEPAGE_TITLE   "Welcome to Querymindr"
!define MUI_WELCOMEPAGE_TEXT    "Querymindr is a local search tool for large external drives.\$\r\$\nIt indexes every file and lets you find anything in seconds — entirely offline, entirely on your PC.\$\r\$\n\$\r\$\nClick Install to continue."
!define MUI_FINISHPAGE_TITLE    "Querymindr Installed"
!define MUI_FINISHPAGE_TEXT     "Querymindr is running in the background and will start automatically every time you log in.\$\r\$\n\$\r\$\nYour browser will open automatically in a few seconds."

!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH

!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

!insertmacro MUI_LANGUAGE "English"

; ---------------------------------------------------------------
; Install Section
; ---------------------------------------------------------------
Section "Querymindr" SecMain

$FILE_SECTION

  ; Write silent batch launcher
  SetOutPath "\$INSTDIR"
  FileOpen \$0 "\$INSTDIR\start.bat" w
  FileWrite \$0 "@echo off\$\r\$\n"
  FileWrite \$0 "SET PORT=8080\$\r\$\n"
  FileWrite \$0 "SET NODE_ENV=production\$\r\$\n"
  FileWrite \$0 "cd /d \$\"%~dp0\$\"\$\r\$\n"
  FileWrite \$0 "start \$\"Querymindr\$\" /B node.exe index.mjs\$\r\$\n"
  FileClose \$0

  ; Write VBScript wrapper to launch batch without showing a terminal window
  FileOpen \$0 "\$INSTDIR\start.vbs" w
  FileWrite \$0 "Set oShell = CreateObject(\$\"WScript.Shell\$\")\$\r\$\n"
  FileWrite \$0 "oShell.Run \$\"cmd.exe /c \$\"\$\"\$INSTDIR\start.bat\$\"\$\"\$\", 0, False\$\r\$\n"
  FileClose \$0

  ; Register Task Scheduler entry — starts server at every login
  nsExec::ExecToLog 'schtasks /create /tn "\${TASK_NAME}" /tr "wscript.exe \\"\$INSTDIR\start.vbs\\"" /sc ONLOGON /f'

  ; Start server immediately
  nsExec::ExecToLog 'wscript.exe "\$INSTDIR\start.vbs"'

  ; Start Menu shortcuts
  CreateDirectory "\$SMPROGRAMS\Querymindr"
  WriteIniStr "\$INSTDIR\Querymindr.url" "InternetShortcut" "URL" "\${APP_URL}"
  CreateShortCut "\$SMPROGRAMS\Querymindr\Open Querymindr.lnk" "\$INSTDIR\Querymindr.url"
  CreateShortCut "\$SMPROGRAMS\Querymindr\Uninstall Querymindr.lnk" "\$INSTDIR\uninstall.exe"

  ; Desktop shortcut
  CreateShortCut "\$DESKTOP\Querymindr.lnk" "\$INSTDIR\Querymindr.url"

  ; Write uninstaller and Add/Remove Programs entry
  WriteUninstaller "\$INSTDIR\uninstall.exe"
  WriteRegStr HKCU "\${UNINSTALL_KEY}" "DisplayName"    "Querymindr"
  WriteRegStr HKCU "\${UNINSTALL_KEY}" "UninstallString" "\$INSTDIR\uninstall.exe"
  WriteRegStr HKCU "\${UNINSTALL_KEY}" "DisplayVersion"  "\${APP_VERSION}"
  WriteRegStr HKCU "\${UNINSTALL_KEY}" "Publisher"       "Music Savvy"

  ; Give the server 3 seconds to start, then open the browser
  Sleep 3000
  ExecShell "open" "\${APP_URL}"

SectionEnd

; ---------------------------------------------------------------
; Uninstall Section
; ---------------------------------------------------------------
Section "Uninstall"
  nsExec::ExecToLog 'schtasks /delete /tn "\${TASK_NAME}" /f'
  nsExec::ExecToLog 'taskkill /f /im node.exe'
  RMDir /r "\$INSTDIR"
  RMDir /r "\$SMPROGRAMS\Querymindr"
  Delete "\$DESKTOP\Querymindr.lnk"
  DeleteRegKey HKCU "\${UNINSTALL_KEY}"
SectionEnd
NSIEOF

echo "     Done."

# ---------------------------------------------------------------
# Build the .exe
# ---------------------------------------------------------------
echo "5/5  Building Windows installer..."
makensis -V2 "$NSIS_SCRIPT"
rm -rf "$STAGING"

if [ -f "$OUTPUT" ]; then
  SIZE=$(du -sh "$OUTPUT" | cut -f1)
  echo ""
  echo "╔════════════════════════════════════════════╗"
  echo "║  Querymindr-windows-setup.exe is ready!    ║"
  echo "║  Size: $SIZE                                ║"
  echo "╚════════════════════════════════════════════╝"
  echo ""
  echo "  Share Querymindr-windows-setup.exe with Windows users."
  echo "  They double-click it — no Terminal needed."
  echo ""
else
  echo "ERROR: Build failed — output file not found."
  exit 1
fi
