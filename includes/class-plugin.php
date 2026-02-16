<?php
/**
 * Main plugin orchestrator.
 *
 * @package AlyntPluginUpdater
 * @since   1.0.0
 */

namespace Alynt\PluginUpdater;

use Alynt\PluginUpdater\Admin\Admin_Menu;
use Alynt\PluginUpdater\Admin\Plugins_List;
use Alynt\PluginUpdater\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin.
 *
 * @since 1.0.0
 */
class Plugin {
	/**
	 * Plugin scanner.
	 *
	 * @since 1.0.0
	 * @var   Plugin_Scanner
	 */
	private Plugin_Scanner $scanner;

	/**
	 * Update checker.
	 *
	 * @since 1.0.0
	 * @var   Update_Checker
	 */
	private Update_Checker $update_checker;

	/**
	 * Cron manager.
	 *
	 * @since 1.0.0
	 * @var   Cron_Manager
	 */
	private Cron_Manager $cron_manager;

	/**
	 * Webhook handler.
	 *
	 * @since 1.0.0
	 * @var   Webhook_Handler
	 */
	private Webhook_Handler $webhook_handler;

	/**
	 * Admin menu.
	 *
	 * @since 1.0.0
	 * @var   Admin_Menu|null
	 */
	private ?Admin_Menu $admin_menu = null;

	/**
	 * Settings handler.
	 *
	 * @since 1.0.0
	 * @var   Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Plugins list handler.
	 *
	 * @since 1.0.0
	 * @var   Plugins_List|null
	 */
	private ?Plugins_List $plugins_list = null;

	/**
	 * Initialize the plugin.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function init(): void {
		self::load_textdomain();

		$instance = new self();
		$instance->register_hooks();
	}

	/**
	 * Load plugin translation files.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function load_textdomain(): void {
		load_plugin_textdomain(
			'alynt-plugin-updater',
			false,
			dirname( ALYNT_PU_PLUGIN_BASENAME ) . '/languages/'
		);
	}

	/**
	 * Plugin constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$services = Service_Factory::create_runtime_services();

		$this->scanner         = $services['scanner'];
		$this->update_checker  = $services['update_checker'];
		$this->webhook_handler = $services['webhook_handler'];
		$this->cron_manager    = new Cron_Manager( $this->update_checker, $services['logger'] );

		/** @var GitHub_API $github_api */
		$github_api = $services['github_api'];

		if ( is_admin() ) {
			$this->settings     = new Settings( $this->scanner, $this->update_checker, $this->webhook_handler );
			$this->admin_menu   = new Admin_Menu( $this->settings );
			$this->plugins_list = new Plugins_List( $this->scanner, $github_api, $this->update_checker );
		}
	}

	/**
	 * Register all hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function register_hooks(): void {
		$this->scanner->register_hooks();
		$this->update_checker->register_hooks();
		$this->cron_manager->register_hooks();

		add_action( 'rest_api_init', array( $this->webhook_handler, 'register_rest_route' ) );

		if ( null !== $this->settings ) {
			add_action( 'admin_init', array( $this->settings, 'register_settings' ) );
			$this->settings->register_ajax_handlers();
		}

		if ( null !== $this->admin_menu ) {
			$this->admin_menu->register_hooks();
		}

		if ( null !== $this->plugins_list ) {
			$this->plugins_list->register_hooks();
		}
	}
}
