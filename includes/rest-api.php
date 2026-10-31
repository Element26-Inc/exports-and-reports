<?php
/**
 * REST API: report data and exports for scripts and cronjobs.
 *
 * Routes under exports-reports/v1, answered either for a logged-in user who may view the report
 * (cookie and nonce, or an application password) or for the API token from the Settings page, sent
 * as "Authorization: Bearer <token>" or as the token query parameter.
 *
 *   GET  /reports/<id>/data?full=1|pg=N&limit=N   the rows (paged, or all of them)
 *   POST /reports/<id>/export?type=csv[&download=1]   write an export; with download=1 the file is the response
 *   GET  /reports/<id>/exports/<file>               download an export of that report
 *
 * @package Exports_And_Reports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'exports_reports_rest_routes' );
add_filter( 'rest_pre_serve_request', 'exports_reports_rest_serve_file', 10, 3 );

/**
 * Registers the routes.
 *
 * @since 0.9.5
 */
function exports_reports_rest_routes() {
	$id = array(
		'type'              => 'integer',
		'required'          => true,
		'sanitize_callback' => 'absint',
	);

	register_rest_route(
		'exports-reports/v1',
		'/reports/(?P<id>\d+)/data',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'exports_reports_rest_data',
			'permission_callback' => 'exports_reports_rest_permission',
			'args'                => array(
				'id'    => $id,
				'full'  => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'pg'    => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		)
	);

	register_rest_route(
		'exports-reports/v1',
		'/reports/(?P<id>\d+)/export',
		array(
			'methods'             => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ),
			'callback'            => 'exports_reports_rest_export',
			'permission_callback' => 'exports_reports_rest_permission',
			'args'                => array(
				'id'       => $id,
				'type'     => array(
					'type'    => 'string',
					'enum'    => array( 'csv', 'tsv', 'pipe', 'xlsx', 'xml', 'json', 'pdf' ),
					'default' => 'csv',
				),
				'download' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		)
	);

	register_rest_route(
		'exports-reports/v1',
		'/reports/(?P<id>\d+)/exports/(?P<file>[A-Za-z0-9._-]+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'exports_reports_rest_download',
			'permission_callback' => 'exports_reports_rest_permission',
			'args'                => array(
				'id'   => $id,
				'file' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_file_name',
				),
			),
		)
	);
}

/**
 * Whether the request may read the report: the API token, or a logged-in user the Reports menu
 * would show the report to.
 *
 * @since 0.9.5
 *
 * @param WP_REST_Request $request The request.
 * @return true|WP_Error
 */
function exports_reports_rest_permission( $request ) {
	$token_matches = exports_reports_rest_token_matches( $request );

	// A caller without a credential learns nothing about which ids exist.
	if ( ! $token_matches && ! is_user_logged_in() ) {
		return new WP_Error( 'exports_reports_unauthorized', 'Send the token or log in to read reports.', array( 'status' => 401 ) );
	}

	$report = exports_reports_get_report( (int) $request['id'] );
	$group  = $report ? exports_reports_get_group( (int) $report->group ) : null;

	if ( ! $report || ! $group ) {
		return new WP_Error( 'exports_reports_not_found', 'No report with that id.', array( 'status' => 404 ) );
	}

	if ( $token_matches || exports_reports_user_can_view_report( $report, $group ) ) {
		return true;
	}

	return new WP_Error( 'exports_reports_forbidden', 'You are not allowed to read this report.', array( 'status' => rest_authorization_required_code() ) );
}

/**
 * Whether the request carries the API token, in the Authorization header or the token parameter.
 *
 * @since 0.9.5
 *
 * @param WP_REST_Request $request The request.
 * @return bool
 */
function exports_reports_rest_token_matches( $request ) {
	$token = (string) get_option( 'exports_reports_token' );

	if ( '' === $token ) {
		return false;
	}

	$given = (string) $request->get_param( 'token' );
	$auth  = (string) $request->get_header( 'authorization' );

	if ( 0 === stripos( $auth, 'Bearer ' ) ) {
		$given = trim( substr( $auth, 7 ) );
	}

	return '' !== $given && hash_equals( $token, $given );
}

/**
 * The rows of a report, paged or all of them.
 *
 * @since 0.9.5
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function exports_reports_rest_data( $request ) {
	$report = exports_reports_get_report( (int) $request['id'] );

	if ( ! $report ) {
		return new WP_Error( 'exports_reports_not_found', 'No report with that id.', array( 'status' => 404 ) );
	}

	require_once dirname( __DIR__ ) . '/wp-admin-ui/class-exports-reports-admin-ui.php';

	$admin      = new Exports_Reports_Admin_UI( exports_reports_report_options( $report ) );
	$admin->api = true;
	$full       = (bool) $request['full'];

	if ( ! $full ) {
		$admin->page = max( 1, (int) $request['pg'] );

		if ( null !== $request['limit'] ) {
			$admin->limit = max( 1, (int) $request['limit'] );
		}
	}

	ob_start();
	$rows  = $admin->get_data( $full );
	$error = exports_reports_rest_ui_error( (string) ob_get_clean() );

	if ( '' !== $error || ! is_array( $rows ) ) {
		return new WP_Error( 'exports_reports_query_failed', '' !== $error ? $error : 'The report query failed.', array( 'status' => 500 ) );
	}

	$data = array(
		'report' => (int) $report->id,
		'name'   => $report->name,
		'rows'   => array_values( $rows ),
		'total'  => $full ? count( $rows ) : (int) $admin->total,
	);

	if ( ! $full ) {
		$data['page']  = (int) $admin->page;
		$data['limit'] = (int) $admin->limit;
	}

	if ( ! empty( $admin->sum_data ) ) {
		$data['totals'] = $admin->sum_data;
	}

	return rest_ensure_response( $data );
}

/**
 * Writes an export of the report and names the file; with download=1 the file is the response.
 *
 * @since 0.9.5
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function exports_reports_rest_export( $request ) {
	/** @var wpdb $wpdb */ global $wpdb;

	$report = exports_reports_get_report( (int) $request['id'] );

	if ( ! $report ) {
		return new WP_Error( 'exports_reports_not_found', 'No report with that id.', array( 'status' => 404 ) );
	}

	if ( 0 !== (int) $report->disable_export ) {
		return new WP_Error( 'exports_reports_export_disabled', 'Exports are disabled for this report.', array( 'status' => 403 ) );
	}

	require_once dirname( __DIR__ ) . '/wp-admin-ui/class-exports-reports-admin-ui.php';

	// A large export outlives the request's defaults, and a cronjob that disconnects still gets its file.
	set_time_limit( 6000 );
	ignore_user_abort( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- session setting for the export's connection.
	$wpdb->query( 'SET SESSION wait_timeout = 800' );

	$admin              = new Exports_Reports_Admin_UI( exports_reports_report_options( $report ) );
	$admin->api         = true;
	$admin->action      = 'export';
	$admin->export_type = (string) $request['type'];

	ob_start();
	$admin->export();
	$error = exports_reports_rest_ui_error( (string) ob_get_clean() );

	if ( '' !== $error || empty( $admin->exported_file ) ) {
		return new WP_Error( 'exports_reports_export_failed', '' !== $error ? $error : 'The export failed.', array( 'status' => 500 ) );
	}

	$file = (string) $admin->exported_file;

	return rest_ensure_response(
		array(
			'report'   => (int) $report->id,
			'name'     => $report->name,
			'type'     => (string) $request['type'],
			'file'     => $file,
			'url'      => rest_url( sprintf( 'exports-reports/v1/reports/%d/exports/%s', (int) $report->id, rawurlencode( $file ) ) ),
			'download' => (bool) $request['download'],
		)
	);
}

/**
 * Names an export of the report for download; the file itself is sent by exports_reports_rest_serve_file().
 *
 * @since 0.9.5
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function exports_reports_rest_download( $request ) {
	/** @var wpdb $wpdb */ global $wpdb;

	$file = basename( (string) $request['file'] );

	$sql = 'SELECT COUNT(*) FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'log` WHERE `report_id` = %d AND `filename` = %s';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own export log.
	$logged = (int) $wpdb->get_var( $wpdb->prepare( $sql, (int) $request['id'], $file ) );

	if ( 0 === $logged || ! is_file( WP_ADMIN_UI_EXPORT_DIR . '/' . $file ) ) {
		return new WP_Error( 'exports_reports_file_not_found', 'No such export of this report.', array( 'status' => 404 ) );
	}

	return rest_ensure_response(
		array(
			'report'   => (int) $request['id'],
			'file'     => $file,
			'download' => true,
		)
	);
}

/**
 * Sends the export file instead of the JSON when a response asks for it.
 *
 * @since 0.9.5
 *
 * @param bool             $served  Whether the request was served already.
 * @param WP_HTTP_Response $result  The response.
 * @param WP_REST_Request  $request The request.
 * @return bool
 */
function exports_reports_rest_serve_file( $served, $result, $request ) {
	if ( $served || 0 !== strpos( $request->get_route(), '/exports-reports/v1/reports/' ) || 200 !== $result->get_status() ) {
		return $served;
	}

	$data = $result->get_data();

	if ( ! is_array( $data ) || empty( $data['download'] ) || empty( $data['file'] ) ) {
		return $served;
	}

	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	if ( ! exports_reports_send_export_file( (string) $data['file'] ) ) {
		status_header( 404 );
		echo wp_json_encode(
			array(
				'code'    => 'exports_reports_file_not_found',
				'message' => 'No such export of this report.',
			)
		);
	}

	return true;
}

/**
 * The message of an error the admin UI printed, if it printed one.
 *
 * @since 0.9.5
 *
 * @param string $output What the admin UI echoed.
 * @return string The message, or an empty string.
 */
function exports_reports_rest_ui_error( $output ) {
	if ( preg_match( '/<div id="message" class="error fade"><p><strong>Error:<\/strong> (.*?)<\/p><\/div>/is', $output, $matches ) ) {
		return trim( wp_strip_all_tags( $matches[1] ) );
	}

	return '';
}
