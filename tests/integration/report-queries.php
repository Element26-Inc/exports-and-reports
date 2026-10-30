<?php
/**
 * Report queries through Exports_Reports_Admin_UI against a real database.
 *
 *   wp eval-file tests/integration/report-queries.php
 *
 * Creates a small table, runs report definitions of several shapes through get_data( true ), the
 * path the view and the exports share, and writes two CSV exports (streamed and buffered) of the
 * same report. One line per case; the exit code is non-zero when a case fails. The table and the
 * export files are removed at the end. CTEs need MariaDB 10.2 or MySQL 8; EXECUTE IMMEDIATE is
 * MariaDB only and is skipped elsewhere.
 *
 * @package Exports_And_Reports
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit;
}

// wp eval-file includes this script inside a function, so these are declared global: the helpers
// below and the query filter then work on the same variables as the top-level code.
global $wpdb, $er_failed, $er_errors;

if ( ! defined( 'EXPORTS_REPORTS_TBL' ) || ! defined( 'EXPORTS_REPORTS_DIR' ) ) {
	WP_CLI::error( 'exports-and-reports is not active' );
}

require_once EXPORTS_REPORTS_DIR . 'wp-admin-ui/class-exports-reports-admin-ui.php';

$er_table    = $wpdb->prefix . 'er_test_videos';
$er_failed   = 0;
$er_errors   = array();
$er_mariadb  = false !== stripos( (string) $wpdb->db_server_info(), 'mariadb' );
$er_rows     = 60;
$er_channels = array( 'alpha', 'beta', 'gamma' );

// Database errors from every statement a case runs, not only the last one.
add_filter(
	'query',
	static function ( $query ) {
		global $wpdb, $er_errors;
		if ( '' !== (string) $wpdb->last_error ) {
			$er_errors[] = $wpdb->last_error;
		}
		return $query;
	}
);

/**
 * Print one result line and count failures.
 *
 * @param string $name   The case.
 * @param bool   $passed Whether it passed.
 * @param string $detail Rows or the error.
 */
function er_report( $name, $passed, $detail ) {
	global $er_failed;
	if ( ! $passed ) {
		++$er_failed;
	}
	WP_CLI::log( sprintf( '%s: %s (%s)', $passed ? 'ok' : 'FAIL', $name, $detail ) );
}

/**
 * An admin UI instance for a report definition, with the options exports_reports_view() sets.
 *
 * @param string $sql     The report SQL.
 * @param string $name    The report name.
 * @param array  $options Options added to the defaults (columns, sql_count).
 * @return Exports_Reports_Admin_UI
 */
function er_admin( $sql, $name, $options = array() ) {
	return new Exports_Reports_Admin_UI(
		array_merge(
			array(
				'readonly'     => true,
				'export'       => true,
				'search'       => false,
				'default_none' => false,
				'sql'          => $sql,
				'item'         => 'item',
				'items'        => $name,
				'heading'      => array( 'manage' => 'View Report:' ),
				'report_id'    => 0,
			),
			$options
		)
	);
}

/**
 * Run a report definition the way an export does and return [ instance, rows, error ].
 *
 * @param string $sql     The report SQL.
 * @param string $name    The report name.
 * @param bool   $stream  Whether export_sv() should read the rows itself.
 * @param array  $options Options added to the defaults.
 * @param string $type    The export type.
 * @return array{0: Exports_Reports_Admin_UI, 1: array, 2: string}
 */
function er_run( $sql, $name, $stream = false, $options = array(), $type = 'csv' ) {
	global $wpdb, $er_errors;
	$er_errors            = array();
	$wpdb->last_error     = '';
	$admin                = er_admin( $sql, $name, $options );
	$admin->export_type   = $type;
	$admin->stream_export = $stream;
	ob_start();
	$admin->get_data( true );
	ob_end_clean();
	if ( '' !== (string) $wpdb->last_error ) {
		$er_errors[] = $wpdb->last_error;
	}
	$rows = is_array( $admin->full_data ) ? $admin->full_data : array();
	return array( $admin, $rows, implode( ' | ', array_unique( $er_errors ) ) );
}

/**
 * Write the delimited export of a prepared instance and return the file's contents.
 *
 * @param Exports_Reports_Admin_UI $admin The instance, get_data( true ) already run.
 * @return string The contents, '' when no file was written.
 */
function er_export_contents( $admin ) {
	ob_start();
	$file = $admin->export_sv();
	ob_end_clean();
	if ( ! is_string( $file ) ) {
		return '';
	}
	$path = WP_ADMIN_UI_EXPORT_DIR . '/' . $file;
	if ( ! is_readable( $path ) ) {
		return '';
	}
	$contents = (string) file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
	wp_delete_file( $path );
	return $contents;
}

/**
 * Custom display callback for the test.
 *
 * @param mixed $value The value.
 * @return string
 */
function er_shout( $value ) {
	return strtoupper( (string) $value );
}

// --- the table ---
// --- the plugin's tables carry the site's character set and collation ---
$er_collations = array();
foreach ( array( 'groups', 'reports', 'log' ) as $er_suffix ) {
	$er_collations[ $er_suffix ] = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->prefix . EXPORTS_REPORTS_TBL . $er_suffix ) );
}
er_report(
	'plugin tables created with the site collation',
	3 === count( array_unique( $er_collations ) ) ? false : array( $wpdb->collate ) === array_values( array_unique( $er_collations ) ),
	implode( ', ', $er_collations ) . ' (site: ' . $wpdb->collate . ')'
);

// --- an install from before 0.9.5 is converted on upgrade ---
$er_log_table = $wpdb->prefix . EXPORTS_REPORTS_TBL . 'log';
$wpdb->query( "ALTER TABLE `{$er_log_table}` CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$er_converted = exports_reports_convert_tables_charset();
$er_after     = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $er_log_table ) );
er_report(
	'a three-byte utf8 table is converted on upgrade',
	array( $er_log_table ) === $er_converted && $wpdb->collate === $er_after,
	'converted ' . implode( ', ', $er_converted ) . '; now ' . $er_after
);

$wpdb->query( "DROP TABLE IF EXISTS `{$er_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "CREATE TABLE `{$er_table}` ( `id` INT NOT NULL AUTO_INCREMENT, `channel` VARCHAR(32) NOT NULL, `title` VARCHAR(191) NOT NULL, `revenue` DECIMAL(10,2) NOT NULL, `published` DATE NOT NULL, PRIMARY KEY (`id`) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$er_over_fifty = 0;
for ( $i = 1; $i <= $er_rows; $i++ ) {
	$er_title   = 'Video ' . $i;
	$er_revenue = round( $i * 1.5, 2 );
	if ( 7 === $i ) {
		$er_title = '=1+1';
	} elseif ( 8 === $i ) {
		$er_title = 'Say "hi", pause; go';
	} elseif ( 9 === $i ) {
		$er_revenue = -13.5;
	} elseif ( 10 === $i ) {
		$er_title = "Two\nlines";
	}
	if ( $er_revenue > 50 ) {
		++$er_over_fifty;
	}
	$wpdb->insert(
		$er_table,
		array(
			'channel'   => $er_channels[ $i % 3 ],
			'title'     => $er_title,
			'revenue'   => $er_revenue,
			'published' => gmdate( 'Y-m-d', strtotime( '-' . $i . ' days' ) ),
		)
	);
}
if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$er_table}`" ) !== $er_rows ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	WP_CLI::error( 'could not seed ' . $er_table );
}

// --- a plain SELECT ---
list( , $rows, $er_error ) = er_run( "SELECT id, channel, title, revenue FROM `{$er_table}` ORDER BY id", 'Plain' );
er_report( 'plain SELECT', '' === $er_error && count( $rows ) === $er_rows && array_keys( $rows[0] ) === array( 'id', 'channel', 'title', 'revenue' ), '' !== $er_error ? $er_error : count( $rows ) . ' rows' );

// --- a common table expression ---
list( , $rows, $er_error ) = er_run( "WITH per_channel AS ( SELECT channel, SUM(revenue) AS total FROM `{$er_table}` GROUP BY channel ) SELECT channel, total FROM per_channel ORDER BY channel", 'CTE' );
er_report( 'CTE', '' === $er_error && 3 === count( $rows ) && 'alpha' === $rows[0]['channel'], '' !== $er_error ? $er_error : count( $rows ) . ' rows' );

// --- a dynamic statement (MariaDB) with the LIMIT placeholder inside it ---
if ( $er_mariadb ) {
	list( , $rows, $er_error ) = er_run( "EXECUTE IMMEDIATE CONCAT('SELECT channel, COUNT(*) AS videos FROM `{$er_table}` GROUP BY channel ORDER BY channel LIMIT %%LIMIT%%')", 'Dynamic' );
	er_report( 'EXECUTE IMMEDIATE with LIMIT %%LIMIT%%', '' === $er_error && 3 === count( $rows ) && 20 === (int) $rows[0]['videos'], '' !== $er_error ? $er_error : count( $rows ) . ' rows' );
} else {
	WP_CLI::log( 'skip: EXECUTE IMMEDIATE (not MariaDB)' );
}

// --- tab-indented SQL: the placeholder removal must not depend on spaces ---
list( , $rows, $er_error ) = er_run( "SELECT id, title\n\tFROM `{$er_table}`\n\tORDER BY id\n\tLIMIT %%LIMIT%%", 'Tabs' );
er_report( 'tab-indented LIMIT %%LIMIT%%', '' === $er_error && count( $rows ) === $er_rows, '' !== $er_error ? $er_error : count( $rows ) . ' rows' );

// --- several statements, run through the wp_admin_ui_get_data_sql filter by site code ---
// The plugin runs one statement; a site that needs variables or temporary tables splits the report
// itself and runs the preparation on wpdb's connection, which this callback does for the test.
add_filter(
	'wp_admin_ui_get_data_sql',
	static function ( $args ) {
		global $wpdb;
		$sql = isset( $args[1] ) ? (string) $args[1] : '';
		// Split on ";" followed by whitespace, outside single-quoted strings.
		$statements = preg_split( "/;\\s+(?=(?:[^']*'[^']*')*[^']*$)/", $sql, -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $statements ) < 2 ) {
			return $args;
		}
		$last = trim( array_pop( $statements ) );
		if ( mysqli_multi_query( $wpdb->dbh, implode( '; ', $statements ) ) ) { // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_multi_query
			do {
				$result = mysqli_store_result( $wpdb->dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_store_result
				if ( $result instanceof mysqli_result ) {
					mysqli_free_result( $result ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_free_result
				}
				if ( ! mysqli_more_results( $wpdb->dbh ) ) { // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_more_results
					break;
				}
			} while ( mysqli_next_result( $wpdb->dbh ) ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_next_result
		}
		if ( '' !== mysqli_error( $wpdb->dbh ) ) { // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_error
			$wpdb->last_error = 'Preparation statement failed: ' . mysqli_error( $wpdb->dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_error
			$args[1]          = false;
			return $args;
		}
		$args[1] = $last;
		return $args;
	}
);
list( , $rows, $er_error ) = er_run( "SET @cutoff = 50;\nCREATE TEMPORARY TABLE er_test_top AS SELECT * FROM `{$er_table}` WHERE revenue > @cutoff;\nSELECT id, title, revenue FROM er_test_top ORDER BY revenue DESC", 'Prepared' );
er_report( 'variables and a temporary table through the filter', '' === $er_error && count( $rows ) === $er_over_fifty, '' !== $er_error ? $er_error : count( $rows ) . ' rows' );
$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS er_test_top' );

// --- the CSV export, streamed and buffered, byte for byte the same ---
wp_mkdir_p( WP_ADMIN_UI_EXPORT_DIR );
$er_csv = array();
$er_modes = array(
	'streamed' => true,
	'buffered' => false,
);
foreach ( $er_modes as $er_mode => $stream ) {
	list( $admin, , $er_error ) = er_run( "SELECT id, channel, title, revenue FROM `{$er_table}` ORDER BY id", 'Export ' . $er_mode, $stream );
	ob_start();
	$file = $admin->export_sv();
	ob_end_clean();
	$er_path = WP_ADMIN_UI_EXPORT_DIR . '/' . $file;
	if ( '' !== $er_error || ! is_string( $file ) || ! is_readable( $er_path ) ) {
		er_report( 'CSV export (' . $er_mode . ')', false, '' !== $er_error ? $er_error : 'no file written' );
		continue;
	}
	$er_csv[ $er_mode ] = (string) file_get_contents( $er_path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
	wp_delete_file( $er_path );
	$lines = explode( "\n", rtrim( $er_csv[ $er_mode ], "\n" ) );
	// Every value quoted, the labels ucwords() of the column names, the formula-like title wrapped in a
	// string formula, the title with quotes, a comma and a semicolon intact, a negative amount left a number,
	// a line break replaced by a space.
	$check = ( 0 === strpos( $er_csv[ $er_mode ], "\xEF\xBB\xBF" ) )
		&& count( $lines ) === $er_rows + 1
		&& "\xEF\xBB\xBF" . '"Id","Channel","Title","Revenue"' === $lines[0]
		&& '"7","beta","="""" & ""=1+1""","10.50"' === $lines[7]
		&& '"8","gamma","Say ""hi"", pause; go","12.00"' === $lines[8]
		&& '"9","alpha","Video 9","-13.50"' === $lines[9]
		&& '"10","beta","Two lines","15.00"' === $lines[10];
	er_report( 'CSV export (' . $er_mode . ')', $check, $check ? ( count( $lines ) - 1 ) . ' rows, BOM, quoted, formula neutralised' : 'unexpected content: ' . substr( $er_csv[ $er_mode ], 0, 120 ) . ' ... ' . ( isset( $lines[7], $lines[8] ) ? $lines[7] . ' / ' . $lines[8] : count( $lines ) . ' lines' ) );
}
if ( isset( $er_csv['streamed'], $er_csv['buffered'] ) ) {
	er_report( 'streamed and buffered exports identical', $er_csv['streamed'] === $er_csv['buffered'], strlen( $er_csv['streamed'] ) . ' bytes' );
}

// --- the count query through its filter (the view path runs it for the totals row) ---
$er_count_seen = '';
$er_count_cb   = static function ( $args ) use ( &$er_count_seen ) {
	$er_count_seen = isset( $args[1] ) ? (string) $args[1] : '';
	$args[1]       = 'SELECT 42 AS revenue';
	return $args;
};
add_filter( 'wp_admin_ui_get_data_sql_count', $er_count_cb );
$er_admin = er_admin( "SELECT id, revenue FROM `{$er_table}` ORDER BY id", 'Count', array( 'sql_count' => "SELECT SUM(revenue) AS revenue FROM `{$er_table}`" ) );
ob_start();
$er_admin->get_data( false );
ob_end_clean();
remove_filter( 'wp_admin_ui_get_data_sql_count', $er_count_cb );
er_report(
	'count query through the filter',
	false !== strpos( $er_count_seen, 'SUM(revenue)' ) && isset( $er_admin->sum_data[0]['revenue'] ) && 42 === (int) $er_admin->sum_data[0]['revenue'],
	'' !== $er_count_seen ? 'sum_data ' . wp_json_encode( $er_admin->sum_data ) : 'filter not called'
);

// --- a declined query: the callback returns false once it has reported why ---
$er_decline = static function ( $args ) {
	global $wpdb;
	$wpdb->last_error = 'declined by the test';
	$args[1]          = false;
	return $args;
};
add_filter( 'wp_admin_ui_get_data_sql', $er_decline );
$er_admin              = er_admin( "SELECT id FROM `{$er_table}`", 'Declined' );
$er_admin->export_type = 'csv';
$er_files              = (array) glob( WP_ADMIN_UI_EXPORT_DIR . '/*' );
ob_start();
$er_data   = $er_admin->get_data( true );
$er_export = $er_admin->export();
ob_end_clean();
remove_filter( 'wp_admin_ui_get_data_sql', $er_decline );
$er_new = array_diff( (array) glob( WP_ADMIN_UI_EXPORT_DIR . '/*' ), $er_files );
er_report(
	'declined query runs nothing and writes no export',
	false === $er_data && false === $er_export && empty( $er_new ) && empty( $er_admin->full_data ),
	'get_data ' . var_export( $er_data, true ) . ', export ' . var_export( $er_export, true ) . ', ' . count( $er_new ) . ' new files'
);

// --- streaming needs plain columns: a related column or a custom display callback keeps the buffered path ---
$er_columns = array(
	'id'      => array( 'label' => 'Id' ),
	'channel' => array( 'label' => 'Channel' ),
);
$er_plain   = er_admin( "SELECT id, channel FROM `{$er_table}` WHERE id = 1", 'Plain columns', array( 'columns' => $er_columns ) );
$er_columns['channel']['custom_display'] = 'er_shout';
$er_custom             = er_admin( "SELECT id, channel FROM `{$er_table}` WHERE id = 1", 'Custom display', array( 'columns' => $er_columns ) );
$er_columns['channel'] = array(
	'label'         => 'Channel',
	'type'          => 'related',
	'related'       => $er_table,
	'related_id'    => 'channel',
	'related_field' => 'channel',
);
$er_related = er_admin( "SELECT id, channel FROM `{$er_table}` WHERE id = 1", 'Related column', array( 'columns' => $er_columns ) );
er_report(
	'streaming only with plain columns',
	$er_plain->can_stream_export() && ! $er_custom->can_stream_export() && ! $er_related->can_stream_export(),
	'plain ' . var_export( $er_plain->can_stream_export(), true ) . ', custom display ' . var_export( $er_custom->can_stream_export(), true ) . ', related ' . var_export( $er_related->can_stream_export(), true )
);
$er_custom->export_type = 'csv';
$er_files               = (array) glob( WP_ADMIN_UI_EXPORT_DIR . '/*' );
ob_start();
$er_custom->export();
ob_end_clean();
$er_new = array_values( array_diff( (array) glob( WP_ADMIN_UI_EXPORT_DIR . '/*' ), $er_files, array( WP_ADMIN_UI_EXPORT_DIR . '/index.php' ) ) );
$er_out = 1 === count( $er_new ) ? (string) file_get_contents( $er_new[0] ) : ''; // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
foreach ( $er_new as $er_path ) {
	wp_delete_file( $er_path );
}
$er_want   = "\xEF\xBB\xBF\"Id\",\"Channel\"\n\"1\",\"BETA\"\n";
$er_detail = 'content ' . substr( $er_out, 3, 60 );
if ( 1 !== count( $er_new ) ) {
	$er_detail = count( $er_new ) . ' files';
}
er_report( 'custom display column exports through the buffered path', $er_want === $er_out, $er_detail );

// --- the export directory refuses direct requests, and the download function serves only exports ---
$er_guards = file_exists( WP_ADMIN_UI_EXPORT_DIR . '/.htaccess' ) && file_exists( WP_ADMIN_UI_EXPORT_DIR . '/index.php' );
er_report( 'export directory carries .htaccess and index.php after an export', $er_guards, $er_guards ? 'both present' : 'missing' );

file_put_contents( WP_ADMIN_UI_EXPORT_DIR . '/download_test.csv', "a,b\n1,2\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
ob_start();
$er_sent = exports_reports_send_export_file( 'download_test.csv' );
$er_body = ob_get_clean();
ob_start();
$er_refused = array(
	exports_reports_send_export_file( '../index.php' ),
	exports_reports_send_export_file( 'index.php' ),
	exports_reports_send_export_file( '.htaccess' ),
	exports_reports_send_export_file( 'missing.csv' ),
);
ob_end_clean();
wp_delete_file( WP_ADMIN_UI_EXPORT_DIR . '/download_test.csv' );
er_report(
	'download function streams an export and refuses anything else',
	true === $er_sent && "a,b\n1,2\n" === $er_body && array( false, false, false, false ) === $er_refused,
	'sent ' . var_export( $er_sent, true ) . ', body ' . str_replace( "\n", '\\n', $er_body ) . ', refused ' . wp_json_encode( $er_refused )
);

// --- TSV: the same writer with a tab ---
list( $er_admin, , $er_error ) = er_run( "SELECT id, channel FROM `{$er_table}` WHERE id <= 2 ORDER BY id", 'Export tsv', true, array(), 'tsv' );
$er_out                       = er_export_contents( $er_admin );
$er_want                      = "\xEF\xBB\xBF\"Id\"\t\"Channel\"\n\"1\"\t\"beta\"\n\"2\"\t\"gamma\"\n";
er_report(
	'TSV export',
	'' === $er_error && $er_want === $er_out,
	'' !== $er_error ? $er_error : 'content ' . str_replace( "\t", '<tab>', substr( $er_out, 3, 60 ) )
);

// --- the formula wrapping can be turned off ---
add_filter( 'wp_admin_ui_export_escape_formulas', '__return_false' );
list( $er_admin, , $er_error ) = er_run( "SELECT id, title FROM `{$er_table}` WHERE id = 7", 'Formulas kept', true );
$er_out                       = er_export_contents( $er_admin );
remove_filter( 'wp_admin_ui_export_escape_formulas', '__return_false' );
$er_want = "\xEF\xBB\xBF\"Id\",\"Title\"\n\"7\",\"=1+1\"\n";
er_report(
	'formula wrapping off through the filter',
	'' === $er_error && $er_want === $er_out,
	'' !== $er_error ? $er_error : 'content ' . substr( $er_out, 3, 60 )
);

// --- REST API: data and exports for a report, with the token or as a user who may view it ---
$er_now = current_time( 'mysql' );
$wpdb->insert(
	$wpdb->prefix . EXPORTS_REPORTS_TBL . 'groups',
	array(
		'name'        => 'API test',
		'disabled'    => 0,
		'role_access' => '',
		'weight'      => 0,
		'created'     => $er_now,
		'updated'     => $er_now,
	)
);
$er_group_id = (int) $wpdb->insert_id;
$wpdb->insert(
	$wpdb->prefix . EXPORTS_REPORTS_TBL . 'reports',
	array(
		'name'             => 'API report',
		'group'            => $er_group_id,
		'disabled'         => 0,
		'disable_export'   => 0,
		'default_none'     => 0,
		'role_access'      => '',
		'sql_query'        => "SELECT id, channel, title, revenue FROM `{$er_table}` ORDER BY id LIMIT %%LIMIT%%",
		'sql_query_count'  => '',
		'page_orientation' => 'P',
		'field_data'       => '',
		'weight'           => 0,
		'created'          => $er_now,
		'updated'          => $er_now,
	)
);
$er_report_id = (int) $wpdb->insert_id;
update_option( 'exports_reports_token', 'er-test-token' );

/**
 * Dispatch a REST request.
 *
 * @param string $method  GET or POST.
 * @param string $route   The route.
 * @param array  $params  Request parameters.
 * @param array  $headers Request headers.
 * @return WP_REST_Response
 */
function er_rest( $method, $route, $params = array(), $headers = array() ) {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	foreach ( $headers as $key => $value ) {
		$request->set_header( $key, $value );
	}
	ob_start();
	$response = rest_do_request( $request );
	ob_end_clean();
	return $response;
}

$er_route = sprintf( '/exports-reports/v1/reports/%d', $er_report_id );
wp_set_current_user( 0 );

$er_anon  = er_rest( 'GET', $er_route . '/data' );
$er_wrong = er_rest( 'GET', $er_route . '/data', array( 'token' => 'nope' ) );
er_report(
	'REST refuses the report without the token or a user',
	401 === $er_anon->get_status() && 401 === $er_wrong->get_status(),
	'status ' . $er_anon->get_status() . ' / ' . $er_wrong->get_status()
);

$er_full = er_rest(
	'GET',
	$er_route . '/data',
	array(
		'token' => 'er-test-token',
		'full'  => 1,
	)
);
$er_body = $er_full->get_data();
er_report(
	'REST full data with the token parameter',
	200 === $er_full->get_status() && isset( $er_body['rows'] ) && count( $er_body['rows'] ) === $er_rows && $er_body['total'] === $er_rows,
	'status ' . $er_full->get_status() . ', ' . ( isset( $er_body['rows'] ) ? count( $er_body['rows'] ) . ' rows' : wp_json_encode( $er_body ) )
);

$er_page = er_rest(
	'GET',
	$er_route . '/data',
	array(
		'pg'    => 2,
		'limit' => 25,
	),
	array( 'Authorization' => 'Bearer er-test-token' )
);
$er_body = $er_page->get_data();
er_report(
	'REST paged data with the bearer token',
	200 === $er_page->get_status() && isset( $er_body['rows'][0]['id'] ) && 25 === count( $er_body['rows'] ) && 26 === (int) $er_body['rows'][0]['id'] && $er_body['total'] === $er_rows && 2 === $er_body['page'],
	'status ' . $er_page->get_status() . ', ' . ( isset( $er_body['rows'][0]['id'] ) ? count( $er_body['rows'] ) . ' rows from id ' . $er_body['rows'][0]['id'] . ' of ' . $er_body['total'] : wp_json_encode( $er_body ) )
);

$er_subscriber = wp_insert_user(
	array(
		'user_login' => 'er_subscriber',
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	)
);
wp_set_current_user( (int) $er_subscriber );
$er_as_subscriber = er_rest( 'GET', $er_route . '/data' );
wp_set_current_user( 1 );
$er_as_admin = er_rest( 'GET', $er_route . '/data', array( 'full' => 1 ) );
wp_set_current_user( 0 );
er_report(
	'REST follows the report access rules for users',
	403 === $er_as_subscriber->get_status() && 200 === $er_as_admin->get_status(),
	'subscriber ' . $er_as_subscriber->get_status() . ', administrator ' . $er_as_admin->get_status()
);

$er_export = er_rest( 'POST', $er_route . '/export', array( 'type' => 'csv' ), array( 'Authorization' => 'Bearer er-test-token' ) );
$er_body   = $er_export->get_data();
$er_file   = isset( $er_body['file'] ) ? (string) $er_body['file'] : '';
$er_logged = '' === $er_file ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . EXPORTS_REPORTS_TBL . 'log` WHERE report_id = %d AND filename = %s', $er_report_id, $er_file ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
er_report(
	'REST export writes the file, logs it and names its download URL',
	200 === $er_export->get_status() && '' !== $er_file && '.csv' === substr( $er_file, -4 ) && file_exists( WP_ADMIN_UI_EXPORT_DIR . '/' . $er_file ) && 1 === $er_logged && isset( $er_body['url'] ) && false !== strpos( $er_body['url'], $er_route . '/exports/' ),
	'status ' . $er_export->get_status() . ', ' . ( '' !== $er_file ? $er_file . ', logged ' . $er_logged : wp_json_encode( $er_body ) )
);

// The serving filter streams the file itself after closing every output buffer, so the test checks its
// decision on the two kinds of response and reads the file through the function it calls.
$er_download = er_rest( 'GET', $er_route . '/exports/' . $er_file, array( 'token' => 'er-test-token' ) );
$er_body     = $er_download->get_data();
$er_request  = new WP_REST_Request( 'GET', $er_route . '/data' );
$er_passed   = exports_reports_rest_serve_file( false, $er_full, $er_request );
$er_other    = er_rest( 'GET', $er_route . '/exports/other_report.csv', array( 'token' => 'er-test-token' ) );
ob_start();
$er_sent_ok = exports_reports_send_export_file( $er_file );
$er_sent    = ob_get_clean();
$er_on_disk = '' === $er_file ? '' : (string) file_get_contents( WP_ADMIN_UI_EXPORT_DIR . '/' . $er_file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
er_report(
	'REST download names the logged export for serving and refuses other names',
	200 === $er_download->get_status() && isset( $er_body['download'], $er_body['file'] ) && true === $er_body['download'] && $er_file === $er_body['file'] && false === $er_passed && true === $er_sent_ok && '' !== $er_sent && $er_sent === $er_on_disk && 404 === $er_other->get_status(),
	'status ' . $er_download->get_status() . ', download ' . wp_json_encode( isset( $er_body['download'] ) ? $er_body['download'] : null ) . ', data response left alone ' . var_export( false === $er_passed, true ) . ', ' . strlen( $er_sent ) . ' bytes, other ' . $er_other->get_status()
);

$wpdb->update( $wpdb->prefix . EXPORTS_REPORTS_TBL . 'reports', array( 'disabled' => 1 ), array( 'id' => $er_report_id ) );
$er_disabled = er_rest( 'GET', $er_route . '/data', array( 'token' => 'er-test-token' ) );
er_report( 'REST hides a disabled report', 404 === $er_disabled->get_status(), 'status ' . $er_disabled->get_status() );
wp_delete_user( (int) $er_subscriber );

// --- the plugin's tables carry the site's character set and collation ---
$er_collations = array();
foreach ( array( 'groups', 'reports', 'log' ) as $er_suffix ) {
	$er_collations[ $er_suffix ] = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->prefix . EXPORTS_REPORTS_TBL . $er_suffix ) );
}
er_report(
	'plugin tables created with the site collation',
	3 === count( array_unique( $er_collations ) ) ? false : array( $wpdb->collate ) === array_values( array_unique( $er_collations ) ),
	implode( ', ', $er_collations ) . ' (site: ' . $wpdb->collate . ')'
);

// --- an install from before 0.9.5 is converted on upgrade ---
$er_log_table = $wpdb->prefix . EXPORTS_REPORTS_TBL . 'log';
$wpdb->query( "ALTER TABLE `{$er_log_table}` CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$er_converted = exports_reports_convert_tables_charset();
$er_after     = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $er_log_table ) );
er_report(
	'a three-byte utf8 table is converted on upgrade',
	array( $er_log_table ) === $er_converted && $wpdb->collate === $er_after,
	'converted ' . implode( ', ', $er_converted ) . '; now ' . $er_after
);

// --- the report form's field editor renders the saved fields and saves what it posts ---
$er_form_row      = new stdClass();
$er_form_row->row = array(
	'field_data' => wp_json_encode(
		array(
			array(
				'name'        => 'revenue',
				'type'        => 'decimal',
				'label'       => 'Revenue',
				'hide_report' => '1',
				'search'      => '1',
				'filter'      => '1',
				'width'       => '20',
			),
			array(
				'name'    => 'channel',
				'type'    => 'related',
				'related' => 'wp_users',
			),
		)
	),
);
ob_start();
exports_reports_report_field( 'field_data', array(), $er_form_row );
$er_form_html = (string) ob_get_clean();
$er_form_ok   = 3 === substr_count( $er_form_html, 'class="er-field"' )
	&& 1 === preg_match( '#name="field_type\[0\]".*?<option value="decimal" selected#s', $er_form_html )
	&& 1 === preg_match( '#name="field_type\[1\]".*?<option value="related" selected#s', $er_form_html )
	&& 1 === preg_match( '#type="checkbox" name="field_hide_report\[0\]" value="1" checked#', $er_form_html )
	&& 1 === preg_match( '#type="checkbox" name="field_search\[0\]" value="0" />#', $er_form_html )
	&& 1 === preg_match( '#type="checkbox" name="field_search\[1\]" value="0" checked#', $er_form_html )
	&& 1 === preg_match( '#name="width\[0\]" value="20"#', $er_form_html )
	&& false !== strpos( $er_form_html, 'name="field_name[__i__]"' );
er_report( 'report field editor renders the saved fields and a template', $er_form_ok, strlen( $er_form_html ) . ' bytes, ' . substr_count( $er_form_html, 'class="er-field"' ) . ' rows' );

$_POST = array(
	'field_name'        => array(
		3 => 'revenue',
		7 => 'channel',
		9 => '',
	),
	'field_type'        => array(
		3 => 'decimal',
		7 => 'text',
	),
	'field_hide_report' => array(
		3 => '1',
		7 => '0',
	),
	'field_search'      => array(
		3 => '1',
		7 => '0',
	),
	'width'             => array( 3 => '20' ),
);
$er_saved = json_decode( exports_reports_report_field_save( '', 'field_data', array(), $er_form_row ), true );
$_POST    = array();
er_report(
	'report field editor saves the posted rows in order',
	is_array( $er_saved ) && 2 === count( $er_saved ) && 'revenue' === $er_saved[0]['name'] && 'decimal' === $er_saved[0]['type'] && 1 === $er_saved[0]['hide_report'] && 1 === $er_saved[0]['search'] && '20' === $er_saved[0]['width'] && 'channel' === $er_saved[1]['name'] && 0 === $er_saved[1]['search'],
	is_array( $er_saved ) ? count( $er_saved ) . ' fields: ' . wp_json_encode( array_map( static fn( $f ) => $f['name'] . ':' . $f['type'] . ':' . $f['search'], $er_saved ) ) : 'not an array'
);

$wpdb->query( "DROP TABLE IF EXISTS `{$er_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// --- uninstall removes the tables, the options, the cleanup event and the export files (last: nothing runs after it) ---
$er_reports_table = $wpdb->prefix . EXPORTS_REPORTS_TBL . 'reports';
wp_mkdir_p( WP_ADMIN_UI_EXPORT_DIR );
file_put_contents( WP_ADMIN_UI_EXPORT_DIR . '/leftover.csv', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
define( 'WP_UNINSTALL_PLUGIN', true );
include EXPORTS_REPORTS_DIR . 'uninstall.php';
$er_table_left = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $er_reports_table ) );
er_report(
	'uninstall removes tables, options, the cleanup event and export files',
	null === $er_table_left && false === get_option( 'exports_reports_version' ) && false === get_option( 'exports_reports_token' ) && false === wp_next_scheduled( 'exports_reports_cleanup' ) && ! file_exists( WP_ADMIN_UI_EXPORT_DIR . '/leftover.csv' ),
	'table ' . ( null === $er_table_left ? 'gone' : 'present' ) . ', version option ' . var_export( get_option( 'exports_reports_version' ), true ) . ', event ' . var_export( wp_next_scheduled( 'exports_reports_cleanup' ), true ) . ', file ' . ( file_exists( WP_ADMIN_UI_EXPORT_DIR . '/leftover.csv' ) ? 'present' : 'gone' )
);

if ( $er_failed > 0 ) {
	WP_CLI::halt( 1 );
}
WP_CLI::success( 'report queries pass' );
