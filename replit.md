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

## User Preferences

- **Always include the terminal install command** whenever a new release (`querymindr-release.tar.gz`) is packaged. Show both the standard install command and the `.pkg` build command.
  - Install: `cd ~/Downloads && tar -xzf querymindr-release.tar.gz && cd querymindr-release && bash install-querymindr.sh`
  - Build pkg: `cd ~/Downloads && tar -xzf querymindr-release.tar.gz && cd querymindr-release && bash build-pkg.sh`
- Non-technical user — always lead with the simplest approach first, avoid jargon.

## Key Commands

- `pnpm run typecheck` — full typecheck across all packages
- `pnpm run build` — typecheck + build all packages
- `pnpm --filter @workspace/api-spec run codegen` — regenerate API hooks and Zod schemas from OpenAPI spec
- `pnpm --filter @workspace/db run push` — push DB schema changes (dev only)
- `pnpm --filter @workspace/api-server run dev` — run API server locally

See the `pnpm-workspace` skill for workspace structure, TypeScript setup, and package details.
