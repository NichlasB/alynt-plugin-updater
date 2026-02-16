<?php
/**
 * Logger utility.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger.
 *
 * @since 1.0.0
 */
class Logger {
	/**
	 * Log a debug message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$this->log( 'debug', $message, $context );
	}

	/**
	 * Log an info message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Format and write the log entry.
	 *
	 * @since 1.0.0
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	private function log( string $level, string $message, array $context = array() ): void {
		$prefix  = '[Alynt Plugin Updater]';
		$context = empty( $context ) ? '' : wp_json_encode( $context );
		$line    = sprintf( '%s [%s] %s %s', $prefix, $level, $message, $context );

		error_log( trim( $line ) );
	}
}
