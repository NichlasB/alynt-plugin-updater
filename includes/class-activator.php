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
 *
 * @since 1.0.0
 */
class Activator {
	/**
	 * Run on plugin activation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function activate(): void {
		add_option( 'alynt_pu_check_frequency', 'twicedaily' );
		add_option( Config::CACHE_DURATION_OPTION, Config::CACHE_DURATION_DEFAULT );
		add_option( 'alynt_pu_webhook_secret', '' );
		add_option( 'alynt_pu_last_check', 0 );
		add_option( 'alynt_pu_last_results', array() );

		delete_transient( 'alynt_pu_github_plugins' );
		delete_transient( 'alynt_pu_rate_limited' );

		$hook      = 'alynt_pu_scheduled_check';
		$frequency = get_option( 'alynt_pu_check_frequency', 'twicedaily' );
		$schedules = wp_get_schedules();

		if ( ! isset( $schedules[ $frequency ] ) ) {
			$frequency = 'twicedaily';
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			$scheduled = wp_schedule_event( time(), $frequency, $hook );
			if ( is_wp_error( $scheduled ) ) {
				error_log( '[Alynt Plugin Updater] Activation: failed to schedule cron — ' . $scheduled->get_error_message() );
			}
		}
	}
}
