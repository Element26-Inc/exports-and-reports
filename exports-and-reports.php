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
 * Version:           0.9.5
 * Author:            Scott Kingsley Clark
 * Author URI:        https://www.scottkclark.com/
 * Text Domain:       exports-and-reports
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.7
 * Requires PHP:      7.2
 * GitHub Plugin URI: https://github.com/sc0ttkclark/exports-and-reports
 * Primary Branch:    main
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin runs the administrator's report queries and keeps its own tables; there is nothing to cache.

define( 'EXPORTS_REPORTS_TBL', 'exportsreports_' );
define( 'EXPORTS_REPORTS_VERSION', '095' );
define( 'EXPORTS_REPORTS_URL', plugin_dir_url( __FILE__ ) );
define( 'EXPORTS_REPORTS_DIR', plugin_dir_path( __FILE__ ) );

// The export directory and its URL: a folder of the uploads directory, unless wp-config.php defines the
// constants. The names before 0.9.5, WP_ADMIN_UI_EXPORT_DIR and WP_ADMIN_UI_EXPORT_URL, are still read.
if ( ! defined( 'EXPORTS_REPORTS_EXPORT_DIR' ) ) {
	define( 'EXPORTS_REPORTS_EXPORT_DIR', defined( 'WP_ADMIN_UI_EXPORT_DIR' ) ? WP_ADMIN_UI_EXPORT_DIR : wp_get_upload_dir()['basedir'] . '/exports-and-reports' );
}

if ( ! defined( 'EXPORTS_REPORTS_EXPORT_URL' ) ) {
	define( 'EXPORTS_REPORTS_EXPORT_URL', defined( 'WP_ADMIN_UI_EXPORT_URL' ) ? WP_ADMIN_UI_EXPORT_URL : wp_get_upload_dir()['baseurl'] . '/exports-and-reports' );
}

// The admin-ajax action that serves an export file, and the nonce action of its link.
define( 'EXPORTS_REPORTS_EXPORT_ACTION', 'exports_reports_export' );
define( 'EXPORTS_REPORTS_EXPORT_NONCE', 'exports-reports-export' );

/**
 * The plugin's tables as properties of $wpdb ($wpdb->exports_reports_groups, $wpdb->exports_reports_reports and
 * $wpdb->exports_reports_log), named the way core names its own, so a statement writes them in place. Set again
 * when the site switches, as the prefix does.
 *
 * @since 0.9.5
 */
function exports_reports_tables() {
	global $wpdb;

	foreach ( array( 'groups', 'reports', 'log' ) as $table ) {
		$wpdb->{'exports_reports_' . $table} = $wpdb->prefix . EXPORTS_REPORTS_TBL . $table;
	}
}
exports_reports_tables();
add_action( 'switch_blog', 'exports_reports_tables' );

register_activation_hook( __FILE__, 'exports_reports_install_upgrade' );
register_deactivation_hook( __FILE__, 'exports_reports_deactivate' );

add_action( 'admin_init', 'exports_reports_init' );
add_action( 'admin_init', 'exports_reports_ensure_cleanup_scheduled' );
add_action( 'admin_menu', 'exports_reports_menu' );
add_action( 'admin_menu', 'exports_reports_admin_menu' );

add_action( 'wp_ajax_' . EXPORTS_REPORTS_EXPORT_ACTION, 'exports_reports_export_download' );
add_action( 'exports_reports_cleanup', 'exports_reports_cleanup_event' );
add_filter( 'user_has_cap', 'exports_reports_user_has_cap', 10, 4 );

require_once EXPORTS_REPORTS_DIR . 'vendor/autoload.php';

require_once EXPORTS_REPORTS_DIR . 'vendor/vendor-prefixed/autoload.php';
require_once EXPORTS_REPORTS_DIR . 'includes/rest-api.php';

/**
 * The admin-ajax action EXPORTS_REPORTS_EXPORT_ACTION: wp-admin-ui/export.php sends the file and exits, so a
 * request that names no export ends here.
 */
function exports_reports_export_download() {
	require_once EXPORTS_REPORTS_DIR . 'wp-admin-ui/export.php';

	wp_die( esc_html__( 'Invalid request.', 'exports-and-reports' ), '', array( 'response' => 400 ) );
}

/**
 * Registers the admin script and style, and enqueues the report form's editor assets on the Manage Reports page.
 *
 * @param string $hook_suffix The admin page, as admin_enqueue_scripts passes it.
 */
function exports_reports_assets( $hook_suffix ) {
	wp_register_script( 'exports-reports-admin', EXPORTS_REPORTS_URL . 'assets/admin.js', array( 'jquery' ), EXPORTS_REPORTS_VERSION, true );
	wp_register_style( 'exports-reports-admin', EXPORTS_REPORTS_URL . 'assets/admin.css', array(), EXPORTS_REPORTS_VERSION );

	if ( get_plugin_page_hookname( 'exports-reports-admin-reports', 'exports-reports-admin' ) === $hook_suffix ) {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_script( 'exports-reports-report-fields', EXPORTS_REPORTS_URL . 'assets/report-fields.js', array( 'jquery', 'jquery-ui-sortable' ), EXPORTS_REPORTS_VERSION, true );
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
 * Creates the plugin's tables from assets/dump.sql, or brings them up to date through dbDelta() on an
 * upgrade; records the version, generates the API token on a first install and schedules the daily
 * cleanup.
 *
 * @param bool $upgrade Whether the tables exist already.
 */
function exports_reports_sql_install( $upgrade = false ) {
	/** @var wpdb $wpdb */ global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/file.php';

	/** @var WP_Filesystem_Base|null $wp_filesystem */ global $wp_filesystem;

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

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a statement of the plugin's own schema file.
			$wpdb->query( $query );

			continue;
		}

		require_once ABSPATH . '/wp-admin/includes/upgrade.php';

		dbDelta( $query );
	}

	if ( $upgrade ) {
		exports_reports_convert_tables_charset();
		exports_reports_move_legacy_export_dir();
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

		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );

		if ( empty( $current ) || $current === $wpdb->collate ) {
			continue;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the plugin's own table; character set and collation are wpdb's, not request data.
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

	echo '<div class="notice notice-error"><p>' . wp_kses( __( '<strong>Exports and Reports:</strong> a table could not be converted to the site\'s character set. Convert it by hand or ask your host, then deactivate and reactivate the plugin to retry.', 'exports-and-reports' ), array( 'strong' => array() ) ) . '</p><ul>';

	foreach ( $failed as $table => $error ) {
		echo '<li><code>' . esc_html( $table ) . '</code>: ' . esc_html( $error ) . '</li>';
	}

	echo '</ul></div>';
}

add_action( 'admin_notices', 'exports_reports_charset_errors_notice' );

/**
 * Grants exports_reports_full_access to administrators while the plugin is active, without writing it
 * to their user meta. The exports_reports_full_access filter decides who gets it.
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
 * On activation: installs the tables when the plugin has never run here, upgrades them otherwise.
 */
function exports_reports_install_upgrade() {
	/** @var \wpdb $wpdb */ global $wpdb;

	$version = (int) get_option( 'exports_reports_version' );

	if ( empty( $version ) ) {
		exports_reports_sql_install();
	} else {
		exports_reports_upgrade();
	}
}

/**
 * Brings the tables and the stored version up to date when the plugin's version has changed; an
 * install from before 0.6 gets a new API token as well.
 */
function exports_reports_upgrade() {
	/** @var \wpdb $wpdb */ global $wpdb;

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
 * On admin_init: upgrades when needed and sorts out the capabilities. With the Members plugin active,
 * the plugin's capabilities are registered with it (the way Gravity Forms does), an administrator
 * without any of them gets them all on the administrator role, and a copy of
 * exports_reports_full_access on the user itself is removed. Without Members,
 * exports_reports_user_has_cap() grants administrators full access at run time.
 */
function exports_reports_init() {
	exports_reports_upgrade();

	$capabilities = exports_reports_capabilities();

	add_filter( 'members_get_capabilities', 'exports_reports_get_capabilities' );

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
		// exports_reports_user_has_cap() grants administrators full access while the plugin is active.
		// Versions before 0.9.5 wrote the capability into the user's meta instead, where it would outlive
		// the plugin; a copy found there is removed.
		$user = get_userdata( get_current_user_id() );

		if ( $user && isset( $user->caps['exports_reports_full_access'] ) ) {
			$user->remove_cap( 'exports_reports_full_access' );
		}
	}
}

/**
 * The Reports Admin menu: Manage Groups, Manage Reports and Settings, for users with the settings
 * capability or full access (super admins and administrators count as full access).
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
		add_menu_page( __( 'Reports Admin', 'exports-and-reports' ), __( 'Reports Admin', 'exports-and-reports' ), $has_full_access ? 'read' : $min_cap, 'exports-reports-admin', '', EXPORTS_REPORTS_URL . 'assets/icons/16.png' );
		add_submenu_page( 'exports-reports-admin', __( 'Manage Groups', 'exports-and-reports' ), __( 'Manage Groups', 'exports-and-reports' ), $has_full_access ? 'read' : 'exports_reports_settings', 'exports-reports-admin', 'exports_reports_groups' );
		add_submenu_page( 'exports-reports-admin', __( 'Manage Reports', 'exports-and-reports' ), __( 'Manage Reports', 'exports-and-reports' ), $has_full_access ? 'read' : 'exports_reports_settings', 'exports-reports-admin-reports', 'exports_reports_reports' );
		add_submenu_page( 'exports-reports-admin', __( 'Settings', 'exports-and-reports' ), __( 'Settings', 'exports-and-reports' ), $has_full_access ? 'read' : 'exports_reports_settings', 'exports-reports-admin-settings', 'exports_reports_settings' );
	}
}

/**
 * The Reports menu: the enabled groups the current user may view, by role access or full access.
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

	$groups = $wpdb->get_results( "SELECT `id`, `role_access`, `name` FROM {$wpdb->exports_reports_groups} WHERE `disabled` = 0 ORDER BY `weight`, `name`" );

	if ( ! empty( $groups ) ) {
		foreach ( $groups as $group ) {
			$reports = $wpdb->get_results( $wpdb->prepare( "SELECT `id`, `role_access` FROM {$wpdb->exports_reports_reports} WHERE `disabled` = 0 AND `group` = %d ORDER BY `weight`, `name`", $group->id ) );

			if ( ! empty( $reports ) ) {
				foreach ( $reports as $report ) {
					if ( $has_full_access || exports_reports_current_user_can_any( 'exports_reports_view' ) || exports_reports_current_user_can_any( 'exports_reports_view_group_' . $group->id ) || exports_reports_current_user_can_any( 'exports_reports_view_report_' . $report->id ) ) {
						$menu_page = 'exports-reports-group-' . $group->id;

						if ( ! $init ) {
							add_menu_page( __( 'Reports', 'exports-and-reports' ), __( 'Reports', 'exports-and-reports' ), 'read', $menu_page, '', EXPORTS_REPORTS_URL . 'assets/icons/16.png' );

							$init = $menu_page;
						}

						add_submenu_page( $init, $group->name, $group->name, 'read', $menu_page, 'exports_reports_view' );

						break;
					}

					$roles = array_filter( array_unique( array_merge( explode( ',', $group->role_access ), explode( ',', $report->role_access ) ) ) );

					if ( empty( $roles ) ) {
						continue;
					}

					foreach ( $roles as $role ) {
						if ( exports_reports_has_role( $role ) ) {
							$menu_page = 'exports-reports-group-' . $group->id;

							if ( ! $init ) {
								add_menu_page( __( 'Reports', 'exports-and-reports' ), __( 'Reports', 'exports-and-reports' ), 'read', $menu_page, '', EXPORTS_REPORTS_URL . 'assets/icons/16.png' );

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

	$rest_base = rest_url( 'exports-reports/v1/reports/YOUR_REPORT_ID' );
	$token     = (string) get_option( 'exports_reports_token' );

	wp_enqueue_style( 'exports-reports-admin' );
	?>
	<div class="wrap exports-reports-settings">
		<div id="icon-edit-pages" class="icon32" style="background-image:url(<?php echo esc_url( EXPORTS_REPORTS_URL . 'assets/icons/32.png' ); ?>)">
			<br /></div>
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

/**
 * The Manage Groups page: an Exports_Reports_Admin_UI on the groups table.
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
		'label'            => __( 'WP Roles with Access', 'exports-and-reports' ),
		'comments'         => __( 'Add the exports_reports_full_access capability to a role for full access to reports, exports_reports_settings for only access to settings, exports_reports_view for access to view all reports, exports_reports_view_group_{ID} for access to view a group and all of the reports within, or exports_reports_view_report_{ID} for access to view a single report', 'exports-and-reports' ),
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
		'item'         => __( 'Report Group', 'exports-and-reports' ),
		'items'        => __( 'Report Groups', 'exports-and-reports' ),
		'table'        => $wpdb->exports_reports_groups,
		'columns'      => $columns,
		'form_columns' => $form_columns,
		'icon'         => EXPORTS_REPORTS_URL . 'assets/icons/32.png',
		'duplicate'    => true,
	);

	$admin = new Exports_Reports_Admin_UI( $admin_ui );

	$admin->go();
}

/**
 * The Manage Reports page: an Exports_Reports_Admin_UI on the reports table, with the field editor on
 * the field_data column.
 */
function exports_reports_reports() {
	global $wpdb;

	require_once EXPORTS_REPORTS_DIR . 'wp-admin-ui/class-exports-reports-admin-ui.php';

	$columns = array(
		'name',
		'group'    => array(
			'label'   => __( 'Group', 'exports-and-reports' ),
			'type'    => 'related',
			'related' => $wpdb->exports_reports_groups,
		),
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
			'label' => __( 'Report ID', 'exports-and-reports' ),
			'type'  => 'number',
		),
	);

	$columns['created']['filter']       = true;
	$columns['created']['filter_label'] = 'Lifespan (created / modified)';
	$columns['created']['date_ongoing'] = 'updated';

	$form_columns = $columns;

	unset( $form_columns['id'] );

	$form_columns['disabled']['label'] = __( 'Disabled?', 'exports-and-reports' );

	$form_columns['disable_export'] = array(
		'label' => __( 'Disable Export?', 'exports-and-reports' ),
		'type'  => 'bool',
	);

	$form_columns['default_none'] = array(
		'label'    => __( 'Default to No Results?', 'exports-and-reports' ),
		'type'     => 'bool',
		'comments' => __( 'On = Show no results and require search; Off (default) = Show all results', 'exports-and-reports' ),
	);

	$form_columns['page_orientation'] = array(
		'label'   => __( 'PDF Report Page Orientation', 'exports-and-reports' ),
		'type'    => 'related',
		'related' => array(
			'L' => __( 'Landscape', 'exports-and-reports' ),
			'P' => __( 'Portrait', 'exports-and-reports' ),
		),
	);

	$form_columns['created']['date_touch_on_create'] = true;
	$form_columns['created']['display']              = false;
	$form_columns['updated']['date_touch']           = true;
	$form_columns['updated']['display']              = false;

	$roles = exports_reports_get_roles();

	$form_columns['role_access'] = array(
		'label'            => __( 'WP Roles with Access', 'exports-and-reports' ),
		'type'             => 'related',
		'related'          => $roles,
		'related_multiple' => true,
	);

	$form_columns['sql_query'] = array(
		'label'    => __( 'SQL Query', 'exports-and-reports' ),
		'type'     => 'desc',
		'comments' => __( 'Available Variables: %%WHERE%% %%HAVING%% %%ORDERBY%% %%LIMIT%%<br />(example: WHERE %%WHERE%% my_field=1)', 'exports-and-reports' ),
	);

	$form_columns['sql_query_count'] = array(
		'label'    => __( 'SQL Query for Count (advanced, optional)', 'exports-and-reports' ),
		'type'     => 'desc',
		'comments' => __( 'For advanced/complex queries above, you can SELECT minimal fields here for better "Total Count" performance.<br />Available Variables: %%WHERE%% %%HAVING%%<br />(example: WHERE %%WHERE%% my_field=1)', 'exports-and-reports' ),
	);

	$form_columns['field_data'] = array(
		'label'        => __( 'Fields (optional)', 'exports-and-reports' ),
		'custom_input' => 'exports_reports_report_field',
		'custom_save'  => 'exports_reports_report_field_save',
	);

	$admin_ui = array(
		'reorder'       => 'weight',
		'reorder_order' => '`group` ASC,`weight`',
		'order'         => '`group` ASC,`weight`',
		'order_dir'     => 'ASC',
		'css'           => EXPORTS_REPORTS_URL . 'assets/admin.css',
		'item'          => __( 'Report', 'exports-and-reports' ),
		'items'         => __( 'Reports', 'exports-and-reports' ),
		'table'         => $wpdb->exports_reports_reports,
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
		<p class="description"><?php esc_html_e( 'Leave this empty to show every column the query returns. Drag a field by its handle to change the order.', 'exports-and-reports' ); ?></p>
		<div class="er-fields__list">
			<?php
			foreach ( $fields as $index => $field ) {
				exports_reports_report_field_row( (string) $index, exports_reports_field_defaults( $field ), $types );
			}
			?>
		</div>
		<p><button type="button" class="button er-fields__add"><?php esc_html_e( 'Add Field', 'exports-and-reports' ); ?></button></p>
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
			<span class="er-field__handle dashicons dashicons-move" title="<?php esc_attr_e( 'Drag to reorder', 'exports-and-reports' ); ?>"></span>
			<?php $text( 'field_name', __( 'Field name (column or alias)', 'exports-and-reports' ) ); ?>
			<label>
				<span><?php esc_html_e( 'Data type', 'exports-and-reports' ); ?></span>
				<select name="<?php echo esc_attr( 'field_type[' . $index . ']' ); ?>">
					<?php foreach ( $types as $type => $type_label ) { ?>
						<option value="<?php echo esc_attr( $type ); ?>"<?php selected( $field['type'], $type ); ?>><?php echo esc_html( $type_label ); ?></option>
					<?php } ?>
				</select>
			</label>
			<?php $text( 'field_label', __( 'Label', 'exports-and-reports' ), __( 'defaults to the field name', 'exports-and-reports' ) ); ?>
			<button type="button" class="button-link button-link-delete er-field__remove"><?php esc_html_e( 'Remove', 'exports-and-reports' ); ?></button>
		</div>
		<div class="er-field__flags">
			<?php
			$flag( 'field_hide_report', __( 'Hide from report', 'exports-and-reports' ), 1 === (int) $field['hide_report'] );
			$flag( 'field_hide_export', __( 'Hide from export', 'exports-and-reports' ), 1 === (int) $field['hide_export'] );
			$flag( 'field_search', __( 'Searchable', 'exports-and-reports' ), 1 !== (int) $field['search'], '0', '1' );
			$flag( 'field_filter', __( 'Filterable', 'exports-and-reports' ), 1 === (int) $field['filter'] );
			$flag( 'field_group_related', __( 'Filter with HAVING', 'exports-and-reports' ), 1 === (int) $field['group_related'] );
			?>
		</div>
		<details class="er-field__advanced">
			<summary><?php esc_html_e( 'Advanced options', 'exports-and-reports' ); ?></summary>
			<div class="er-field__grid">
				<?php
				$text( 'field_real_name', __( 'Real field', 'exports-and-reports' ), __( 'when the name is an alias', 'exports-and-reports' ) );
				$text( 'field_custom_display', __( 'Display function', 'exports-and-reports' ), __( 'a function a plugin or theme defines', 'exports-and-reports' ) );
				$text( 'width', __( 'Width', 'exports-and-reports' ), __( 'relative, for the PDF export', 'exports-and-reports' ) );
				$text( 'field_filter_label', __( 'Filter label', 'exports-and-reports' ) );
				$text( 'field_filter_default', __( 'Default filter value', 'exports-and-reports' ) );
				$text( 'field_filter_ongoing', __( 'Ongoing date field', 'exports-and-reports' ), __( 'for a date range filter', 'exports-and-reports' ) );
				$text( 'field_filter_ongoing_default', __( 'Ongoing default filter value', 'exports-and-reports' ) );
				$text( 'field_related', __( 'Related table', 'exports-and-reports' ), __( 'related type only', 'exports-and-reports' ) );
				$text( 'field_related_field', __( 'Related field', 'exports-and-reports' ) );
				$text( 'field_related_id', __( 'Related ID field', 'exports-and-reports' ) );
				$text( 'field_related_sql', __( 'Related WHERE / ORDER BY', 'exports-and-reports' ) );
				?>
			</div>
		</details>
	</div>
	<?php
}

/**
 * The custom_save callback of the field_data column: reads the rows the field editor posted and
 * returns them as JSON, in order, each with the keys exports_reports_field_defaults() names.
 *
 * @param mixed                    $value      The column's value in the request; the rows are read from the field inputs instead.
 * @param string                   $column     The column name (field_data).
 * @param array                    $attributes The column attributes.
 * @param Exports_Reports_Admin_UI $obj        The admin UI.
 *
 * @return string JSON: the list of fields, empty when none was posted.
 */
function exports_reports_report_field_save( $value, $column, $attributes, $obj ) {
	// Exports_Reports_Admin_UI::save() verified the form nonce before calling this custom_save callback; checked again so the function stands on its own.
	$form = isset( $obj->do ) && is_string( $obj->do ) ? $obj->do : '';

	if ( '' === $form || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'exports-reports-form-' . $form ) ) {
		return $value;
	}

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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a SQL fragment the administrator wrote for the lookup, kept as written; the report's own query is stored the same way.
		$field['related_sql'] = isset( $_POST['field_related_sql'][ $key ] ) ? trim( (string) wp_unslash( $_POST['field_related_sql'][ $key ] ) ) : '';

		$value[] = $field;
	}

	return wp_json_encode( $value );
}

/**
 * A field definition with every key set, empty strings for the ones not given.
 *
 * @param array|null $field A field definition, possibly partial.
 *
 * @return array
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

	$report = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->exports_reports_reports} WHERE `id` = %d AND `disabled` = 0 LIMIT 1", absint( $report_id ) ) );

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

	$group = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->exports_reports_groups} WHERE `id` = %d AND `disabled` = 0 LIMIT 1", absint( $group_id ) ) );

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
 * The admin UI options for a report row: what the report page and the REST API hand to
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

	$options['export']       = ( 0 === (int) $report->disable_export );
	$options['search']       = ( strlen( (string) $report->field_data ) > 0 ? true : false );
	$options['default_none'] = ( 1 === (int) $report->default_none ? true : false );
	$options['sql']          = trim( (string) $report->sql_query );
	$options['sql_count']    = trim( (string) $report->sql_query_count );

	if ( empty( $options['sql_count'] ) ) {
		unset( $options['sql_count'] );
	}

	$options['page_orientation'] = $report->page_orientation;
	$options['item']             = __( 'item', 'exports-and-reports' );
	$options['items']            = $report->name;
	$options['icon']             = EXPORTS_REPORTS_URL . 'assets/icons/32.png';
	$options['heading']          = array(
		'manage' => __( 'View Report:', 'exports-and-reports' ),
	);

	$field_data = json_decode( (string) $report->field_data, true );

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
 * The report page of a group: the reports the user may view, and the chosen one through
 * Exports_Reports_Admin_UI.
 *
 * @param int|false $group_id        The group, or false to read it from the page slug.
 * @param bool|null $has_full_access Whether the user has full access; null to work it out.
 *
 * @return bool False when there is nothing to show.
 */
function exports_reports_view( $group_id = false, $has_full_access = null ) {
	/** @var string|null $plugin_page The admin page slug, as the admin sets it. */ global $plugin_page;

	$page = isset( $plugin_page ) ? sanitize_key( (string) $plugin_page ) : '';

	if ( '' === $page ) {
		return false;
	}

	/** @var wpdb $wpdb */ global $wpdb;

	wp_enqueue_script( 'exports-reports-admin' );
	wp_enqueue_style( 'exports-reports-admin' );

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

		$group = $wpdb->get_row( $wpdb->prepare( "SELECT `id`, `role_access` FROM {$wpdb->exports_reports_groups} WHERE `disabled` = 0 AND `id` = %d ORDER BY `weight`, `name` LIMIT 1", $group_id ) );

		if ( empty( $group ) ) {
			return false;
		}
		$group_roles = explode( ',', $group->role_access );
	} else {
		$group_id    = 0;
		$group_roles = array();

		$groups = $wpdb->get_results( "SELECT `id`, `role_access` FROM {$wpdb->exports_reports_groups} WHERE `disabled` = 0 ORDER BY `weight`, `name`" );

		if ( ! empty( $groups ) ) {
			foreach ( $groups as $group ) {
				$reports = $wpdb->get_results( $wpdb->prepare( "SELECT `id`, `role_access` FROM {$wpdb->exports_reports_reports} WHERE `disabled` = 0 AND `group` = %d ORDER BY `weight`, `name`", $group->id ) );

				if ( ! empty( $reports ) ) {
					foreach ( $reports as $report ) {
						if ( $has_full_access || exports_reports_current_user_can_any( 'exports_reports_view' ) || exports_reports_current_user_can_any( 'exports_reports_view_group_' . $group->id ) || exports_reports_current_user_can_any( 'exports_reports_view_report_' . $report->id ) ) {
							$group_id    = $group->id;
							$group_roles = explode( ',', $group->role_access );

							break;
						}

						$roles = array_filter( array_unique( array_merge( explode( ',', $group->role_access ), explode( ',', $report->role_access ) ) ) );

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

	$reports = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->exports_reports_reports} WHERE `disabled` = 0 AND `group` = %d ORDER BY `weight`, `name`", $group_id ) );

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

		$roles = array_filter( array_unique( array_merge( $group_roles, explode( ',', $report->role_access ) ) ) );

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
		<div class="exports-reports-selector">
			<strong><?php esc_html_e( 'Exports and Reports:', 'exports-and-reports' ); ?></strong>
			<label for="report"><?php esc_html_e( 'Choose Report', 'exports-and-reports' ); ?></label>
			<select id="report">
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

add_action( 'exports_reports_post_export', 'exports_reports_log', 10, 2 );
add_action( 'exports_reports_post_remove_export', 'exports_reports_delete_log', 10, 2 );

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
	$directory = realpath( EXPORTS_REPORTS_EXPORT_DIR );
	$path      = realpath( EXPORTS_REPORTS_EXPORT_DIR . '/' . $filename );

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

	$report_id = $wpdb->get_var( $wpdb->prepare( "SELECT `report_id` FROM {$wpdb->exports_reports_log} WHERE `filename` = %s ORDER BY `id` DESC LIMIT 1", $filename ) );

	if ( null === $report_id ) {
		return false;
	}

	$report = exports_reports_get_report( (int) $report_id );
	$group  = $report ? exports_reports_get_group( (int) $report->group ) : null;

	return $report && $group && exports_reports_user_can_view_report( $report, $group );
}

/**
 * Logs a written export file against its report (the exports_reports_post_export action).
 *
 * @param array $args The arguments do_hook() passes, from index 1: the file name first.
 * @param array $obj  The admin UI instance and the hook name, as do_hook() passes them.
 */
function exports_reports_log( $args, $obj ) {
	/** @var wpdb $wpdb */ global $wpdb;

	$filename = $args[1];

	$wpdb->insert(
		$wpdb->exports_reports_log,
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
 * Removes the log row of a deleted export file (the exports_reports_post_remove_export action).
 *
 * @param array $args The arguments do_hook() passes, from index 1: the file name, then whether it was removed.
 * @param array $obj  The admin UI instance and the hook name, as do_hook() passes them.
 */
function exports_reports_delete_log( $args, $obj ) {
	/** @var wpdb $wpdb */ global $wpdb;

	$filename = $args[1];

	if ( false !== $args[2] ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->exports_reports_log} WHERE `report_id` = %d AND `filename` = %s", $obj[0]->report_id, $filename ) );
	}
}

/**
 * Schedules the daily cleanup event, replacing the one already there.
 *
 * @return bool What wp_schedule_event() returns.
 */
function exports_reports_schedule_cleanup() {
	wp_clear_scheduled_hook( 'exports_reports_cleanup' );

	return wp_schedule_event( time(), 'daily', 'exports_reports_cleanup' );
}

/**
 * Moves this site's export files from the directory the plugin used before 0.9.5, wp-content/exports, into
 * the current one, when the two differ and the old one is still there: the files the site's export log
 * names, so on a network each site takes its own. The log keeps file names only, so downloads follow the
 * move. The protection files the plugin wrote there, and the directory, go once nothing else is left.
 * Runs on upgrade.
 *
 * @since 0.9.5
 */
function exports_reports_move_legacy_export_dir() {
	global $wpdb;

	$legacy = WP_CONTENT_DIR . '/exports';

	if ( EXPORTS_REPORTS_EXPORT_DIR === $legacy || ! is_dir( $legacy ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	if ( ! WP_Filesystem() || ! wp_mkdir_p( EXPORTS_REPORTS_EXPORT_DIR ) ) {
		return;
	}

	/** @var WP_Filesystem_Base|null $wp_filesystem */ global $wp_filesystem;

	if ( ! $wp_filesystem ) {
		return;
	}

	require_once EXPORTS_REPORTS_DIR . 'wp-admin-ui/class-exports-reports-admin-ui.php';
	Exports_Reports_Admin_UI::protect_export_dir();

	$logged     = array_map( 'strval', (array) $wpdb->get_col( "SELECT `filename` FROM {$wpdb->exports_reports_log}" ) );
	$protection = array();
	$left       = array();

	foreach ( (array) $wp_filesystem->dirlist( $legacy, true ) as $file ) {
		$path     = $legacy . '/' . $file['name'];
		$contents = 'f' === $file['type'] && in_array( $file['name'], array( '.htaccess', 'index.php' ), true ) ? (string) $wp_filesystem->get_contents( $path ) : '';

		if ( 'f' === $file['type'] && in_array( $file['name'], $logged, true ) && exports_reports_is_export_file( $file['name'] ) ) {
			$wp_filesystem->move( $path, EXPORTS_REPORTS_EXPORT_DIR . '/' . $file['name'], true );
		} elseif ( false !== strpos( $contents, 'Exports and Reports' ) || false !== strpos( $contents, 'Silence is golden' ) ) {
			$protection[] = $path;
		} else {
			$left[] = $file['name'];
		}
	}

	if ( array() === $left ) {
		foreach ( $protection as $path ) {
			$wp_filesystem->delete( $path );
		}

		$wp_filesystem->rmdir( $legacy );
	}
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

	/** @var WP_Filesystem_Base|null $wp_filesystem */ global $wp_filesystem;

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
 * The daily cleanup: removes the export files older than a day and their log rows. With $full every
 * export file and the whole log go (the Clear Now button).
 *
 * @param bool $full Whether to remove everything.
 *
 * @return bool Whether anything was removed; always false for a full clear.
 */
function exports_reports_cleanup( $full = false ) {
	/** @var wpdb $wpdb */ global $wpdb;

	if ( $full ) {
		$wpdb->query( "TRUNCATE {$wpdb->exports_reports_log}" );

		exports_reports_delete_dir_files( EXPORTS_REPORTS_EXPORT_DIR );
	} else {
		$purge_age = 1; // day(s) in age to purge

		$cleanup = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->exports_reports_log} WHERE `created` < DATE_ADD( NOW(), INTERVAL -%d DAY )", $purge_age ) );

		if ( ! empty( $cleanup ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			/** @var WP_Filesystem_Base|null $wp_filesystem */ global $wp_filesystem;

			WP_Filesystem();

			foreach ( $cleanup as $export ) {
				if ( $wp_filesystem ) {
					$file = EXPORTS_REPORTS_EXPORT_DIR . '/' . str_replace( array( '/', '..' ), '', $export->filename );

					$wp_filesystem->delete( $file );
				}

				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->exports_reports_log} WHERE `id` = %d", $export->id ) );
			}

			return true;
		}
	}

	return false;
}

/**
 * Adds the plugin's capabilities to the list the Members plugin manages (members_get_capabilities).
 *
 * @param string[] $caps The capabilities Members knows.
 *
 * @return string[]
 */
function exports_reports_get_capabilities( $caps ) {
	$caps = array_merge( $caps, exports_reports_capabilities() );

	return $caps;
}

/**
 * The capabilities the plugin registers, filtered.
 *
 * @return string[]
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
 * Whether the current user has any of the capabilities, or full access.
 *
 * @param string|string[] $caps One capability or several.
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
 * The first of the capabilities the current user has.
 *
 * @param string[] $caps The capabilities, in order of preference.
 *
 * @return string|false The capability, or false when the user has none of them.
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
 * The site's roles.
 *
 * @return string[] Role names by slug.
 */
function exports_reports_get_roles() {
	return wp_roles()->get_names();
}

/**
 * Whether the current user has a role.
 *
 * @param string $role The role slug.
 *
 * @return bool
 */
function exports_reports_has_role( $role ) {
	global $current_user;

	if ( ! is_user_logged_in() ) {
		return false;
	}

	wp_get_current_user();

	if ( null !== get_role( $role ) && in_array( $role, $current_user->roles, true ) ) {
		return true;
	}

	return false;
}

/**
 * Filter the robots.txt contents and add the export directory to the list of disallowed folders.
 *
 * @since 0.9.0
 *
 * @param string $robots_txt The robots.txt contents.
 *
 * @return string The robots.txt contents.
 */
function exports_reports_robots_txt( $robots_txt ) {
	$exclude_path = trim( (string) wp_parse_url( EXPORTS_REPORTS_EXPORT_URL, PHP_URL_PATH ), '/' );

	if ( '' === $exclude_path ) {
		return $robots_txt;
	}

	return $robots_txt . "\nDisallow: /" . $exclude_path . '/';
}

add_filter( 'robots_txt', 'exports_reports_robots_txt' );
