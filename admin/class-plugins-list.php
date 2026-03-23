<?php
/**
 * Plugins list enhancements.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater\Admin;

use Alynt\PluginUpdater\GitHub_API;
use Alynt\PluginUpdater\Plugin_Scanner;
use Alynt\PluginUpdater\Update_Checker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugins_List.
 *
 * @since 1.0.0
 */
class Plugins_List {
	/**
	 * Plugin scanner.
	 *
	 * @since 1.0.0
	 * @var Plugin_Scanner
	 */
	private Plugin_Scanner $scanner;

	/**
	 * GitHub API client.
	 *
	 * @since 1.0.0
	 * @var GitHub_API
	 */
	private GitHub_API $github_api;

	/**
	 * Update checker.
	 *
	 * @since 1.0.0
	 * @var Update_Checker
	 */
	private Update_Checker $update_checker;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Plugin_Scanner $scanner      Plugin scanner.
	 * @param GitHub_API     $github_api   GitHub API client.
	 * @param Update_Checker $update_checker Update checker.
	 */
	public function __construct( Plugin_Scanner $scanner, GitHub_API $github_api, Update_Checker $update_checker ) {
		$this->scanner        = $scanner;
		$this->github_api     = $github_api;
		$this->update_checker = $update_checker;
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'plugin_row_meta', array( $this, 'add_check_update_link' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_alynt_pu_check_single_update', array( $this, 'ajax_check_single_update' ) );
	}

	/**
	 * Add "Check for updates" link to plugin row.
	 *
	 * @since 1.0.0
	 * @param array  $links       Existing links.
	 * @param string $plugin_file Plugin file path.
	 * @return array Modified links.
	 */
	public function add_check_update_link( array $links, string $plugin_file ): array {
		$github_plugins = $this->scanner->get_github_plugins();

		if ( ! isset( $github_plugins[ $plugin_file ] ) ) {
			return $links;
		}

		$links[] = sprintf(
			'<button type="button" class="button-link alynt-pu-check-update" data-plugin="%s" data-nonce="%s">%s</button><span class="screen-reader-text alynt-pu-check-update-status" aria-live="polite" aria-atomic="true"></span>',
			esc_attr( $plugin_file ),
			esc_attr( wp_create_nonce( 'alynt_pu_check_' . $plugin_file ) ),
			esc_html__( 'Check for Updates', 'alynt-plugin-updater' )
		);

		return $links;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 1.0.0
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( 'plugins.php' !== $hook_suffix ) {
			return;
		}

		Asset_Manager::enqueue_admin_assets();
		Asset_Manager::localize_admin_script( Asset_Manager::get_base_localization_data() );
	}

	/**
	 * AJAX handler for single plugin update check.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_check_single_update(): void {
		$plugin_file = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';

		if ( ! check_ajax_referer( 'alynt_pu_check_' . $plugin_file, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'alynt-plugin-updater' ) ), 403 );
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to check plugin updates. Contact your site administrator if you believe this is incorrect.', 'alynt-plugin-updater' ) ), 403 );
		}

		$github_data = $this->scanner->get_plugin_github_data( $plugin_file );
		if ( ! $github_data ) {
			wp_send_json_error( array( 'message' => __( 'This plugin could not be checked for updates because it is not registered as a supported GitHub-managed plugin.', 'alynt-plugin-updater' ) ), 400 );
		}

		$this->github_api->clear_cache( $github_data['owner'], $github_data['repo'] );
		$result = $this->update_checker->check_plugin_update( $plugin_file );
		delete_site_transient( 'update_plugins' );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error(
				array(
					'message' => ! empty( $result['error_message'] )
						? $result['error_message']
						: __( 'Could not check for updates. Please try again later.', 'alynt-plugin-updater' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'update_available' => (bool) $result['update_available'],
				'current_version'  => (string) $result['current_version'],
				'new_version'      => (string) $result['new_version'],
			)
		);
	}
}
