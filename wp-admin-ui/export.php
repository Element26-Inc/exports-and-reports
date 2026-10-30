<?php
/**
 * Serves an export file to the user who asked for it: the admin-ajax action wp_admin_ui_export,
 * reached from the link and the window the export page opens.
 *
 * @package Exports_And_Reports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_ADMIN_UI_EXPORT_DIR' ) ) {
	define( 'WP_ADMIN_UI_EXPORT_DIR', WP_CONTENT_DIR . '/exports' );
}

if ( ! isset( $_GET['exports_and_reports_download'], $_GET['_wpnonce'], $_GET['exports_and_reports_export'] ) ) {
	return;
}

if ( false === wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wp-admin-ui-export' ) ) {
	wp_die( 'Access denied', '', array( 'response' => 403 ) );
}

do_action( 'wp_admin_ui_export_download' );

while ( ob_get_level() > 0 ) {
	ob_end_clean();
}

if ( ! exports_reports_send_export_file( sanitize_file_name( wp_unslash( $_GET['exports_and_reports_export'] ) ) ) ) {
	wp_die( 'File not found.', '', array( 'response' => 404 ) );
}

exit;
