<?php
/**
 * Report queries through Exports_Reports_Admin_UI against a real database.
 *
 *   wp eval-file tests/integration/report-queries.php
 *
 * Creates a small table and runs the cases tests/integration/README.md lists: report definitions of
 * several shapes through get_data( true ), the path the view and the exports share; the export
 * writers and the download paths; the REST routes; the request handling; the uninstall. One line
 * per case; the exit code is non-zero when a case fails. The table and the export files are removed
 * at the end. CTEs need MariaDB 10.2 or MySQL 8; EXECUTE IMMEDIATE is MariaDB only and is skipped
 * elsewhere.
 *
 * @package Exports_And_Reports
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit;
}

// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- the failure details of a test script.

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
	$contents = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the test wrote
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
$er_csv   = array();
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
	$er_csv[ $er_mode ] = (string) file_get_contents( $er_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the test wrote
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

// --- the page view of a report with a totals query still counts every row, not only the page ---
$_GET['limit'] = '2';
$er_paged      = er_admin( "SELECT id, revenue FROM `{$er_table}` ORDER BY id", 'Paged totals', array( 'sql_count' => "SELECT SUM(revenue) AS revenue FROM `{$er_table}`" ) );
ob_start();
$er_paged->get_data( false );
ob_end_clean();
unset( $_GET['limit'] );
$er_rows_in_table = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$er_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
er_report(
	'paged view with a totals query counts every row',
	2 < $er_rows_in_table && $er_rows_in_table === (int) $er_paged->total && 2 === count( $er_paged->data ) && isset( $er_paged->sum_data[0]['revenue'] ),
	'total ' . $er_paged->total . ' of ' . $er_rows_in_table . ' rows, ' . count( $er_paged->data ) . ' on the page, sum_data ' . wp_json_encode( $er_paged->sum_data )
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
$_GET                  = array( '_wpnonce' => wp_create_nonce( 'wp-admin-ui-export-request' ) );
ob_start();
$er_data   = $er_admin->get_data( true );
$er_export = $er_admin->export();
ob_end_clean();
$_GET = array();
remove_filter( 'wp_admin_ui_get_data_sql', $er_decline );
$er_new = array_diff( (array) glob( WP_ADMIN_UI_EXPORT_DIR . '/*' ), $er_files );
er_report(
	'declined query runs nothing and writes no export',
	false === $er_data && false === $er_export && empty( $er_new ) && empty( $er_admin->full_data ),
	'get_data ' . var_export( $er_data, true ) . ', export ' . var_export( $er_export, true ) . ', ' . count( $er_new ) . ' new files'
);

// --- streaming needs plain columns: a related column or a custom display callback keeps the buffered path ---
$er_columns                              = array(
	'id'      => array( 'label' => 'Id' ),
	'channel' => array( 'label' => 'Channel' ),
);
$er_plain                                = er_admin( "SELECT id, channel FROM `{$er_table}` WHERE id = 1", 'Plain columns', array( 'columns' => $er_columns ) );
$er_columns['channel']['custom_display'] = 'er_shout';
$er_custom                               = er_admin( "SELECT id, channel FROM `{$er_table}` WHERE id = 1", 'Custom display', array( 'columns' => $er_columns ) );
$er_columns['channel']                   = array(
	'label'         => 'Channel',
	'type'          => 'related',
	'related'       => $er_table,
	'related_id'    => 'channel',
	'related_field' => 'channel',
);
$er_related                              = er_admin( "SELECT id, channel FROM `{$er_table}` WHERE id = 1", 'Related column', array( 'columns' => $er_columns ) );
er_report(
	'streaming only with plain columns',
	$er_plain->can_stream_export() && ! $er_custom->can_stream_export() && ! $er_related->can_stream_export(),
	'plain ' . var_export( $er_plain->can_stream_export(), true ) . ', custom display ' . var_export( $er_custom->can_stream_export(), true ) . ', related ' . var_export( $er_related->can_stream_export(), true )
);
$er_custom->export_type = 'csv';
$er_files               = (array) glob( WP_ADMIN_UI_EXPORT_DIR . '/*' );
$_GET                   = array( '_wpnonce' => wp_create_nonce( 'wp-admin-ui-export-request' ) );
ob_start();
$er_custom->export();
ob_end_clean();
$_GET   = array();
$er_new = array_values( array_diff( (array) glob( WP_ADMIN_UI_EXPORT_DIR . '/*' ), $er_files, array( WP_ADMIN_UI_EXPORT_DIR . '/index.php' ) ) );
$er_out = 1 === count( $er_new ) ? (string) file_get_contents( $er_new[0] ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the test wrote
foreach ( $er_new as $er_path ) {
	wp_delete_file( $er_path );
}
$er_want   = "\xEF\xBB\xBF\"Id\",\"Channel\"\n\"1\",\"BETA\"\n";
$er_detail = 'content ' . substr( $er_out, 3, 60 );
if ( 1 !== count( $er_new ) ) {
	$er_detail = count( $er_new ) . ' files';
}
er_report( 'custom display column exports through the buffered path', $er_want === $er_out, $er_detail );

// --- a display function PHP itself defines is not called; a plugin's is; the filter has the last word ---
$er_columns['channel'] = array(
	'label'          => 'Channel',
	'custom_display' => 'strtoupper',
);
$er_internal           = er_admin( "SELECT id, channel FROM `{$er_table}` WHERE id = 1", 'Internal callback', array( 'columns' => $er_columns ) );
$er_allow_strtoupper   = static function ( $args ) {
	if ( 'strtoupper' === $args[2] ) {
		$args[1] = true;
	}
	return $args;
};
add_filter( 'wp_admin_ui_allowed_callback', $er_allow_strtoupper );
$er_internal_allowed = er_admin( "SELECT id, channel FROM `{$er_table}` WHERE id = 1", 'Internal callback allowed', array( 'columns' => $er_columns ) );
remove_filter( 'wp_admin_ui_allowed_callback', $er_allow_strtoupper );
er_report(
	'display functions: a plugin function is called, a PHP function is not unless a filter allows it',
	$er_internal->is_allowed_callback( 'er_shout' ) && ! $er_internal->is_allowed_callback( 'strtoupper' ) && ! $er_internal->is_allowed_callback( 'no_such_function_er' ) && false === $er_internal->columns['channel']['custom_display'] && 'strtoupper' === $er_internal_allowed->columns['channel']['custom_display'],
	'er_shout ' . var_export( $er_internal->is_allowed_callback( 'er_shout' ), true ) . ', strtoupper ' . var_export( $er_internal->is_allowed_callback( 'strtoupper' ), true ) . ', column keeps ' . var_export( $er_internal->columns['channel']['custom_display'], true ) . ', allowed by filter ' . var_export( $er_internal_allowed->columns['channel']['custom_display'], true )
);

// --- a report name outside ASCII still gives a file name the download link carries ---
list( $er_utf_admin ) = er_run( "SELECT id, channel FROM `{$er_table}` WHERE id = 1", '报告 Ünïcode / Sales' );
ob_start();
$er_utf_file = $er_utf_admin->export_sv();
ob_end_clean();
$er_utf_path = WP_ADMIN_UI_EXPORT_DIR . '/' . $er_utf_file;
$er_utf_ok   = is_string( $er_utf_file ) && 1 === preg_match( '/^unicode_sales_\d{2}-\d{2}-\d{4}_\d{2}-\d{2}-\d{2}_[A-Za-z0-9]{8}\.csv$/', $er_utf_file ) && sanitize_file_name( $er_utf_file ) === $er_utf_file && file_exists( $er_utf_path );
wp_delete_file( $er_utf_path );
$er_blank_admin = er_admin( "SELECT id FROM `{$er_table}` WHERE id = 1", '报告' );
er_report(
	'export file names stay ASCII for a report named outside ASCII',
	$er_utf_ok && 0 === strpos( $er_blank_admin->export_file_name( 'csv' ), 'export_' ),
	var_export( $er_utf_file, true ) . ', nothing left: ' . $er_blank_admin->export_file_name( 'csv' )
);

// --- an export from a link needs its nonce; remove_export takes export files only ---
$er_nonce_admin              = er_admin( "SELECT id, channel FROM `{$er_table}` WHERE id = 1", 'Export nonce' );
$er_nonce_admin->export_type = 'csv';
$_GET                        = array();
ob_start();
$er_no_nonce     = $er_nonce_admin->export();
$er_no_nonce_out = (string) ob_get_clean();
$_GET            = array( '_wpnonce' => wp_create_nonce( 'wp-admin-ui-export-request' ) );
ob_start();
$er_nonce_admin->export();
ob_end_clean();
$er_nonce_file = (string) $er_nonce_admin->exported_file;
if ( '' !== $er_nonce_file ) {
	wp_delete_file( WP_ADMIN_UI_EXPORT_DIR . '/' . $er_nonce_file );
}
$er_link = $er_nonce_admin->export_link( array( 'export_type' => 'csv' ) );
$_GET    = array(
	'_wpnonce'      => wp_create_nonce( 'wp-admin-ui-export-request' ),
	'remove_export' => '.htaccess',
);
ob_start();
$er_remove_protected     = $er_nonce_admin->export();
$er_remove_protected_out = (string) ob_get_clean();
$_GET                    = array();
er_report(
	'export() needs the link nonce; remove_export leaves the protection files alone',
	false === $er_no_nonce && false !== strpos( $er_no_nonce_out, 'expired' ) && '' !== $er_nonce_file && false !== strpos( $er_link, 'action=export' ) && false !== strpos( $er_link, '_wpnonce=' ) && false === $er_remove_protected && false !== strpos( $er_remove_protected_out, 'not an export file' ) && file_exists( WP_ADMIN_UI_EXPORT_DIR . '/.htaccess' ),
	'without nonce ' . var_export( $er_no_nonce, true ) . ', with nonce file ' . var_export( $er_nonce_file, true ) . ', .htaccess ' . ( file_exists( WP_ADMIN_UI_EXPORT_DIR . '/.htaccess' ) ? 'kept' : 'gone' )
);

// --- a multiple-select filter sends an array: the SQL takes every value, and the page does not fail ---
$er_filter_sql     = array();
$er_capture_filter = static function ( $filter_sql ) use ( &$er_filter_sql ) {
	$er_filter_sql[] = $filter_sql;
	return $filter_sql;
};
add_filter( 'wp_admin_ui_data_filter_sql', $er_capture_filter );
$_GET = array( 'filter_channel' => array( 'alpha', 'beta', '' ) );
list( $er_multi_admin, $er_multi_rows, $er_multi_error ) = er_run(
	"SELECT id, channel FROM `{$er_table}`",
	'Array filter',
	false,
	array(
		'search'  => true,
		'columns' => array(
			'id'      => array( 'label' => 'Id' ),
			'channel' => array(
				'label'   => 'Channel',
				'type'    => 'related',
				'related' => array(
					'alpha' => 'Alpha',
					'beta'  => 'Beta',
				),
				'filter'  => true,
			),
		),
	)
);
$_GET = array();
remove_filter( 'wp_admin_ui_data_filter_sql', $er_capture_filter );
$er_multi_want = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$er_table}` WHERE channel IN ('alpha', 'beta')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
er_report(
	'an array filter value filters on each value',
	'' === $er_multi_error && array( "(`channel` LIKE '%alpha%' OR `channel` LIKE '%beta%')" ) === $er_filter_sql && 0 < $er_multi_want && count( $er_multi_rows ) === $er_multi_want,
	'filter sql ' . wp_json_encode( $er_filter_sql ) . ', ' . count( $er_multi_rows ) . ' rows' . ( '' !== $er_multi_error ? ', error ' . $er_multi_error : '' )
);

// --- PDF export: a file the viewer can open, with the pages the rows need; both engines where the PHP allows ---
$er_pdf_engines = PHP_VERSION_ID >= 80200 ? array( 'tc-lib-pdf', 'tcpdf' ) : array( 'tcpdf' );
foreach ( $er_pdf_engines as $er_pdf_engine ) {
	$er_force_engine = static function () use ( $er_pdf_engine ) {
		return $er_pdf_engine;
	};
	add_filter( 'wp_admin_ui_export_pdf_engine', $er_force_engine );
	$er_pdf_admin              = er_admin( "SELECT id, channel, title, revenue FROM `{$er_table}` ORDER BY id", 'PDF export' );
	$er_pdf_admin->action      = 'export';
	$er_pdf_admin->export_type = 'pdf';
	$_GET                      = array( '_wpnonce' => wp_create_nonce( 'wp-admin-ui-export-request' ) );
	ob_start();
	$er_pdf_admin->export();
	ob_end_clean();
	$_GET = array();
	remove_filter( 'wp_admin_ui_export_pdf_engine', $er_force_engine );
	$er_pdf_file  = (string) $er_pdf_admin->exported_file;
	$er_pdf       = '' === $er_pdf_file ? '' : (string) file_get_contents( WP_ADMIN_UI_EXPORT_DIR . '/' . $er_pdf_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the test wrote
	$er_pdf_pages = preg_match_all( '#/Type\s*/Page[^s]#', $er_pdf );
	// The producer string (UTF-16BE) names the library: TCPDF 6 is the TCPDF engine, "TCPDF 8" is tc-lib-pdf.
	$er_pdf_major = preg_match( '/T\x00C\x00P\x00D\x00F\x00 \x00(\d)/', $er_pdf, $er_pdf_producer ) ? (int) $er_pdf_producer[1] : 0; // PCRE escapes: a NUL byte in the pattern itself is refused below PHP 8.2.
	$er_pdf_want  = 'tcpdf' === $er_pdf_engine ? 6 : 8;
	$er_pdf_ok    = '.pdf' === substr( $er_pdf_file, -4 ) && '%PDF-' === substr( $er_pdf, 0, 5 ) && false !== strpos( $er_pdf, '%%EOF' ) && 2 <= $er_pdf_pages && $er_pdf_want === $er_pdf_major;
	er_report(
		'PDF export (' . $er_pdf_engine . ')',
		$er_pdf_ok,
		'' === $er_pdf_file ? 'no file' : $er_pdf_file . ', ' . strlen( $er_pdf ) . ' bytes, ' . $er_pdf_pages . ' pages, producer major ' . $er_pdf_major
	);
}
er_report(
	'the default PDF engine follows the PHP version',
	( PHP_VERSION_ID >= 80200 ? 'tc-lib-pdf' : 'tcpdf' ) === Exports_Reports_Admin_UI::pdf_engine(),
	Exports_Reports_Admin_UI::pdf_engine() . ' on PHP ' . PHP_VERSION
);

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
$er_out                        = er_export_contents( $er_admin );
$er_want                       = "\xEF\xBB\xBF\"Id\"\t\"Channel\"\n\"1\"\t\"beta\"\n\"2\"\t\"gamma\"\n";
er_report(
	'TSV export',
	'' === $er_error && $er_want === $er_out,
	'' !== $er_error ? $er_error : 'content ' . str_replace( "\t", '<tab>', substr( $er_out, 3, 60 ) )
);

// --- the formula wrapping can be turned off ---
add_filter( 'wp_admin_ui_export_escape_formulas', '__return_false' );
list( $er_admin, , $er_error ) = er_run( "SELECT id, title FROM `{$er_table}` WHERE id = 7", 'Formulas kept', true );
$er_out                        = er_export_contents( $er_admin );
remove_filter( 'wp_admin_ui_export_escape_formulas', '__return_false' );
$er_want = "\xEF\xBB\xBF\"Id\",\"Title\"\n\"7\",\"=1+1\"\n";
er_report(
	'formula wrapping off through the filter',
	'' === $er_error && $er_want === $er_out,
	'' !== $er_error ? $er_error : 'content ' . substr( $er_out, 3, 60 )
);

// --- the XLSX, XML and JSON writers: a file of each, with the labels and rows the report shows ---
foreach ( array( 'xlsx', 'xml', 'json' ) as $er_type ) {
	list( $er_writer )      = er_run( "SELECT id, channel, title FROM `{$er_table}` WHERE id <= 2 ORDER BY id", 'Writers' );
	$er_writer->action      = 'export';
	$er_writer->export_type = $er_type;
	$_GET                   = array( '_wpnonce' => wp_create_nonce( 'wp-admin-ui-export-request' ) );
	ob_start();
	$er_writer->export();
	ob_end_clean();
	$_GET           = array();
	$er_writer_file = (string) $er_writer->exported_file;
	$er_writer_path = WP_ADMIN_UI_EXPORT_DIR . '/' . $er_writer_file;
	$er_writer_out  = '' === $er_writer_file ? '' : (string) file_get_contents( $er_writer_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the test wrote
	$er_writer_ok   = '' !== $er_writer_out && 0 === substr_compare( $er_writer_file, '.' . $er_type, -1 - strlen( $er_type ) );
	if ( $er_writer_ok && 'xlsx' === $er_type ) {
		// A zip with one worksheet; XLSXWriter writes the labels and the values as inline strings.
		$er_zip   = new ZipArchive();
		$er_sheet = '';
		if ( true === $er_zip->open( $er_writer_path ) ) {
			$er_sheet = (string) $er_zip->getFromName( 'xl/worksheets/sheet1.xml' );
			$er_zip->close();
		}
		$er_writer_ok = 0 === strpos( $er_writer_out, "PK\x03\x04" ) && 3 === substr_count( $er_sheet, '<row ' ) && false !== strpos( $er_sheet, '<t>Id</t>' ) && false !== strpos( $er_sheet, '<t>Channel</t>' ) && false !== strpos( $er_sheet, '<t>Video 1</t>' ) && false !== strpos( $er_sheet, '<t>gamma</t>' );
	} elseif ( $er_writer_ok && 'xml' === $er_type ) {
		$er_xml       = simplexml_load_string( $er_writer_out );
		$er_writer_ok = $er_xml instanceof SimpleXMLElement && 2 === (int) $er_xml['count'] && 2 === count( $er_xml->item ) && 'beta' === (string) $er_xml->item[0]->channel && 'Video 2' === (string) $er_xml->item[1]->title;
	} elseif ( $er_writer_ok ) {
		$er_json      = json_decode( $er_writer_out, true );
		$er_writer_ok = is_array( $er_json ) && 2 === $er_json['items']['count'] && 2 === count( $er_json['items']['item'] ) && 'Video 1' === $er_json['items']['item'][0]['title'] && 'gamma' === $er_json['items']['item'][1]['channel'];
	}
	if ( '' !== $er_writer_file ) {
		wp_delete_file( $er_writer_path );
	}
	er_report( strtoupper( $er_type ) . ' export writes the labels and the rows', $er_writer_ok, '' === $er_writer_file ? 'no file' : $er_writer_file . ', ' . strlen( $er_writer_out ) . ' bytes' );
}

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

$er_anon          = er_rest( 'GET', $er_route . '/data' );
$er_wrong         = er_rest( 'GET', $er_route . '/data', array( 'token' => 'nope' ) );
$er_anon_missing  = er_rest( 'GET', '/exports-reports/v1/reports/999999/data' );
$er_token_missing = er_rest( 'GET', '/exports-reports/v1/reports/999999/data', array( 'token' => 'er-test-token' ) );
er_report(
	'REST refuses the report without the token or a user',
	401 === $er_anon->get_status() && 401 === $er_wrong->get_status() && 401 === $er_anon_missing->get_status() && 404 === $er_token_missing->get_status(),
	'status ' . $er_anon->get_status() . ' / ' . $er_wrong->get_status() . '; unknown id without credential ' . $er_anon_missing->get_status() . ', with token ' . $er_token_missing->get_status()
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
$er_on_disk = '' === $er_file ? '' : (string) file_get_contents( WP_ADMIN_UI_EXPORT_DIR . '/' . $er_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the test wrote
er_report(
	'REST download names the logged export for serving and refuses other names',
	200 === $er_download->get_status() && isset( $er_body['download'], $er_body['file'] ) && true === $er_body['download'] && $er_file === $er_body['file'] && false === $er_passed && true === $er_sent_ok && '' !== $er_sent && $er_sent === $er_on_disk && 404 === $er_other->get_status(),
	'status ' . $er_download->get_status() . ', download ' . wp_json_encode( isset( $er_body['download'] ) ? $er_body['download'] : null ) . ', data response left alone ' . var_export( false === $er_passed, true ) . ', ' . strlen( $er_sent ) . ' bytes, other ' . $er_other->get_status()
);

// The admin-ajax download (export.php) serves a logged file to a user who may view its report.
wp_set_current_user( (int) $er_subscriber );
$er_dl_subscriber    = exports_reports_user_can_download_export( $er_file );
$er_full_access_meta = isset( get_userdata( 1 )->caps['exports_reports_full_access'] );
wp_set_current_user( 1 );
$er_dl_admin   = exports_reports_user_can_download_export( $er_file );
$er_dl_unknown = exports_reports_user_can_download_export( 'other_report.csv' );
$er_dl_foreign = exports_reports_user_can_download_export( 'index.php' );
wp_set_current_user( 0 );
$er_dl_anon = exports_reports_user_can_download_export( $er_file );
er_report(
	'download authorisation follows the log row and the report access rules',
	false === $er_dl_subscriber && true === $er_dl_admin && false === $er_dl_unknown && false === $er_dl_foreign && false === $er_dl_anon,
	'subscriber ' . var_export( $er_dl_subscriber, true ) . ', administrator ' . var_export( $er_dl_admin, true ) . ', unknown ' . var_export( $er_dl_unknown, true ) . ', anonymous ' . var_export( $er_dl_anon, true )
);
er_report(
	'administrators hold exports_reports_full_access through the capability filter, not user meta',
	user_can( 1, 'exports_reports_full_access' ) && ! user_can( (int) $er_subscriber, 'exports_reports_full_access' ) && ! $er_full_access_meta,
	'administrator ' . var_export( user_can( 1, 'exports_reports_full_access' ), true ) . ', subscriber ' . var_export( user_can( (int) $er_subscriber, 'exports_reports_full_access' ), true ) . ', in meta ' . var_export( $er_full_access_meta, true )
);

$wpdb->update( $wpdb->prefix . EXPORTS_REPORTS_TBL . 'reports', array( 'disabled' => 1 ), array( 'id' => $er_report_id ) );
$er_disabled = er_rest( 'GET', $er_route . '/data', array( 'token' => 'er-test-token' ) );
er_report( 'REST hides a disabled report', 404 === $er_disabled->get_status(), 'status ' . $er_disabled->get_status() );
wp_delete_user( (int) $er_subscriber );

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

$_POST    = array(
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
	'field_label'       => array( 3 => "O\\'Brien <b>revenue</b>" ),
	'field_related_sql' => array( 7 => "WHERE name <> 'O\\'Brien'" ),
);
$er_saved = json_decode( exports_reports_report_field_save( '', 'field_data', array(), $er_form_row ), true );
$_POST    = array();

$er_saved_summary = array();
foreach ( (array) $er_saved as $er_saved_field ) {
	$er_saved_summary[] = $er_saved_field['name'] . ':' . $er_saved_field['type'] . ':' . $er_saved_field['search'];
}
er_report(
	'report field editor saves the posted rows in order, unslashed and sanitised',
	is_array( $er_saved ) && 2 === count( $er_saved ) && 'revenue' === $er_saved[0]['name'] && 'decimal' === $er_saved[0]['type'] && 1 === $er_saved[0]['hide_report'] && 1 === $er_saved[0]['search'] && '20' === $er_saved[0]['width'] && "O'Brien revenue" === $er_saved[0]['label'] && 'channel' === $er_saved[1]['name'] && 0 === $er_saved[1]['search'] && "WHERE name <> 'O'Brien'" === $er_saved[1]['related_sql'],
	is_array( $er_saved ) ? count( $er_saved ) . ' fields: ' . wp_json_encode( $er_saved_summary ) : 'not an array'
);


// --- save() and reorder() refuse a request without their nonce; reorder() with the nonce writes the weights; the settings form carries its nonce ---
$er_groups_table = $wpdb->prefix . EXPORTS_REPORTS_TBL . 'groups';
$er_now          = current_time( 'mysql' );
$er_order_ids    = array();
foreach ( array( 'Order A', 'Order B' ) as $er_i => $er_group_name ) {
	$wpdb->insert(
		$er_groups_table,
		array(
			'name'        => $er_group_name,
			'disabled'    => 0,
			'role_access' => '',
			'weight'      => $er_i,
			'created'     => $er_now,
			'updated'     => $er_now,
		)
	);
	$er_order_ids[] = (int) $wpdb->insert_id;
}
$er_gate_admin = er_admin(
	'',
	'Groups',
	array(
		'readonly'   => false,
		'table'      => $er_groups_table,
		'identifier' => 'id',
		'reorder'    => 'weight',
		'id'         => $er_order_ids[0],
		'do'         => 'save',
	)
);
$_POST         = array( 'name' => 'Renamed' );
ob_start();
$er_save_refused = $er_gate_admin->save();
$er_save_out     = (string) ob_get_clean();
$er_name_after   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM `{$er_groups_table}` WHERE id = %d", $er_order_ids[0] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
er_report(
	'save() refuses a request without the form nonce',
	false === $er_save_refused && false !== strpos( $er_save_out, 'expired' ) && 'Order A' === $er_name_after,
	'returned ' . var_export( $er_save_refused, true ) . ', name now ' . $er_name_after
);

$_POST = array( 'order' => array( $er_order_ids[1], $er_order_ids[0] ) );
ob_start();
$er_reorder_refused = $er_gate_admin->reorder();
$er_reorder_out     = (string) ob_get_clean();
$er_weight_refused  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT weight FROM `{$er_groups_table}` WHERE id = %d", $er_order_ids[0] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$_POST['_wpnonce']  = wp_create_nonce( 'wp-admin-ui-reorder' );
ob_start();
$er_gate_admin->reorder();
$er_reorder_ok_out = (string) ob_get_clean();
$er_weight_a       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT weight FROM `{$er_groups_table}` WHERE id = %d", $er_order_ids[0] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$er_weight_b       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT weight FROM `{$er_groups_table}` WHERE id = %d", $er_order_ids[1] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$_POST             = array();
er_report(
	'reorder() needs the reorder nonce, then writes the posted order',
	false === $er_reorder_refused && false !== strpos( $er_reorder_out, 'expired' ) && 0 === $er_weight_refused && 1 === $er_weight_a && 0 === $er_weight_b && false !== strpos( $er_reorder_ok_out, 'Order updated' ),
	'without nonce: weight A ' . $er_weight_refused . '; with nonce: A ' . $er_weight_a . ', B ' . $er_weight_b
);

wp_set_current_user( 1 );
ob_start();
exports_reports_settings();
$er_settings_html = (string) ob_get_clean();
wp_set_current_user( 0 );
er_report(
	'the settings form carries its nonce',
	false !== strpos( $er_settings_html, 'name="_wpnonce"' ) && false !== strpos( $er_settings_html, 'name="cronjob_token"' ),
	strlen( $er_settings_html ) . ' bytes'
);

// --- saving the token empty turns token access off; it comes back for the tests that follow ---
wp_set_current_user( 1 );
$_POST    = array( 'cronjob_token' => '' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'exports-reports-settings' ) );
ob_start();
exports_reports_settings();
ob_end_clean();
$er_token_cleared = get_option( 'exports_reports_token' );
$_POST            = array();
$_REQUEST         = array();
wp_set_current_user( 0 );
$er_no_token = er_rest( 'GET', $er_route . '/data', array( 'token' => 'er-test-token' ) );
update_option( 'exports_reports_token', 'er-test-token' );
er_report(
	'an empty token saved from the settings page turns token access off',
	'' === $er_token_cleared && 401 === $er_no_token->get_status(),
	'option ' . var_export( $er_token_cleared, true ) . ', REST with the old token ' . $er_no_token->get_status()
);

// --- the pipe and custom exports are .txt files and are served; the cleanup event has its handler ---
wp_mkdir_p( WP_ADMIN_UI_EXPORT_DIR );
file_put_contents( WP_ADMIN_UI_EXPORT_DIR . '/served.txt', "a|b\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
ob_start();
$er_txt_ok  = exports_reports_send_export_file( 'served.txt' );
$er_txt_out = (string) ob_get_clean();
wp_delete_file( WP_ADMIN_UI_EXPORT_DIR . '/served.txt' );
er_report(
	'.txt exports are served and the daily cleanup has a handler',
	true === $er_txt_ok && "a|b\n" === $er_txt_out && exports_reports_is_export_file( 'x.txt' ) && ! exports_reports_is_export_file( 'index.php' ) && ! exports_reports_is_export_file( 'notes.md' ) && 10 === has_action( 'exports_reports_cleanup', 'exports_reports_cleanup_event' ),
	'served ' . var_export( $er_txt_ok, true ) . ' (' . strlen( $er_txt_out ) . ' bytes), handler ' . var_export( has_action( 'exports_reports_cleanup', 'exports_reports_cleanup_event' ), true )
);

// --- deactivation clears the daily cleanup event; an admin request puts a missing event back ---
exports_reports_deactivate();
$er_event_cleared = wp_next_scheduled( 'exports_reports_cleanup' );
exports_reports_ensure_cleanup_scheduled();
$er_event_back = wp_next_scheduled( 'exports_reports_cleanup' );
er_report(
	'deactivation clears the cleanup event and the next admin request reschedules it',
	false === $er_event_cleared && false !== $er_event_back,
	'after deactivation ' . var_export( $er_event_cleared, true ) . ', after admin_init ' . var_export( $er_event_back, true )
);

// --- Clear Now removes export files only ---
file_put_contents( WP_ADMIN_UI_EXPORT_DIR . '/old.csv', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( WP_ADMIN_UI_EXPORT_DIR . '/notes.md', 'keep' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
exports_reports_cleanup( true );
$er_clear_ok = ! file_exists( WP_ADMIN_UI_EXPORT_DIR . '/old.csv' ) && file_exists( WP_ADMIN_UI_EXPORT_DIR . '/notes.md' ) && file_exists( WP_ADMIN_UI_EXPORT_DIR . '/index.php' );
wp_delete_file( WP_ADMIN_UI_EXPORT_DIR . '/notes.md' );
er_report( 'clearing the export directory removes export files only', $er_clear_ok, 'old.csv ' . ( file_exists( WP_ADMIN_UI_EXPORT_DIR . '/old.csv' ) ? 'present' : 'gone' ) . ', notes.md kept ' . var_export( $er_clear_ok, true ) );

// --- request values arrive slashed (wp_magic_quotes) and are read unslashed and sanitised; a value outside the allowed list (order_dir=asc) leaves the default; hidden fields carry them unslashed ---
$_GET         = array(
	'search_query' => "O\\'Brien <b>x</b>",
	'pg'           => '3',
	'order_dir'    => 'asc',
	'action'       => 'view',
	'filter_name'  => array( "a\\'b" ),
);
$er_req_admin = er_admin( '', 'Request', array( 'search' => true ) );
ob_start();
$er_req_admin->hidden_vars();
$er_hidden_html = (string) ob_get_clean();
$er_filter_var  = $er_req_admin->get_var( 'filter_name' );
$_GET           = array();
er_report(
	'request values are unslashed and sanitised on the way in',
	"O'Brien x" === $er_req_admin->search_query && 3 === $er_req_admin->page && 'DESC' === $er_req_admin->order_dir && 'view' === $er_req_admin->action && array( "a'b" ) === $er_filter_var && false !== strpos( $er_hidden_html, 'value="O&#039;Brien x"' ) && false === strpos( $er_hidden_html, '\\' ),
	'search ' . var_export( $er_req_admin->search_query, true ) . ', page ' . var_export( $er_req_admin->page, true ) . ', order_dir ' . var_export( $er_req_admin->order_dir, true ) . ', hidden ' . strlen( $er_hidden_html ) . ' bytes'
);

// --- the pre-0.9.5 api.php answers through the REST routes with its old response shape ---
$er_api_capture = static function ( array $query ) use ( $er_report_id ) {
	$_GET = array_merge(
		array(
			'report' => (string) $er_report_id,
			'token'  => (string) get_option( 'exports_reports_token' ),
		),
		$query
	);
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'deprecated_file_trigger_error', '__return_false' );
	$er_thrower = static function () {
		return static function () {
			throw new RuntimeException( 'api.php finished' );
		};
	};
	add_filter( 'wp_die_ajax_handler', $er_thrower );
	$ended = false;
	ob_start();
	try {
		include EXPORTS_REPORTS_DIR . 'api.php';
	} catch ( RuntimeException $e ) {
		$ended = true; // wp_send_json() ended the response.
	}
	$out = (string) ob_get_clean();
	remove_filter( 'wp_die_ajax_handler', $er_thrower );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	remove_filter( 'deprecated_file_trigger_error', '__return_false' );
	$_GET = array();
	return $ended ? json_decode( $out, true ) : null;
};
$wpdb->update( $wpdb->prefix . EXPORTS_REPORTS_TBL . 'reports', array( 'disabled' => 0 ), array( 'id' => $er_report_id ) );
$er_api_rows   = $er_api_capture(
	array(
		'action' => 'json',
		'full'   => '1',
	)
);
$er_api_export = $er_api_capture( array( 'export_type' => 'csv' ) );
$er_api_bad    = $er_api_capture(
	array(
		'action' => 'json',
		'token'  => 'wrong',
	)
);
er_report(
	'api.php (deprecated) returns rows, an export file and the old error shape through the REST routes',
	is_array( $er_api_rows ) && true === $er_api_rows['success'] && is_array( $er_api_rows['data'] ) && count( $er_api_rows['data'] ) === $er_rows
		&& is_array( $er_api_export ) && true === $er_api_export['success'] && 'Report exported' === $er_api_export['data']['message'] && file_exists( $er_api_export['data']['export_file'] )
		&& is_array( $er_api_bad ) && false === $er_api_bad['success'],
	'rows ' . ( is_array( $er_api_rows ) && isset( $er_api_rows['data'] ) ? count( (array) $er_api_rows['data'] ) : 'n/a' ) . ', export ' . ( is_array( $er_api_export ) ? wp_json_encode( $er_api_export['data'] ) : 'n/a' ) . ', wrong token ' . wp_json_encode( $er_api_bad )
);

$wpdb->query( "DROP TABLE IF EXISTS `{$er_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// --- uninstall removes the tables, the options, the cleanup event and the export files (last: nothing runs after it) ---
$er_reports_table = $wpdb->prefix . EXPORTS_REPORTS_TBL . 'reports';
wp_mkdir_p( WP_ADMIN_UI_EXPORT_DIR );
file_put_contents( WP_ADMIN_UI_EXPORT_DIR . '/leftover.csv', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
define( 'WP_UNINSTALL_PLUGIN', true );
require EXPORTS_REPORTS_DIR . 'uninstall.php';
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
