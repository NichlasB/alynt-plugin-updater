<?php
/**
 * Admin menu registration.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Menu.
 *
 * @since 1.0.0
 */
class Admin_Menu {
	/**
	 * Settings handler.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Settings $settings Settings handler.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register admin hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	/**
	 * Add settings page under Settings menu.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'Alynt Plugin Updater', 'alynt-plugin-updater' ),
			__( 'Plugin Updater', 'alynt-plugin-updater' ),
			'manage_options',
			'alynt-plugin-updater',
			array( $this->settings, 'render_settings_page' )
		);
	}
}
