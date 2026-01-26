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
	'alynt_pu_cache_duration',
);

foreach ( $option_keys as $key ) {
	delete_option( $key );
}

delete_transient( 'alynt_pu_github_plugins' );
delete_transient( 'alynt_pu_rate_limited' );

// Delete release and lock transients.
if ( function_exists( 'get_site_transient' ) ) {
	global $wpdb;
	$transient_like = $wpdb->esc_like( '_transient_alynt_pu_release_' ) . '%';
	$timeout_like   = $wpdb->esc_like( '_transient_timeout_alynt_pu_release_' ) . '%';
	$lock_like      = $wpdb->esc_like( '_transient_alynt_pu_updating_' ) . '%';
	$lock_timeout   = $wpdb->esc_like( '_transient_timeout_alynt_pu_updating_' ) . '%';

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_like ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_like ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $lock_like ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $lock_timeout ) );
}

wp_clear_scheduled_hook( 'alynt_pu_scheduled_check' );
