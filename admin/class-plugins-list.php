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
 */
class Plugins_List {
	/**
	 * Plugin scanner.
	 *
	 * @var Plugin_Scanner
	 */
	private Plugin_Scanner $scanner;

	/**
	 * GitHub API client.
	 *
	 * @var GitHub_API
	 */
	private GitHub_API $github_api;

	/**
	 * Update checker.
	 *
	 * @var Update_Checker
	 */
	private Update_Checker $update_checker;

	/**
	 * Constructor.
	 *
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
			'<a href="#" class="alynt-pu-check-update" data-plugin="%s" data-nonce="%s">%s</a>',
			esc_attr( $plugin_file ),
			esc_attr( wp_create_nonce( 'alynt_pu_check_' . $plugin_file ) ),
			esc_html__( 'Check for updates', 'alynt-plugin-updater' )
		);

		return $links;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( 'plugins.php' !== $hook_suffix ) {
			return;
		}

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
				'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'checking'        => __( 'Checking...', 'alynt-plugin-updater' ),
				'upToDate'        => __( 'Up to date ✓', 'alynt-plugin-updater' ),
				'updateAvailable' => __( 'Update available (v%s)', 'alynt-plugin-updater' ),
				'checkFailed'     => __( 'Check failed', 'alynt-plugin-updater' ),
			)
		);
	}

	/**
	 * AJAX handler for single plugin update check.
	 *
	 * @return void
	 */
	public function ajax_check_single_update(): void {
		$plugin_file = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$nonce       = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'alynt_pu_check_' . $plugin_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'alynt-plugin-updater' ) ), 403 );
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'alynt-plugin-updater' ) ), 403 );
		}

		$github_data = $this->scanner->get_plugin_github_data( $plugin_file );
		if ( ! $github_data ) {
			wp_send_json_error( array( 'message' => __( 'Invalid plugin.', 'alynt-plugin-updater' ) ), 400 );
		}

		$this->github_api->clear_cache( $github_data['owner'], $github_data['repo'] );
		$result = $this->update_checker->check_plugin_update( $plugin_file );
		delete_site_transient( 'update_plugins' );

		wp_send_json_success(
			array(
				'update_available' => (bool) $result['update_available'],
				'current_version'  => (string) $result['current_version'],
				'new_version'      => (string) $result['new_version'],
			)
		);
	}
}
