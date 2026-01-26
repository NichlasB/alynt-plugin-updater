<?php
/**
 * Plugin Name:       Alynt Plugin Updater
 * Plugin URI:        https://github.com/[username]/alynt-plugin-updater
 * Description:       Enable automatic updates for GitHub-hosted WordPress plugins.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            [Your Name]
 * Author URI:        https://[your-site].com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       alynt-plugin-updater
 * Domain Path:       /languages
 * GitHub Plugin URI: [username]/alynt-plugin-updater
 *
 * @package AlyntPluginUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ALYNT_PU_VERSION', '1.0.0' );
define( 'ALYNT_PU_PLUGIN_FILE', __FILE__ );
define( 'ALYNT_PU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALYNT_PU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ALYNT_PU_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once ALYNT_PU_PLUGIN_DIR . 'includes/class-loader.php';

Alynt\PluginUpdater\Loader::register();

register_activation_hook( __FILE__, array( 'Alynt\\PluginUpdater\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Alynt\\PluginUpdater\\Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Alynt\\PluginUpdater\\Plugin', 'init' ) );
