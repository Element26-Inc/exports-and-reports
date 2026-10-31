<?php
/**
 * The pre-0.9.5 JSON API endpoint, kept for the cronjob URLs already in use. It answers through
 * the REST routes of includes/rest-api.php with the old response shape and goes away in 1.0; the
 * Settings page shows the REST URLs to move to.
 *
 * @package Exports_And_Reports
 */

global $wpdb;

if ( ! is_object( $wpdb ) ) {
	ob_start();
	require_once '../../../wp-load.php';
	ob_end_clean();
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'is_plugin_active' ) || ! is_plugin_active( basename( __DIR__ ) . '/exports-and-reports.php' ) ) {
	wp_die( 'Exports and Reports plugin not activated' );
}

_deprecated_file( basename( __FILE__ ), '0.9.5', 'the REST API under exports-reports/v1 (the Settings page shows the URLs)' );

if ( ! headers_sent() ) {
	status_header( 200 );
	nocache_headers();
	header( 'Deprecation: true' );
	header( 'Link: <' . esc_url_raw( rest_url( 'exports-reports/v1/' ) ) . '>; rel="successor-version"' );
	header( 'X-Exports-Reports-Deprecated: api.php answers through the REST API and will be removed in 1.0; see the plugin Settings page for the URLs to move to' );
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token in the request is the credential; the REST route checks it.
$exports_reports_query = wp_unslash( $_GET );
// phpcs:enable

$exports_reports_token  = isset( $exports_reports_query['token'] ) ? sanitize_text_field( $exports_reports_query['token'] ) : '';
$exports_reports_report = isset( $exports_reports_query['report'] ) ? absint( $exports_reports_query['report'] ) : 0;

if ( '' === $exports_reports_token ) {
	wp_send_json_error( 'Invalid Token' );
} elseif ( 0 === $exports_reports_report ) {
	wp_send_json_error( 'Invalid Report' );
}

if ( isset( $exports_reports_query['action'] ) && 'json' === $exports_reports_query['action'] ) {
	$exports_reports_request = new WP_REST_Request( 'GET', sprintf( '/exports-reports/v1/reports/%d/data', $exports_reports_report ) );
	$exports_reports_request->set_query_params(
		array(
			'token' => $exports_reports_token,
			'full'  => ! empty( $exports_reports_query['full'] ),
			'pg'    => isset( $exports_reports_query['pg'] ) ? max( 1, absint( $exports_reports_query['pg'] ) ) : 1,
		)
	);
} else {
	if ( empty( $exports_reports_query['export_type'] ) ) {
		wp_send_json_error( 'Invalid Export Type' );
	}

	$exports_reports_request = new WP_REST_Request( 'GET', sprintf( '/exports-reports/v1/reports/%d/export', $exports_reports_report ) );
	$exports_reports_request->set_query_params(
		array(
			'token'    => $exports_reports_token,
			'type'     => strtolower( sanitize_key( $exports_reports_query['export_type'] ) ),
			'download' => ! empty( $exports_reports_query['exports_and_reports_download'] ),
		)
	);
}

$exports_reports_response = rest_do_request( $exports_reports_request );

if ( $exports_reports_response->is_error() ) {
	wp_send_json_error( $exports_reports_response->as_error()->get_error_message() );
}

$exports_reports_data = $exports_reports_response->get_data();

if ( ! empty( $exports_reports_data['download'] ) ) {
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	if ( ! exports_reports_send_export_file( (string) $exports_reports_data['file'] ) ) {
		wp_die( 'File not found.' );
	}

	exit;
}

if ( isset( $exports_reports_data['rows'] ) ) {
	wp_send_json_success( $exports_reports_data['rows'] );
}

wp_send_json_success(
	array(
		'export_file' => WP_ADMIN_UI_EXPORT_DIR . '/' . $exports_reports_data['file'],
		'message'     => 'Report exported',
	)
);
