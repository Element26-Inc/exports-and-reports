<?php
/**
 * The Settings page.
 *
 * @package Exports_And_Reports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reset settings for plugin.
 *
 * @since 0.9.4
 */
function exports_reports_reset() {
	update_option( 'exports_reports_version', EXPORTS_REPORTS_VERSION );

	$token = md5( microtime() . wp_generate_password( 20 ) );
	update_option( 'exports_reports_token', $token );
}

/**
 * Output the plugin settings page.
 */
function exports_reports_settings() {
	if ( ! current_user_can( 'manage_options' ) && ! exports_reports_current_user_can_any( array( 'exports_reports_full_access', 'exports_reports_settings' ) ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage the Exports and Reports settings.', 'exports-and-reports' ), 403 );
	}

	$cleared = false;
	$reset   = false;

	if ( isset( $_POST['cronjob_token'] ) || ! empty( $_POST['clear'] ) || ! empty( $_POST['reset'] ) ) {
		check_admin_referer( 'exports-reports-settings' );

		if ( isset( $_POST['cronjob_token'] ) ) {
			// An empty token turns the token access off.
			update_option( 'exports_reports_token', sanitize_title( sanitize_text_field( wp_unslash( $_POST['cronjob_token'] ) ) ) );
		}

		if ( ! empty( $_POST['clear'] ) || ! empty( $_POST['reset'] ) ) {
			exports_reports_cleanup( true );
			$cleared = true;
		}

		if ( ! empty( $_POST['reset'] ) ) {
			exports_reports_reset();
			$reset = true;
		}
	}

	$rest_base = rest_url( 'exports-reports/v1/reports/YOUR_REPORT_ID' );
	$token     = (string) get_option( 'exports_reports_token' );

	wp_enqueue_style( 'exports-reports-admin' );
	?>
	<div class="wrap exports-reports-settings">
		<h2><?php esc_html_e( 'Exports and Reports - Settings', 'exports-and-reports' ); ?></h2>

		<?php if ( $cleared ) : ?>
			<div id="message" class="updated fade">
				<p><?php esc_html_e( 'Your Exports directory has been cleaned up and all export files have been removed.', 'exports-and-reports' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $reset ) : ?>
			<div id="message" class="updated fade">
				<p><?php esc_html_e( 'Your Settings have been reset.', 'exports-and-reports' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="">
			<?php wp_nonce_field( 'exports-reports-settings' ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="clear"><?php esc_html_e( 'Clear Exports Directory', 'exports-and-reports' ); ?></label></th>
					<td>
						<input name="clear" type="submit" id="clear" class="button" value="<?php esc_attr_e( 'Clear Now', 'exports-and-reports' ); ?>" />
						<span class="description">
							<?php
							/* translators: %s: the export directory, relative to the WordPress directory. */
							echo esc_html( sprintf( __( 'This will remove all files from your Exports directory - %s', 'exports-and-reports' ), str_replace( ABSPATH, '', EXPORTS_REPORTS_EXPORT_DIR ) ) );
							?>
						</span>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Export Directory', 'exports-and-reports' ); ?></th>
					<td>
						<code><?php echo esc_html( EXPORTS_REPORTS_EXPORT_DIR ); ?></code><br />
						<span class="description">
							<?php esc_html_e( 'Downloads are served by the plugin after an access check; the directory itself should not be reachable by URL.', 'exports-and-reports' ); ?>
							<?php if ( file_exists( EXPORTS_REPORTS_EXPORT_DIR . '/.htaccess' ) ) : ?>
								<?php esc_html_e( 'Apache: the plugin\'s .htaccess denies direct requests.', 'exports-and-reports' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Apache: the plugin writes an .htaccess that denies direct requests with the first export.', 'exports-and-reports' ); ?>
							<?php endif; ?>
							<?php
							echo wp_kses(
								sprintf(
									/* translators: 1: the nginx location rule, 2: the EXPORTS_REPORTS_EXPORT_DIR constant. */
									__( 'nginx ignores .htaccess; add %1$s to the server block, or define %2$s in wp-config.php with a directory outside the web root, which works on any server.', 'exports-and-reports' ),
									'<code>location ~* ' . esc_html( trailingslashit( (string) wp_parse_url( EXPORTS_REPORTS_EXPORT_URL, PHP_URL_PATH ) ) ) . ' { deny all; }</code>',
									'<code>EXPORTS_REPORTS_EXPORT_DIR</code>'
								),
								array( 'code' => array() )
							);
							?>
						</span>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="cronjob_token"><?php esc_html_e( 'Cronjob/JSON API Token', 'exports-and-reports' ); ?></label></th>
					<td>
						<input name="cronjob_token" type="text" id="cronjob_token" size="50" value="<?php echo esc_attr( get_option( 'exports_reports_token' ) ); ?>" /><br />
						<span class="description">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: 1: the Authorization header, 2: the token query parameter, both as code. */
									__( 'Keep this token secret: it lets a script or a cronjob run and download exports and read report data through the REST API, without a logged-in user. Send it as the header %1$s, or as a %2$s query parameter. You can change it anytime as needed; save it empty to turn token access off.', 'exports-and-reports' ),
									'<code>Authorization: Bearer TOKEN</code>',
									'<code>token</code>'
								),
								array( 'code' => array() )
							);
							?>
							<br /><br />

							<label for="export_api_url1" class="exports-reports-url-label"><strong><?php esc_html_e( 'URL to Export (useful for Cronjobs):', 'exports-and-reports' ); ?></strong></label><br />
							<input type="text" id="export_api_url1" class="exports-reports-url" readonly value="<?php echo esc_url( $rest_base . '/export?type=YOUR_EXPORT_TYPE&token=' . rawurlencode( $token ) ); ?>" /><br />

							<label for="export_api_url4" class="exports-reports-url-label"><strong><?php esc_html_e( 'URL to Export and then Download File:', 'exports-and-reports' ); ?></strong></label><br />
							<input type="text" id="export_api_url4" class="exports-reports-url" readonly value="<?php echo esc_url( $rest_base . '/export?type=YOUR_EXPORT_TYPE&download=1&token=' . rawurlencode( $token ) ); ?>" /><br />

							<label for="export_api_url2" class="exports-reports-url-label"><strong><?php esc_html_e( 'URL to JSON API (paginated):', 'exports-and-reports' ); ?></strong></label><br />
							<input type="text" id="export_api_url2" class="exports-reports-url" readonly value="<?php echo esc_url( $rest_base . '/data?pg=PAGE_NUMBER&token=' . rawurlencode( $token ) ); ?>" /><br />

							<label for="export_api_url3" class="exports-reports-url-label"><strong><?php esc_html_e( 'URL to JSON API (full data):', 'exports-and-reports' ); ?></strong></label><br />
							<input type="text" id="export_api_url3" class="exports-reports-url" readonly value="<?php echo esc_url( $rest_base . '/data?full=1&token=' . rawurlencode( $token ) ); ?>" />
						</span>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="reset"><?php esc_html_e( 'Reset All Settings', 'exports-and-reports' ); ?></label></th>
					<td>
						<input name="reset" type="submit" id="reset" class="button" value="<?php esc_attr_e( 'Reset Now', 'exports-and-reports' ); ?>" />
						<span class="description">
							<?php
							/* translators: %s: the export directory, relative to the WordPress directory. */
							echo esc_html( sprintf( __( 'This generates a new token, clears the export log and removes the export files from your Exports directory - %s. Groups and reports are kept.', 'exports-and-reports' ), str_replace( ABSPATH, '', EXPORTS_REPORTS_EXPORT_DIR ) ) );
							?>
						</span>
					</td>
				</tr>
				<!--
				<tr valign="top">
					<th scope="row"><label for=""></label></th>
					<td>
						<input name="" type="text" id="" value="0" class="small-text" />
						<span class="description"></span>
					</td>
				</tr>-->
			</table>
			<p class="submit">
				<input type="submit" name="Submit" class="button-primary" value="Save Settings" />
			</p>
		</form>
	</div>
	<?php
}
