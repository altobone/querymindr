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

Self-contained local search tool for large external drives. React+Vite frontend + Express/Node.js backend. Indexes files into SQLite (node:sqlite). Fuzzy search is the core product. Runs locally on Mac.

- **App URL**: `http://localhost:8080/querymindr/`
- **DB path**: `~/.config/querymindr/querymindr.db`
- **Install dir**: `~/.querymindr/`
- **launchd label**: `com.musicsavvy.querymindr` (Mac)

### Search Features
- **Exact match**: all query words must appear in filename (case-insensitive, substring)
- **Fuzzy fallback**: n-gram SQL pre-filter + Fuse.js; fires only when zero exact matches
- **Type synonyms**: natural-language words in query auto-expand to extension filters (e.g. "photos" → jpg/png/heic, "videos" → mp4/mov, "stems" → wav/aif)
- **More Like This**: pure fuzzy (Fuse.js) similarity by filename — no AI dependency
- **Folder search**: parallel folder-name search with Browse + Reveal in Finder

### Release Artifacts (build on Replit, distribute to users)

| File | Platform | Size | How to build |
|------|----------|------|--------------|
| `querymindr-release.tar.gz` | macOS | ~2.7 MB | `bash create-release.sh` |

### Mac build command (for user's machine)
```
cd ~/Downloads && tar -xzf querymindr-release.tar.gz && cd querymindr-release && bash build-pkg.sh
```
- NEVER give the user `install-querymindr.sh` — always `build-pkg.sh` to get the .pkg installer
- API key stored in `~/.config/querymindr/querymindr.db` — survives reinstalls

## User Preferences

- **Non-technical user** — always lead with the simplest approach first, avoid jargon.

## Key Commands

- `pnpm run typecheck` — full typecheck across all packages
- `pnpm run build` — typecheck + build all packages
- `pnpm --filter @workspace/api-spec run codegen` — regenerate API hooks and Zod schemas from OpenAPI spec
- `pnpm --filter @workspace/db run push` — push DB schema changes (dev only)
- `pnpm --filter @workspace/api-server run dev` — run API server locally

See the `pnpm-workspace` skill for workspace structure, TypeScript setup, and package details.
