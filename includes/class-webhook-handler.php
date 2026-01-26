<?php
/**
 * Webhook handler for GitHub updates.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Webhook_Handler.
 */
class Webhook_Handler {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private const REST_NAMESPACE = 'alynt-pu/v1';

	/**
	 * REST route.
	 *
	 * @var string
	 */
	private const REST_ROUTE = '/webhook';

	/**
	 * Plugin scanner.
	 *
	 * @var Plugin_Scanner
	 */
	private Plugin_Scanner $scanner;

	/**
	 * GitHub API.
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
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Plugin_Scanner $scanner        Plugin scanner.
	 * @param GitHub_API     $github_api     GitHub API client.
	 * @param Update_Checker $update_checker Update checker.
	 * @param Logger         $logger         Logger.
	 */
	public function __construct( Plugin_Scanner $scanner, GitHub_API $github_api, Update_Checker $update_checker, Logger $logger ) {
		$this->scanner        = $scanner;
		$this->github_api     = $github_api;
		$this->update_checker = $update_checker;
		$this->logger         = $logger;
	}

	/**
	 * Register REST API route.
	 *
	 * @return void
	 */
	public function register_rest_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle incoming webhook request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$signature = $request->get_header( 'X-Hub-Signature-256' );
		if ( empty( $signature ) ) {
			return new WP_REST_Response( array( 'error' => 'missing_signature' ), 400 );
		}

		$payload = $request->get_body();
		$verified = $this->verify_signature( $payload, $signature );
		if ( $verified instanceof WP_REST_Response ) {
			return $verified;
		}

		$data = json_decode( $payload, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_json' ), 400 );
		}

		if ( empty( $data['action'] ) || ! is_string( $data['action'] ) ) {
			return new WP_REST_Response( array( 'error' => 'missing_action' ), 400 );
		}

		if ( ! in_array( $data['action'], array( 'published', 'released', 'created' ), true ) ) {
			return new WP_REST_Response( array( 'status' => 'ignored', 'reason' => 'unsupported_action' ), 200 );
		}

		if ( empty( $data['repository']['full_name'] ) || ! is_string( $data['repository']['full_name'] ) ) {
			return new WP_REST_Response( array( 'error' => 'missing_repository' ), 400 );
		}

		return $this->process_payload( $data );
	}

	/**
	 * Verify webhook signature.
	 *
	 * @param string $payload   Raw request body.
	 * @param string $signature Header signature.
	 * @return bool|WP_REST_Response True if valid, or error response.
	 */
	private function verify_signature( string $payload, string $signature ) {
		$secret = get_option( 'alynt_pu_webhook_secret', '' );
		if ( empty( $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'webhook_not_configured' ), 403 );
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			$this->logger->warning(
				'Invalid webhook signature attempt.',
				array(
					'ip'               => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown',
					'signature_prefix' => substr( $signature, 0, 20 ),
				)
			);

			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		return true;
	}

	/**
	 * Process valid webhook payload.
	 *
	 * @param array $payload Payload data.
	 * @return WP_REST_Response Response object.
	 */
	private function process_payload( array $payload ): WP_REST_Response {
		$full_name = $payload['repository']['full_name'];
		$parts     = array_values( array_filter( explode( '/', $full_name ) ) );

		if ( 2 !== count( $parts ) ) {
			return new WP_REST_Response( array( 'error' => 'missing_repository' ), 400 );
		}

		$owner = $parts[0];
		$repo  = $parts[1];

		$plugins = $this->scanner->get_github_plugins();
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( $plugin_data['owner'] !== $owner || $plugin_data['repo'] !== $repo ) {
				continue;
			}

			$this->github_api->clear_cache( $owner, $repo );
			$this->update_checker->check_plugin_update( $plugin_file );
			delete_site_transient( 'update_plugins' );

			return new WP_REST_Response(
				array(
					'status' => 'success',
					'plugin' => $plugin_file,
				),
				200
			);
		}

		return new WP_REST_Response( array( 'status' => 'no_matching_plugin' ), 200 );
	}

	/**
	 * Get the full webhook URL.
	 *
	 * @return string Webhook URL.
	 */
	public function get_webhook_url(): string {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/**
	 * Generate a new webhook secret.
	 *
	 * @return string Secret key.
	 */
	public static function generate_secret(): string {
		return wp_generate_password( 32, false );
	}
}
