<?php
/**
 * Autoloader for plugin classes.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Loader.
 *
 * @since 1.0.0
 */
class Loader {
	/**
	 * Register the autoloader.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload callback.
	 *
	 * @since 1.0.0
	 * @param string $class Fully-qualified class name.
	 * @return void
	 */
	public static function autoload( string $class ): void {
		$prefix = 'Alynt\\PluginUpdater\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$base_dir = defined( 'ALYNT_PU_PLUGIN_DIR' )
			? ALYNT_PU_PLUGIN_DIR
			: rtrim( dirname( __DIR__ ), '/\\' ) . DIRECTORY_SEPARATOR;

		if ( 0 === strpos( $relative, 'Admin\\' ) ) {
			$relative = substr( $relative, strlen( 'Admin\\' ) );
			$dir      = $base_dir . 'admin' . DIRECTORY_SEPARATOR;
		} else {
			$dir = $base_dir . 'includes' . DIRECTORY_SEPARATOR;
		}

		$relative = str_replace( '\\', '_', $relative );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		$path     = $dir . $file;

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
