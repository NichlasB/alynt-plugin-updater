<?php
/**
 * Active plugin restorer source tests.
 *
 * @package AlyntPluginUpdater
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests the updater active-state restore integration.
 */
class Alynt_Plugin_Updater_Active_Plugin_Restorer_Source_Test extends TestCase {
	/**
	 * Read a project file.
	 *
	 * @param string $relative_path Project-relative path.
	 * @return string
	 */
	private function read_project_file( string $relative_path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source test reads project files.
		$contents = file_get_contents( dirname( __DIR__ ) . '/' . $relative_path );

		$this->assertIsString( $contents );

		return $contents;
	}

	/**
	 * Active restorer registers around the native plugin update flow.
	 *
	 * @return void
	 */
	public function test_active_restorer_registers_update_restore_hooks(): void {
		$source = $this->read_project_file( 'includes/class-active-plugin-restorer.php' );

		$this->assertStringContainsString( "add_filter( 'upgrader_pre_install'", $source );
		$this->assertStringContainsString( "add_action( 'upgrader_process_complete'", $source );
		$this->assertStringContainsString( 'snapshot_before_install', $source );
		$this->assertStringContainsString( 'restore_after_upgrade', $source );
		$this->assertStringContainsString( 'finalize_restorations', $source );
		$this->assertStringContainsString( "add_action( 'shutdown'", $source );
		$this->assertStringContainsString( 'PHP_INT_MAX', $source );
		$this->assertStringContainsString( 'activate_plugin( $plugin_file', $source );
		$this->assertStringContainsString( "update_option( 'active_plugins'", $source );
	}

	/**
	 * Active restorer is part of the runtime service graph.
	 *
	 * @return void
	 */
	public function test_active_restorer_is_wired_into_runtime_services(): void {
		$factory = $this->read_project_file( 'includes/class-service-factory.php' );
		$plugin  = $this->read_project_file( 'includes/class-plugin.php' );

		$this->assertStringContainsString( 'new Active_Plugin_Restorer( $scanner, $logger )', $factory );
		$this->assertStringContainsString( "'active_restorer' => \$active_restorer", $factory );
		$this->assertStringContainsString( '$this->active_restorer = $services[\'active_restorer\'];', $plugin );
		$this->assertStringContainsString( '$this->active_restorer->register_hooks();', $plugin );
	}
}
