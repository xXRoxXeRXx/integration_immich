# Copilot Instructions – integration_immich

## Project Summary

**Immich Integration for Nextcloud** – a Nextcloud app that embeds the full Immich photo library (timeline, albums, people, map, explore, lightbox) as a native Nextcloud page.  
App ID: `integration_immich` | Version: 1.3.0 | License: AGPL-3.0-or-later  
Author: Marcel Meyer (`gh@grenzallee.eu`)

---

## Tech Stack

| Layer | Technologies |
|---|---|
| Backend | PHP 8.2+, Nextcloud AppFramework (OCP/OCA), Composer, PSR-4 |
| Frontend | Vue 3 (Composition API / `<script setup>`), Pinia, Vue Router 4, `@nextcloud/vue`, `@nextcloud/axios` |
| Build | Node.js 24, npm, Webpack 5 (`@nextcloud/webpack-vue-config`) |
| Tests | PHPUnit (PHP 8.2–8.4 × SQLite/MySQL/PostgreSQL × NC stable30–33/master) |

---

## Project Layout

```
appinfo/
  info.xml          # Nextcloud app manifest (ID, version, dependencies)
  routes.php        # All API routes → maps to lib/Controller/*
lib/
  AppInfo/
    Application.php # Bootstrap: registers event listeners (CSP, file scripts)
  Controller/       # AlbumsController, AssetsController, ConfigController,
                    # PageController, PeopleController, UploadController
  Service/
    ImmichService.php  # Core Immich HTTP API wrapper
  Listener/         # CspListener, LoadAdditionalScriptsListener
  Settings/         # PersonalSection.php, PersonalSettings.php
src/
  main.js           # Webpack entry – main Vue SPA
  adminSettings.js  # Webpack entry – admin settings panel
  fileAction.js     # Webpack entry – NC < 32 file action
  fileAction-nc32.js # Webpack entry – NC 32+ file action
  App.vue           # Root component
  components/       # 12 Vue views: TimelineView, AlbumsView, AlbumDetailView,
                    # PeopleView, PersonDetailView, MapView, ExploreView,
                    # PlaceDetailView, LightboxView, PhotoGrid,
                    # Navigation, AssetPickerModal
  services/
    api.js          # Axios-based API client (all backend calls)
    logger.js       # @nextcloud/logger wrapper
    storage.js      # @nextcloud/initial-state + localStorage helpers
    uploadProgress.js
  store/immich.js   # Pinia store
  router/index.js   # Vue Router configuration
l10n/               # i18n: *.json (source) + *.js (generated – do NOT edit manually)
webpack.config.js   # Extends @nextcloud/webpack-vue-config; publicPath = 'auto'
build-l10n.mjs      # Generates l10n/*.js from l10n/*.json
```

---

## Build & Development

### JavaScript (frontend)

> **Always run `npm install --legacy-peer-deps`** before any build.  
> Plain `npm install` fails due to peer dependency conflicts.

```bash
# Install dependencies (required once, or after package.json changes)
npm install --legacy-peer-deps

# Production build (used for releases)
npm run build

# Development build (unminified)
npm run dev

# Watch mode (rebuilds on file change)
npm run watch

# Regenerate only l10n JS files (after editing l10n/*.json)
npm run l10n
```

`npm run build` / `npm run dev` automatically runs `node build-l10n.mjs` before Webpack.  
The compiled output lands in `js/` and must be committed.  
`.map` source map files are **stripped** in release tarballs.

### PHP (backend)

```bash
# Install PHP dev dependencies (required for tests)
composer install

# Install production-only PHP dependencies (for release)
composer install --no-dev
```

---

## PHP Conventions

- All PHP files start with the SPDX header:
  ```php
  // SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  // SPDX-License-Identifier: AGPL-3.0-or-later
  ```
- Always use `declare(strict_types=1);` at the top of every PHP file.
- PHP namespace root: `OCA\IntegrationImmich` (matching `lib/`).
- New routes go in `appinfo/routes.php` following the existing naming pattern `controllerName#methodName`.
- New controllers extend `\OCP\AppFramework\Controller` and are auto-wired via DI.

## Frontend Conventions

- Use Vue 3 `<script setup>` Composition API for all new components.
- HTTP calls go through `src/services/api.js` (uses `@nextcloud/axios`).
- User-visible strings must be wrapped with `t('integration_immich', '...')` from `@nextcloud/l10n`.
- After adding new translatable strings, run `npm run l10n` and commit the updated `l10n/*.js` files.
- Use `@nextcloud/vue` components (`NcButton`, `NcLoadingIcon`, etc.) instead of raw HTML where applicable.

---

## CI / Workflows

| Workflow | Trigger | What it does |
|---|---|---|
| `.github/workflows/phpunit.yml` | Push/PR touching `lib/`, `tests/`, `appinfo/`, `composer.*` | PHPUnit matrix: PHP 8.2–8.4 × 3 DBs × NC stable30–33/master |
| `.github/workflows/release.yml` | GitHub release published | `npm ci --legacy-peer-deps` → `npm run build` → assemble & sign tar.gz → upload to Nextcloud App Store |

Before submitting a PR that touches PHP, verify the changes would pass PHPUnit.  
Before submitting a PR that touches JS/Vue, run `npm run build` and ensure no Webpack errors.

---

## Key Facts

- The Webpack `publicPath` is set to `'auto'` so the app works from `custom_apps/` (not just `apps/`).
- Two file-action entry points exist because the Nextcloud Files API changed in NC 32 (`fileAction-nc32.js`).
- `l10n/*.js` files are **generated** – only edit the corresponding `*.json` files.
- The `build/` directory is `.gitignore`-excluded except `build/release/` and `build/source/` which are CI-only.
- Nextcloud compatibility: `min-version="27"`, `max-version="34"` (see `appinfo/info.xml`).
