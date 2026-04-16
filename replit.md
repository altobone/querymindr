# Workspace

## Overview

pnpm workspace monorepo using TypeScript. Each package manages its own dependencies.

## Stack

- **Monorepo tool**: pnpm workspaces
- **Node.js version**: 24
- **Package manager**: pnpm
- **TypeScript version**: 5.9
- **API framework**: Express 5
- **Database**: PostgreSQL + Drizzle ORM
- **Validation**: Zod (`zod/v4`), `drizzle-zod`
- **API codegen**: Orval (from OpenAPI spec)
- **Build**: esbuild (CJS bundle)

## The Loft Mobile App

Expo (React Native) app at `artifacts/loft-mobile` for Music Savvy musicians to submit recordings for coaching feedback.

### Features
- Login with WordPress username + password (normal credentials, not Application Passwords)
- Account registration from within the app
- Audio recording via expo-av (M4A format) with playback and re-record
- Video link mode (YouTube/Vimeo URL + optional start time)
- Two feedback questions: "What doesn't feel right?" and "What would you like to improve?"
- Instrument picker loaded from The Loft REST API
- S3 upload via presign flow, then submission to The Loft WordPress plugin

### Auth Flow
- Login calls `POST /auth` with username + password → receives JWT (90-day expiry)
- Registration calls `POST /register` → receives JWT immediately (auto-login)
- JWT stored in SecureStore; sent as `Authorization: Bearer <token>` on all requests
- Server validates JWT via `determine_current_user` filter hook (integrates with WP natively)
- `AuthContext` exposes `login(username, token)`, `logout()`, `getAuthHeader()`

### API Integration
- Base URL: `https://musicsavvy.com/wp-json/the-loft/v1/`
- Auth: Custom JWT Bearer token (issued by `/auth` or `/register` endpoints)
- Endpoints: POST /auth, POST /register, GET /instruments, POST /presign, POST /submit
- WordPress plugin extension lives in `loft-plugin-extension/`

### Key Packages
- `expo-av` — audio recording
- `expo-secure-store` — credential storage
- `@tanstack/react-query` — API state management
- `react-native-keyboard-controller` — keyboard handling

## Querymindr (File Finder)

Self-contained local search tool for large external drives. React+Vite frontend + Express/Node.js backend. Indexes files into SQLite (node:sqlite). Optional Claude AI. Runs entirely offline.

- **App URL**: `http://localhost:8080/querymindr/`
- **DB path**: `~/.config/querymindr/querymindr.db`
- **Install dir**: `~/.querymindr/`
- **launchd label**: `com.musicsavvy.querymindr` (Mac)

### Release Artifacts (build on Replit, distribute to users)

| File | Platform | Size | How to build |
|------|----------|------|--------------|
| `querymindr-mac-release.tar.gz` | macOS | ~35 MB | auto-built by `build-release.sh` / `pnpm run build` |
| `querymindr-linux_1.0_amd64.deb` | Linux | ~32 MB | `bash build-deb.sh` |
| `Querymindr-windows-setup.exe` | Windows | ~23 MB | `bash build-exe.sh` |

### Mac build command (for user's machine)
```
cd ~/Downloads && tar -xzf querymindr-mac-release.tar.gz && cd querymindr-release && bash build-pkg.sh
```
- NEVER give the user `install-querymindr.sh` — always `build-pkg.sh` to get the .pkg installer
- API key stored in `~/.config/querymindr/querymindr.db` — survives reinstalls

### Trial & Licensing System
- **Trial**: 7 days from first install, tracked in `kv_store` DB table AND `~/.config/querymindr/.trial` file (both must be deleted to reset)
- **License keys**: `QMDR-XXXX-XXXX-XXXX-XXXX` format — self-validating HMAC-SHA256 offline (no server needed)
- **Key generator**: `node generate-keys.mjs [count]` — generates N keys for import into WooCommerce Serial Numbers plugin
- **Activation endpoint**: `POST /api/querymindr/license/activate` — validates and stores key in `kv_store`
- **Status endpoint**: `GET /api/querymindr/license/status` — returns `{ licensed, daysRemaining, installDate }`
- **Frontend gate**: `App.tsx` checks status on load → shows `TrialExpiredPage` when `!licensed && daysRemaining === 0`
- **Sidebar badge**: Shows "X days left in trial" (amber when ≤ 2 days) or "Licensed" (green) in the sidebar
- **Settings card**: License card at top of Settings page with key entry field
- **Checkout URLs**: Placeholder `https://musicsavvy.com/checkout/querymindr-{mac,windows,linux}` — update once CartFlows pages are live
- **Secret**: Embedded in `artifacts/api-server/src/lib/querymindr-license.ts` and `generate-keys.mjs` — keep `generate-keys.mjs` private

### Windows installer (`build-exe.sh`)
- NSIS installer built on Replit via `nix-shell -p nsis -p unzip`
- Installs to `%LOCALAPPDATA%\Querymindr\` (no admin required)
- Task Scheduler entry for auto-start at login
- Desktop + Start Menu shortcuts
- Uninstaller registered in Add/Remove Programs
- Node.js v22.13.1 Windows x64 binary bundled (~27 MB, cached at `~/.querymindr-build-cache/`)

## User Preferences

- **Always include the terminal install command** whenever a new release (`querymindr-mac-release.tar.gz`) is packaged. Show the `.pkg` build command.
  - Build pkg: `cd ~/Downloads && tar -xzf querymindr-mac-release.tar.gz && cd querymindr-release && bash build-pkg.sh`
- Non-technical user — always lead with the simplest approach first, avoid jargon.

## Key Commands

- `pnpm run typecheck` — full typecheck across all packages
- `pnpm run build` — typecheck + build all packages
- `pnpm --filter @workspace/api-spec run codegen` — regenerate API hooks and Zod schemas from OpenAPI spec
- `pnpm --filter @workspace/db run push` — push DB schema changes (dev only)
- `pnpm --filter @workspace/api-server run dev` — run API server locally

See the `pnpm-workspace` skill for workspace structure, TypeScript setup, and package details.
