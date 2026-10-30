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
 * Run a report definition the way the view does and return [ rows, error ].
 *
 * @param string $sql    The report SQL.
 * @param string $name   The report name.
 * @param bool   $stream Whether export_sv() should read the rows itself.
 * @return array{0: Exports_Reports_Admin_UI, 1: array, 2: string}
 */
function er_run( $sql, $name, $stream = false ) {
	global $wpdb, $er_errors;
	$er_errors        = array();
	$wpdb->last_error = '';
	$admin            = new Exports_Reports_Admin_UI(
		array(
			'readonly'     => true,
			'export'       => true,
			'search'       => false,
			'default_none' => false,
			'sql'          => $sql,
			'item'         => 'item',
			'items'        => $name,
			'heading'      => array( 'manage' => 'View Report:' ),
		)
	);
	$admin->export_type   = 'csv';
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

// --- the table ---
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
	// string formula and the title with quotes, a comma and a semicolon intact.
	$check = ( 0 === strpos( $er_csv[ $er_mode ], "\xEF\xBB\xBF" ) )
		&& count( $lines ) === $er_rows + 1
		&& "\xEF\xBB\xBF" . '"Id","Channel","Title","Revenue"' === $lines[0]
		&& '"7","beta","="""" & ""=1+1""","10.50"' === $lines[7]
		&& '"8","gamma","Say ""hi"", pause; go","12.00"' === $lines[8];
	er_report( 'CSV export (' . $er_mode . ')', $check, $check ? ( count( $lines ) - 1 ) . ' rows, BOM, quoted, formula neutralised' : 'unexpected content: ' . substr( $er_csv[ $er_mode ], 0, 120 ) . ' ... ' . ( isset( $lines[7], $lines[8] ) ? $lines[7] . ' / ' . $lines[8] : count( $lines ) . ' lines' ) );
}
if ( isset( $er_csv['streamed'], $er_csv['buffered'] ) ) {
	er_report( 'streamed and buffered exports identical', $er_csv['streamed'] === $er_csv['buffered'], strlen( $er_csv['streamed'] ) . ' bytes' );
}

$wpdb->query( "DROP TABLE IF EXISTS `{$er_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

if ( $er_failed > 0 ) {
	WP_CLI::halt( 1 );
}
WP_CLI::success( 'report queries pass' );
