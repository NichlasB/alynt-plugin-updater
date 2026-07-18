<?php
/**
 * Restores active state for managed plugins after WordPress upgrades.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

use WP_Error;
use WP_Upgrader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Active_Plugin_Restorer.
 *
 * WordPress normally preserves active plugins during admin updates, but
 * programmatic/headless upgrader flows can leave an updated plugin inactive
 * after a successful package replacement. This class snapshots only managed
 * active plugins before an update and restores those same plugins afterward.
 *
 * @since 1.1.2
 */
class Active_Plugin_Restorer {
	/**
	 * Plugin scanner.
	 *
	 * @since 1.1.2
	 * @var Plugin_Scanner
	 */
	private Plugin_Scanner $scanner;

	/**
	 * Logger.
	 *
	 * @since 1.1.2
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Active-state snapshots keyed by plugin file.
	 *
	 * @since 1.1.2
	 * @var array<string, array{active: bool, position: int|false}>
	 */
	private array $snapshots = array();

	/**
	 * Completed active-plugin snapshots awaiting final request shutdown verification.
	 *
	 * @since 1.1.3
	 * @var array<string, array{active: bool, position: int|false}>
	 */
	private array $pending_finalizations = array();

	/**
	 * Whether the final shutdown reconciliation hook has been registered.
	 *
	 * @since 1.1.3
	 * @var bool
	 */
	private bool $shutdown_hook_registered = false;

	/**
	 * Constructor.
	 *
	 * @since 1.1.2
	 * @param Plugin_Scanner $scanner Plugin scanner.
	 * @param Logger         $logger  Logger.
	 */
	public function __construct( Plugin_Scanner $scanner, Logger $logger ) {
		$this->scanner = $scanner;
		$this->logger  = $logger;
	}

	/**
	 * Register active-state restore hooks.
	 *
	 * @since 1.1.2
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'upgrader_pre_install', array( $this, 'snapshot_before_install' ), 1, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'restore_after_upgrade' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Snapshot a managed plugin's active state before WordPress installs it.
	 *
	 * @since 1.1.2
	 * @param bool|WP_Error $response   Install response.
	 * @param array         $hook_extra Extra upgrader context.
	 * @return bool|WP_Error Unchanged install response.
	 */
	public function snapshot_before_install( $response, array $hook_extra ) {
		$plugin_file = $this->get_plugin_file_from_hook_extra( $hook_extra );
		if ( null === $plugin_file || ! $this->is_managed_plugin( $plugin_file ) ) {
			return $response;
		}

		$active_plugins = $this->get_active_plugins();
		$position       = array_search( $plugin_file, $active_plugins, true );

		$this->snapshots[ $plugin_file ] = array(
			'active'   => false !== $position,
			'position' => $position,
		);

		return $response;
	}

	/**
	 * Restore active managed plugins after WordPress completes an update.
	 *
	 * @since 1.1.2
	 * @param WP_Upgrader $upgrader   Upgrader instance.
	 * @param array       $hook_extra Extra upgrader context.
	 * @return void
	 */
	public function restore_after_upgrade( WP_Upgrader $upgrader, array $hook_extra ): void {
		unset( $upgrader );

		$plugin_files = $this->get_completed_plugin_files( $hook_extra );
		if ( empty( $plugin_files ) ) {
			return;
		}

		foreach ( $plugin_files as $plugin_file ) {
			if ( empty( $this->snapshots[ $plugin_file ] ) ) {
				continue;
			}

			$snapshot = $this->snapshots[ $plugin_file ];
			unset( $this->snapshots[ $plugin_file ] );

			if ( empty( $snapshot['active'] ) ) {
				continue;
			}

			$this->pending_finalizations[ $plugin_file ] = $snapshot;
			$this->restore_plugin_active_state( $plugin_file, $snapshot );
		}

		$this->register_shutdown_reconciliation();
	}

	/**
	 * Reconcile completed plugin updates after all other request callbacks run.
	 *
	 * @since 1.1.3
	 * @return void
	 */
	public function finalize_restorations(): void {
		foreach ( $this->pending_finalizations as $plugin_file => $snapshot ) {
			$this->restore_plugin_active_state( $plugin_file, $snapshot );
		}

		$this->pending_finalizations = array();
	}

	/**
	 * Restore one plugin's active state and prior active-list position.
	 *
	 * @since 1.1.2
	 * @param string $plugin_file Plugin file.
	 * @param array  $snapshot    Snapshot data.
	 * @return void
	 */
	public function restore_plugin_active_state( string $plugin_file, array $snapshot ): void {
		if ( ! $this->plugin_file_exists( $plugin_file ) ) {
			$this->logger->warning(
				'Managed plugin could not be reactivated because its plugin file is missing after update.',
				array( 'plugin' => $plugin_file )
			);
			return;
		}

		$this->ensure_plugin_functions_loaded();

		if ( ! is_plugin_active( $plugin_file ) ) {
			$result = activate_plugin( $plugin_file, '', false, true );
			if ( is_wp_error( $result ) ) {
				$this->logger->warning(
					'Managed plugin could not be reactivated after update.',
					array(
						'plugin' => $plugin_file,
						'code'   => $result->get_error_code(),
						'error'  => $result->get_error_message(),
					)
				);
				return;
			}
		}

		$this->restore_active_plugin_position( $plugin_file, $snapshot['position'] );
	}

	/**
	 * Restore plugin position in the active_plugins option.
	 *
	 * @since 1.1.2
	 * @param string    $plugin_file Plugin file.
	 * @param int|false $position    Original position.
	 * @return void
	 */
	public function restore_active_plugin_position( string $plugin_file, $position ): void {
		if ( false === $position ) {
			return;
		}

		$active_plugins = array_values(
			array_filter(
				$this->get_active_plugins(),
				static function ( $entry ) use ( $plugin_file ) {
					return is_string( $entry ) && '' !== $entry && $entry !== $plugin_file;
				}
			)
		);

		array_splice( $active_plugins, min( (int) $position, count( $active_plugins ) ), 0, array( $plugin_file ) );
		update_option( 'active_plugins', $active_plugins );
		wp_cache_delete( 'active_plugins', 'options' );
	}

	/**
	 * Resolve plugin file from upgrader hook context.
	 *
	 * @since 1.1.2
	 * @param array $hook_extra Extra upgrader context.
	 * @return string|null Plugin file or null.
	 */
	private function get_plugin_file_from_hook_extra( array $hook_extra ): ?string {
		if ( ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			return $hook_extra['plugin'];
		}

		return null;
	}

	/**
	 * Resolve completed plugin files from upgrader hook context.
	 *
	 * @since 1.1.2
	 * @param array $hook_extra Extra upgrader context.
	 * @return array<int, string> Plugin files.
	 */
	private function get_completed_plugin_files( array $hook_extra ): array {
		if ( 'update' !== ( $hook_extra['action'] ?? '' ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
			return array();
		}

		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			return array_values( array_filter( $hook_extra['plugins'], 'is_string' ) );
		}

		$plugin_file = $this->get_plugin_file_from_hook_extra( $hook_extra );
		return null === $plugin_file ? array() : array( $plugin_file );
	}

	/**
	 * Check whether the plugin is managed by Alynt Plugin Updater.
	 *
	 * @since 1.1.2
	 * @param string $plugin_file Plugin file.
	 * @return bool True when managed.
	 */
	private function is_managed_plugin( string $plugin_file ): bool {
		return null !== $this->scanner->get_plugin_github_data( $plugin_file );
	}

	/**
	 * Get the active plugins option.
	 *
	 * @since 1.1.2
	 * @return array<int, string> Active plugin files.
	 */
	private function get_active_plugins(): array {
		$active_plugins = get_option( 'active_plugins', array() );
		return is_array( $active_plugins ) ? $active_plugins : array();
	}

	/**
	 * Check whether a plugin file exists.
	 *
	 * @since 1.1.2
	 * @param string $plugin_file Plugin file.
	 * @return bool True when the plugin file exists.
	 */
	private function plugin_file_exists( string $plugin_file ): bool {
		return file_exists( WP_PLUGIN_DIR . '/' . $plugin_file );
	}

	/**
	 * Ensure WordPress plugin functions are available.
	 *
	 * @since 1.1.2
	 * @return void
	 */
	private function ensure_plugin_functions_loaded(): void {
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Register one late request reconciliation for completed active plugin updates.
	 *
	 * @since 1.1.3
	 * @return void
	 */
	private function register_shutdown_reconciliation(): void {
		if ( $this->shutdown_hook_registered || empty( $this->pending_finalizations ) ) {
			return;
		}

		$this->shutdown_hook_registered = true;
		add_action( 'shutdown', array( $this, 'finalize_restorations' ), PHP_INT_MAX );
	}
}
