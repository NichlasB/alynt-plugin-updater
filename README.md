# Alynt Plugin Updater

A lightweight WordPress plugin that enables automatic updates for GitHub-hosted plugins. Uses WordPress's native update system with GitHub public releases.

## Features

- Checks GitHub releases for new versions.
- Supports tags formatted as `v1.0.0` or `1.0.0`.
- Per-plugin "Check for updates" link in the plugins list.
- Optional GitHub webhook support for instant update checks.
- Settings page with status, cache controls, and webhook setup.

## Installation

1. Upload the `alynt-plugin-updater` folder to `/wp-content/plugins/`.
2. Activate the plugin via the WordPress admin.
3. Visit **Settings → Plugin Updater** to configure options.

## Registering a Plugin

Add a header to the plugin's main file:

```
GitHub Plugin URI: username/repository
```

Or use the full URL:

```
GitHub Plugin URI: https://github.com/username/repository
```

## Webhook Setup

Webhook support is optional. Configure it in **Settings → Plugin Updater** and follow the instructions shown.

## Development

Install dependencies:

```
composer install
npm install
```

Build assets:

```
npm run build
```

Lint JavaScript:

```
npm run lint
```

## License

GPL v2 or later.
