<?php
/**
 * Cron manager for scheduled update checks.
 *
 * @package AlyntPluginUpdater
 */

namespace Alynt\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cron_Manager.
 *
 * @since 1.0.0
 */
class Cron_Manager {
	/**
	 * Scheduled hook name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const HOOK_NAME = 'alynt_pu_scheduled_check';

	/**
	 * Update checker.
	 *
	 * @since 1.0.0
	 * @var Update_Checker
	 */
	private Update_Checker $update_checker;

	/**
	 * Logger.
	 *
	 * @since 1.0.0
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Update_Checker $update_checker Update checker.
	 * @param Logger         $logger         Logger.
	 */
	public function __construct( Update_Checker $update_checker, Logger $logger ) {
		$this->update_checker = $update_checker;
		$this->logger         = $logger;
	}

	/**
	 * Register cron hooks and custom schedules.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_action( self::HOOK_NAME, array( $this, 'run_scheduled_check' ) );

		add_action(
			'update_option_alynt_pu_check_frequency',
			array( $this, 'handle_frequency_option_update' ),
			10,
			2
		);
	}

	/**
	 * Handle updates to the check frequency option.
	 *
	 * @since 1.0.0
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public function handle_frequency_option_update( $old_value, $new_value ): void {
		if ( $old_value === $new_value ) {
			return;
		}

		$this->update_frequency( (string) $new_value );
	}

	/**
	 * Add custom cron schedules.
	 *
	 * @since 1.0.0
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules( array $schedules ): array {
		$schedules['six_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours', 'alynt-plugin-updater' ),
		);

		$schedules['weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once weekly', 'alynt-plugin-updater' ),
		);

		return $schedules;
	}

	/**
	 * Schedule the update check event.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function schedule_checks(): void {
		$frequency = get_option( 'alynt_pu_check_frequency', 'twicedaily' );
		$schedules = wp_get_schedules();

		if ( ! isset( $schedules[ $frequency ] ) ) {
			$frequency = 'twicedaily';
		}

		if ( ! wp_next_scheduled( self::HOOK_NAME ) ) {
			wp_schedule_event( time(), $frequency, self::HOOK_NAME );
		}
	}

	/**
	 * Unschedule the update check event.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function unschedule_checks(): void {
		wp_clear_scheduled_hook( self::HOOK_NAME );
	}

	/**
	 * Update the check frequency.
	 *
	 * @since 1.0.0
	 * @param string $new_frequency New frequency value.
	 * @return void
	 */
	public function update_frequency( string $new_frequency ): void {
		$this->unschedule_checks();

		$schedules = wp_get_schedules();
		if ( ! isset( $schedules[ $new_frequency ] ) ) {
			$new_frequency = 'twicedaily';
		}

		wp_schedule_event( time(), $new_frequency, self::HOOK_NAME );
	}

	/**
	 * Callback for scheduled check event.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function run_scheduled_check(): void {
		$results = $this->update_checker->check_all_updates( false );
		update_option( 'alynt_pu_last_check', time() );

		$error_count = 0;
		foreach ( $results as $plugin_file => $result ) {
			if ( ! empty( $result['error'] ) ) {
				++$error_count;
			}
		}

		if ( $error_count > 0 ) {
			$this->logger->warning(
				'Scheduled check completed with errors.',
				array(
					'total'  => count( $results ),
					'errors' => $error_count,
				)
			);
		}
	}

	/**
	 * Check if event is currently scheduled.
	 *
	 * @since 1.0.0
	 * @return bool|int False if not scheduled, or timestamp of next run.
	 */
	public function is_scheduled() {
		return wp_next_scheduled( self::HOOK_NAME );
	}
}
