<?php
/**
 * Factory for shared runtime services.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Service_Factory.
 *
 * @since 1.0.0
 */
class Service_Factory {
	/**
	 * Build the core service graph used across runtime flows.
	 *
	 * @since 1.0.0
	 * @return array{
	 *     logger:          Logger,
	 *     scanner:         Plugin_Scanner,
	 *     version_util:    Version_Util,
	 *     github_api:      GitHub_API,
	 *     update_checker:  Update_Checker,
	 *     webhook_handler: Webhook_Handler
	 * }
	 */
	public static function create_runtime_services(): array {
		$scanner         = new Plugin_Scanner();
		$version_util    = new Version_Util();
		$logger          = new Logger();
		$http_client     = new GitHub_Http_Client( $logger );
		$release_cache   = new GitHub_Release_Cache( $logger );
		$github_api      = new GitHub_API( $http_client, $release_cache, $version_util, $logger );
		$source_fixer    = new Source_Directory_Fixer( $scanner );
		$update_checker  = new Update_Checker( $scanner, $github_api, $version_util, $source_fixer, $logger );
		$webhook_handler = new Webhook_Handler( $scanner, $github_api, $update_checker, $logger );

		return array(
			'logger'          => $logger,
			'scanner'         => $scanner,
			'version_util'    => $version_util,
			'github_api'      => $github_api,
			'update_checker'  => $update_checker,
			'webhook_handler' => $webhook_handler,
		);
	}
}
