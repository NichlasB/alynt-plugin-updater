# Alynt Plugin Updater Hooks

This plugin integrates with WordPress's native update system and does not currently expose custom hooks for extensibility. However, it hooks into several WordPress core filters to provide its functionality.

## WordPress Core Hooks Used

### `pre_set_site_transient_update_plugins`

**Purpose:** Inject GitHub-sourced updates into WordPress's plugin update transient.

**Handler:** `Alynt\PluginUpdater\Update_Checker::check_for_updates()`

**Priority:** 10 (default)

**When It Fires:** Automatically when WordPress checks for plugin updates (typically twice daily, or when manually refreshing the plugins page).

**What It Does:**
- Scans for installed plugins with `GitHub Plugin URI` header
- Fetches latest release data from GitHub for each plugin
- Compares current version with GitHub version
- Adds update information to the transient if newer version available

**Developer Notes:**
- This filter is called by WordPress core during its update check routine
- The plugin does not prevent other update sources from working
- Updates are only added for plugins with valid `GitHub Plugin URI` headers

---

### `plugins_api`

**Purpose:** Provide plugin information when WordPress requests details about available updates.

**Handler:** `Alynt\PluginUpdater\Update_Checker::plugin_information()`

**Priority:** 10 (default)

**Parameters:** `$result`, `$action`, `$args`

**When It Fires:** When WordPress needs detailed information about a plugin (e.g., when viewing the update details modal).

**What It Does:**
- Intercepts `plugin_information` requests for GitHub-managed plugins
- Returns formatted plugin data including:
  - Name, version, author, homepage
  - Download link
  - Changelog (from GitHub release notes)
  - Last updated date

**Developer Notes:**
- Only processes requests where `$action === 'plugin_information'`
- Only handles plugins managed by this updater (with GitHub Plugin URI)
- Returns original `$result` for all other plugins

---

### `upgrader_source_selection`

**Purpose:** Fix directory names when downloading plugins from GitHub.

**Handler:** `Alynt\PluginUpdater\Source_Directory_Fixer::fix_source_directory()`

**Priority:** 10 (default)

**Parameters:** `$source`, `$remote_source`, `$upgrader`, `$args`

**When It Fires:** During the WordPress plugin upgrade process, after the zip file is extracted.

**What It Does:**
- Detects GitHub zipball folder names (e.g., `owner-repo-commithash`)
- Renames the folder to match the expected plugin slug
- Ensures WordPress recognizes the upgrade correctly

**Why It's Needed:**
- GitHub zipball archives extract to folders named `{owner}-{repo}-{hash}`
- WordPress expects the folder to match the plugin's slug
- Without this fix, updates would install to a new folder instead of replacing the existing plugin

**Developer Notes:**
- Only processes upgrades for plugins managed by this updater
- Uses WordPress filesystem API for safe file operations
- Includes fallback to copy+delete for Windows compatibility

---

### `cron_schedules`

**Purpose:** Register custom cron schedules for update checks.

**Handler:** `Alynt\PluginUpdater\Cron_Manager::add_cron_schedules()`

**Priority:** 10 (default)

**When It Fires:** When WordPress loads available cron schedules.

**What It Does:**
- Adds `six_hours` schedule (6 hours interval)
- Adds `weekly` schedule (7 days interval)

**Developer Notes:**
- WordPress core provides `hourly`, `twicedaily`, and `daily`
- These custom schedules extend the available frequency options

---

### `plugin_row_meta`

**Purpose:** Add "Check for updates" link to plugin row actions.

**Handler:** `Alynt\PluginUpdater\Admin\Plugins_List::add_check_update_link()`

**Priority:** 10 (default)

**Parameters:** `$links`, `$plugin_file`

**When It Fires:** When rendering each plugin row on the plugins.php admin page.

**What It Does:**
- Checks if the plugin has a GitHub Plugin URI
- Adds a "Check for updates" link with AJAX functionality
- Includes security nonce for the plugin file

**Developer Notes:**
- Only adds link for GitHub-managed plugins
- Link triggers AJAX request to check for updates
- Provides instant feedback without page reload

---

## Cron Actions

### `alynt_pu_scheduled_check`

**Purpose:** Scheduled action for automatic update checks.

**Handler:** `Alynt\PluginUpdater\Cron_Manager::run_scheduled_check()`

**Schedule:** Configurable via settings (6 hours, 12 hours, 24 hours, or weekly)

**What It Does:**
- Runs `Update_Checker::check_all_updates()` for all registered plugins
- Updates `alynt_pu_last_check` option with current timestamp
- Respects cache settings (uses cached data unless expired)

**Developer Notes:**
- Schedule frequency controlled by `alynt_pu_check_frequency` option
- Event is registered during plugin activation
- Event is cleared during plugin deactivation

---

### `update_option_alynt_pu_check_frequency`

**Purpose:** Update cron schedule when check frequency changes.

**Handler:** Anonymous function in `Cron_Manager::register_hooks()`

**Priority:** 10

**Parameters:** `$old_value`, `$new_value`

**What It Does:**
- Clears existing cron schedule
- Registers new schedule with updated frequency

**Developer Notes:**
- Automatically triggered when user saves settings
- Ensures cron frequency stays in sync with saved option

---

## REST API

### `rest_api_init`

**Purpose:** Register REST API endpoint for GitHub webhooks.

**Handler:** `Alynt\PluginUpdater\Webhook_Handler::register_rest_route()`

**Endpoint:** `/wp-json/alynt-pu/v1/webhook`

**Method:** POST

**What It Does:**
- Registers public endpoint (permission callback: `__return_true`)
- Handles incoming GitHub webhook payloads
- Verifies HMAC SHA-256 signature
- Clears cache and triggers update check for matching plugin

**Developer Notes:**
- Authentication handled via webhook secret validation
- Only processes `published`, `released`, and `created` actions
- Returns appropriate HTTP status codes for all cases

---

## AJAX Actions

### `wp_ajax_alynt_pu_check_all_updates`

**Handler:** `Alynt\PluginUpdater\Admin\Settings::ajax_check_all_updates()`

**Capability Required:** `update_plugins`

**Nonce:** `alynt_pu_check_all`

**What It Does:**
- Forces fresh update check for all plugins (bypasses cache)
- Updates `alynt_pu_last_check` option
- Clears `update_plugins` site transient

---

### `wp_ajax_alynt_pu_check_single_update`

**Handler:** `Alynt\PluginUpdater\Admin\Plugins_List::ajax_check_single_update()`

**Capability Required:** `update_plugins`

**Nonce:** `alynt_pu_check_{plugin_file}`

**What It Does:**
- Clears cache for specific plugin
- Checks for updates
- Returns JSON with version information

---

### `wp_ajax_alynt_pu_generate_secret`

**Handler:** `Alynt\PluginUpdater\Admin\Settings::ajax_generate_secret()`

**Capability Required:** `manage_options`

**Nonce:** `alynt_pu_generate_secret`

**What It Does:**
- Generates a new 32-character webhook secret
- Updates `alynt_pu_webhook_secret` option
- Returns the new secret in JSON response

---

## Plugin Lifecycle Hooks

### `activated_plugin`

**Handler:** `Alynt\PluginUpdater\Plugin_Scanner::clear_cache()`

**What It Does:** Clears the cached plugin list when any plugin is activated.

---

### `deactivated_plugin`

**Handler:** `Alynt\PluginUpdater\Plugin_Scanner::clear_cache()`

**What It Does:** Clears the cached plugin list when any plugin is deactivated.

---

### `upgrader_process_complete`

**Handler:** `Alynt\PluginUpdater\Plugin_Scanner::clear_cache()`

**What It Does:** Clears the cached plugin list when any plugin is upgraded.

---

### `switch_theme`

**Handler:** `Alynt\PluginUpdater\Plugin_Scanner::clear_cache()`

**What It Does:** Clears the cached plugin list when the theme changes (in case theme bundled plugins).

---

## Extensibility

This plugin does not currently expose custom action or filter hooks for third-party developers to extend its functionality. The plugin is designed to work with WordPress's native update system.

### Extending This Plugin

If you need to extend this plugin's functionality, consider:

1. **Reading plugin settings:**
   ```php
   $check_frequency = get_option( 'alynt_pu_check_frequency', 'twicedaily' );
   $cache_duration = get_option( 'alynt_pu_cache_duration', 3600 );
   ```

2. **Accessing cached release data:**
   ```php
   $cache_key = 'alynt_pu_release_' . sanitize_key( $owner . '_' . $repo );
   $release_data = get_transient( $cache_key );
   ```

3. **Hooking before/after core WordPress hooks:**
   ```php
   // Run before this plugin's update check
   add_filter( 'pre_set_site_transient_update_plugins', 'my_function', 9 );
   
   // Run after this plugin's update check
   add_filter( 'pre_set_site_transient_update_plugins', 'my_function', 11 );
   ```

4. **Manually triggering an update check:**
   ```php
   delete_site_transient( 'update_plugins' );
   wp_update_plugins(); // Force WordPress to check for updates
   ```

### Feature Requests

If you need custom hooks for specific use cases, please open an issue on the GitHub repository. We're open to adding extensibility hooks in future versions if there's community demand.

---

## Summary

This plugin integrates seamlessly with WordPress's native update system without modifying core functionality. It does not expose custom hooks but provides a comprehensive integration through WordPress core hooks, REST API, and AJAX actions.

For most use cases, the plugin works automatically once configured. Advanced users can interact with the plugin through WordPress core hooks or by directly accessing the plugin's stored options and transients.
