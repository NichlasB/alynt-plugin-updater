<?php
/**
 * Shared admin asset manager.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Asset_Manager.
 *
 * @since 1.0.0
 */
class Asset_Manager {
	/**
	 * Script/style handle.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const SCRIPT_HANDLE = 'alynt-pu-admin';

	/**
	 * Localized object name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const SCRIPT_OBJECT = 'alyntPuAdmin';

	/**
	 * Enqueue shared admin scripts and styles.
	 *
	 * @since 1.0.0
	 * @return string Script handle.
	 */
	public static function enqueue_admin_assets(): string {
		$script_path = ALYNT_PU_PLUGIN_DIR . 'assets/dist/admin/index.js';
		$script_url  = ALYNT_PU_PLUGIN_URL . 'assets/dist/admin/index.js';
		$style_path  = ALYNT_PU_PLUGIN_DIR . 'assets/dist/admin/style.css';
		$style_url   = ALYNT_PU_PLUGIN_URL . 'assets/dist/admin/style.css';

		if ( ! file_exists( $script_path ) ) {
			$script_path = ALYNT_PU_PLUGIN_DIR . 'assets/src/admin/index.js';
			$script_url  = ALYNT_PU_PLUGIN_URL . 'assets/src/admin/index.js';
		}

		if ( ! file_exists( $style_path ) ) {
			$style_path = ALYNT_PU_PLUGIN_DIR . 'assets/src/admin/style.css';
			$style_url  = ALYNT_PU_PLUGIN_URL . 'assets/src/admin/style.css';
		}

		$version = file_exists( $script_path ) ? filemtime( $script_path ) : ALYNT_PU_VERSION;

		wp_enqueue_script( self::SCRIPT_HANDLE, $script_url, array(), $version, true );
		wp_enqueue_style( self::SCRIPT_HANDLE, $style_url, array(), $version );

		return self::SCRIPT_HANDLE;
	}

	/**
	 * Get shared localization payload for admin UI.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public static function get_base_localization_data(): array {
		return array(
			'ajaxurl'         => admin_url( 'admin-ajax.php' ),
			'checking'        => __( 'Checking...', 'alynt-plugin-updater' ),
			'upToDate'        => __( 'Up to date ✓', 'alynt-plugin-updater' ),
			/* translators: %s: available update version. */
			'updateAvailable' => __( 'Update available (v%s)', 'alynt-plugin-updater' ),
			'checkFailed'     => __( 'Check failed', 'alynt-plugin-updater' ),
		);
	}

	/**
	 * Localize data to the shared admin script.
	 *
	 * @since 1.0.0
	 * @param array $payload Localization payload.
	 * @return void
	 */
	public static function localize_admin_script( array $payload ): void {
		wp_localize_script( self::SCRIPT_HANDLE, self::SCRIPT_OBJECT, $payload );
	}
}
