<?php
/**
 * Plugin uninstall handler.
 *
 * @package AlyntPluginUpdater
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$option_keys = array(
	'alynt_pu_check_frequency',
	'alynt_pu_webhook_secret',
	'alynt_pu_last_check',
	'alynt_pu_last_results',
	'alynt_pu_cache_duration',
);

foreach ( $option_keys as $key ) {
	delete_option( $key );
}

delete_transient( 'alynt_pu_github_plugins' );
delete_transient( 'alynt_pu_rate_limited' );

// Delete release transients.
if ( function_exists( 'get_site_transient' ) ) {
	global $wpdb;
	$transient_like = $wpdb->esc_like( '_transient_alynt_pu_release_' ) . '%';
	$timeout_like   = $wpdb->esc_like( '_transient_timeout_alynt_pu_release_' ) . '%';

	$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_like ) );
	if ( false === $result ) {
		error_log( '[Alynt Plugin Updater] Uninstall: failed to delete release transients — ' . $wpdb->last_error );
	}

	$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_like ) );
	if ( false === $result ) {
		error_log( '[Alynt Plugin Updater] Uninstall: failed to delete release timeout transients — ' . $wpdb->last_error );
	}
}

wp_clear_scheduled_hook( 'alynt_pu_scheduled_check' );
