<?php
/**
 * Source directory fixer for GitHub plugin downloads.
 *
 * GitHub zipball archives extract to folders named {owner}-{repo}-{hash}.
 * This class renames the extracted folder to match the expected plugin slug
 * so WordPress recognises the upgrade correctly.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Source_Directory_Fixer.
 *
 * @since 1.0.0
 */
class Source_Directory_Fixer {
	/**
	 * Plugin scanner.
	 *
	 * @since 1.0.0
	 * @var Plugin_Scanner
	 */
	private Plugin_Scanner $scanner;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Plugin_Scanner $scanner Plugin scanner.
	 */
	public function __construct( Plugin_Scanner $scanner ) {
		$this->scanner = $scanner;
	}

	/**
	 * Fix the source directory name for GitHub downloads.
	 *
	 * Hooked to the 'upgrader_source_selection' filter.
	 *
	 * @since 1.0.0
	 * @param string       $source         File source location.
	 * @param string       $_remote_source Remote file source location.
	 * @param \WP_Upgrader $_upgrader      WP_Upgrader instance.
	 * @param array        $args           Extra arguments passed to hooked filters.
	 * @return string|WP_Error Corrected source path or WP_Error.
	 */
	public function fix_source_directory( string $source, string $_remote_source, $_upgrader, array $args ) {
		if ( ! isset( $args['plugin'] ) || empty( $args['plugin'] ) ) {
			return $source;
		}

		$plugin_file = $args['plugin'];
		$plugins     = $this->scanner->get_github_plugins();

		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			return $source;
		}

		$expected_slug = dirname( $plugin_file );
		$source_slug   = basename( untrailingslashit( $source ) );

		// Already correct name — no action needed.
		if ( $source_slug === $expected_slug ) {
			return $source;
		}

		// Check if this looks like a GitHub-style folder name (owner-repo-hash).
		if ( strpos( $source_slug, '-' ) === false ) {
			return $source;
		}

		if ( ! $this->ensure_filesystem() ) {
			return new WP_Error(
				'filesystem_error',
				__( 'Could not initialise the WordPress filesystem. Please check your file permissions and try the update again.', 'alynt-plugin-updater' )
			);
		}

		$new_source = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $expected_slug . '/';

		return $this->move_source( $source, $new_source, $source_slug, $expected_slug );
	}

	/**
	 * Ensure the WordPress filesystem API is available.
	 *
	 * @since 1.0.0
	 * @return bool True if the filesystem is ready, false otherwise.
	 */
	private function ensure_filesystem(): bool {
		global $wp_filesystem;

		if ( $wp_filesystem ) {
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$initialised = WP_Filesystem();

		return ( $initialised && $wp_filesystem );
	}

	/**
	 * Move or copy the source directory to the expected location.
	 *
	 * @since 1.0.0
	 * @param string $source        Current source path.
	 * @param string $new_source    Target path.
	 * @param string $source_slug   Current folder name.
	 * @param string $expected_slug Expected folder name.
	 * @return string|WP_Error New source path or WP_Error on failure.
	 */
	private function move_source( string $source, string $new_source, string $source_slug, string $expected_slug ) {
		global $wp_filesystem;

		// If destination already exists, remove it first.
		if ( $wp_filesystem->exists( $new_source ) ) {
			$wp_filesystem->delete( $new_source, true );
		}

		if ( $wp_filesystem->move( $source, $new_source ) ) {
			return $new_source;
		}

		// Fallback: try copy + delete instead of move (Windows compatibility).
		$copied = $wp_filesystem->copy( $source, $new_source, true, FS_CHMOD_DIR );
		if ( ! $copied ) {
			$copied = ( copy_dir( $source, $new_source ) === true );
		}

		if ( $copied ) {
			$wp_filesystem->delete( $source, true );
			return $new_source;
		}

		return new WP_Error(
			'rename_failed',
			sprintf(
				/* translators: 1: source folder, 2: destination folder */
				__( 'Could not rename plugin folder from %1$s to %2$s.', 'alynt-plugin-updater' ),
				$source_slug,
				$expected_slug
			)
		);
	}
}
