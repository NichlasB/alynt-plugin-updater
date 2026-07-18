<?php
/**
 * Update checker stale-cache behavior tests.
 *
 * @package AlyntPluginUpdater
 */

// phpcs:ignoreFile -- Isolated PHPUnit WordPress API test doubles.

use Alynt\PluginUpdater\GitHub_API;
use Alynt\PluginUpdater\Logger;
use Alynt\PluginUpdater\Plugin_Scanner;
use Alynt\PluginUpdater\Source_Directory_Fixer;
use Alynt\PluginUpdater\Update_Checker;
use Alynt\PluginUpdater\Version_Util;
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Upgrader' ) ) {
	/**
	 * Minimal upgrader test double.
	 */
	class WP_Upgrader {
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WordPress error test double.
	 */
	class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		private string $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private string $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( string $code, string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Get the error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Get the error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Determine whether a value is a WordPress error.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
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
	 * @param string $option   Option name.
	 * @param mixed  $value    Option value.
	 * @param bool   $autoload Whether to autoload.
	 * @return bool
	 */
	function update_option( string $option, $value, bool $autoload = true ): bool {
		unset( $autoload );

		$GLOBALS['alynt_pu_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_site_transient' ) ) {
	/**
	 * Capture deleted site transients.
	 *
	 * @param string $transient Transient name.
	 * @return bool
	 */
	function delete_site_transient( string $transient ): bool {
		$GLOBALS['alynt_pu_deleted_site_transients'][] = $transient;

		return true;
	}
}

/**
 * Scanner test double for update-checker stale-cache tests.
 */
class Alynt_PU_Update_Checker_Test_Scanner extends Plugin_Scanner {
	/**
	 * Plugin data keyed by plugin file.
	 *
	 * @var array<string, array>
	 */
	private array $plugins;

	/**
	 * Constructor.
	 *
	 * @param array<string, array> $plugins Plugin data.
	 */
	public function __construct( array $plugins ) {
		$this->plugins = $plugins;
	}

	/**
	 * Return all GitHub-managed test plugins.
	 *
	 * @return array<string, array>
	 */
	public function get_github_plugins(): array {
		return $this->plugins;
	}

	/**
	 * Return GitHub data for one plugin.
	 *
	 * @param string $plugin_file Plugin file.
	 * @return array|null
	 */
	public function get_plugin_github_data( string $plugin_file ): ?array {
		return $this->plugins[ $plugin_file ] ?? null;
	}
}

/**
 * GitHub API test double with deterministic release payloads.
 */
class Alynt_PU_Update_Checker_Test_GitHub_API extends GitHub_API {
	/**
	 * Releases keyed by owner/repo.
	 *
	 * @var array<string, array|WP_Error>
	 */
	private array $releases;

	/**
	 * Constructor.
	 *
	 * @param array<string, array|WP_Error> $releases Release payloads.
	 */
	public function __construct( array $releases ) {
		$this->releases = $releases;
	}

	/**
	 * Return a configured release.
	 *
	 * @param string $owner       Repository owner.
	 * @param string $repo        Repository name.
	 * @param bool   $force_fresh Skip cache.
	 * @param bool   $cache_only  Cache-only flag.
	 * @return array|WP_Error
	 */
	public function get_latest_release( string $owner, string $repo, bool $force_fresh = false, bool $cache_only = false ) {
		unset( $force_fresh, $cache_only );

		return $this->releases[ $owner . '/' . $repo ] ?? new WP_Error( 'no_cached_data', 'No cached release data available.' );
	}
}

/**
 * Tests update checker stale-cache handling.
 */
class Alynt_Plugin_Updater_Update_Checker_Stale_Cache_Test extends TestCase {
	/**
	 * Managed plugin file.
	 */
	private const TARGET = 'alynt-account-gateway/alynt-account-gateway.php';

	/**
	 * Reset isolated WordPress state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['alynt_pu_test_hooks']                = array();
		$GLOBALS['alynt_pu_test_options']              = array();
		$GLOBALS['alynt_pu_deleted_site_transients']   = array();
	}

	/**
	 * A cached release matching the installed version removes stale update entries.
	 *
	 * @return void
	 */
	public function test_check_for_updates_removes_stale_response_when_plugin_is_current(): void {
		$checker   = $this->create_checker( '1.1.6', $this->release( '1.1.6' ) );
		$transient = (object) array(
			'response' => array(
				self::TARGET => (object) array(
					'new_version' => '1.1.6',
					'package'     => 'https://example.com/old.zip',
				),
			),
		);

		$result = $checker->check_for_updates( $transient );

		$this->assertArrayNotHasKey( self::TARGET, $result->response );
	}

	/**
	 * A cached newer release still produces an update response.
	 *
	 * @return void
	 */
	public function test_check_for_updates_keeps_response_when_release_is_newer(): void {
		$checker   = $this->create_checker( '1.1.5', $this->release( '1.1.6' ) );
		$transient = (object) array( 'response' => array() );

		$result = $checker->check_for_updates( $transient );

		$this->assertArrayHasKey( self::TARGET, $result->response );
		$this->assertSame( '1.1.6', $result->response[ self::TARGET ]->new_version );
	}

	/**
	 * Completed managed plugin updates refresh stored status and clear native update cache.
	 *
	 * @return void
	 */
	public function test_refresh_after_plugin_upgrade_updates_stored_results_and_clears_native_transient(): void {
		$GLOBALS['alynt_pu_test_options']['alynt_pu_last_results'] = array(
			self::TARGET => array(
				'update_available' => true,
				'current_version'  => '1.1.5',
				'new_version'      => '1.1.6',
				'download_url'     => 'https://example.com/release.zip',
			),
		);

		$checker = $this->create_checker( '1.1.6', $this->release( '1.1.6' ) );

		$checker->refresh_after_plugin_upgrade(
			new WP_Upgrader(),
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => self::TARGET,
			)
		);

		$stored = get_option( 'alynt_pu_last_results', array() );

		$this->assertFalse( $stored[ self::TARGET ]['update_available'] );
		$this->assertSame( '1.1.6', $stored[ self::TARGET ]['current_version'] );
		$this->assertSame( '1.1.6', $stored[ self::TARGET ]['new_version'] );
		$this->assertContains( 'update_plugins', $GLOBALS['alynt_pu_deleted_site_transients'] );
	}

	/**
	 * Create an update checker with one managed plugin.
	 *
	 * @param string              $current_version Current installed version.
	 * @param array<string,mixed> $release         Cached release payload.
	 * @return Update_Checker
	 */
	private function create_checker( string $current_version, array $release ): Update_Checker {
		$scanner = new Alynt_PU_Update_Checker_Test_Scanner(
			array(
				self::TARGET => array(
					'name'        => 'Alynt Account Gateway',
					'version'     => $current_version,
					'owner'       => 'NichlasB',
					'repo'        => 'alynt-account-gateway',
					'plugin_uri'  => 'https://github.com/NichlasB/alynt-account-gateway',
					'author'      => 'Alynt',
					'description' => '',
				),
			)
		);

		return new Update_Checker(
			$scanner,
			new Alynt_PU_Update_Checker_Test_GitHub_API(
				array(
					'NichlasB/alynt-account-gateway' => $release,
				)
			),
			new Version_Util(),
			new Source_Directory_Fixer( $scanner ),
			new Logger()
		);
	}

	/**
	 * Build a cached release payload.
	 *
	 * @param string $version Version number.
	 * @return array<string, string>
	 */
	private function release( string $version ): array {
		return array(
			'version'      => $version,
			'tag'          => 'v' . $version,
			'download_url' => 'https://example.com/release.zip',
			'changelog'    => '',
			'published_at' => '2026-07-18T00:00:00Z',
			'source'       => 'releases',
		);
	}
}
