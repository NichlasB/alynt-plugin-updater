<?php
/**
 * Settings handler.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater\Admin;

use Alynt\PluginUpdater\Config;
use Alynt\PluginUpdater\Plugin_Scanner;
use Alynt\PluginUpdater\Update_Checker;
use Alynt\PluginUpdater\Webhook_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings.
 *
 * @since 1.0.0
 */
class Settings {
	/**
	 * Settings group.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const OPTION_GROUP = 'alynt_pu_settings';

	/**
	 * Plugin scanner.
	 *
	 * @since 1.0.0
	 * @var Plugin_Scanner
	 */
	private Plugin_Scanner $scanner;

	/**
	 * Update checker.
	 *
	 * @since 1.0.0
	 * @var Update_Checker
	 */
	private Update_Checker $update_checker;

	/**
	 * Webhook handler.
	 *
	 * @since 1.0.0
	 * @var Webhook_Handler
	 */
	private Webhook_Handler $webhook_handler;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Plugin_Scanner  $scanner         Plugin scanner.
	 * @param Update_Checker  $update_checker  Update checker.
	 * @param Webhook_Handler $webhook_handler Webhook handler.
	 */
	public function __construct( Plugin_Scanner $scanner, Update_Checker $update_checker, Webhook_Handler $webhook_handler ) {
		$this->scanner         = $scanner;
		$this->update_checker  = $update_checker;
		$this->webhook_handler = $webhook_handler;
	}

	/**
	 * Register settings with WordPress Settings API.
	 *
	 * @since 1.0.0
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
			Config::CACHE_DURATION_OPTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_cache_duration' ),
				'default'           => Config::CACHE_DURATION_DEFAULT,
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
	 * @since 1.0.0
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'alynt-plugin-updater' ),
				esc_html__( 'Permission Denied', 'alynt-plugin-updater' ),
				array( 'response' => 403 )
			);
		}

		$frequency        = (string) get_option( 'alynt_pu_check_frequency', 'twicedaily' );
		$cache_duration   = (int) get_option( Config::CACHE_DURATION_OPTION, Config::CACHE_DURATION_DEFAULT );
		$webhook_secret   = (string) get_option( 'alynt_pu_webhook_secret', '' );
		$last_check       = (int) get_option( 'alynt_pu_last_check', 0 );
		$next_check       = wp_next_scheduled( 'alynt_pu_scheduled_check' );
		$rate_limit_reset = get_transient( 'alynt_pu_rate_limited' );

		$plugins = $this->scanner->get_github_plugins();
		$results = $this->update_checker->get_stored_results();

		$frequency_options     = $this->get_frequency_options();
		$webhook_url           = $this->webhook_handler->get_webhook_url();
		$check_all_nonce       = wp_create_nonce( 'alynt_pu_check_all' );
		$generate_secret_nonce = wp_create_nonce( 'alynt_pu_generate_secret' );
		$cache_duration_min    = Config::CACHE_DURATION_MIN;
		$cache_duration_max    = Config::CACHE_DURATION_MAX;

		Asset_Manager::enqueue_admin_assets();
		Asset_Manager::localize_admin_script( $this->get_settings_localization_data( $check_all_nonce, $generate_secret_nonce ) );

		require ALYNT_PU_PLUGIN_DIR . 'admin/partials/settings-page.php';
	}

	/**
	 * Register AJAX handler for "Check All Updates" button.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_ajax_handlers(): void {
		add_action( 'wp_ajax_alynt_pu_check_all_updates', array( $this, 'ajax_check_all_updates' ) );
		add_action( 'wp_ajax_alynt_pu_generate_secret', array( $this, 'ajax_generate_secret' ) );
	}

	/**
	 * AJAX callback for checking all updates.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_check_all_updates(): void {
		if ( ! check_ajax_referer( 'alynt_pu_check_all', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'alynt-plugin-updater' ) ), 403 );
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'alynt-plugin-updater' ) ), 403 );
		}

		$lock_key = 'alynt_pu_checking_all';
		if ( get_transient( $lock_key ) ) {
			wp_send_json_error( array( 'message' => __( 'An update check is already in progress. Please wait a moment and try again.', 'alynt-plugin-updater' ) ), 429 );
		}
		set_transient( $lock_key, true, 2 * MINUTE_IN_SECONDS );

		$results = $this->update_checker->check_all_updates( true );
		update_option( 'alynt_pu_last_check', time() );
		delete_site_transient( 'update_plugins' );

		delete_transient( $lock_key );

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Sanitize frequency option.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 * @param mixed $value Raw value.
	 * @return int Sanitized value.
	 */
	public function sanitize_cache_duration( $value ): int {
		$sanitized = absint( $value );
		$clamped   = max( Config::CACHE_DURATION_MIN, min( Config::CACHE_DURATION_MAX, $sanitized ) );

		if ( $clamped !== $sanitized ) {
			add_settings_error(
				Config::CACHE_DURATION_OPTION,
				'cache_duration_clamped',
				sprintf(
					/* translators: 1: minimum value, 2: maximum value */
					__( 'Cache duration must be between %1$d and %2$d seconds. Your value has been adjusted.', 'alynt-plugin-updater' ),
					Config::CACHE_DURATION_MIN,
					Config::CACHE_DURATION_MAX
				),
				'warning'
			);
		}

		return $clamped;
	}

	/**
	 * Get available frequency options.
	 *
	 * @since 1.0.0
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
	 * Build localized script data for the settings page.
	 *
	 * @since 1.0.0
	 * @param string $check_all_nonce Check-all updates nonce.
	 * @param string $generate_secret_nonce Generate-secret nonce.
	 * @return array<string, string>
	 */
	private function get_settings_localization_data( string $check_all_nonce, string $generate_secret_nonce ): array {
		return array_merge(
			Asset_Manager::get_base_localization_data(),
			array(
				'checkingAll'           => __( 'Checking all updates...', 'alynt-plugin-updater' ),
				'checkAllComplete'      => __( 'Check complete.', 'alynt-plugin-updater' ),
				'checkAllFailed'        => __( 'Check failed.', 'alynt-plugin-updater' ),
				/* translators: 1: total count, 2: updates count, 3: error count */
				'checkAllSummary'       => __( '%1$d plugins checked: %2$d update(s) available, %3$d error(s).', 'alynt-plugin-updater' ),
				'copied'                => __( 'Copied!', 'alynt-plugin-updater' ),
				'copyFailed'            => __( 'Copy failed.', 'alynt-plugin-updater' ),
				'generatingSecret'      => __( 'Generating...', 'alynt-plugin-updater' ),
				'secretGenerated'       => __( 'New secret generated.', 'alynt-plugin-updater' ),
				'secretFailed'          => __( 'Failed to generate secret.', 'alynt-plugin-updater' ),
				'confirmGenerateSecret' => __( "Generate a new webhook secret?\n\nYour existing GitHub webhook configuration will stop working until you update the secret in your repository's webhook settings.", 'alynt-plugin-updater' ),
				'networkError'          => __( 'You appear to be offline. Check your connection and try again.', 'alynt-plugin-updater' ),
				'checkAllNonce'         => $check_all_nonce,
				'generateSecretNonce'   => $generate_secret_nonce,
			)
		);
	}

	/**
	 * AJAX callback for generating webhook secret.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_generate_secret(): void {
		if ( ! check_ajax_referer( 'alynt_pu_generate_secret', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'alynt-plugin-updater' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'alynt-plugin-updater' ) ), 403 );
		}

		$secret = Webhook_Handler::generate_secret();
		update_option( 'alynt_pu_webhook_secret', $secret );

		wp_send_json_success( array( 'secret' => $secret ) );
	}
}
