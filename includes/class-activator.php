<?php
/**
 * Plugin activation handler.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activator.
 */
class Activator {
	/**
	 * Run on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		add_option( 'alynt_pu_check_frequency', 'twicedaily' );
		add_option( 'alynt_pu_cache_duration', 3600 );
		add_option( 'alynt_pu_webhook_secret', '' );
		add_option( 'alynt_pu_last_check', 0 );

		delete_transient( 'alynt_pu_github_plugins' );
		delete_transient( 'alynt_pu_rate_limited' );

		$hook      = 'alynt_pu_scheduled_check';
		$frequency = get_option( 'alynt_pu_check_frequency', 'twicedaily' );
		$schedules = wp_get_schedules();

		if ( ! isset( $schedules[ $frequency ] ) ) {
			$frequency = 'twicedaily';
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), $frequency, $hook );
		}
	}
}
