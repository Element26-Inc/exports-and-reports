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
 * @param array  $options Options added to the defaults.
 * @param string $type    The export type.
 * @return array{0: Exports_Reports_Admin_UI, 1: array, 2: string}
 */
function er_run( $sql, $name, $options = array(), $type = 'csv' ) {
	global $wpdb, $er_errors;
	$er_errors          = array();
	$wpdb->last_error   = '';
	$admin              = er_admin( $sql, $name, $options );
	$admin->export_type = $type;
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

// --- the XLSX, XML and JSON writers: a file of each, with the labels and rows the report shows ---
foreach ( array( 'xlsx', 'xml', 'json' ) as $er_type ) {
	list( $er_writer ) = er_run( "SELECT id, channel, title FROM `{$er_table}` WHERE id <= 2 ORDER BY id", 'Writers' );
	$er_writer->action      = 'export';
	$er_writer->export_type = $er_type;
	ob_start();
	$er_writer->export();
	ob_end_clean();
	$er_writer_file = (string) $er_writer->exported_file;
	$er_writer_path = WP_ADMIN_UI_EXPORT_DIR . '/' . $er_writer_file;
	$er_writer_out  = '' === $er_writer_file ? '' : (string) file_get_contents( $er_writer_path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
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

$wpdb->query( "DROP TABLE IF EXISTS `{$er_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

if ( $er_failed > 0 ) {
	WP_CLI::halt( 1 );
}
WP_CLI::success( 'report queries pass' );
