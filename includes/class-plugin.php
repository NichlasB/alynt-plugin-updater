<?php
/**
 * Main plugin orchestrator.
 *
 * @package AlyntPluginUpdater
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
 */
class Plugin {
	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Plugin scanner.
	 *
	 * @var Plugin_Scanner
	 */
	private Plugin_Scanner $scanner;

	/**
	 * Version utility.
	 *
	 * @var Version_Util
	 */
	private Version_Util $version_util;

	/**
	 * GitHub API client.
	 *
	 * @var GitHub_API
	 */
	private GitHub_API $github_api;

	/**
	 * Update checker.
	 *
	 * @var Update_Checker
	 */
	private Update_Checker $update_checker;

	/**
	 * Plugin installer.
	 *
	 * @var Plugin_Installer
	 */
	private Plugin_Installer $installer;

	/**
	 * Cron manager.
	 *
	 * @var Cron_Manager
	 */
	private Cron_Manager $cron_manager;

	/**
	 * Webhook handler.
	 *
	 * @var Webhook_Handler
	 */
	private Webhook_Handler $webhook_handler;

	/**
	 * Admin menu.
	 *
	 * @var Admin_Menu|null
	 */
	private ?Admin_Menu $admin_menu = null;

	/**
	 * Settings handler.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Plugins list handler.
	 *
	 * @var Plugins_List|null
	 */
	private ?Plugins_List $plugins_list = null;

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public static function init(): void {
		$instance = new self();
		$instance->register_hooks();
	}

	/**
	 * Plugin constructor.
	 */
	private function __construct() {
		$this->logger         = new Logger();
		$this->scanner        = new Plugin_Scanner();
		$this->version_util   = new Version_Util();
		$this->github_api     = new GitHub_API( $this->version_util, $this->logger );
		$this->update_checker = new Update_Checker( $this->scanner, $this->github_api, $this->version_util );
		$this->installer      = new Plugin_Installer( $this->logger );
		$this->cron_manager   = new Cron_Manager( $this->update_checker );
		$this->webhook_handler = new Webhook_Handler( $this->scanner, $this->github_api, $this->update_checker, $this->logger );

		if ( is_admin() ) {
			$this->settings     = new Settings();
			$this->admin_menu   = new Admin_Menu( $this->settings );
			$this->plugins_list = new Plugins_List( $this->scanner, $this->github_api, $this->update_checker );
		}
	}

	/**
	 * Register all hooks.
	 *
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
