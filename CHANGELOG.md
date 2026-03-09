# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.4] - 2026-03-09

### Fixed

- Missing `use OCP\AppFramework\Http\Attribute\NoAdminRequired;` import in `ConfigController` — the attribute was present but PHP silently ignored it without the import, causing Nextcloud to treat both config endpoints as admin-only and returning `403 Forbidden` for regular users
- Added `#[NoAdminRequired]` to `getConfig()` so regular users can also read their own saved settings

### Documentation

- Added required Immich API key permissions table to README

### Dependencies

- `vue` 3.5.29 → 3.5.30 (bug fixes: reactivity, SSR, custom elements)

## [1.0.3] - 2026-03-05

### Fixed

- File upload to Immich now streams the file content instead of loading it fully into PHP memory — prevents OOM crashes for large files
- `userId` null-guard added in upload and save-to-Nextcloud flows to return `401` instead of crashing
- `setConfig()` now correctly ignores `validate=false` strings (PHP truthy-check bug)
- `#[NoAdminRequired]` added to `setConfig()` so regular users can save their own settings

### Refactored

- UUID validation regex extracted to `ImmichService::UUID_PATTERN` — eliminates 18 duplicated inline patterns across all controllers
- `getUniqueFileName()` loop replaced with bounded `for`-loop and `uniqid()` fallback to prevent infinite loops
- `getPersonAssets()` capped at 24 monthly buckets (~2 years) to prevent unbounded sequential HTTP requests
- `uploadAsset()` response null-guard: invalid JSON from Immich no longer returns `null` to the frontend
- API key decrypt failures now logged as `warning` with hint to re-save the key

### Security

- `dompurify` updated to 3.3.2 — fixes XSS bypass and prototype pollution
- `immutable` updated to 5.1.5 — fixes prototype pollution in `mergeDeep`/`toJS`
- `minimatch` updated to 3.1.5 — fixes ReDoS vulnerability

### Dependencies

- `pinia` 2 → 3
- `vue` → 3.5.29, `vue-router` 4 → 5
- `eslint-webpack-plugin` 4 → 5
- `actions/checkout` v4 → v6, `actions/setup-node` v4 → v6
- `terser-webpack-plugin` → 5.3.17, `fast-xml-parser` → 4.5.4

## [1.0.2] - 2026-03-04

### Fixed

- **NC26–32 compatibility**: "Add to Immich" file action was not visible on Nextcloud 26–32
  due to a registry scoping change in `@nextcloud/files` v4 (only compatible with NC33+).
  A separate webpack bundle using `@nextcloud/files` v3 is now built and loaded automatically
  for Nextcloud versions below 33. Minimum supported version is now NC27.

### Security

- **API key encryption**: The Immich API key is now encrypted at rest using Nextcloud's
  `OCP\Security\ICrypto` before being written to `oc_preferences`. On read, the value is
  transparently decrypted; a plaintext fallback handles keys stored by older versions.
- **Input validation**: All controllers now validate and sanitize incoming parameters
  (album IDs, asset IDs, paths, URLs) and return proper HTTP error codes (`400`, `500`)
  instead of leaking raw error messages to the client.
- **Error handling**: `ImmichService` catches exceptions internally and surfaces structured
  error responses, preventing stack traces from reaching the frontend.

### Changed

- **Bootstrap**: Migrated from `boot()` + `addListener()` to `register()` +
  `registerEventListener()` for proper lazy-loading of the file-action event listener
  (recommended pattern since NC26).

## [1.0.1] - 2026-03-02

### Fixed

- Release ZIP now sets correct Unix permissions (755 for directories, 644 for files)
  so that `lib/` is traversable after unzip on Linux without a manual `chmod`
- Fixed autoloader not being executed due to a UTF-8 BOM in `composer/autoload.php`
  that caused a PHP fatal error (`strict_types` must be the first statement)
- Timeline: fast scrollbar jumps no longer leave the view blank — in-flight HTTP
  requests for buckets that are no longer visible are now cancelled via `AbortController`
  and stale entries are purged from the load queue immediately

## [1.0.0] - 2026-02-27

### Added

- Timeline view with lazy-loaded photos and videos grouped by date
- Albums view — browse, create, rename and delete Immich albums
- People view — explore your library by recognized person (face recognition)
- Map view — interactive map of all geotagged photos with cluster markers
- Explore view — browse by city, country, state, object or tag
- Lightbox — full-screen viewer with keyboard navigation, pinch-to-zoom and EXIF metadata panel
- Favorites — mark and unmark assets as favorites from any view
- Save to Nextcloud — save Immich originals directly to your Nextcloud Files via folder picker
- Upload to Immich — send photos and videos from Nextcloud Files to Immich via file action menu
- Multi-select mode — select multiple assets for batch save, download, favorite and album operations
- Album management in Lightbox — add current photo to existing or new album
- German (de) translation
- Personal settings — configure Immich server URL and API key per user
- Admin settings — set instance-wide defaults for server URL and API key
