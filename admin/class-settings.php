<?php
/**
 * Settings handler.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater\Admin;

use Alynt\PluginUpdater\GitHub_API;
use Alynt\PluginUpdater\Logger;
use Alynt\PluginUpdater\Plugin_Scanner;
use Alynt\PluginUpdater\Update_Checker;
use Alynt\PluginUpdater\Version_Util;
use Alynt\PluginUpdater\Webhook_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings.
 */
class Settings {
	/**
	 * Settings group.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'alynt_pu_settings';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'alynt-plugin-updater';

	/**
	 * Register settings with WordPress Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			'alynt_pu_check_frequency',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_frequency' ),
				'default'           => 'twicedaily',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'alynt_pu_cache_duration',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_cache_duration' ),
				'default'           => 3600,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'alynt_pu_webhook_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$frequency        = (string) get_option( 'alynt_pu_check_frequency', 'twicedaily' );
		$cache_duration   = (int) get_option( 'alynt_pu_cache_duration', 3600 );
		$webhook_secret   = (string) get_option( 'alynt_pu_webhook_secret', '' );
		$last_check       = (int) get_option( 'alynt_pu_last_check', 0 );
		$next_check       = wp_next_scheduled( 'alynt_pu_scheduled_check' );
		$rate_limit_reset = get_transient( 'alynt_pu_rate_limited' );

		$scanner         = new Plugin_Scanner();
		$version_util    = new Version_Util();
		$logger          = new Logger();
		$github_api      = new GitHub_API( $version_util, $logger );
		$update_checker  = new Update_Checker( $scanner, $github_api, $version_util );
		$webhook_handler = new Webhook_Handler( $scanner, $github_api, $update_checker, $logger );

		$plugins = $scanner->get_github_plugins();
		$results = $update_checker->check_all_updates( false );

		$frequency_options     = $this->get_frequency_options();
		$webhook_url           = $webhook_handler->get_webhook_url();
		$check_all_nonce     = wp_create_nonce( 'alynt_pu_check_all' );
		$generate_secret_nonce = wp_create_nonce( 'alynt_pu_generate_secret' );

		$script_handle = 'alynt-pu-admin';
		$script_path   = ALYNT_PU_PLUGIN_DIR . 'assets/dist/admin/index.js';
		$script_url    = ALYNT_PU_PLUGIN_URL . 'assets/dist/admin/index.js';
		$style_path    = ALYNT_PU_PLUGIN_DIR . 'assets/dist/admin/style.css';
		$style_url     = ALYNT_PU_PLUGIN_URL . 'assets/dist/admin/style.css';

		if ( ! file_exists( $script_path ) ) {
			$script_path = ALYNT_PU_PLUGIN_DIR . 'assets/src/admin/index.js';
			$script_url  = ALYNT_PU_PLUGIN_URL . 'assets/src/admin/index.js';
		}

		if ( ! file_exists( $style_path ) ) {
			$style_path = ALYNT_PU_PLUGIN_DIR . 'assets/src/admin/style.css';
			$style_url  = ALYNT_PU_PLUGIN_URL . 'assets/src/admin/style.css';
		}

		$version = file_exists( $script_path ) ? filemtime( $script_path ) : ALYNT_PU_VERSION;

		wp_enqueue_script( $script_handle, $script_url, array(), $version, true );
		wp_enqueue_style( $script_handle, $style_url, array(), $version );

		wp_localize_script(
			$script_handle,
			'alyntPuAdmin',
			array(
				'ajaxurl'              => admin_url( 'admin-ajax.php' ),
				'checking'             => __( 'Checking...', 'alynt-plugin-updater' ),
				'upToDate'             => __( 'Up to date ✓', 'alynt-plugin-updater' ),
				'updateAvailable'      => __( 'Update available (v%s)', 'alynt-plugin-updater' ),
				'checkFailed'          => __( 'Check failed', 'alynt-plugin-updater' ),
				'checkingAll'          => __( 'Checking all updates...', 'alynt-plugin-updater' ),
				'checkAllComplete'     => __( 'Check complete.', 'alynt-plugin-updater' ),
				'checkAllFailed'       => __( 'Check failed.', 'alynt-plugin-updater' ),
				'copied'               => __( 'Copied!', 'alynt-plugin-updater' ),
				'checkAllNonce'        => $check_all_nonce,
				'generateSecretNonce'  => $generate_secret_nonce,
			)
		);

		require ALYNT_PU_PLUGIN_DIR . 'admin/partials/settings-page.php';
	}

	/**
	 * Register AJAX handler for "Check All Updates" button.
	 *
	 * @return void
	 */
	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_alynt_pu_check_all_updates', array( $this, 'ajax_check_all_updates' ) );
		add_action( 'wp_ajax_alynt_pu_generate_secret', array( $this, 'ajax_generate_secret' ) );
	}

	/**
	 * AJAX callback for checking all updates.
	 *
	 * @return void
	 */
	public function ajax_check_all_updates(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'alynt_pu_check_all' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'alynt-plugin-updater' ) ), 403 );
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'alynt-plugin-updater' ) ), 403 );
		}

		$scanner        = new Plugin_Scanner();
		$version_util   = new Version_Util();
		$logger         = new Logger();
		$github_api     = new GitHub_API( $version_util, $logger );
		$update_checker = new Update_Checker( $scanner, $github_api, $version_util );

		$results = $update_checker->check_all_updates( true );
		update_option( 'alynt_pu_last_check', time() );
		delete_site_transient( 'update_plugins' );

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Sanitize frequency option.
	 *
	 * @param string $value Raw value.
	 * @return string Sanitized value.
	 */
	public function sanitize_frequency( string $value ): string {
		$allowed = array_keys( $this->get_frequency_options() );
		$value   = sanitize_text_field( $value );

		return in_array( $value, $allowed, true ) ? $value : 'twicedaily';
	}

	/**
	 * Sanitize cache duration option.
	 *
	 * @param mixed $value Raw value.
	 * @return int Sanitized value.
	 */
	public function sanitize_cache_duration( $value ): int {
		$value = absint( $value );
		$value = max( 300, min( 86400, $value ) );

		return $value;
	}

	/**
	 * Get available frequency options.
	 *
	 * @return array<string, string> Value => Label pairs.
	 */
	public function get_frequency_options(): array {
		return array(
			'six_hours'  => __( 'Every 6 hours', 'alynt-plugin-updater' ),
			'twicedaily' => __( 'Every 12 hours', 'alynt-plugin-updater' ),
			'daily'      => __( 'Every 24 hours', 'alynt-plugin-updater' ),
			'weekly'     => __( 'Once weekly', 'alynt-plugin-updater' ),
		);
	}

	/**
	 * AJAX callback for generating webhook secret.
	 *
	 * @return void
	 */
	public function ajax_generate_secret(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'alynt_pu_generate_secret' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'alynt-plugin-updater' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'alynt-plugin-updater' ) ), 403 );
		}

		$secret = Webhook_Handler::generate_secret();
		update_option( 'alynt_pu_webhook_secret', $secret );

		wp_send_json_success( array( 'secret' => $secret ) );
	}
}
