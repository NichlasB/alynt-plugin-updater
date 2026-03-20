=== Alynt Plugin Updater ===
Contributors: alynt
Tags: updates, github, plugins
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enable automatic updates for GitHub-hosted WordPress plugins.

== Description ==

A lightweight WordPress plugin that enables automatic updates for GitHub-hosted plugins. Uses the native WordPress update system and GitHub public releases.

Features:

* Checks GitHub releases for new versions.
* Supports tags formatted as v1.0.0 or 1.0.0.
* Per-plugin "Check for updates" link in plugins list.
* Optional GitHub webhook support for instant update checks.
* Admin settings page with status and configuration.

== Installation ==

1. Upload the `alynt-plugin-updater` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Configure settings under Settings → Plugin Updater.

== Frequently Asked Questions ==

= How do I register a plugin? =

Add a header to the plugin's main file:

GitHub Plugin URI: username/repository

= Does this support private repositories? =

No. This plugin only supports public GitHub repositories.

= Does this require a GitHub API token? =

No. This plugin uses the public GitHub API and caches responses.

== Changelog ==

= 1.1.0 =
* Refactored the plugin toward a clearer service-based architecture.
* Improved update checker result handling for more reliable GitHub release detection.
* Improved plugin metadata and update folder handling.
* Refreshed the GitHub release workflow and compatibility guide.

= 1.0.0 =
* Initial release.
