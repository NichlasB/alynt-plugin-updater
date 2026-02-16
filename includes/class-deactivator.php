<?php
/**
 * Plugin deactivation handler.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deactivator.
 *
 * @since 1.0.0
 */
class Deactivator {
	/**
	 * Run on plugin deactivation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'alynt_pu_scheduled_check' );
	}
}
