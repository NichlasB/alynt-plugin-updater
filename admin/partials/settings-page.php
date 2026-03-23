<?php
/**
 * Settings page template.
 *
 * @package AlyntPluginUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$last_check_display = $last_check ? date_i18n( 'Y-m-d H:i:s', $last_check ) : __( 'Never', 'alynt-plugin-updater' );
$next_check_display = $next_check ? date_i18n( 'Y-m-d H:i:s', $next_check ) : __( 'Not scheduled', 'alynt-plugin-updater' );

$rate_limit_display = __( 'OK', 'alynt-plugin-updater' );
if ( $rate_limit_reset ) {
	$rate_limit_display = sprintf(
		/* translators: %s: datetime */
		__( 'Limited until %s', 'alynt-plugin-updater' ),
		date_i18n( 'Y-m-d H:i:s', (int) $rate_limit_reset )
	);
}

$cache_duration_error_message = ! empty( $cache_duration_error ) && ! empty( $cache_duration_error[0]['message'] )
	? wp_strip_all_tags( (string) $cache_duration_error[0]['message'] )
	: '';
?>
<div class="wrap alynt-pu-settings">
	<h1><?php echo esc_html__( 'Alynt Plugin Updater', 'alynt-plugin-updater' ); ?></h1>
	<?php settings_errors(); ?>
	<hr class="wp-header-end">

	<form method="post" action="options.php">
		<?php settings_fields( 'alynt_pu_settings' ); ?>
		<h2><?php echo esc_html__( 'General Settings', 'alynt-plugin-updater' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="alynt_pu_check_frequency"><?php echo esc_html__( 'Check Frequency', 'alynt-plugin-updater' ); ?></label>
				</th>
				<td>
					<select name="alynt_pu_check_frequency" id="alynt_pu_check_frequency">
						<?php foreach ( $frequency_options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $frequency, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="alynt_pu_cache_duration"><?php echo esc_html__( 'Cache Duration (seconds)', 'alynt-plugin-updater' ); ?></label>
				</th>
				<td>
					<input type="number" class="small-text" id="alynt_pu_cache_duration" name="alynt_pu_cache_duration" value="<?php echo esc_attr( $cache_duration_field_value ); ?>" min="<?php echo esc_attr( $cache_duration_min ); ?>" max="<?php echo esc_attr( $cache_duration_max ); ?>" aria-describedby="alynt-pu-cache-duration-desc<?php echo $cache_duration_error_message ? ' alynt-pu-cache-duration-error' : ''; ?>" <?php echo $cache_duration_error_message ? 'aria-invalid="true"' : ''; ?> />
					<p class="description" id="alynt-pu-cache-duration-desc"><?php echo esc_html__( 'How long to cache GitHub API responses.', 'alynt-plugin-updater' ); ?></p>
					<?php if ( $cache_duration_error_message ) : ?>
						<p id="alynt-pu-cache-duration-error" class="alynt-pu-field-error" role="alert"><?php echo esc_html( $cache_duration_error_message ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'alynt-plugin-updater' ) ); ?>
	</form>

	<hr />

	<h2><?php echo esc_html__( 'Webhook Configuration', 'alynt-plugin-updater' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="alynt_pu_webhook_url"><?php echo esc_html__( 'Webhook URL', 'alynt-plugin-updater' ); ?></label>
			</th>
			<td>
				<input type="text" readonly class="regular-text" id="alynt_pu_webhook_url" value="<?php echo esc_attr( $webhook_url ); ?>" />
				<button type="button" class="button alynt-pu-copy" data-target="alynt_pu_webhook_url" aria-label="<?php esc_attr_e( 'Copy webhook URL', 'alynt-plugin-updater' ); ?>">
					<?php echo esc_html__( 'Copy', 'alynt-plugin-updater' ); ?>
				</button>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="alynt_pu_webhook_secret"><?php echo esc_html__( 'Secret Key', 'alynt-plugin-updater' ); ?></label>
			</th>
			<td>
				<input type="text" readonly class="regular-text" id="alynt_pu_webhook_secret" value="<?php echo esc_attr( $webhook_secret ); ?>" />
				<button type="button" class="button alynt-pu-copy" data-target="alynt_pu_webhook_secret" aria-label="<?php esc_attr_e( 'Copy secret key', 'alynt-plugin-updater' ); ?>">
					<?php echo esc_html__( 'Copy', 'alynt-plugin-updater' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="alynt-pu-generate-secret">
					<?php echo esc_html__( 'Generate New Secret', 'alynt-plugin-updater' ); ?>
				</button>
			</td>
		</tr>
	</table>

	<details class="alynt-pu-webhook-instructions">
		<summary><?php echo esc_html__( 'Webhook Setup Instructions', 'alynt-plugin-updater' ); ?></summary>
		<ol>
			<li><?php echo esc_html__( 'Go to your GitHub repository → Settings → Webhooks → Add webhook.', 'alynt-plugin-updater' ); ?></li>
			<li><?php echo esc_html__( 'Payload URL: use the webhook URL above.', 'alynt-plugin-updater' ); ?></li>
			<li><?php echo esc_html__( 'Content type: application/json.', 'alynt-plugin-updater' ); ?></li>
			<li><?php echo esc_html__( 'Secret: use the secret key above.', 'alynt-plugin-updater' ); ?></li>
			<li><?php echo esc_html__( 'SSL verification: enable.', 'alynt-plugin-updater' ); ?></li>
			<li><?php echo esc_html__( 'Select individual events and choose Releases only.', 'alynt-plugin-updater' ); ?></li>
		</ol>
		<p><?php echo esc_html__( 'Webhook is optional. Cron checks will still run if not configured.', 'alynt-plugin-updater' ); ?></p>
	</details>

	<hr />

	<h2><?php echo esc_html__( 'Status', 'alynt-plugin-updater' ); ?></h2>
	<table class="widefat striped" aria-label="<?php esc_attr_e( 'Update check status', 'alynt-plugin-updater' ); ?>">
		<tbody>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Last Check', 'alynt-plugin-updater' ); ?></th>
				<td><?php echo esc_html( $last_check_display ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Next Scheduled Check', 'alynt-plugin-updater' ); ?></th>
				<td><?php echo esc_html( $next_check_display ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Rate Limit Status', 'alynt-plugin-updater' ); ?></th>
				<td><?php echo esc_html( $rate_limit_display ); ?></td>
			</tr>
		</tbody>
	</table>

	<div class="alynt-pu-actions">
		<button type="button" class="button button-primary" id="alynt-pu-check-all" data-nonce="<?php echo esc_attr( $check_all_nonce ); ?>">
			<?php echo esc_html__( 'Check All for Updates Now', 'alynt-plugin-updater' ); ?>
		</button>
		<span id="alynt-pu-check-all-status" class="alynt-pu-status-message" aria-live="polite" aria-atomic="true"></span>
	</div>
	<div id="alynt-pu-screen-reader-feedback" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>

	<h2><?php echo esc_html__( 'Registered Plugins', 'alynt-plugin-updater' ); ?></h2>
	<table class="widefat striped alynt-pu-plugins-table" aria-label="<?php esc_attr_e( 'Registered plugins', 'alynt-plugin-updater' ); ?>">
		<thead>
			<tr>
				<th scope="col"><?php echo esc_html__( 'Plugin', 'alynt-plugin-updater' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Current Version', 'alynt-plugin-updater' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'GitHub Repo', 'alynt-plugin-updater' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Last Checked', 'alynt-plugin-updater' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Status', 'alynt-plugin-updater' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $plugins ) ) : ?>
				<tr>
					<td colspan="5">
						<div class="alynt-plugin-updater-empty-state">
							<h3><?php echo esc_html__( 'No Registered Plugins Yet', 'alynt-plugin-updater' ); ?></h3>
							<p><?php echo esc_html__( 'GitHub-managed plugins will appear here after the updater detects a supported plugin on this site.', 'alynt-plugin-updater' ); ?></p>
							<p><?php echo esc_html__( 'Add the required GitHub plugin headers to a plugin, then refresh this page to check again.', 'alynt-plugin-updater' ); ?></p>
						</div>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $plugins as $plugin_file => $plugin_data ) : ?>
					<?php
					$status = __( 'Unknown', 'alynt-plugin-updater' );
					if ( isset( $results[ $plugin_file ] ) && is_array( $results[ $plugin_file ] ) ) {
						$result = $results[ $plugin_file ];
						if ( ! empty( $result['update_available'] ) ) {
							$status = sprintf(
								/* translators: %s: version number */
								__( 'Update available (v%s)', 'alynt-plugin-updater' ),
								$result['new_version']
							);
						} else {
							$status = __( 'Up to date', 'alynt-plugin-updater' );
						}
					}
					?>
					<tr>
						<td><?php echo esc_html( $plugin_data['name'] ); ?></td>
						<td><?php echo esc_html( $plugin_data['version'] ); ?></td>
						<td>
							<a href="<?php echo esc_url( $plugin_data['plugin_uri'] ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $plugin_data['owner'] . '/' . $plugin_data['repo'] ); ?>
								<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'alynt-plugin-updater' ); ?></span>
							</a>
						</td>
						<td><?php echo esc_html( $last_check_display ); ?></td>
						<td><?php echo esc_html( $status ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
