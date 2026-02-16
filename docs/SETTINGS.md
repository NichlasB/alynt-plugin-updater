# Alynt Plugin Updater Settings Documentation

## Database Options

| Option Key | Type | Default | Sanitization | Location | Description |
|------------|------|---------|--------------|----------|-------------|
| `alynt_pu_check_frequency` | string | `'twicedaily'` | `Settings::sanitize_frequency()` | General Settings | Update check frequency. Valid values: `six_hours`, `twicedaily`, `daily`, `weekly` |
| `alynt_pu_cache_duration` | integer | `3600` | Clamped 300-86400 | General Settings | Cache duration for GitHub API responses in seconds. Minimum: 300 (5 min), Maximum: 86400 (24 hrs) |
| `alynt_pu_webhook_secret` | string | `''` | `sanitize_text_field()` | Webhook Configuration | Secret key for GitHub webhook HMAC SHA-256 authentication. Empty means webhooks are disabled |
| `alynt_pu_last_check` | integer | `0` | N/A (runtime) | Status Display | Unix timestamp of the last scheduled or manual update check |

## Transients

| Transient Key | Type | TTL | Description |
|---------------|------|-----|-------------|
| `alynt_pu_github_plugins` | array | Persistent (0) | Cached list of installed plugins with `GitHub Plugin URI` header. Cleared on plugin activation/deactivation/upgrade |
| `alynt_pu_rate_limited` | integer | Dynamic | Unix timestamp when GitHub API rate limit resets. Automatically deleted when limit expires |
| `alynt_pu_release_{owner}_{repo}` | array | Configurable | Cached release data for a specific repository. TTL set by `alynt_pu_cache_duration` option |

### Transient Structure Examples

**GitHub Plugins Cache (`alynt_pu_github_plugins`):**
```php
array(
    'my-plugin/my-plugin.php' => array(
        'name'        => 'My Plugin',
        'version'     => '1.0.0',
        'owner'       => 'username',
        'repo'        => 'repository',
        'plugin_uri'  => 'https://github.com/username/repository',
        'author'      => 'Author Name',
        'author_uri'  => 'https://example.com',
        'description' => 'Plugin description',
    ),
)
```

**Release Cache (`alynt_pu_release_{owner}_{repo}`):**
```php
array(
    'version'      => '1.2.0',
    'tag'          => 'v1.2.0',
    'download_url' => 'https://github.com/owner/repo/releases/download/v1.2.0/plugin.zip',
    'changelog'    => 'Release notes markdown',
    'published_at' => '2026-02-16T12:00:00Z',
    'cached_at'    => 1708088400,
    'source'       => 'releases', // or 'tags'
)
```

**Negative Cache (No Releases):**
```php
array(
    'error'     => true,
    'code'      => 'no_releases',
    'cached_at' => 1708088400,
)
```

## Cron Events

| Event Name | Schedule | Handler | Description |
|------------|----------|---------|-------------|
| `alynt_pu_scheduled_check` | Configurable | `Cron_Manager::run_scheduled_check()` | Scheduled update check for all registered GitHub plugins. Frequency controlled by `alynt_pu_check_frequency` option |

### Custom Cron Schedules

The plugin registers these custom schedules:

| Schedule Key | Interval | Display Name |
|--------------|----------|--------------|
| `six_hours` | 21600 seconds (6 hours) | Every 6 hours |
| `weekly` | 604800 seconds (7 days) | Once weekly |

Note: `twicedaily` and `daily` are WordPress core schedules.

## REST API Endpoints

| Endpoint | Method | Authentication | Handler | Description |
|----------|--------|----------------|---------|-------------|
| `/wp-json/alynt-pu/v1/webhook` | POST | Webhook secret (HMAC SHA-256) | `Webhook_Handler::handle_webhook()` | GitHub webhook receiver for release events. Validates signature, processes payload, clears cache, triggers update check |

### Webhook Request Format

**Headers:**
- `X-Hub-Signature-256`: GitHub's HMAC SHA-256 signature
- `Content-Type`: `application/json`

**Payload:**
```json
{
    "action": "published",
    "repository": {
        "full_name": "username/repository"
    },
    "release": {
        "tag_name": "v1.2.0"
    }
}
```

**Accepted Actions:** `published`, `released`, `created`

## AJAX Actions

| Action | Handler | Capability | Nonce | Description |
|--------|---------|------------|-------|-------------|
| `alynt_pu_check_all_updates` | `Settings::ajax_check_all_updates()` | `update_plugins` | `alynt_pu_check_all` | Check all registered plugins for updates, clearing cache first |
| `alynt_pu_generate_secret` | `Settings::ajax_generate_secret()` | `manage_options` | `alynt_pu_generate_secret` | Generate a new webhook secret (32-character random string) |
| `alynt_pu_check_single_update` | `Plugins_List::ajax_check_single_update()` | `update_plugins` | `alynt_pu_check_{plugin_file}` | Check a single plugin for updates, clearing its cache first |

## Settings UI Sections

### General Settings

- **Check Frequency**: Dropdown to control how often the plugin checks GitHub for updates
  - Every 6 hours
  - Every 12 hours (default)
  - Every 24 hours
  - Once weekly

- **Cache Duration**: Number input for GitHub API response cache duration in seconds
  - Minimum: 300 seconds (5 minutes)
  - Maximum: 86400 seconds (24 hours)
  - Default: 3600 seconds (1 hour)

### Webhook Configuration

- **Webhook URL**: Read-only field displaying the REST API endpoint URL. Includes copy button.
- **Secret Key**: Read-only field displaying the current webhook secret. Includes:
  - Copy button
  - "Generate New Secret" button to create a fresh secret

### Status

- **Last Check**: Human-readable timestamp of the last full update check
- **Next Scheduled Check**: Human-readable timestamp of the next cron run
- **Rate Limit Status**: Shows when GitHub API limits reset (if currently rate-limited)
- **Registered Plugins**: Table listing all detected plugins with:
  - Plugin name
  - Current version
  - GitHub repository
  - Update status

### Actions

- **Check All Updates**: Button to manually trigger an update check for all plugins
  - Clears all caches
  - Force-fetches fresh data from GitHub
  - Updates the update_plugins transient

## GitHub Webhook Setup

1. Go to your GitHub repository → **Settings** → **Webhooks** → **Add webhook**
2. **Payload URL**: Use the webhook URL from plugin settings
3. **Content type**: Select `application/json`
4. **Secret**: Paste the generated secret from plugin settings
5. **SSL verification**: Enable SSL verification
6. **Which events**: Select "Let me select individual events"
   - Check **Releases** only
   - Uncheck all other events
7. **Active**: Ensure the webhook is marked as active
8. Click **Add webhook**

### Testing the Webhook

After setup, you can test the webhook by:
1. Publishing a new release on GitHub
2. Checking the webhook delivery history in GitHub Settings
3. Checking WordPress debug.log for `[Alynt Plugin Updater]` entries (if WP_DEBUG is enabled)

Note: Webhook is optional. Scheduled cron checks will still run if webhooks are not configured.
