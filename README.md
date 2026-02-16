# Alynt Plugin Updater

A lightweight WordPress plugin that enables automatic updates for GitHub-hosted plugins. Uses WordPress's native update system with GitHub public releases.

## Features

- Checks GitHub releases for new versions
- Supports tags formatted as `v1.0.0` or `1.0.0`
- Automatic fallback to tags API when releases are unavailable
- Per-plugin "Check for updates" link in the plugins list
- Optional GitHub webhook support for instant update checks
- Settings page with status, cache controls, and webhook setup
- Configurable check frequency (every 6, 12, 24 hours, or weekly)
- GitHub API rate limit tracking and handling
- Comprehensive caching system to minimize API requests

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- Composer (for development)
- Node.js 14+ and npm (for asset building)

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

## Webhook Setup (Optional)

Webhook support is optional but provides instant update notifications. Configure it in **Settings → Plugin Updater** and follow the instructions shown.

1. Go to your GitHub repository → **Settings** → **Webhooks** → **Add webhook**
2. Set **Payload URL** to the webhook URL shown in plugin settings
3. Set **Content type** to `application/json`
4. Set **Secret** to the secret key shown in plugin settings
5. Enable **SSL verification**
6. Select individual events → Check **Releases** only
7. Click **Add webhook**

Note: Even without webhooks, the plugin will check for updates via scheduled cron jobs.

## Development

Install dependencies:

```bash
composer install
npm install
```

Build assets:

```bash
npm run build
```

Lint JavaScript:

```bash
npm run lint
```

Run tests:

```bash
composer test
```

Check code style:

```bash
composer phpcs
```

## FAQ

### How do I register a plugin for updates?

Add the following header to your plugin's main PHP file:

```php
/**
 * GitHub Plugin URI: username/repository
 */
```

Or use the full URL:

```php
/**
 * GitHub Plugin URI: https://github.com/username/repository
 */
```

### Does this work with private repositories?

No, this plugin only supports public GitHub repositories. Private repository support would require GitHub authentication tokens.

### How often does it check for updates?

By default, every 12 hours. You can configure this in **Settings → Plugin Updater** to check every 6 hours, 12 hours, 24 hours, or weekly.

### Do I need to set up webhooks?

No, webhooks are optional. The plugin will check for updates via scheduled cron jobs even without webhooks. Webhooks simply provide instant notifications when you publish a new release.

### What happens if GitHub rate limits are reached?

The plugin will use cached data until the rate limit resets. The settings page shows when the limit will reset. For unauthenticated requests, GitHub allows 60 requests per hour.

### Can I manually check for updates?

Yes, there are two ways:
1. Click the "Check for updates" link below any GitHub-managed plugin in the plugins list
2. Use the "Check All Updates" button in **Settings → Plugin Updater**

### What version tag formats are supported?

Both `v1.0.0` and `1.0.0` formats are supported. The plugin automatically normalizes version strings.

### Does this plugin modify my managed plugins?

No, it only provides update information to WordPress. The actual plugin updates are handled by WordPress's native update system.

### Where can I find the plugin logs?

If `WP_DEBUG` is enabled, logs are written to your WordPress debug.log file with the prefix `[Alynt Plugin Updater]`.

## License

GPL v2 or later.
