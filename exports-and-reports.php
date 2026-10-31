<?php
/**
 * Exports and Reports
 *
 * @package   ExportsReports
 * @author    Scott Kingsley Clark
 * @copyright 2024 Scott Kingsley Clark
 * @license   GPL v2 or later
 *
 * Plugin Name:       Exports and Reports
 * Plugin URI:        https://github.com/sc0ttkclark/exports-and-reports
 * Description:       Define custom exports / reports for users by creating each export / report and defining the fields as well as custom MySQL queries to run.
 * Version:           0.9.4
 * Author:            Scott Kingsley Clark
 * Author URI:        https://www.scottkclark.com/
 * Text Domain:       exports-and-reports
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      7.2
 * GitHub Plugin URI: https://github.com/sc0ttkclark/exports-and-reports
 * Primary Branch:    main
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXPORTS_REPORTS_TBL', 'exportsreports_' );
define( 'EXPORTS_REPORTS_VERSION', '094' );
define( 'EXPORTS_REPORTS_URL', plugin_dir_url( __FILE__ ) );
define( 'EXPORTS_REPORTS_DIR', plugin_dir_path( __FILE__ ) );

if ( ! defined( 'WP_ADMIN_UI_EXPORT_URL' ) ) {
	define( 'WP_ADMIN_UI_EXPORT_URL', content_url( 'exports' ) );
}

if ( ! defined( 'WP_ADMIN_UI_EXPORT_DIR' ) ) {
	define( 'WP_ADMIN_UI_EXPORT_DIR', WP_CONTENT_DIR . '/exports' );
}

register_activation_hook( __FILE__, 'exports_reports_install_upgrade' );
register_deactivation_hook( __FILE__, 'exports_reports_deactivate' );

add_action( 'admin_init', 'exports_reports_init' );
add_action( 'admin_init', 'exports_reports_ensure_cleanup_scheduled' );
add_action( 'admin_menu', 'exports_reports_menu' );
add_action( 'admin_menu', 'exports_reports_admin_menu' );

add_action( 'wp_ajax_wp_admin_ui_export', 'exports_reports_wp_admin_ui_export' );
add_action( 'exports_reports_cleanup', 'exports_reports_cleanup_event' );
add_filter( 'user_has_cap', 'exports_reports_user_has_cap', 10, 4 );

require_once EXPORTS_REPORTS_DIR . 'vendor/autoload.php';

require_once EXPORTS_REPORTS_DIR . 'vendor/vendor-prefixed/autoload.php';
require_once EXPORTS_REPORTS_DIR . 'includes/rest-api.php';

/**
 *
 */
function exports_reports_wp_admin_ui_export() {
	require_once EXPORTS_REPORTS_DIR . 'wp-admin-ui/export.php';

	die( 'Invalid request' ); // AJAX dies
}

function exports_reports_assets() {
	wp_register_script( 'exports-reports-admin', EXPORTS_REPORTS_URL . 'assets/admin.js', array( 'jquery' ), EXPORTS_REPORTS_VERSION, array( 'in_footer' => true ) );
	wp_register_style( 'exports-reports-admin', EXPORTS_REPORTS_URL . 'assets/admin.css', array(), EXPORTS_REPORTS_VERSION );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only the admin page slug is read.
	if ( isset( $_GET['page'] ) && 'exports-reports-admin-reports' === $_GET['page'] ) {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_script( 'exports-reports-report-fields', EXPORTS_REPORTS_URL . 'assets/report-fields.js', array( 'jquery', 'jquery-ui-sortable' ), EXPORTS_REPORTS_VERSION, array( 'in_footer' => true ) );
		exports_reports_sql_editor();
	}
}
add_action( 'admin_enqueue_scripts', 'exports_reports_assets' );

/**
 * CodeMirror with SQL highlighting on the report form's query fields.
 *
 * Nothing is enqueued when the user has turned syntax highlighting off in their profile.
 */
function exports_reports_sql_editor() {
	$settings = wp_enqueue_code_editor( array( 'type' => 'text/x-sql' ) );

	if ( false === $settings ) {
		return;
	}

	wp_add_inline_script(
		'code-editor',
		sprintf(
			'jQuery( function() { [ "admin_ui_sql_query", "admin_ui_sql_query_count" ].forEach( function( id ) { var field = document.getElementById( id ); if ( field ) { wp.codeEditor.initialize( field, %s ); } } ); } );',
			wp_json_encode( $settings )
		)
	);
}

/**
 *
 */
function exports_reports_sql_install( $upgrade = false ) {
	/** @var wpdb $wpdb */ global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/file.php';

	/**
	 * @var $wp_filesystem WP_Filesystem_Base
	 */ global $wp_filesystem;

	WP_Filesystem();

	$sql = $wp_filesystem->get_contents( EXPORTS_REPORTS_DIR . 'assets/dump.sql' );

	// The dump carries the three-byte utf8 the tables were first written with; the site's own
	// character set and collation take its place (utf8mb4 on any current install).
	$charset_collate = $wpdb->get_charset_collate();

	if ( '' !== $charset_collate ) {
		$sql = str_replace( 'DEFAULT CHARSET=utf8', $charset_collate, $sql );
	}

	$sql = explode( ";\n", str_replace( array( "\r", 'wp_' ), array( "\n", $wpdb->prefix ), $sql ) );

	foreach ( $sql as $query ) {
		$query = trim( $query );

		if ( empty( $query ) ) {
			continue;
		}

		if ( 0 !== stripos( $query, 'CREATE TABLE' ) ) {
			if ( $upgrade ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $query );

			continue;
		}

		require_once ABSPATH . '/wp-admin/includes/upgrade.php';

		dbDelta( $query );
	}

	if ( $upgrade ) {
		exports_reports_convert_tables_charset();
	}

	delete_option( 'exports_reports_version' );
	add_option( 'exports_reports_version', EXPORTS_REPORTS_VERSION );

	if ( ! $upgrade ) {
		$token = md5( microtime() . wp_generate_password( 20, true ) );

		update_option( 'exports_reports_token', $token );
	}

	exports_reports_schedule_cleanup();
}

/**
 * Converts the plugin's tables to the site's character set and collation when they differ.
 *
 * Installs before 0.9.5 created the tables in three-byte utf8 whatever the site used (the
 * replacement in the installer never matched the dump), so a report name or filter value outside
 * the Basic Multilingual Plane could not be stored. dbDelta() does not change the character set of
 * an existing table, so the upgrade converts them.
 *
 * @since 0.9.5
 *
 * @return string[] The tables converted.
 */
function exports_reports_convert_tables_charset() {
	/** @var wpdb $wpdb */ global $wpdb;

	// Only towards utf8mb4, the superset every earlier WordPress character set converts into without loss;
	// a site on another character set keeps its tables as they are.
	if ( 'utf8mb4' !== $wpdb->charset || empty( $wpdb->collate ) ) {
		return array();
	}

	$converted = array();
	$failed    = array();

	foreach ( array( 'groups', 'reports', 'log' ) as $suffix ) {
		$table = $wpdb->prefix . EXPORTS_REPORTS_TBL . $suffix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema lookup at upgrade time.
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );

		if ( empty( $current ) || $current === $wpdb->collate ) {
			continue;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; character set and collation are wpdb's, not request data.
		$wpdb->query( "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET {$wpdb->charset} COLLATE {$wpdb->collate}" );

		if ( '' === $wpdb->last_error ) {
			$converted[] = $table;
		} else {
			$failed[ $table ] = $wpdb->last_error;
		}
	}

	// A table that did not convert is reported on the plugin's pages until it does (the next upgrade retries).
	if ( array() === $failed ) {
		delete_option( 'exports_reports_charset_errors' );
	} else {
		update_option( 'exports_reports_charset_errors', $failed, false );
	}

	return $converted;
}

/**
 * Shows the character set conversion errors, if any, on the plugin's pages.
 */
function exports_reports_charset_errors_notice() {
	$failed = get_option( 'exports_reports_charset_errors' );

	if ( ! is_array( $failed ) || array() === $failed || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>Exports and Reports:</strong> a table could not be converted to the site\'s character set. Convert it by hand or ask your host, then deactivate and reactivate the plugin to retry.</p><ul>';

	foreach ( $failed as $table => $error ) {
		echo '<li><code>' . esc_html( $table ) . '</code>: ' . esc_html( $error ) . '</li>';
	}

	echo '</ul></div>';
}

add_action( 'admin_notices', 'exports_reports_charset_errors_notice' );

/**
 * Grants exports_reports_full_access to administrators while the plugin is active, without writing it
 * to their user meta. The exports_reports_full_access filter decides who gets it, as before.
 * Sites with the Members plugin manage the capabilities through its roles instead.
 *
 * @param array   $allcaps The user's capabilities.
 * @param array   $caps    The capabilities asked for.
 * @param array   $args    The arguments of the check.
 * @param WP_User $user    The user.
 *
 * @return array
 */
function exports_reports_user_has_cap( $allcaps, $caps, $args, $user ) {
	static $inside = false;

	if ( $inside || function_exists( 'members_get_capabilities' ) ) {
		return $allcaps;
	}

	$inside = true;

	$full_access = in_array( 'administrator', (array) $user->roles, true ) ? 'exports_reports_full_access' : '';
	$full_access = apply_filters( 'exports_reports_full_access', $full_access );

	$inside = false;

	if ( ! empty( $full_access ) && is_string( $full_access ) ) {
		$allcaps[ $full_access ] = true;
	}

	return $allcaps;
}

/**
 *
 */
function exports_reports_install_upgrade() {
	/** @var \wpdb $wpdb */ global $wpdb;

	// check version
	$version = (int) get_option( 'exports_reports_version' );

	if ( empty( $version ) ) {
		exports_reports_sql_install();
	} else {
		exports_reports_upgrade();
	}
}

/**
 *
 */
function exports_reports_upgrade() {
	/** @var \wpdb $wpdb */ global $wpdb;

	// check version
	$version = (int) get_option( 'exports_reports_version' );

	if ( ! empty( $version ) && (int) EXPORTS_REPORTS_VERSION !== $version ) {
		$version = absint( $version );

		if ( $version < 60 ) {
			$token = md5( microtime() . wp_generate_password( 20, true ) );
			update_option( 'exports_reports_token', $token );
		}

		exports_reports_sql_install( true );
	}
}

/**
 *
 */
function exports_reports_init() {
	exports_reports_upgrade();

	$capabilities = exports_reports_capabilities();

	add_filter( 'members_get_capabilities', 'exports_reports_get_capabilities' );

	// thx gravity forms, great way of integration with members!
	if ( ! is_user_logged_in() ) {
		return;
	}

	if ( function_exists( 'members_get_capabilities' ) ) {
		if ( exports_reports_current_user_can_any( 'exports_reports_full_access' ) ) {
			$user = get_userdata( get_current_user_id() );

			if ( $user instanceof WP_User ) {
				$user->remove_cap( 'exports_reports_full_access' );
			}
		}

		$is_admin_with_no_permissions = exports_reports_has_role( 'administrator' ) && ! exports_reports_current_user_can_any( exports_reports_capabilities() );

		if ( $is_admin_with_no_permissions ) {
			$role = get_role( 'administrator' );

			if ( $role ) {
				foreach ( $capabilities as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
	} else {
		// exports_reports_user_has_cap() grants full access while the plugin is active; a copy an earlier
		// version wrote to the user's meta would outlive the plugin, so it is removed.
		$user = get_userdata( get_current_user_id() );

		if ( $user && isset( $user->caps['exports_reports_full_access'] ) ) {
			$user->remove_cap( 'exports_reports_full_access' );
		}
	}
}

/**
 *
 */
function exports_reports_admin_menu() {
	if ( defined( 'EXPORTS_REPORTS_DISABLE_MENU' ) ) {
		return;
	}

	$has_full_access = exports_reports_current_user_can_any( 'exports_reports_full_access' );

	if ( is_super_admin() || ( ! $has_full_access && exports_reports_has_role( 'administrator' ) ) ) {
		$has_full_access = true;
	}

	$min_cap = exports_reports_current_user_can_which( exports_reports_capabilities() );

	if ( empty( $min_cap ) ) {
		$min_cap = 'exports_reports_full_access';
	}

	if ( $has_full_access || exports_reports_current_user_can_any( $min_cap ) || exports_reports_current_user_can_any( 'exports_reports_settings' ) ) {
		add_menu_page( 'Reports Admin', 'Reports Admin', $has_full_access ? 'read' : $min_cap, 'exports-reports-admin', null, EXPORTS_REPORTS_URL . 'assets/icons/16.png' );
		add_submenu_page( 'exports-reports-admin', 'Manage Groups', 'Manage Groups', $has_full_access ? 'read' : 'exports_reports_settings', 'exports-reports-admin', 'exports_reports_groups' );
		add_submenu_page( 'exports-reports-admin', 'Manage Reports', 'Manage Reports', $has_full_access ? 'read' : 'exports_reports_settings', 'exports-reports-admin-reports', 'exports_reports_reports' );
		add_submenu_page( 'exports-reports-admin', 'Settings', 'Settings', $has_full_access ? 'read' : 'exports_reports_settings', 'exports-reports-admin-settings', 'exports_reports_settings' );
	}
}

/**
 *
 */
function exports_reports_menu() {
	/** @var wpdb $wpdb */ global $wpdb;

	if ( defined( 'EXPORTS_REPORTS_DISABLE_MENU' ) ) {
		return;
	}

	$has_full_access = exports_reports_current_user_can_any( 'exports_reports_full_access' );

	if ( is_super_admin() || ( ! $has_full_access && exports_reports_has_role( 'administrator' ) ) ) {
		$has_full_access = true;
	}

	$init = false;

	$sql = '
		SELECT `id`, `role_access`, `name`
		FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'groups`
		WHERE `disabled` = 0
		ORDER BY `weight`, `name`	
	';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$groups = $wpdb->get_results( $sql );

	if ( ! empty( $groups ) ) {
		foreach ( $groups as $group ) {
			$sql = '
				SELECT `id`, `role_access`
				FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'reports`
				WHERE `disabled` = 0 AND `group` = %d
				ORDER BY `weight`, `name`
			';

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, array( $group->id ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$reports = $wpdb->get_results( $sql );

			if ( 0 < @count( $reports ) ) {
				foreach ( $reports as $report ) {
					if ( $has_full_access || exports_reports_current_user_can_any( 'exports_reports_view' ) || exports_reports_current_user_can_any( 'exports_reports_view_group_' . $group->id ) || exports_reports_current_user_can_any( 'exports_reports_view_report_' . $report->id ) ) {
						$menu_page = 'exports-reports-group-' . $group->id;

						if ( ! $init ) {
							add_menu_page( 'Reports', 'Reports', 'read', $menu_page, null, EXPORTS_REPORTS_URL . 'assets/icons/16.png' );

							$init = $menu_page;
						}

						add_submenu_page( $init, $group->name, $group->name, 'read', $menu_page, 'exports_reports_view' );

						break;
					}

					$roles = array_unique( array_merge( explode( ',', $group->role_access ), explode( ',', $report->role_access ) ) );

					if ( empty( $roles ) ) {
						continue;
					}

					foreach ( $roles as $role ) {
						if ( exports_reports_has_role( $role ) ) {
							$menu_page = 'exports-reports-group-' . $group->id;

							if ( ! $init ) {
								add_menu_page( 'Reports', 'Reports', 'read', $menu_page, null, EXPORTS_REPORTS_URL . 'assets/icons/16.png' );

								$init = $menu_page;
							}

							add_submenu_page( $init, $group->name, $group->name, 'read', $menu_page, 'exports_reports_view' );

							break 2;
						}
					}
				}
			}
		}
	}
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

	$rest_base  = rest_url( 'exports-reports/v1/reports/YOUR_REPORT_ID' );
	$token      = (string) get_option( 'exports_reports_token' );
	$legacy_url = EXPORTS_REPORTS_URL . 'api.php?report=YOUR_REPORT_ID&export_type=YOUR_EXPORT_TYPE&token=' . rawurlencode( $token );

	wp_enqueue_style( 'exports-reports-admin' );
	?>
	<div class="wrap">
		<div id="icon-edit-pages" class="icon32" style="background-position:0 0;background-image:url(<?php echo esc_url( EXPORTS_REPORTS_URL . 'assets/icons/32.png' ); ?>)">
			<br /></div>
		<h2>Exports and Reports - Settings</h2>

		<?php if ( $cleared ) : ?>
			<div id="message" class="updated fade">
				<p>Your Exports directory has been cleaned up and all export files have been removed.</p>
			</div>
		<?php endif; ?>

		<?php if ( $reset ) : ?>
			<div id="message" class="updated fade">
				<p>Your Settings have been reset.</p>
			</div>
		<?php endif; ?>

		<div style="height:20px;"></div>
		<form method="post" action="">
			<?php wp_nonce_field( 'exports-reports-settings' ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="clear">Clear Exports Directory</label></th>
					<td>
						<input name="clear" type="submit" id="clear" value=" Clear Now " />
						<span class="description">This will remove all files from your Exports directory - <?php echo esc_html( str_replace( ABSPATH, '', WP_ADMIN_UI_EXPORT_DIR ) ); ?></span>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">Export Directory</th>
					<td>
						<code><?php echo esc_html( WP_ADMIN_UI_EXPORT_DIR ); ?></code><br />
						<span class="description">
							Downloads are served by the plugin after an access check; the directory itself should not be reachable by URL.
							<?php if ( file_exists( WP_ADMIN_UI_EXPORT_DIR . '/.htaccess' ) ) : ?>
								Apache: the plugin's .htaccess denies direct requests.
							<?php else : ?>
								Apache: the plugin writes an .htaccess that denies direct requests with the first export.
							<?php endif; ?>
							nginx ignores .htaccess; add <code>location ~* /wp-content/exports/ { deny all; }</code> to the server block, or define <code>WP_ADMIN_UI_EXPORT_DIR</code> in wp-config.php with a directory outside the web root, which works on any server.
						</span>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="cronjob_token">Cronjob/JSON API Token</label></th>
					<td>
						<input name="cronjob_token" type="text" id="cronjob_token" size="50" value="<?php echo esc_attr( get_option( 'exports_reports_token' ) ); ?>" /><br />
						<span class="description">
							Keep this token secret: it lets a script or a cronjob run and download exports and read report data through the REST API, without a logged-in user. Send it as the header <code>Authorization: Bearer TOKEN</code>, or as a <code>token</code> query parameter. You can change it anytime as needed; save it empty to turn token access off.<br /><br />

							<label for="export_api_url1" style="font-style:normal;"><strong>URL to Export (useful for Cronjobs):</strong></label><br />
							<input type="text" id="export_api_url1" style="width:100%;" readonly value="<?php echo esc_url( $rest_base . '/export?type=YOUR_EXPORT_TYPE&token=' . rawurlencode( $token ) ); ?>" /><br />

							<label for="export_api_url4" style="font-style:normal;"><strong>URL to Export and then Download File:</strong></label><br />
							<input type="text" id="export_api_url4" style="width:100%;" readonly value="<?php echo esc_url( $rest_base . '/export?type=YOUR_EXPORT_TYPE&download=1&token=' . rawurlencode( $token ) ); ?>" /><br />

							<label for="export_api_url2" style="font-style:normal;"><strong>URL to JSON API (paginated):</strong></label><br />
							<input type="text" id="export_api_url2" style="width:100%;" readonly value="<?php echo esc_url( $rest_base . '/data?pg=PAGE_NUMBER&token=' . rawurlencode( $token ) ); ?>" /><br />

							<label for="export_api_url3" style="font-style:normal;"><strong>URL to JSON API (full data):</strong></label><br />
							<input type="text" id="export_api_url3" style="width:100%;" readonly value="<?php echo esc_url( $rest_base . '/data?full=1&token=' . rawurlencode( $token ) ); ?>" /><br /><br />

							The pre-0.9.5 endpoint still answers for cronjobs set up before: <code><?php echo esc_html( $legacy_url ); ?></code>. It will be removed in 1.0; move those jobs to the URLs above.
						</span>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="reset">Reset All Settings</label></th>
					<td>
						<input name="reset" type="submit" id="reset" value=" Reset Now " />
						<span class="description">This generates a new token, clears the export log and removes the export files from your Exports directory - <?php echo esc_html( str_replace( ABSPATH, '', WP_ADMIN_UI_EXPORT_DIR ) ); ?>. Groups and reports are kept.</span>
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

/**
 *
 */
function exports_reports_groups() {
	global $wpdb;

	require_once EXPORTS_REPORTS_DIR . 'wp-admin-ui/class-exports-reports-admin-ui.php';

	$columns = array(
		'name',
		'disabled' => array(
			'label' => __( 'Disabled', 'exports-and-reports' ),
			'type'  => 'bool',
		),
		'created'  => array(
			'label' => __( 'Date Created', 'exports-and-reports' ),
			'type'  => 'datetime',
		),
		'updated'  => array(
			'label' => __( 'Last Modified', 'exports-and-reports' ),
			'type'  => 'datetime',
		),
		'id'       => array(
			'label' => __( 'Group ID', 'exports-and-reports' ),
			'type'  => 'number',
		),
	);

	$form_columns = $columns;

	unset( $form_columns['id'] );

	$roles = exports_reports_get_roles();

	$form_columns['role_access'] = array(
		'label'            => 'WP Roles with Access',
		'comments'         => 'Add the exports_reports_full_access capability to a role for full access to reports, exports_reports_settings for only access to settings, exports_reports_view for access to view all reports, exports_reports_view_group_{ID} for access to view a group and all of the reports within, or exports_reports_view_report_{ID} for access to view a single report',
		'type'             => 'related',
		'related'          => $roles,
		'related_multiple' => true,
	);

	$form_columns['created']['date_touch_on_create'] = true;
	$form_columns['created']['display']              = false;
	$form_columns['updated']['date_touch']           = true;
	$form_columns['updated']['display']              = false;

	$admin_ui = array(
		'reorder'      => 'weight',
		'order'        => '`weight`',
		'order_dir'    => 'ASC',
		'css'          => EXPORTS_REPORTS_URL . 'assets/admin.css',
		'item'         => 'Report Group',
		'items'        => 'Report Groups',
		'table'        => $wpdb->prefix . EXPORTS_REPORTS_TBL . 'groups',
		'columns'      => $columns,
		'form_columns' => $form_columns,
		'icon'         => EXPORTS_REPORTS_URL . 'assets/icons/32.png',
		'duplicate'    => true,
	);

	$admin = new Exports_Reports_Admin_UI( $admin_ui );

	$admin->go();
}

/**
 *
 */
function exports_reports_reports() {
	global $wpdb;

	require_once EXPORTS_REPORTS_DIR . 'wp-admin-ui/class-exports-reports-admin-ui.php';

	$columns = array(
		'name',
		'group'    => array(
			'label'   => 'Group',
			'type'    => 'related',
			'related' => $wpdb->prefix . EXPORTS_REPORTS_TBL . 'groups',
		),
		'disabled' => array(
			'label' => 'Disabled',
			'type'  => 'bool',
		),
		'created'  => array(
			'label' => 'Date Created',
			'type'  => 'datetime',
		),
		'updated'  => array(
			'label' => 'Last Modified',
			'type'  => 'datetime',
		),
		'id'       => array(
			'label' => 'Report ID',
			'type'  => 'number',
		),
	);

	$columns['created']['filter']       = true;
	$columns['created']['filter_label'] = 'Lifespan (created / modified)';
	$columns['created']['date_ongoing'] = 'updated';

	$form_columns = $columns;

	unset( $form_columns['id'] );

	$form_columns['disabled']['label'] = 'Disabled?';

	$form_columns['disable_export'] = array(
		'label' => 'Disable Export?',
		'type'  => 'bool',
	);

	$form_columns['default_none'] = array(
		'label'    => 'Default to No Results?',
		'type'     => 'bool',
		'comments' => 'On = Show no results and require search; Off (default) = Show all results',
	);

	$form_columns['page_orientation'] = array(
		'label'   => 'PDF Report Page Orientation',
		'type'    => 'related',
		'related' => array(
			'L' => 'Landscape',
			'P' => 'Portrait',
		),
	);

	$form_columns['created']['date_touch_on_create'] = true;
	$form_columns['created']['display']              = false;
	$form_columns['updated']['date_touch']           = true;
	$form_columns['updated']['display']              = false;

	$roles = exports_reports_get_roles();

	$form_columns['role_access'] = array(
		'label'            => 'WP Roles with Access',
		'type'             => 'related',
		'related'          => $roles,
		'related_multiple' => true,
	);

	$form_columns['sql_query'] = array(
		'label'    => 'SQL Query',
		'type'     => 'desc',
		'comments' => 'Available Variables: %%WHERE%% %%HAVING%% %%ORDERBY%% %%LIMIT%%<br />(example: WHERE %%WHERE%% my_field=1)',
	);

	$form_columns['sql_query_count'] = array(
		'label'    => 'SQL Query for Count (advanced, optional)',
		'type'     => 'desc',
		'comments' => 'For advanced/complex queries above, you can SELECT minimal fields here for better "Total Count" performance.<br />Available Variables: %%WHERE%% %%HAVING%%<br />(example: WHERE %%WHERE%% my_field=1)',
	);

	$form_columns['field_data'] = array(
		'label'        => 'Fields (optional)',
		'custom_input' => 'exports_reports_report_field',
		'custom_save'  => 'exports_reports_report_field_save',
	);

	$admin_ui = array(
		'reorder'       => 'weight',
		'reorder_order' => '`group` ASC,`weight`',
		'order'         => '`group` ASC,`weight`',
		'order_dir'     => 'ASC',
		'css'           => EXPORTS_REPORTS_URL . 'assets/admin.css',
		'item'          => 'Report',
		'items'         => 'Reports',
		'table'         => $wpdb->prefix . EXPORTS_REPORTS_TBL . 'reports',
		'columns'       => $columns,
		'form_columns'  => $form_columns,
		'icon'          => EXPORTS_REPORTS_URL . 'assets/icons/32.png',
		'duplicate'     => true,
	);

	$admin = new Exports_Reports_Admin_UI( $admin_ui );

	$admin->go();
}

/**
 * The field editor of the report form: one card per field, the advanced options folded away, new
 * rows from a template, the order by drag and drop (assets/report-fields.js).
 *
 * @param string                   $column     The column name (field_data).
 * @param array                    $attributes The column attributes.
 * @param Exports_Reports_Admin_UI $obj        The admin UI.
 */
function exports_reports_report_field( $column, $attributes, $obj ) {
	$field_data = json_decode( isset( $obj->row[ $column ] ) ? (string) $obj->row[ $column ] : '', true );
	$fields     = is_array( $field_data ) ? array_values( $field_data ) : array();
	$types      = exports_reports_field_types();
	?>
	<div class="er-fields" data-next-index="<?php echo esc_attr( (string) count( $fields ) ); ?>">
		<p class="description">Leave this empty to show every column the query returns. Drag a field by its handle to change the order.</p>
		<div class="er-fields__list">
			<?php
			foreach ( $fields as $index => $field ) {
				exports_reports_report_field_row( (string) $index, exports_reports_field_defaults( $field ), $types );
			}
			?>
		</div>
		<p><button type="button" class="button er-fields__add">Add Field</button></p>
		<template class="er-fields__template"><?php exports_reports_report_field_row( '__i__', exports_reports_field_defaults(), $types ); ?></template>
	</div>
	<input type="hidden" name="<?php echo esc_attr( $column ); ?>" value="" />
	<?php
}

/**
 * The data types a field can have.
 *
 * @return array<string, string>
 */
function exports_reports_field_types() {
	return array(
		'text'     => __( 'Text', 'exports-and-reports' ),
		'bool'     => __( 'Boolean (Checkbox)', 'exports-and-reports' ),
		'date'     => __( 'Date', 'exports-and-reports' ),
		'time'     => __( 'Time', 'exports-and-reports' ),
		'datetime' => __( 'Date + Time', 'exports-and-reports' ),
		'number'   => __( 'Number (no decimal)', 'exports-and-reports' ),
		'decimal'  => __( 'Decimal (two places)', 'exports-and-reports' ),
		'related'  => __( 'Related', 'exports-and-reports' ),
	);
}

/**
 * One field of the editor. The input names are the ones exports_reports_report_field_save() reads;
 * a flag posts its "off" value from a hidden input and its "on" value from the checkbox after it.
 *
 * @param string $index The row index, or the placeholder the template carries.
 * @param array  $field The field with every key (exports_reports_field_defaults()).
 * @param array  $types The data types.
 */
function exports_reports_report_field_row( $index, $field, $types ) {
	$text = static function ( $key, $label, $hint = '' ) use ( $index, $field ) {
		?>
		<label>
			<span><?php echo esc_html( $label ); ?></span>
			<input type="text" name="<?php echo esc_attr( $key . '[' . $index . ']' ); ?>" value="<?php echo esc_attr( (string) $field[ str_replace( array( 'field_', 'width' ), array( '', 'width' ), $key ) ] ); ?>" class="regular-text"<?php echo '' !== $hint ? ' placeholder="' . esc_attr( $hint ) . '"' : ''; ?> />
		</label>
		<?php
	};
	$flag = static function ( $key, $label, $checked, $on = '1', $off = '0' ) use ( $index ) {
		$name = $key . '[' . $index . ']';
		?>
		<label class="er-field__flag">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $off ); ?>" />
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $on ); ?>"<?php checked( $checked ); ?> />
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	};
	?>
	<div class="er-field">
		<div class="er-field__bar">
			<span class="er-field__handle dashicons dashicons-move" title="Drag to reorder"></span>
			<?php $text( 'field_name', 'Field name (column or alias)' ); ?>
			<label>
				<span>Data type</span>
				<select name="<?php echo esc_attr( 'field_type[' . $index . ']' ); ?>">
					<?php foreach ( $types as $type => $type_label ) { ?>
						<option value="<?php echo esc_attr( $type ); ?>"<?php selected( $field['type'], $type ); ?>><?php echo esc_html( $type_label ); ?></option>
					<?php } ?>
				</select>
			</label>
			<?php $text( 'field_label', 'Label', 'defaults to the field name' ); ?>
			<button type="button" class="button-link button-link-delete er-field__remove">Remove</button>
		</div>
		<div class="er-field__flags">
			<?php
			$flag( 'field_hide_report', 'Hide from report', 1 === (int) $field['hide_report'] );
			$flag( 'field_hide_export', 'Hide from export', 1 === (int) $field['hide_export'] );
			$flag( 'field_search', 'Searchable', 1 !== (int) $field['search'], '0', '1' );
			$flag( 'field_filter', 'Filterable', 1 === (int) $field['filter'] );
			$flag( 'field_group_related', 'Filter with HAVING', 1 === (int) $field['group_related'] );
			?>
		</div>
		<details class="er-field__advanced">
			<summary>Advanced options</summary>
			<div class="er-field__grid">
				<?php
				$text( 'field_real_name', 'Real field', 'when the name is an alias' );
				$text( 'field_custom_display', 'Display function', 'a function a plugin or theme defines' );
				$text( 'width', 'Width', 'relative, for the PDF export' );
				$text( 'field_filter_label', 'Filter label' );
				$text( 'field_filter_default', 'Default filter value' );
				$text( 'field_filter_ongoing', 'Ongoing date field', 'for a date range filter' );
				$text( 'field_filter_ongoing_default', 'Ongoing default filter value' );
				$text( 'field_related', 'Related table', 'related type only' );
				$text( 'field_related_field', 'Related field' );
				$text( 'field_related_id', 'Related ID field' );
				$text( 'field_related_sql', 'Related WHERE / ORDER BY' );
				?>
			</div>
		</details>
	</div>
	<?php
}

/**
 * @param $value
 * @param $column
 * @param $attributes
 * @param $obj
 *
 * @return string
 */
function exports_reports_report_field_save( $value, $column, $attributes, $obj ) {
	// Exports_Reports_Admin_UI::save() verifies the form nonce before it calls this custom_save callback, and has unslashed the request.
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$value = array();

	if ( empty( $_POST['field_name'] ) || ! is_array( $_POST['field_name'] ) ) {
		return wp_json_encode( $value );
	}

	$names = array_map( 'sanitize_text_field', wp_unslash( $_POST['field_name'] ) );

	$types = array_keys( exports_reports_field_types() );
	$texts = array(
		'real_name'              => 'field_real_name',
		'label'                  => 'field_label',
		'filter_label'           => 'field_filter_label',
		'group_related'          => 'field_group_related',
		'custom_display'         => 'field_custom_display',
		'width'                  => 'width',
		'filter_default'         => 'field_filter_default',
		'filter_ongoing'         => 'field_filter_ongoing',
		'filter_ongoing_default' => 'field_filter_ongoing_default',
		'related'                => 'field_related',
		'related_field'          => 'field_related_field',
		'related_id'             => 'field_related_id',
	);
	$flags = array(
		'hide_report' => 'field_hide_report',
		'hide_export' => 'field_hide_export',
		'search'      => 'field_search',
		'filter'      => 'field_filter',
	);

	foreach ( $names as $key => $name ) {
		if ( '' === $name ) {
			continue;
		}

		$field = array( 'name' => $name );

		foreach ( $texts as $option => $input ) {
			$field[ $option ] = isset( $_POST[ $input ][ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $input ][ $key ] ) ) : '';
		}

		foreach ( $flags as $option => $input ) {
			$field[ $option ] = isset( $_POST[ $input ][ $key ] ) ? absint( wp_unslash( $_POST[ $input ][ $key ] ) ) : 0;
		}

		$type = isset( $_POST['field_type'][ $key ] ) ? sanitize_key( wp_unslash( $_POST['field_type'][ $key ] ) ) : 'text';

		$field['type']        = in_array( $type, $types, true ) ? $type : 'text';
		$field['total_field'] = '';
		// A query fragment written by the administrator; stored as typed, like the report's own queries.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$field['related_sql'] = isset( $_POST['field_related_sql'][ $key ] ) ? trim( (string) wp_unslash( $_POST['field_related_sql'][ $key ] ) ) : '';

		$value[] = $field;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	return wp_json_encode( $value );
}

/**
 * @param null $field
 *
 * @return array|null
 */
function exports_reports_field_defaults( $field = null ) {
	if ( ! is_array( $field ) ) {
		$field = array();
	}

	$defaults = array(
		'name'                   => '',
		'real_name'              => '',
		'label'                  => '',
		'filter_label'           => '',
		'hide_report'            => '',
		'hide_export'            => '',
		'group_related'          => '',
		'custom_display'         => '',
		'type'                   => '',
		'width'                  => '',
		'search'                 => '',
		'filter'                 => '',
		'filter_default'         => '',
		'filter_ongoing'         => '',
		'filter_ongoing_default' => '',
		'total_field'            => '',
		'related'                => '',
		'related_field'          => '',
		'related_sql'            => '',
		'related_id'             => '',
	);

	$field = array_merge( $defaults, $field );

	return $field;
}

/**
 * An enabled report by id.
 *
 * @since 0.9.5
 *
 * @param int $report_id The report id.
 * @return object|null The row of the reports table, or null when there is no enabled report with that id.
 */
function exports_reports_get_report( $report_id ) {
	/** @var wpdb $wpdb */ global $wpdb;

	$sql = 'SELECT * FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'reports` WHERE `id` = %d AND `disabled` = 0 LIMIT 1';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
	$report = $wpdb->get_row( $wpdb->prepare( $sql, absint( $report_id ) ) );

	return empty( $report ) ? null : $report;
}

/**
 * An enabled group by id.
 *
 * @since 0.9.5
 *
 * @param int $group_id The group id.
 * @return object|null The row of the groups table, or null when there is no enabled group with that id.
 */
function exports_reports_get_group( $group_id ) {
	/** @var wpdb $wpdb */ global $wpdb;

	$sql = 'SELECT * FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'groups` WHERE `id` = %d AND `disabled` = 0 LIMIT 1';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
	$group = $wpdb->get_row( $wpdb->prepare( $sql, absint( $group_id ) ) );

	return empty( $group ) ? null : $group;
}

/**
 * Whether the current user may view a report: the rule the Reports menu and the report page apply.
 *
 * @since 0.9.5
 *
 * @param object $report A row of the reports table.
 * @param object $group  The row of its group.
 * @return bool
 */
function exports_reports_user_can_view_report( $report, $group ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	$has_full_access = exports_reports_current_user_can_any( 'exports_reports_full_access' );

	if ( is_super_admin() || ( ! $has_full_access && exports_reports_has_role( 'administrator' ) ) ) {
		$has_full_access = true;
	}

	if ( $has_full_access || exports_reports_current_user_can_any( 'exports_reports_view' ) || exports_reports_current_user_can_any( 'exports_reports_view_group_' . $group->id ) || exports_reports_current_user_can_any( 'exports_reports_view_report_' . $report->id ) ) {
		return true;
	}

	$roles = array_unique( array_merge( explode( ',', (string) $group->role_access ), explode( ',', (string) $report->role_access ) ) );

	foreach ( $roles as $role ) {
		if ( '' !== $role && exports_reports_has_role( $role ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The admin UI options for a report row: what the report page, the REST API and api.php hand to
 * Exports_Reports_Admin_UI.
 *
 * @since 0.9.5
 *
 * @param object $report A row of the reports table.
 * @return array
 */
function exports_reports_report_options( $report ) {
	$options = array();

	$options['css']      = EXPORTS_REPORTS_URL . 'assets/admin.css';
	$options['readonly'] = true;

	// $options['identifier']   = true;

	$options['export']       = ( 0 === (int) $report->disable_export );
	$options['search']       = ( strlen( (string) $report->field_data ) > 0 ? true : false );
	$options['default_none'] = ( 1 === (int) $report->default_none ? true : false );
	$options['sql']          = trim( (string) $report->sql_query );
	$options['sql_count']    = trim( (string) $report->sql_query_count );

	if ( empty( $options['sql_count'] ) ) {
		unset( $options['sql_count'] );
	}

	$options['page_orientation'] = $report->page_orientation;
	$options['item']             = 'item';
	$options['items']            = $report->name;
	$options['icon']             = EXPORTS_REPORTS_URL . 'assets/icons/32.png';
	$options['heading']          = array(
		'manage' => 'View Report:',
	);

	$field_data = @json_decode( (string) $report->field_data, true );

	if ( is_array( $field_data ) && ! empty( $field_data ) ) {
		$options['columns'] = array();

		foreach ( $field_data as $field ) {
			$field = exports_reports_field_defaults( $field );

			$options['columns'][ $field['name'] ] = array();

			if ( 0 < strlen( $field['real_name'] ) ) {
				$options['columns'][ $field['name'] ]['real_name'] = $field['real_name'];
			}

			if ( 0 < strlen( $field['label'] ) ) {
				$options['columns'][ $field['name'] ]['label'] = $field['label'];
			}

			if ( 0 < strlen( $field['filter_label'] ) ) {
				$options['columns'][ $field['name'] ]['filter_label'] = $field['filter_label'];
			}

			if ( 0 < strlen( $field['custom_display'] ) ) {
				$options['columns'][ $field['name'] ]['custom_display'] = $field['custom_display'];
			}

			if ( 0 < strlen( $field['type'] ) ) {
				$options['columns'][ $field['name'] ]['type'] = $field['type'];
			}

			if ( 0 < strlen( $field['width'] ) ) {
				$options['columns'][ $field['name'] ]['width'] = $field['width'];
			}

			if ( 1 === (int) $field['hide_report'] ) {
				$options['columns'][ $field['name'] ]['display'] = false;
			}

			if ( 1 === (int) $field['hide_export'] ) {
				$options['columns'][ $field['name'] ]['export'] = false;
			}

			if ( 1 === (int) $field['search'] || 1 === (int) $field['hide_report'] ) {
				$options['columns'][ $field['name'] ]['search'] = false;
			} else {
				$options['columns'][ $field['name'] ]['search'] = true;
			}

			if ( 1 === (int) $field['filter'] ) {
				$options['columns'][ $field['name'] ]['filter'] = true;

				if ( 0 < strlen( $field['filter_default'] ) ) {
					$options['columns'][ $field['name'] ]['filter_default'] = $field['filter_default'];
				}

				if ( 0 < strlen( $field['filter_ongoing'] ) ) {
					$options['columns'][ $field['name'] ]['date_ongoing'] = $field['filter_ongoing'];

					if ( 0 < strlen( $field['filter_ongoing_default'] ) ) {
						$options['columns'][ $field['name'] ]['filter_ongoing_default'] = $field['filter_ongoing_default'];
					}
				}
			}

			if ( 1 === (int) $field['total_field'] ) {
				$options['columns'][ $field['name'] ]['total_field'] = true;
			}

			if ( 1 === (int) $field['group_related'] ) {
				$options['columns'][ $field['name'] ]['group_related'] = true;
			}

			if ( 'related' === $field['type'] ) {
				if ( 0 < strlen( $field['related'] ) ) {
					$options['columns'][ $field['name'] ]['related'] = $field['related'];
				}

				if ( 0 < strlen( $field['related_field'] ) ) {
					$options['columns'][ $field['name'] ]['related_field'] = $field['related_field'];
				}

				if ( 0 < strlen( $field['related_id'] ) ) {
					$options['columns'][ $field['name'] ]['related_id'] = $field['related_id'];
				}

				if ( 0 < strlen( $field['related_sql'] ) ) {
					$options['columns'][ $field['name'] ]['related_sql'] = $field['related_sql'];
				}
			}
		}
	}

	$options['report_id'] = (int) $report->id;

	$options = apply_filters( 'exports_reports_report_options', $options, (int) $report->id );

	return $options;
}

/**
 * @param bool      $has_full_access
 * @param bool|null $group_id
 *
 * @return bool
 */
function exports_reports_view( $group_id = false, $has_full_access = null ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the admin page slug and the chosen report; nothing is written from them.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	if ( '' === $page ) {
		return false;
	}

	/** @var wpdb $wpdb */ global $wpdb;

	wp_enqueue_script( 'exports-reports-admin' );

	if ( null === $has_full_access ) {
		$has_full_access = exports_reports_current_user_can_any( 'exports_reports_full_access' );

		if ( is_super_admin() || ( ! $has_full_access && exports_reports_has_role( 'administrator' ) ) ) {
			$has_full_access = true;
		}
	}

	if ( 'exports-reports' !== $page && false !== strpos( $page, 'exports-reports-group-' ) ) {
		if ( empty( $group_id ) ) {
			$group_id = (int) str_replace( 'exports-reports-group-', '', $page );
		}

		$sql = '
			SELECT `id`, `role_access`
			FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'groups`
			WHERE `disabled` = 0 AND `id` = %d
			ORDER BY `weight`, `name`
			LIMIT 1
		';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $sql, array( $group_id ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$group = $wpdb->get_row( $sql );

		if ( empty( $group ) ) {
			return false;
		}
		$group_roles = explode( ',', $group->role_access );
	} else {
		$group_id    = 0;
		$group_roles = array();

		$sql = '
			SELECT `id`, `role_access`
			FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'groups`
			WHERE `disabled` = 0
			ORDER BY `weight`, `name`
		';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$groups = $wpdb->get_results( $sql );

		if ( ! empty( $groups ) ) {
			foreach ( $groups as $group ) {
				$sql = '
					SELECT `id`, `role_access`
					FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'reports`
					WHERE `disabled` = 0 AND `group` = %d
					ORDER BY `weight`, `name`
				';

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$sql = $wpdb->prepare( $sql, array( $group->id ) );

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$reports = $wpdb->get_results( $sql );

				if ( 0 < @count( $reports ) ) {
					foreach ( $reports as $report ) {
						if ( $has_full_access || exports_reports_current_user_can_any( 'exports_reports_view' ) || exports_reports_current_user_can_any( 'exports_reports_view_group_' . $group->id ) || exports_reports_current_user_can_any( 'exports_reports_view_report_' . $report->id ) ) {
							$group_id    = $group->id;
							$group_roles = explode( ',', $group->role_access );

							break;
						}

						$roles = array_unique( array_merge( explode( ',', $group->role_access ), explode( ',', $report->role_access ) ) );

						if ( empty( $roles ) ) {
							continue;
						}

						foreach ( $roles as $role ) {
							if ( exports_reports_has_role( $role ) ) {
								$group_id    = $group->id;
								$group_roles = explode( ',', $group->role_access );

								break 2;
							}
						}
					}
				}
			}
		}
	}

	if ( empty( $group_id ) ) {
		return false;
	}

	$sql = '
		SELECT *
		FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'reports`
		WHERE `disabled` = 0 AND `group` = %d
		ORDER BY `weight`, `name`
	';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql = $wpdb->prepare( $sql, array( $group_id ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$reports = $wpdb->get_results( $sql );

	if ( empty( $reports ) ) {
		return false;
	}

	$selectable_reports = array();
	$current_report     = false;

	foreach ( $reports as $report ) {
		if ( $has_full_access || exports_reports_current_user_can_any( 'exports_reports_view' ) || exports_reports_current_user_can_any( 'exports_reports_view_group_' . $group_id ) || exports_reports_current_user_can_any( 'exports_reports_view_report_' . $report->id ) ) {
			if ( false === $current_report ) {
				$current_report = absint( $report->id );
			}

			$selectable_reports[ $report->id ] = $report;

			continue;
		}

		$roles = array_unique( array_merge( $group_roles, explode( ',', $report->role_access ) ) );

		if ( empty( $roles ) ) {
			continue;
		}

		foreach ( $roles as $role ) {
			if ( exports_reports_has_role( $role ) ) {
				if ( false === $current_report ) {
					$current_report = absint( $report->id );
				}

				$selectable_reports[ $report->id ] = $report;
			}
		}
	}

	if ( empty( $selectable_reports ) ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the report chosen from the dropdown.
	$requested_report = isset( $_GET['report'] ) ? absint( wp_unslash( $_GET['report'] ) ) : 0;

	if ( 0 < $requested_report && isset( $selectable_reports[ $requested_report ] ) ) {
		$current_report = $requested_report;
	}

	require_once EXPORTS_REPORTS_DIR . 'wp-admin-ui/class-exports-reports-admin-ui.php';

	$options = exports_reports_report_options( $selectable_reports[ $current_report ] );

	$admin = new Exports_Reports_Admin_UI( $options );

	if ( 1 < count( $selectable_reports ) ) {
		?>
		<div style="background-color:#E7E7E7;border:1px solid #D7D7D7; padding:5px 15px;margin:15px 15px 0px 5px;">
			<strong style="padding-right:10px;">Exports and Reports:</strong>
			<label for="report" style="vertical-align:baseline;">Choose Report</label>
			<select id="report" onchange="document.location=this.value;">
				<?php
				foreach ( $selectable_reports as $report_id => $report ) {
					?>
					<option value="
					<?php
					echo esc_attr(
						$admin->var_update(
							array(
								'page'   => $page,
								'report' => absint( $report_id ),
							),
							false,
							false,
							true
						)
					);
					?>
					"<?php selected( $current_report, $report_id ); ?>><?php echo esc_html( $report->name ); ?></option>
					<?php
				}
				?>
			</select>
		</div>
		<?php
	}

	$admin->go();

	return true;
}

add_action( 'wp_admin_ui_post_export', 'exports_reports_log', 10, 2 );
add_action( 'wp_admin_ui_post_remove_export', 'exports_reports_delete_log', 10, 2 );

/**
 * Sends a file from the export directory to the browser as an attachment.
 *
 * The directory refuses direct requests (Exports_Reports_Admin_UI::protect_export_dir() writes the
 * rules), so every download comes through here after the caller's nonce or token check. The file
 * is read in chunks; an export can be larger than the memory limit.
 *
 * @since 0.9.5
 *
 * @param string $filename The file's name inside the export directory, without a path.
 * @return bool False when no such export exists; true once the file has been sent.
 */
function exports_reports_send_export_file( $filename ) {
	$types     = exports_reports_export_mime_types();
	$filename  = basename( (string) $filename );
	$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
	$directory = realpath( WP_ADMIN_UI_EXPORT_DIR );
	$path      = realpath( WP_ADMIN_UI_EXPORT_DIR . '/' . $filename );

	if ( ! exports_reports_is_export_file( $filename ) || false === $directory || false === $path || dirname( $path ) !== $directory || ! is_file( $path ) ) {
		return false;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read in chunks; WP_Filesystem would load the whole export into memory.
	$handle = fopen( $path, 'rb' );

	if ( false === $handle ) {
		return false;
	}

	nocache_headers();
	header( 'Content-Type: ' . $types[ $extension ] );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . (string) filesize( $path ) );
	header( 'X-Content-Type-Options: nosniff' );

	fpassthru( $handle );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with the fopen above.
	fclose( $handle );

	return true;
}

/**
 * The daily cleanup event: removes the exports older than a day and their log rows.
 */
function exports_reports_cleanup_event() {
	exports_reports_cleanup();
}

/**
 * The content types of the export files, by extension (pipe and custom delimited exports are .txt).
 *
 * @return array<string, string>
 */
function exports_reports_export_mime_types() {
	return array(
		'csv'  => 'text/csv; charset=utf-8',
		'tsv'  => 'text/tab-separated-values; charset=utf-8',
		'txt'  => 'text/plain; charset=utf-8',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'xml'  => 'application/xml; charset=utf-8',
		'json' => 'application/json; charset=utf-8',
		'pdf'  => 'application/pdf',
	);
}

/**
 * Whether a file name is one an export writes: an export extension, and not one of the files that keep
 * the directory from being served. The functions that remove files from the export directory remove
 * only these, in case the directory is shared.
 *
 * @param mixed $filename The file name.
 *
 * @return bool
 */
function exports_reports_is_export_file( $filename ) {
	$filename = (string) $filename;

	if ( '' === $filename || in_array( $filename, array( 'index.php', '.htaccess' ), true ) ) {
		return false;
	}

	return isset( exports_reports_export_mime_types()[ strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) ] );
}

/**
 * Whether the current user may download an export file: the file is in the export log, and the user
 * may view the report it belongs to.
 *
 * @param string $filename The file name.
 *
 * @return bool
 */
function exports_reports_user_can_download_export( $filename ) {
	/** @var wpdb $wpdb */ global $wpdb;

	$filename = basename( (string) $filename );

	if ( ! exports_reports_is_export_file( $filename ) ) {
		return false;
	}

	$sql = 'SELECT `report_id` FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'log` WHERE `filename` = %s ORDER BY `id` DESC LIMIT 1';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
	$report_id = $wpdb->get_var( $wpdb->prepare( $sql, $filename ) );

	if ( null === $report_id ) {
		return false;
	}

	$report = exports_reports_get_report( (int) $report_id );
	$group  = $report ? exports_reports_get_group( (int) $report->group ) : null;

	return $report && $group && exports_reports_user_can_view_report( $report, $group );
}

/**
 * @param $args
 * @param $obj
 */
function exports_reports_log( $args, $obj ) {
	/** @var wpdb $wpdb */ global $wpdb;

	$filename = $args[1];

	$wpdb->insert(
		$wpdb->prefix . EXPORTS_REPORTS_TBL . 'log',
		array(
			'report_id' => $obj[0]->report_id,
			'filename'  => $filename,
			'created'   => date_i18n( 'Y-m-d H:i:s' ),
		),
		array(
			'%d',
			'%s',
			'%s',
		)
	);
}

/**
 * @param $args
 * @param $obj
 */
function exports_reports_delete_log( $args, $obj ) {
	/** @var wpdb $wpdb */ global $wpdb;

	$filename = $args[1];

	if ( false !== $args[2] ) {
		$sql = '
			DELETE FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'log`
			WHERE `report_id` = %d AND `filename` = %s
		';

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the report's own query, written by an administrator.
			$sql,
			array(
				$obj[0]->report_id,
				$filename,
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );
	}
}

/**
 * @return mixed
 */
function exports_reports_schedule_cleanup() {
	$schedules = _get_cron_array();
	$timestamp = false;

	$key = md5( serialize( array() ) );

	foreach ( $schedules as $ts => $schedule ) {
		if ( isset( $schedule['exports_reports_cleanup'] ) && isset( $schedule['exports_reports_cleanup'][ $key ] ) ) {
			$timestamp = $ts;
			break;
		}
	}

	if ( false !== $timestamp ) {
		wp_unschedule_event( $timestamp, 'exports_reports_cleanup', array() );
	}

	$timestamp  = time();
	$recurrence = 'daily';

	return wp_schedule_event( $timestamp, $recurrence, 'exports_reports_cleanup', array() );
}

/**
 * Puts the daily cleanup event back when it is missing, as after a site has cleared its cron table.
 *
 * @since 0.9.5
 */
function exports_reports_ensure_cleanup_scheduled() {
	if ( false === wp_next_scheduled( 'exports_reports_cleanup' ) ) {
		exports_reports_schedule_cleanup();
	}
}

/**
 * Clears the daily cleanup event on deactivation; it would otherwise fire for a plugin that is not loaded.
 *
 * @since 0.9.5
 */
function exports_reports_deactivate() {
	wp_clear_scheduled_hook( 'exports_reports_cleanup' );
}

/**
 * Delete all files in a directory
 *
 * @param string|null $directory
 */
function exports_reports_delete_dir_files( $directory ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	/**
	 * @var $wp_filesystem WP_Filesystem_Base
	 */ global $wp_filesystem;

	WP_Filesystem();

	if ( ! $wp_filesystem ) {
		return;
	}

	if ( ! $wp_filesystem->is_dir( $directory ) ) {
		return;
	}

	$file_list = $wp_filesystem->dirlist( $directory, false );

	// Export files only: the directory may be shared, and the files that keep it from being served stay.
	foreach ( (array) $file_list as $file ) {
		if ( 'f' === $file['type'] && exports_reports_is_export_file( $file['name'] ) ) {
			$wp_filesystem->delete( $directory . DIRECTORY_SEPARATOR . $file['name'] );
		}
	}
}

/**
 * @param bool $full
 *
 * @return bool
 */
function exports_reports_cleanup( $full = false ) {
	/** @var wpdb $wpdb */ global $wpdb;

	if ( $full ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE ' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'log' );

		exports_reports_delete_dir_files( WP_ADMIN_UI_EXPORT_DIR );
	} else {
		$purge_age = 1; // day(s) in age to purge

		$sql = '
			SELECT *
			FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'log`
			WHERE `created` < DATE_ADD( NOW(), INTERVAL -%d DAY )
		';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $sql, array( $purge_age ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cleanup = $wpdb->get_results( $sql );

		if ( false !== $cleanup && ! empty( $cleanup ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			/**
			 * @var $wp_filesystem WP_Filesystem_Base
			 */ global $wp_filesystem;

			WP_Filesystem();

			foreach ( $cleanup as $export ) {
				if ( $wp_filesystem ) {
					$file = WP_ADMIN_UI_EXPORT_DIR . '/' . str_replace( array( '/', '..' ), '', $export->filename );

					$wp_filesystem->delete( $file );
				}

				$sql = '
					DELETE FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'log`
					WHERE `id` = %d
				';

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$sql = $wpdb->prepare( $sql, array( $export->id ) );

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $sql );
			}

			return true;
		}
	}

	return false;
}

/**
 * @param $caps
 *
 * @return array
 */
function exports_reports_get_capabilities( $caps ) {
	$caps = array_merge( $caps, exports_reports_capabilities() );

	return $caps;
}

/**
 * @return array
 */
function exports_reports_capabilities() {
	$caps = array(
		'exports_reports_full_access',
		'exports_reports_settings',
		'exports_reports_view',
	);

	/**
	 * Filters the capabilities the plugin registers.
	 *
	 * @since 0.9.5 Replaces export_reports_capabilites, which still runs first.
	 *
	 * @param string[] $caps Capability names.
	 */
	$caps = apply_filters( 'exports_reports_capabilities', apply_filters_deprecated( 'export_reports_capabilites', array( $caps ), '0.9.5', 'exports_reports_capabilities' ) );

	return $caps;
}

/**
 * @param $caps
 *
 * @return bool
 */
function exports_reports_current_user_can_any( $caps ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	wp_get_current_user();

	if ( ! is_array( $caps ) ) {
		return current_user_can( $caps );
	}

	foreach ( $caps as $cap ) {
		if ( current_user_can( $cap ) ) {
			return true;
		}
	}

	return current_user_can( 'exports_reports_full_access' );
}

/**
 * @param $caps
 *
 * @return bool
 */
function exports_reports_current_user_can_which( $caps ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	wp_get_current_user();

	foreach ( $caps as $cap ) {
		if ( current_user_can( $cap ) ) {
			return $cap;
		}
	}

	return false;
}

/**
 * @return mixed
 */
function exports_reports_get_roles() {
	return wp_roles()->get_names();
}

/**
 * @param $role
 *
 * @return bool
 */
function exports_reports_has_role( $role ) {
	global $current_user;

	if ( ! is_user_logged_in() ) {
		return false;
	}

	wp_get_current_user();

	if ( null !== get_role( $role ) && in_array( $role, $current_user->roles ) ) {
		return true;
	}

	return false;
}

/**
 * Filter the robots.txt contents and add the /exports/ directory to the list of disallowed folders.
 *
 * @since 0.9.0
 *
 * @param string $robots_txt The robots.txt contents.
 *
 * @return string The robots.txt contents.
 */
function exports_reports_robots_txt( $robots_txt ) {
	$exclude_path = str_replace( ABSPATH, '', WP_ADMIN_UI_EXPORT_DIR );
	$exclude_path = str_replace( DIRECTORY_SEPARATOR, '/', $exclude_path );
	$exclude_path = trim( $exclude_path, '/' );

	return $robots_txt . "\n" . 'Disallow: */' . $exclude_path . '/*';
}

add_filter( 'robots_txt', 'exports_reports_robots_txt' );
