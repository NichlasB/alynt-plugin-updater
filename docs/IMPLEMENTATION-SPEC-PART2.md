# Alynt Plugin Updater — Implementation Specification (Part 2)

---

## 4. Caching Strategy

### 4.1 Transient Definitions

#### `alynt_pu_github_plugins`

**Purpose:** Cache list of plugins with GitHub Plugin URI header

**Structure:**
```php
[
    'my-plugin/my-plugin.php' => [
        'name' => 'My Plugin',
        'version' => '1.0.0',
        'owner' => 'username',
        'repo' => 'my-plugin',
        'plugin_uri' => 'https://github.com/username/my-plugin',
    ],
]
```

**Duration:** No expiration (deleted on invalidation)

**Invalidation Triggers:**
- `activated_plugin`, `deactivated_plugin`, `upgrader_process_complete` hooks
- Manual `Plugin_Scanner::clear_cache()`

---

#### `alynt_pu_release_{owner}_{repo}`

**Key Format:** `alynt_pu_release_` + `sanitize_key("{owner}_{repo}")`

**Structure (success):**
```php
[
    'version' => '1.2.0',
    'tag' => 'v1.2.0',
    'download_url' => 'https://github.com/.../releases/download/v1.2.0/plugin.zip',
    'changelog' => '## Changes\n- Feature X',
    'published_at' => '2026-01-20T10:30:00Z',
    'cached_at' => 1706295600,
    'source' => 'releases',
]
```

**Structure (negative cache):**
```php
[
    'error' => true,
    'code' => 'no_releases',
    'cached_at' => 1706295600,
]
```

**Duration:** `get_option('alynt_pu_cache_duration', 3600)` seconds

**Invalidation:** Manual check, webhook, "Check All" button

---

#### `alynt_pu_rate_limited`

**Structure:** Unix timestamp (rate limit reset time)

**Duration:** Calculated as `(reset_timestamp - current_time)` seconds

---

#### `alynt_pu_updating_{plugin_slug}`

**Structure:** Unix timestamp (lock set time)

**Duration:** 300 seconds (5 minutes)

---

### 4.2 Cache Behavior Matrix

| Scenario | Cache | API | Behavior |
|----------|-------|-----|----------|
| Normal request | Miss | Available | Fetch, cache, return |
| Normal request | Hit (valid) | Available | Return cached |
| Rate limited | Any | Unavailable | Return cached or error |
| API error | Hit (stale) | Unavailable | Return stale, log warning |
| Manual check | Any | Available | Clear cache, fetch fresh |

---

## 5. Method Specifications

### 5.1 class-loader.php

```php
namespace Alynt\PluginUpdater;

class Loader {
    public static function register(): void
    public static function autoload(string $class): void
}
```

**Mapping:** `Alynt\PluginUpdater\` → `/includes/class-{name}.php`, `Admin\` → `/admin/class-{name}.php`

---

### 5.2 class-activator.php

```php
class Activator {
    public static function activate(): void
}
```

**Actions:** Set default options, schedule cron, clear stale transients

**Defaults:**
- `alynt_pu_check_frequency` → `'twicedaily'`
- `alynt_pu_cache_duration` → `3600`
- `alynt_pu_webhook_secret` → `''`
- `alynt_pu_last_check` → `0`

---

### 5.3 class-deactivator.php

```php
class Deactivator {
    public static function deactivate(): void
}
```

**Actions:** Unschedule cron. Do NOT delete options/transients.

---

### 5.4 class-logger.php

```php
class Logger {
    public function debug(string $message, array $context = []): void
    public function info(string $message, array $context = []): void
    public function warning(string $message, array $context = []): void
    public function error(string $message, array $context = []): void
}
```

**Format:** `[Alynt Plugin Updater] [{level}] {message} {json_context}`

Uses `error_log()`. Debug only logs if `WP_DEBUG` is true.

---

### 5.5 class-version-util.php

```php
class Version_Util {
    public function normalize(string $version): string
    public function compare(string $v1, string $v2, string $op = '>'): bool
    public function is_update_available(string $current, string $remote): bool
}
```

Normalization strips `v`/`V` prefix. Uses `version_compare()`.

---

### 5.6 class-plugin-scanner.php

```php
class Plugin_Scanner {
    private const CACHE_KEY = 'alynt_pu_github_plugins';
    private const HEADER_KEY = 'GitHub Plugin URI';

    public function get_github_plugins(): array
    public function get_plugin_github_data(string $plugin_file): ?array
    public function parse_github_uri(string $uri): ?array
    public function clear_cache(): void
    public function register_hooks(): void
}
```

**`parse_github_uri` accepts:**
- `'username/repository'`
- `'https://github.com/username/repository'`
- `'https://github.com/username/repository.git'`

**Returns:** `['owner' => string, 'repo' => string]` or `null`

---

### 5.7 class-github-api.php

```php
class GitHub_API {
    private const API_BASE = 'https://api.github.com';
    private const CACHE_PREFIX = 'alynt_pu_release_';

    public function __construct(Version_Util $version_util, Logger $logger)
    
    public function get_latest_release(string $owner, string $repo, bool $force_fresh = false): array|WP_Error
    public function get_release_changelog(string $owner, string $repo): string
    public function clear_cache(string $owner, string $repo): void
    public function is_rate_limited(): bool|int
    
    private function get_user_agent(): string
    private function request(string $endpoint): array|WP_Error
    private function handle_rate_limit_headers(array $headers): void
    private function get_cache_key(string $owner, string $repo): string
    private function get_download_url(array $release): string
}
```

**WP_Error codes:** `'rate_limited'`, `'no_releases'`, `'api_error'`, `'network_error'`

**User-Agent:** `Alynt-Plugin-Updater/1.0.0; WordPress/{version}; PHP/{version}`

**HTTP Config:**
```php
wp_remote_get($url, [
    'timeout' => 15,
    'headers' => [
        'Accept' => 'application/vnd.github.v3+json',
        'User-Agent' => $this->get_user_agent(),
    ],
]);
```

**Download URL Priority:**
1. First `.zip` asset in release assets
2. `zipball_url` (source archive)

---

### 5.8 class-update-checker.php

```php
class Update_Checker {
    public function __construct(Plugin_Scanner $scanner, GitHub_API $api, Version_Util $util)
    
    public function register_hooks(): void
    public function check_for_updates(object $transient): object
    public function plugin_information(false|object|array $result, string $action, object $args): false|object
    public function check_plugin_update(string $plugin_file): array
    public function check_all_updates(bool $force_fresh = false): array
    
    private function build_plugin_info(string $plugin_file, array $plugin_data, array $release): object
}
```

**Transient Injection Format:**
```php
$transient->response[$plugin_file] = (object) [
    'slug'        => $slug,
    'plugin'      => $plugin_file,
    'new_version' => $release['version'],
    'url'         => "https://github.com/{$owner}/{$repo}",
    'package'     => $release['download_url'],
    'icons'       => [],
    'banners'     => [],
    'tested'      => '',
    'requires_php' => '',
    'compatibility' => new stdClass(),
];
```

**`check_plugin_update` returns:**
```php
[
    'update_available' => bool,
    'current_version' => string,
    'new_version' => string,
    'download_url' => string,
]
```

---

### 5.9 class-plugin-installer.php

```php
class Plugin_Installer {
    public function __construct(Logger $logger)
    
    public function install_update(string $plugin_file, string $download_url): true|WP_Error
    
    private function acquire_lock(string $plugin_slug): bool
    private function release_lock(string $plugin_slug): void
    private function init_filesystem(): bool|WP_Error
    private function find_extracted_folder(string $temp_dir): string|WP_Error
    private function validate_plugin_structure(string $folder, string $plugin_file): bool|WP_Error
    private function cleanup(?string $temp_file, ?string $temp_dir): void
}
```

**WP_Error codes:**
- `'update_in_progress'`
- `'download_failed'`
- `'extraction_failed'`
- `'invalid_plugin_structure'`
- `'move_failed'`
- `'filesystem_error'`

---

### 5.10 class-cron-manager.php

```php
class Cron_Manager {
    private const HOOK_NAME = 'alynt_pu_scheduled_check';

    public function __construct(Update_Checker $checker)
    
    public function register_hooks(): void
    public function add_cron_schedules(array $schedules): array
    public function schedule_checks(): void
    public function unschedule_checks(): void
    public function update_frequency(string $new_frequency): void
    public function run_scheduled_check(): void
    public function is_scheduled(): bool|int
}
```

**Custom Schedules:**
```php
'six_hours' => ['interval' => 21600, 'display' => 'Every 6 hours'],
'weekly' => ['interval' => 604800, 'display' => 'Once weekly'],
```

---

### 5.11 class-webhook-handler.php

```php
class Webhook_Handler {
    private const REST_NAMESPACE = 'alynt-pu/v1';
    private const REST_ROUTE = '/webhook';

    public function __construct(Plugin_Scanner $scanner, GitHub_API $api, Update_Checker $checker, Logger $logger)
    
    public function register_rest_route(): void
    public function handle_webhook(WP_REST_Request $request): WP_REST_Response
    public function get_webhook_url(): string
    public static function generate_secret(): string
    
    private function verify_signature(string $payload, string $signature): bool|WP_REST_Response
    private function process_payload(array $payload): WP_REST_Response
}
```

**REST Route:**
```php
register_rest_route('alynt-pu/v1', '/webhook', [
    'methods' => 'POST',
    'callback' => [$this, 'handle_webhook'],
    'permission_callback' => '__return_true',
]);
```

**Secret Generation:** `wp_generate_password(32, false)`

---

### 5.12 class-admin-menu.php (Admin namespace)

```php
namespace Alynt\PluginUpdater\Admin;

class Admin_Menu {
    public function __construct(Settings $settings)
    public function register_hooks(): void
    public function add_menu_page(): void
}
```

**Menu:**
```php
add_options_page(
    'Alynt Plugin Updater',
    'Plugin Updater',
    'manage_options',
    'alynt-plugin-updater',
    [$this->settings, 'render_settings_page']
);
```

---

### 5.13 class-settings.php (Admin namespace)

```php
class Settings {
    private const OPTION_GROUP = 'alynt_pu_settings';
    private const PAGE_SLUG = 'alynt-plugin-updater';

    public function register_settings(): void
    public function render_settings_page(): void
    public function register_ajax_handlers(): void
    public function ajax_check_all_updates(): void
    public function ajax_generate_secret(): void
    public function sanitize_frequency(string $value): string
    public function sanitize_cache_duration(mixed $value): int
    public function get_frequency_options(): array
}
```

**Frequency Options:**
```php
[
    'six_hours' => 'Every 6 hours',
    'twicedaily' => 'Every 12 hours',
    'daily' => 'Every 24 hours',
    'weekly' => 'Once weekly',
]
```

---

### 5.14 class-plugins-list.php (Admin namespace)

```php
class Plugins_List {
    public function __construct(Plugin_Scanner $scanner, GitHub_API $api, Update_Checker $checker)
    
    public function register_hooks(): void
    public function add_check_update_link(array $links, string $plugin_file): array
    public function enqueue_scripts(string $hook_suffix): void
    public function ajax_check_single_update(): void
}
```

**Script Localization:**
```php
wp_localize_script('alynt-pu-admin', 'alyntPuAdmin', [
    'ajaxurl' => admin_url('admin-ajax.php'),
    'checking' => 'Checking...',
    'upToDate' => 'Up to date ✓',
    'updateAvailable' => 'Update available (v%s)',
    'checkFailed' => 'Check failed',
]);
```
