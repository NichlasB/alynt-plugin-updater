# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-03-23

### Style
- Align admin UI with WordPress design system guide
- Add empty-state and validation-error styles
- Add proper disabled + aria-disabled + aria-busy state to update buttons
- Add dedicated aria-live region for status updates

### Improved
- Add inline field-level validation for cache duration (aria-invalid, role=alert)
- Preserve invalid cache-duration input across validation round-trip
- Replace raw "No GitHub-managed plugins found" row with structured empty state
- Rewrite permission-denied and invalid-plugin error messages in plain language
- Replace update(s)/error(s) pattern with proper singular/plural localization

## [1.1.0] - 2026-03-20

### Changed
- Refactored the plugin toward a clearer service-based architecture
- Improved update checker result handling for more reliable GitHub release detection
- Improved plugin metadata and update folder handling

### Infrastructure
- Refreshed the GitHub release workflow to use current action versions
- Added npm build handling to the release workflow when `package.json` is present
- Updated the plugin compatibility guide to match the current automated GitHub release flow

## [1.0.0] - 2026-02-16

### Added
- GitHub release monitoring for WordPress plugins
- Support for both `v1.0.0` and `1.0.0` tag formats
- Automatic fallback to tags API when releases are not available
- Per-plugin "Check for updates" link in plugins list
- Settings page with configurable check frequency and cache duration
- Optional GitHub webhook support for instant update notifications
- Automatic cache management with configurable duration (5 minutes to 24 hours)
- Rate limit tracking and handling for GitHub API
- Source directory fixing for GitHub zipball downloads to ensure proper plugin folder naming
- Comprehensive logging system with debug, info, warning, and error levels
- REST API endpoint (`/wp-json/alynt-pu/v1/webhook`) for webhook processing
- AJAX handlers for single plugin update checks and bulk update checks
- Webhook signature verification using HMAC SHA-256
- Scheduled cron events with customizable frequencies (6 hours, 12 hours, 24 hours, weekly)
- Plugin scanner with caching for improved performance
- Service factory pattern for dependency injection
- Automatic cleanup of settings and transients on uninstall
