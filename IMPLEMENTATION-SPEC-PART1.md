# Alynt Plugin Updater — Implementation Specification (Part 1)

**Document Version:** 1.0.0  
**Target Plugin Version:** 1.0.0  
**Date:** January 26, 2026  

---

## 1. Architecture Validation

### 1.1 Class Structure — Confirmed with Refinements

**Additions Required:**

| Class | Location | Responsibility |
|-------|----------|----------------|
| `class-version-util.php` | `/includes/` | Version comparison utilities |
| `class-logger.php` | `/includes/` | Centralized logging |

**Dependency Injection Map:**
```
Plugin (main orchestrator)
├── Loader (autoloader) — standalone
├── Plugin_Scanner — no dependencies
├── GitHub_API — depends on: Version_Util, Logger
├── Update_Checker — depends on: Plugin_Scanner, GitHub_API, Version_Util
├── Plugin_Installer — depends on: Logger
├── Cron_Manager — depends on: Update_Checker
├── Webhook_Handler — depends on: Plugin_Scanner, GitHub_API, Update_Checker, Logger
├── Admin_Menu — depends on: Settings
├── Settings — no dependencies
├── Plugins_List — depends on: Plugin_Scanner, GitHub_API, Update_Checker
└── Logger — standalone
```

### 1.2 Initialization Order

All initialization on `plugins_loaded` hook at priority `10`.

**Sequence:**
1. Autoloader registers (immediate, before `plugins_loaded`)
2. `Plugin::init()` on `plugins_loaded` priority `10`
3. Inside `Plugin::init()`:
   - Instantiate Logger → Plugin_Scanner → Version_Util
   - Instantiate GitHub_API with dependencies
   - Instantiate Update_Checker, Plugin_Installer, Cron_Manager, Webhook_Handler
   - If `is_admin()`: Instantiate Settings, Admin_Menu, Plugins_List

### 1.3 Hook Timing Reference

| Hook | Priority | Callback | Class |
|------|----------|----------|-------|
| `plugins_loaded` | 10 | `Plugin::init` | Plugin |
| `init` | 10 | `Webhook_Handler::register_rest_route` | Webhook_Handler |
| `admin_menu` | 10 | `Admin_Menu::add_menu_page` | Admin_Menu |
| `admin_init` | 10 | `Settings::register_settings` | Settings |
| `admin_enqueue_scripts` | 10 | `Plugins_List::enqueue_scripts` | Plugins_List |
| `plugin_row_meta` | 10 | `Plugins_List::add_check_update_link` | Plugins_List |
| `pre_set_site_transient_update_plugins` | 10 | `Update_Checker::check_for_updates` | Update_Checker |
| `plugins_api` | 10 | `Update_Checker::plugin_information` | Update_Checker |
| `upgrader_process_complete` | 10 | `Plugin_Scanner::clear_cache` | Plugin_Scanner |
| `activated_plugin` | 10 | `Plugin_Scanner::clear_cache` | Plugin_Scanner |
| `deactivated_plugin` | 10 | `Plugin_Scanner::clear_cache` | Plugin_Scanner |
| `alynt_pu_scheduled_check` | N/A | `Cron_Manager::run_scheduled_check` | Cron_Manager |
| `wp_ajax_alynt_pu_check_single_update` | N/A | `Plugins_List::ajax_check_single_update` | Plugins_List |
| `wp_ajax_alynt_pu_check_all_updates` | N/A | `Settings::ajax_check_all_updates` | Settings |

### 1.4 Namespace Structure

```
Alynt\PluginUpdater\
├── Plugin, Loader, Activator, Deactivator
├── Logger, Version_Util, Plugin_Scanner
├── GitHub_API, Update_Checker, Plugin_Installer
├── Cron_Manager, Webhook_Handler
└── Admin\
    ├── Admin_Menu, Settings, Plugins_List
```

---

## 2. Security Specifications

### 2.1 Webhook Signature Verification Flow

1. Extract `X-Hub-Signature-256` header
2. Missing → return `400` with `{"error": "missing_signature"}`
3. Get secret from `get_option('alynt_pu_webhook_secret')`
4. Empty secret → return `403` with `{"error": "webhook_not_configured"}`
5. Compute: `'sha256=' . hash_hmac('sha256', $body, $secret)`
6. Use `hash_equals()` for timing-safe comparison
7. Mismatch → log warning, return `401` with `{"error": "invalid_signature"}`
8. Valid → proceed to payload processing

### 2.2 Nonce Strategy

| Nonce Action | Generation | Verification |
|--------------|------------|--------------|
| `alynt_pu_check_{plugin_file}` | `Plugins_List::add_check_update_link()` | `Plugins_List::ajax_check_single_update()` |
| `alynt_pu_check_all` | Settings partial | `Settings::ajax_check_all_updates()` |
| `alynt_pu_settings` | Settings API (auto) | Settings API (auto) |

### 2.3 Capability Requirements

| Operation | Capability | Location |
|-----------|-----------|----------|
| View settings page | `manage_options` | `add_options_page()` |
| Save settings | `manage_options` | Settings API |
| Check single plugin | `update_plugins` | AJAX handler |
| Check all updates | `update_plugins` | AJAX handler |

### 2.4 Input Sanitization

| Input | Sanitization |
|-------|-------------|
| `plugin` file path | `sanitize_text_field()` + validate against known plugins |
| `nonce` | `sanitize_text_field()` |
| Webhook payload | Signature verified, then JSON decode + validate structure |
| `check_frequency` | `sanitize_text_field()` + validate against allowed values |
| `cache_duration` | `absint()` + clamp 300–86400 |

---

## 3. Edge Case Handling

### 3.1 GitHub API 404 (No Releases)

1. Catch 404 from releases endpoint
2. Fallback to Tags API: `/repos/{owner}/{repo}/tags`
3. If tags exist: use first tag, set download_url to source zip
4. If no tags: return `WP_Error('no_releases')`, cache negative result for 1 hour
5. Update_Checker treats as "no update" (not user-visible error)

### 3.2 GitHub API Rate Limit (403)

1. Detect via `X-RateLimit-Remaining: 0` header
2. Extract reset time from `X-RateLimit-Reset`
3. Set transient `alynt_pu_rate_limited` with reset timestamp
4. Return cached data if available, else `WP_Error('rate_limited')`
5. All API calls check rate limit transient first
6. Display admin notice with reset time

### 3.3 Download Fails

- Delete partial temp file
- Return `WP_Error('download_failed')` with original message
- Log error

### 3.4 Zip Extraction Fails

- Delete temp zip file
- Delete temp extraction directory
- Return `WP_Error('extraction_failed')`
- Log error

### 3.5 Unexpected Folder Structure

- Zero directories in zip: check for flat structure, wrap if needed
- Multiple directories: use first alphabetically, log warning
- Missing main plugin file: return `WP_Error('invalid_plugin_structure')`

### 3.6 Move/Rename Fails

- Log error with destination path
- Cleanup temp files
- Return `WP_Error('move_failed')`
- **Do NOT delete original plugin**

### 3.7 Webhook Signature Invalid

- Return `401` with `{"error": "invalid_signature"}`
- Log attempt with IP and signature prefix

### 3.8 Webhook Payload Malformed

- Invalid JSON → return `400` `{"error": "invalid_json"}`
- Unsupported action → return `200` `{"status": "ignored"}`
- Missing repository → return `400` `{"error": "missing_repository"}`

### 3.9 Concurrent Updates

- Check lock transient: `alynt_pu_updating_{slug}` (5 min TTL)
- If locked → return `WP_Error('update_in_progress')`
- Set lock at start, delete at end (success or failure)

### 3.10 Plugin Deactivated During Update

- No special handling needed
- WordPress preserves activation state through folder replacement

### 3.11 Corrupted Cache

- Validate structure on read
- Invalid → delete transient, fetch fresh
- Log debug message
