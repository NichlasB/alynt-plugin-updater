<?php
/**
 * Plugin uninstall handler.
 *
 * @package AlyntPluginUpdater
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( function_exists( 'current_user_can' ) && ! current_user_can( 'activate_plugins' ) ) {
	return;
}

$option_keys = array(
	'alynt_pu_check_frequency',
	'alynt_pu_webhook_secret',
	'alynt_pu_last_check',
	'alynt_pu_last_results',
	'alynt_pu_cache_duration',
);

alynt_pu_cleanup_site_data( $option_keys );

if ( is_multisite() ) {
	$current_blog_id = get_current_blog_id();
	$site_ids        = get_sites(
		array(
			'fields' => 'ids',
		)
	);

	foreach ( $site_ids as $site_id ) {
		$site_id = (int) $site_id;

		if ( $site_id === $current_blog_id ) {
			continue;
		}

		switch_to_blog( $site_id );
		alynt_pu_cleanup_site_data( $option_keys );
		restore_current_blog();
	}
}

/**
 * Delete plugin data in the current blog context.
 *
 * @param array<string> $option_keys Option keys to remove.
 * @return void
 */
function alynt_pu_cleanup_site_data( array $option_keys ): void {
	foreach ( $option_keys as $key ) {
		delete_option( $key );
	}

	delete_transient( 'alynt_pu_github_plugins' );
	delete_transient( 'alynt_pu_rate_limited' );
	delete_transient( 'alynt_pu_checking_all' );

	// Delete webhook and release transients, including orphaned timeout rows.
	alynt_pu_delete_transients_by_prefix( 'alynt_pu_wh_rl_' );
	alynt_pu_delete_transient_timeout_rows( 'alynt_pu_wh_rl_' );
	alynt_pu_delete_transients_by_prefix( 'alynt_pu_release_' );
	alynt_pu_delete_transient_timeout_rows( 'alynt_pu_release_' );

	wp_clear_scheduled_hook( 'alynt_pu_scheduled_check' );
}

/**
 * Delete transients by key prefix in an object-cache-aware way.
 *
 * @param string $prefix Transient key prefix.
 * @return void
 */
function alynt_pu_delete_transients_by_prefix( string $prefix ): void {
	global $wpdb;

	$like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$option_rows = $wpdb->get_col(
		$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
	);

	if ( ! is_array( $option_rows ) ) {
		return;
	}

	$option_prefix_len = strlen( '_transient_' );
	foreach ( $option_rows as $option_name ) {
		delete_transient( substr( $option_name, $option_prefix_len ) );
	}
}

/**
 * Delete orphaned transient timeout rows by key prefix.
 *
 * @param string $prefix Transient key prefix.
 * @return void
 */
function alynt_pu_delete_transient_timeout_rows( string $prefix ): void {
	global $wpdb;

	$timeout_like = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_like )
	);
}
