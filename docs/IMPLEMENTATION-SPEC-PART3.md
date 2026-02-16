# Alynt Plugin Updater — Implementation Specification (Part 3)

---

## 6. Data Flow Diagrams

### 6.1 Manual "Check for Updates" Click (Single Plugin)

```
User clicks "Check for updates" link on plugins.php
    │
    ▼
[JavaScript: index.js]
    │ Prevent default, show "Checking..."
    │ POST to admin-ajax.php
    │   action: alynt_pu_check_single_update
    │   plugin: my-plugin/my-plugin.php
    │   nonce: wp_nonce_xxx
    │
    ▼
[Plugins_List::ajax_check_single_update()]
    │
    ├── wp_verify_nonce() → FAIL: 403 "Security check failed"
    ├── current_user_can('update_plugins') → FAIL: 403 "Permission denied"
    ├── Validate plugin in github_plugins → FAIL: 400 "Invalid plugin"
    │
    ▼
[Plugin_Scanner::get_plugin_github_data()]
    │ Returns: ['owner', 'repo', ...]
    │
    ▼
[GitHub_API::clear_cache($owner, $repo)]
    │ Deletes transient
    │
    ▼
[Update_Checker::check_plugin_update()]
    │
    ├── GitHub_API::get_latest_release(force_fresh: true)
    │     ├── Check rate limit → if limited, return cached/error
    │     ├── HTTP GET releases/latest
    │     │     ├── 200 → parse, cache, return
    │     │     ├── 404 → try Tags API
    │     │     └── 403 → set rate limit, return cached/error
    │     └── Return release data
    │
    ├── Get current version from headers
    ├── Version_Util::is_update_available()
    └── Return result array
    │
    ▼
[Plugins_List] → wp_send_json_success()
    │
    ▼
[JavaScript]
    ├── update_available: true → "Update available (v1.1.0)" red
    ├── update_available: false → "Up to date ✓" green
    └── error → "Check failed" red
    │
    └── After 5s: reset to "Check for updates"
```

---

### 6.2 Cron-Triggered Update Check

```
WordPress Cron fires 'alynt_pu_scheduled_check'
    │
    ▼
[Cron_Manager::run_scheduled_check()]
    │
    ▼
[Update_Checker::check_all_updates(force_fresh: false)]
    │
    ├── Plugin_Scanner::get_github_plugins()
    │
    └── For each plugin:
        ├── Check cache → HIT: use cached
        ├── Cache MISS: GitHub_API::get_latest_release()
        ├── Compare versions
        └── Collect results
    │
    ▼
Update option: alynt_pu_last_check = time()
    │
    ▼
Next page load triggers transient refresh
    │
    ▼
[Update_Checker::check_for_updates($transient)]
    │ (pre_set_site_transient_update_plugins filter)
    │
    └── Inject updates into $transient->response[]
    │
    ▼
WordPress displays update notices
```

---

### 6.3 Webhook-Triggered Update Check

```
GitHub POST → /wp-json/alynt-pu/v1/webhook
    │ Headers: X-Hub-Signature-256
    │ Body: {"action": "published", "repository": {...}}
    │
    ▼
[Webhook_Handler::handle_webhook()]
    │
    ├── Missing signature → 400 {"error": "missing_signature"}
    │
    ├── verify_signature()
    │     ├── Empty secret → 403 {"error": "webhook_not_configured"}
    │     ├── Compute expected hash
    │     └── hash_equals() → FAIL: log + 401 {"error": "invalid_signature"}
    │
    ├── json_decode() → FAIL: 400 {"error": "invalid_json"}
    │
    ├── Validate payload
    │     ├── action != published/released → 200 {"status": "ignored"}
    │     └── Missing repository → 400 {"error": "missing_repository"}
    │
    ▼
[process_payload()]
    │
    ├── Extract owner/repo
    ├── Find matching plugin → none: 200 {"status": "no_matching_plugin"}
    ├── GitHub_API::clear_cache()
    ├── Update_Checker::check_plugin_update()
    ├── delete_site_transient('update_plugins')
    └── Return 200 {"status": "success", "plugin": "..."}
```

---

### 6.4 WordPress Native Update Integration

```
User clicks "Update Now"
    │
    ▼
[WordPress Plugin_Upgrader]
    │
    ├── Read update from transient->response[$plugin_file]
    │     └── Our injected: package URL, new_version
    │
    ├── Download from $response->package (GitHub URL)
    ├── Extract to wp-content/upgrade/
    ├── WordPress handles folder renaming
    ├── Delete old plugin folder
    ├── Move new to wp-content/plugins/
    └── Reactivate if was active
    │
    ▼
[Hook: upgrader_process_complete]
    │
    └── Plugin_Scanner::clear_cache()
```

---

## 7. Constants and Configuration

### 7.1 Plugin Constants (main file)

```php
define('ALYNT_PU_VERSION', '1.0.0');
define('ALYNT_PU_PLUGIN_FILE', __FILE__);
define('ALYNT_PU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ALYNT_PU_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ALYNT_PU_PLUGIN_BASENAME', plugin_basename(__FILE__));
```

### 7.2 Class Constants

| Constant | Class | Value |
|----------|-------|-------|
| `CACHE_KEY` | Plugin_Scanner | `'alynt_pu_github_plugins'` |
| `HEADER_KEY` | Plugin_Scanner | `'GitHub Plugin URI'` |
| `API_BASE` | GitHub_API | `'https://api.github.com'` |
| `CACHE_PREFIX` | GitHub_API | `'alynt_pu_release_'` |
| `HOOK_NAME` | Cron_Manager | `'alynt_pu_scheduled_check'` |
| `REST_NAMESPACE` | Webhook_Handler | `'alynt-pu/v1'` |
| `REST_ROUTE` | Webhook_Handler | `'/webhook'` |
| `OPTION_GROUP` | Settings | `'alynt_pu_settings'` |
| `PAGE_SLUG` | Settings | `'alynt-plugin-updater'` |

---

## 8. Database Schema (Options)

| Option Key | Type | Default | Description |
|------------|------|---------|-------------|
| `alynt_pu_check_frequency` | string | `'twicedaily'` | Cron interval |
| `alynt_pu_webhook_secret` | string | `''` | Webhook HMAC secret |
| `alynt_pu_last_check` | int | `0` | Last full check timestamp |
| `alynt_pu_cache_duration` | int | `3600` | Cache TTL in seconds |

**Frequency Allowed Values:** `'six_hours'`, `'twicedaily'`, `'daily'`, `'weekly'`

**Cache Duration Range:** 300–86400 (5 min to 24 hours)

---

## 9. File Structure (Final)

```
alynt-plugin-updater/
├── alynt-plugin-updater.php
├── uninstall.php
├── readme.txt
├── CHANGELOG.md
├── README.md
├── composer.json
├── package.json
├── .phpcs.xml
├── includes/
│   ├── class-loader.php
│   ├── class-activator.php
│   ├── class-deactivator.php
│   ├── class-plugin.php
│   ├── class-config.php
│   ├── class-service-factory.php
│   ├── class-logger.php
│   ├── class-version-util.php
│   ├── class-plugin-scanner.php
│   ├── class-github-api.php
│   ├── class-update-checker.php
│   ├── class-cron-manager.php
│   └── class-webhook-handler.php
├── admin/
│   ├── class-admin-menu.php
│   ├── class-asset-manager.php
│   ├── class-settings.php
│   ├── class-plugins-list.php
│   └── partials/
│       └── settings-page.php
├── assets/
│   ├── src/
│   │   └── admin/
│   │       ├── index.js
│   │       └── style.css
│   └── dist/
│       └── admin/
│           ├── index.js
│           └── style.css
├── languages/
│   └── alynt-plugin-updater.pot
└── scripts/
    └── build.mjs
```

---

## 10. Settings Page Sections

### Section 1: General Settings

**Fields:**
- **Check Frequency** — Select dropdown with frequency options
- **Cache Duration** — Number input (seconds), helper text: "How long to cache GitHub API responses"

### Section 2: Webhook Configuration

**Fields:**
- **Webhook URL** — Read-only text field with copy button, value: `get_webhook_url()`
- **Secret Key** — Read-only text field with copy button + "Generate New Secret" button
- **Setup Instructions** — Expandable/collapsible instructions block

### Section 3: Status

**Display:**
- **Last Check** — Formatted datetime from `alynt_pu_last_check`
- **Next Scheduled Check** — From `wp_next_scheduled()`
- **Rate Limit Status** — "OK" or "Limited until {time}"
- **Registered Plugins** — Table with: Name, Version, GitHub Repo, Status

### Actions

- **Check All Now** — Button, triggers AJAX `alynt_pu_check_all_updates`
- **Save Changes** — Standard submit button

---

## 11. JavaScript Specification (assets/src/admin/index.js)

### Event Handlers

**Check Single Update Link:**
```javascript
document.querySelectorAll('.alynt-pu-check-update').forEach(link => {
    link.addEventListener('click', async (e) => {
        e.preventDefault();
        const plugin = link.dataset.plugin;
        const nonce = link.dataset.nonce;
        const originalText = link.textContent;
        
        link.textContent = alyntPuAdmin.checking;
        link.style.pointerEvents = 'none';
        
        try {
            const response = await fetch(alyntPuAdmin.ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'alynt_pu_check_single_update',
                    plugin: plugin,
                    nonce: nonce
                })
            });
            const data = await response.json();
            
            if (data.success) {
                if (data.data.update_available) {
                    link.textContent = alyntPuAdmin.updateAvailable.replace('%s', data.data.new_version);
                    link.style.color = '#d63638';
                    link.style.fontWeight = 'bold';
                } else {
                    link.textContent = alyntPuAdmin.upToDate;
                    link.style.color = '#00a32a';
                }
            } else {
                link.textContent = alyntPuAdmin.checkFailed;
                link.style.color = '#d63638';
            }
        } catch {
            link.textContent = alyntPuAdmin.checkFailed;
            link.style.color = '#d63638';
        }
        
        setTimeout(() => {
            link.textContent = originalText;
            link.style.color = '';
            link.style.fontWeight = '';
            link.style.pointerEvents = '';
        }, 5000);
    });
});
```

**Copy to Clipboard:**
```javascript
document.querySelectorAll('.alynt-pu-copy').forEach(btn => {
    btn.addEventListener('click', () => {
        const target = document.getElementById(btn.dataset.target);
        navigator.clipboard.writeText(target.value);
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = 'Copy', 2000);
    });
});
```

**Generate Secret:**
```javascript
document.getElementById('alynt-pu-generate-secret')?.addEventListener('click', async () => {
    const btn = document.getElementById('alynt-pu-generate-secret');
    btn.disabled = true;
    
    const response = await fetch(alyntPuAdmin.ajaxurl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'alynt_pu_generate_secret',
            nonce: alyntPuAdmin.generateSecretNonce
        })
    });
    const data = await response.json();
    
    if (data.success) {
        document.getElementById('alynt_pu_webhook_secret').value = data.data.secret;
    }
    btn.disabled = false;
});
```

---

## 12. Build Configuration

### package.json scripts

```json
{
  "scripts": {
    "build": "node scripts/build.mjs",
    "watch": "node scripts/build.mjs --watch",
    "lint": "eslint assets/src/**/*.js",
    "lint:fix": "eslint assets/src/**/*.js --fix"
  }
}
```

### build.mjs

Uses esbuild to:
1. Bundle `assets/src/admin/index.js` → `assets/dist/admin/index.js`
2. Copy `assets/src/admin/style.css` → `assets/dist/admin/style.css`
3. Minify in production mode

---

## 13. Uninstall Behavior (uninstall.php)

**Delete all options:**
- `alynt_pu_check_frequency`
- `alynt_pu_webhook_secret`
- `alynt_pu_last_check`
- `alynt_pu_cache_duration`

**Delete all transients:**
- `alynt_pu_github_plugins`
- `alynt_pu_rate_limited`
- All `alynt_pu_release_*` transients (use SQL LIKE query)

**Unschedule cron:**
- Clear `alynt_pu_scheduled_check` event

---

## 14. Implementation Checklist for Codex

1. [ ] Create autoloader (`class-loader.php`)
2. [ ] Create `class-activator.php` and `class-deactivator.php`
3. [ ] Create `class-logger.php` and `class-version-util.php`
4. [ ] Create `class-plugin-scanner.php`
5. [ ] Create `class-github-api.php` with caching
6. [ ] Create `class-update-checker.php` with WordPress hooks
7. [ ] Create `class-config.php` and `class-service-factory.php` for shared wiring/config
8. [ ] Create `class-cron-manager.php`
9. [ ] Create `class-webhook-handler.php` with REST endpoint
10. [ ] Create `class-plugin.php` orchestrator
11. [ ] Create admin classes in `admin/`
12. [ ] Create `settings-page.php` partial
13. [ ] Create JavaScript in `assets/src/admin/`
14. [ ] Create CSS styles
15. [ ] Create main plugin file with header
16. [ ] Create `uninstall.php`
17. [ ] Create build scripts and config files
18. [ ] Create `readme.txt` and documentation

---

**End of Implementation Specification**
