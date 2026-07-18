<?php
/**
 * Active plugin restorer behavior tests.
 *
 * @package AlyntPluginUpdater
 */

// phpcs:ignoreFile -- Isolated PHPUnit WordPress API and lifecycle test doubles.

namespace Alynt\PluginUpdater {
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) . '/tests/wp-stubs/plugins' );
	}

	/**
	 * Provide controllable plugin-file existence for the isolated test runtime.
	 *
	 * @param string $filename Plugin path.
	 * @return bool
	 */
	function file_exists( string $filename ): bool {
		unset( $filename );

		return $GLOBALS['alynt_pu_test_plugin_exists'] ?? true;
	}
}

namespace {
	use Alynt\PluginUpdater\Active_Plugin_Restorer;
	use Alynt\PluginUpdater\Logger;
	use Alynt\PluginUpdater\Plugin_Scanner;
	use PHPUnit\Framework\TestCase;

	if ( ! class_exists( 'WP_Upgrader' ) ) {
		/**
		 * Minimal upgrader test double.
		 */
		class WP_Upgrader {
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		/**
		 * Get an isolated test option.
		 *
		 * @param string $option  Option name.
		 * @param mixed  $default Default value.
		 * @return mixed
		 */
		function get_option( string $option, $default = false ) {
			return $GLOBALS['alynt_pu_test_options'][ $option ] ?? $default;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		/**
		 * Update an isolated test option.
		 *
		 * @param string $option Option name.
		 * @param mixed  $value  Option value.
		 * @return bool
		 */
		function update_option( string $option, $value ): bool {
			$GLOBALS['alynt_pu_test_options'][ $option ] = $value;

			return true;
		}
	}

	if ( ! function_exists( 'wp_cache_delete' ) ) {
		/**
		 * No-op cache deletion for isolated tests.
		 *
		 * @param string $key   Cache key.
		 * @param string $group Cache group.
		 * @return bool
		 */
		function wp_cache_delete( string $key, string $group = '' ): bool {
			unset( $key, $group );

			return true;
		}
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		/**
		 * Determine active state from the isolated option store.
		 *
		 * @param string $plugin_file Plugin file.
		 * @return bool
		 */
		function is_plugin_active( string $plugin_file ): bool {
			return in_array( $plugin_file, get_option( 'active_plugins', array() ), true );
		}
	}

	if ( ! function_exists( 'activate_plugin' ) ) {
		/**
		 * Simulate WordPress appending a newly activated plugin.
		 *
		 * @param string $plugin_file Plugin file.
		 * @return null
		 */
		function activate_plugin( string $plugin_file ) {
			$active_plugins   = get_option( 'active_plugins', array() );
			$active_plugins[] = $plugin_file;
			update_option( 'active_plugins', $active_plugins );

			return null;
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		/**
		 * The isolated activation stub always succeeds.
		 *
		 * @param mixed $value Candidate error.
		 * @return bool
		 */
		function is_wp_error( $value ): bool {
			unset( $value );

			return false;
		}
	}

	if ( ! function_exists( 'add_filter' ) ) {
		/**
		 * Capture registered filters.
		 *
		 * @param string   $hook          Hook name.
		 * @param callable $callback      Callback.
		 * @param int      $priority      Priority.
		 * @param int      $accepted_args Accepted arguments.
		 * @return bool
		 */
		function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
			$GLOBALS['alynt_pu_test_hooks'][ $hook ][] = compact( 'callback', 'priority', 'accepted_args' );

			return true;
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		/**
		 * Capture registered actions.
		 *
		 * @param string   $hook          Hook name.
		 * @param callable $callback      Callback.
		 * @param int      $priority      Priority.
		 * @param int      $accepted_args Accepted arguments.
		 * @return bool
		 */
		function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
			return add_filter( $hook, $callback, $priority, $accepted_args );
		}
	}

	/**
	 * Scanner test double with an explicit managed-plugin list.
	 */
	class Alynt_PU_Test_Scanner extends Plugin_Scanner {
		/**
		 * Managed plugin files.
		 *
		 * @var array<int, string>
		 */
		private array $managed_plugins;

		/**
		 * Constructor.
		 *
		 * @param array<int, string> $managed_plugins Managed plugin files.
		 */
		public function __construct( array $managed_plugins ) {
			$this->managed_plugins = $managed_plugins;
		}

		/**
		 * Return metadata for managed plugin files.
		 *
		 * @param string $plugin_file Plugin file.
		 * @return array|null
		 */
		public function get_plugin_github_data( string $plugin_file ): ?array {
			return in_array( $plugin_file, $this->managed_plugins, true ) ? array( 'repo' => 'managed' ) : null;
		}
	}

	/**
	 * Logger test double.
	 */
	class Alynt_PU_Test_Logger extends Logger {
		/**
		 * Captured warnings.
		 *
		 * @var array<int, array{message: string, context: array}>
		 */
		public array $warnings = array();

		/**
		 * Capture a warning.
		 *
		 * @param string $message Warning message.
		 * @param array  $context Warning context.
		 * @return void
		 */
		public function warning( string $message, array $context = array() ): void {
			$this->warnings[] = compact( 'message', 'context' );
		}
	}

	/**
	 * Tests active plugin restoration across the update lifecycle.
	 */
	class Alynt_Plugin_Updater_Active_Plugin_Restorer_Test extends TestCase {
		/**
		 * Managed plugin file.
		 */
		private const TARGET = 'alynt-account-gateway/alynt-account-gateway.php';

		/**
		 * Reset the isolated WordPress state.
		 *
		 * @return void
		 */
		protected function setUp(): void {
			$GLOBALS['alynt_pu_test_options']       = array();
			$GLOBALS['alynt_pu_test_hooks']         = array();
			$GLOBALS['alynt_pu_test_plugin_exists'] = true;
		}

		/**
		 * The restorer runs after competing update callbacks.
		 *
		 * @return void
		 */
		public function test_registers_restore_and_shutdown_hooks_at_latest_priority(): void {
			$restorer = $this->create_restorer();
			$restorer->register_hooks();

			$update_hooks = $GLOBALS['alynt_pu_test_hooks']['upgrader_process_complete'];

			$this->assertSame( PHP_INT_MAX, $update_hooks[0]['priority'] );

			$this->snapshot_active_target( $restorer );
			$this->simulate_core_deactivation();
			$restorer->restore_after_upgrade( new WP_Upgrader(), $this->update_context() );

			$shutdown_hooks = $GLOBALS['alynt_pu_test_hooks']['shutdown'];
			$this->assertCount( 1, $shutdown_hooks );
			$this->assertSame( PHP_INT_MAX, $shutdown_hooks[0]['priority'] );
		}

		/**
		 * Immediate restoration returns the active list to its exact prior order.
		 *
		 * @return void
		 */
		public function test_restores_active_plugin_after_core_deactivation(): void {
			$restorer = $this->create_restorer();

			$this->snapshot_active_target( $restorer );
			$this->simulate_core_deactivation();
			$restorer->restore_after_upgrade( new WP_Upgrader(), $this->update_context() );

			$this->assertSame( $this->original_active_plugins(), get_option( 'active_plugins' ) );
		}

		/**
		 * Shutdown reconciliation repairs a late callback that moved the plugin.
		 *
		 * @return void
		 */
		public function test_shutdown_reconciliation_repairs_late_active_list_reordering(): void {
			$restorer = $this->create_restorer();

			$this->snapshot_active_target( $restorer );
			$this->simulate_core_deactivation();
			$restorer->restore_after_upgrade( new WP_Upgrader(), $this->update_context() );

			update_option(
				'active_plugins',
				array(
					'example/alpha.php',
					'example/beta.php',
					self::TARGET,
				)
			);

			$restorer->finalize_restorations();

			$this->assertSame( $this->original_active_plugins(), get_option( 'active_plugins' ) );
		}

		/**
		 * Create a restorer for the managed target.
		 *
		 * @return Active_Plugin_Restorer
		 */
		private function create_restorer(): Active_Plugin_Restorer {
			return new Active_Plugin_Restorer(
				new Alynt_PU_Test_Scanner( array( self::TARGET ) ),
				new Alynt_PU_Test_Logger()
			);
		}

		/**
		 * Snapshot the target from its original active list.
		 *
		 * @param Active_Plugin_Restorer $restorer Restorer.
		 * @return void
		 */
		private function snapshot_active_target( Active_Plugin_Restorer $restorer ): void {
			update_option( 'active_plugins', $this->original_active_plugins() );
			$restorer->snapshot_before_install( true, array( 'plugin' => self::TARGET ) );
		}

		/**
		 * Simulate WordPress core deactivating the target before replacement.
		 *
		 * @return void
		 */
		private function simulate_core_deactivation(): void {
			update_option(
				'active_plugins',
				array(
					'example/alpha.php',
					'example/beta.php',
				)
			);
		}

		/**
		 * Get the original active plugin order.
		 *
		 * @return array<int, string>
		 */
		private function original_active_plugins(): array {
			return array(
				'example/alpha.php',
				self::TARGET,
				'example/beta.php',
			);
		}

		/**
		 * Get successful plugin update hook context.
		 *
		 * @return array<string, string>
		 */
		private function update_context(): array {
			return array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => self::TARGET,
			);
		}
	}
}
