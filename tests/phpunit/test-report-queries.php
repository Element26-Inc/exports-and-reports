<?php
/**
 * Report definitions of several shapes through get_data( true ), the path the report page and the
 * exports share, and the page view's search, filter, totals and sort handling.
 *
 * @package Exports_And_Reports
 */

class Tests_Report_Queries extends Exports_Reports_Test_Case {

	/**
	 * Runs the page view of a report definition and returns the instance.
	 *
	 * @param string $sql     The report SQL.
	 * @param string $name    The report name.
	 * @param array  $options Options added to the defaults.
	 * @return Exports_Reports_Admin_UI
	 */
	protected function view( $sql, $name, $options = array() ) {
		$report = $this->report( $sql, $name, $options );

		ob_start();
		$report->get_data( false );
		ob_end_clean();

		return $report;
	}

	public function test_a_plain_select() {
		list( , $rows, $error ) = $this->run_report( 'SELECT id, channel, title, revenue FROM ' . self::videos() . ' ORDER BY id', 'Plain' );

		$this->assertSame( '', $error );
		$this->assertCount( self::$video_rows, $rows );
		$this->assertSame( array( 'id', 'channel', 'title', 'revenue' ), array_keys( $rows[0] ) );
	}

	public function test_a_common_table_expression() {
		list( , $rows, $error ) = $this->run_report( 'WITH per_channel AS ( SELECT channel, SUM(revenue) AS total FROM ' . self::videos() . ' GROUP BY channel ) SELECT channel, total FROM per_channel ORDER BY channel', 'CTE' );

		$this->assertSame( '', $error );
		$this->assertCount( 3, $rows );
		$this->assertSame( 'alpha', $rows[0]['channel'] );
	}

	/**
	 * A dynamic statement with the LIMIT placeholder inside its string; MariaDB only.
	 */
	public function test_execute_immediate_with_the_limit_placeholder() {
		if ( ! $this->is_mariadb() ) {
			$this->markTestSkipped( 'EXECUTE IMMEDIATE is MariaDB only.' );
		}

		list( , $rows, $error ) = $this->run_report( "EXECUTE IMMEDIATE CONCAT('SELECT channel, COUNT(*) AS videos FROM " . self::videos() . " GROUP BY channel ORDER BY channel LIMIT %%LIMIT%%')", 'Dynamic' );

		$this->assertSame( '', $error );
		$this->assertCount( 3, $rows );
		$this->assertSame( 20, (int) $rows[0]['videos'] );
	}

	/**
	 * A definition of several statements: the ones before the query run first, on the same connection, so
	 * the query reads the session variables they set. Doubled and trailing semicolons, and a semicolon in a
	 * string, do not get in the way. (The variables compared are numbers: a text variable takes the
	 * connection's collation, which need not be the column's.)
	 */
	public function test_statements_before_the_query_run_first() {
		list( , $rows, $error ) = $this->run_report( "SET @remainder = 0;;\nSET @note = 'a; b';\nSELECT id, title, @note AS note FROM " . self::videos() . " WHERE id % 3 = @remainder ORDER BY id;", 'Session variables' );

		$this->assertSame( '', $error );
		$this->assertCount( 20, $rows );
		$this->assertSame( 3, (int) $rows[0]['id'] );
		$this->assertSame( 'a; b', $rows[0]['note'] );
	}

	/**
	 * The page view of such a definition: the count and the page's LIMIT go into the last statement; the ones
	 * before it stay as written (a SELECT in one of them does not take the count).
	 */
	public function test_the_page_of_several_statements_counts_the_last() {
		$report        = $this->report( "SET @total = ( SELECT COUNT(*) FROM " . self::videos() . " );\nSELECT id, title, @total AS total FROM " . self::videos() . " WHERE channel = 'beta' ORDER BY id", 'Session variables page' );
		$report->limit = 5;

		ob_start();
		$report->get_data( false );
		ob_end_clean();

		$this->assertSame( '', $this->db_error() );
		$this->assertSame( 20, (int) $report->total );
		$this->assertCount( 5, $report->data );
		$this->assertSame( self::$video_rows, (int) $report->data[0]['total'] );

		$sql = $report->query->statement( false );
		$sql = $sql['sql'];

		$this->assertStringStartsWith( 'SET @total = ( SELECT COUNT(*)', $sql );
		$this->assertSame( 1, substr_count( $sql, 'SQL_CALC_FOUND_ROWS' ) );
		$this->assertGreaterThan( strpos( $sql, ";\n" ), strpos( $sql, 'SQL_CALC_FOUND_ROWS' ), 'the count goes into the last statement' );
		$this->assertGreaterThan( strpos( $sql, ";\n" ), strpos( $sql, ' LIMIT ' ), 'the page goes into the last statement' );
	}

	/**
	 * The shape dynamic reports take: session variables, then EXECUTE IMMEDIATE with the LIMIT placeholder
	 * inside its string and a line break before its SELECT, which takes the count; MariaDB only.
	 */
	public function test_session_variables_then_execute_immediate() {
		if ( ! $this->is_mariadb() ) {
			$this->markTestSkipped( 'EXECUTE IMMEDIATE is MariaDB only.' );
		}

		$report        = $this->report( "SET @least = 41;;\nEXECUTE IMMEDIATE CONCAT('\nSELECT id, title FROM " . self::videos() . " WHERE id >= ', @least, ' ORDER BY id LIMIT %%LIMIT%%');", 'Dynamic with variables' );
		$report->limit = 5;

		ob_start();
		$report->get_data( false );
		ob_end_clean();

		$this->assertSame( '', $this->db_error() );
		$this->assertSame( 20, (int) $report->total );
		$this->assertCount( 5, $report->data );
		$this->assertSame( 41, (int) $report->data[0]['id'] );
		$this->assertSame( array( 'id', 'title' ), array_keys( $report->data[0] ), 'the columns come from the result' );
	}

	/**
	 * A statement before the query that fails stops the report with the database's error; nothing runs after it.
	 */
	public function test_a_failing_statement_before_the_query_stops_the_report() {
		global $wpdb;

		$shown = $wpdb->suppress_errors();

		list( , $rows, $error ) = $this->run_report( "SET @broken = (SELECT no_such_column FROM " . self::videos() . ");\nSELECT id FROM " . self::videos(), 'Broken variable' );

		$wpdb->suppress_errors( $shown );

		$this->assertSame( array(), $rows );
		$this->assertStringContainsString( 'no_such_column', $error );
	}

	/**
	 * The splitter: semicolons inside quotes and comments do not split, a comment stays with its statement,
	 * empty pieces and comment-only pieces are dropped.
	 */
	public function test_statements_are_split_outside_quotes_and_comments() {
		$sql = "SET @a = 'x;y';; -- a note; not a split\nSET @b = \"p;q\"; /* c; */ SELECT `we;ird`, 'it\\'s' AS apostrophe FROM t WHERE a LIKE '%;%';\n# tail; comment";

		$this->assertSame(
			array(
				"SET @a = 'x;y'",
				"-- a note; not a split\nSET @b = \"p;q\"",
				"/* c; */ SELECT `we;ird`, 'it\\'s' AS apostrophe FROM t WHERE a LIKE '%;%'",
			),
			Exports_Reports_Query::statements( $sql )
		);
		$this->assertSame( array( 'SELECT 1' ), Exports_Reports_Query::statements( ' SELECT 1 ; ; ' ) );
		$this->assertSame( array(), Exports_Reports_Query::statements( "-- nothing\n;" ) );
	}

	/**
	 * A full read after the page (the list screen writes an export after it has loaded its page) keeps the
	 * page's count; a streamed export counts nothing and must not zero it.
	 */
	public function test_a_full_read_after_the_page_keeps_the_page_count() {
		$report = $this->view( 'SELECT id, title FROM ' . self::videos() . ' ORDER BY id', 'Count kept' );

		$this->assertSame( self::$video_rows, (int) $report->total );

		$report->stream_export = true;

		ob_start();
		$report->get_data( true );
		ob_end_clean();

		$report->stream_export = false;

		$this->assertSame( self::$video_rows, (int) $report->total );
	}

	/**
	 * The paging prints the page count the way it prints the row count.
	 */
	public function test_the_page_count_is_formatted_like_the_row_count() {
		$report        = $this->report( 'SELECT id FROM ' . self::videos(), 'Pages' );
		$report->total = 100000;
		$report->limit = 1;

		ob_start();
		$report->pagination( true );
		$html = ob_get_clean();

		$this->assertStringContainsString( '100,000 pages', $html );
		$this->assertStringContainsString( '<span class="total-pages">100,000</span>', $html );
	}

	/**
	 * The placeholder removal must not depend on spaces.
	 */
	public function test_a_tab_indented_limit_placeholder() {
		list( , $rows, $error ) = $this->run_report( "SELECT id, title\n\tFROM " . self::videos() . "\n\tORDER BY id\n\tLIMIT %%LIMIT%%", 'Tabs' );

		$this->assertSame( '', $error );
		$this->assertCount( self::$video_rows, $rows );
	}

	/**
	 * A search for an underscore matches only titles that contain one.
	 */
	public function test_a_search_terms_like_wildcards_are_escaped() {
		global $wpdb;

		$_GET['search_query'] = '_';

		$report = $this->view(
			'SELECT id, title FROM ' . self::videos() . ' ORDER BY id',
			'Wildcard search',
			array(
				'search'  => true,
				'columns' => array( 'title' ),
			)
		);

		$expected = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::videos() . ' WHERE title LIKE %s', '%' . $wpdb->esc_like( '_' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertLessThan( self::$video_rows, $expected, 'an unescaped underscore would match every title' );
		$this->assertSame( $expected, (int) $report->total );
	}

	public function test_a_paged_view_with_a_totals_query_counts_every_row() {
		global $wpdb;

		$_GET['limit'] = '2';

		$report = $this->view(
			'SELECT id, revenue FROM ' . self::videos() . ' ORDER BY id',
			'Paged totals',
			array( 'sql_count' => 'SELECT SUM(revenue) AS revenue FROM ' . self::videos() )
		);

		$rows_in_table = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::videos() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertGreaterThan( 2, $rows_in_table );
		$this->assertSame( $rows_in_table, (int) $report->total );
		$this->assertCount( 2, $report->data );
		$this->assertTrue( isset( $report->sum_data[0]['revenue'] ), 'the totals row is there' );
	}

	/**
	 * The alias map must not carry the row-count modifier.
	 */
	public function test_a_search_on_an_aliased_column_of_a_report_with_a_totals_query() {
		global $wpdb;

		$_GET['search_query'] = 'alpha';

		$report = $this->view(
			'SELECT channel AS chan, revenue FROM ' . self::videos() . ' ORDER BY id',
			'Alias search',
			array(
				'search'    => true,
				'columns'   => array( 'chan' ),
				'sql_count' => 'SELECT SUM(revenue) AS revenue FROM ' . self::videos() . ' WHERE %%WHERE%% 1=1',
			)
		);

		$alpha_rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::videos() . " WHERE channel = 'alpha'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( '', $this->db_error() );
		$this->assertGreaterThan( 0, $alpha_rows );
		$this->assertCount( $alpha_rows, $report->data );
		$this->assertSame( $alpha_rows, (int) $report->total );
	}

	/**
	 * A multiple-select filter sends an array: the SQL takes every value, and the page does not fail.
	 */
	public function test_an_array_filter_value_filters_on_each_value() {
		global $wpdb;

		$_GET = array( 'filter_channel' => array( 'alpha', 'beta', '' ) );

		list( , $rows, $error ) = $this->run_report( 'SELECT id, channel FROM ' . self::videos(), 'Array filter', false, $this->channel_filter_options() );

		$_GET = array();

		$want = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::videos() . " WHERE channel IN ('alpha', 'beta')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( '', $error );
		$this->assertGreaterThan( 0, $want );
		$this->assertCount( $want, $rows );
	}

	/**
	 * A sort column the request names must be one of the report's columns; anything else keeps the report's own order.
	 */
	public function test_the_sort_column_must_be_one_of_the_reports_columns() {
		$options = array(
			'order'     => '`id`',
			'order_dir' => 'DESC',
		);

		$_GET['order']     = 'no_such_column';
		$_GET['order_dir'] = 'ASC';

		$unknown = $this->view( 'SELECT id, revenue FROM ' . self::videos(), 'Sort check', $options );

		$_GET['order'] = 'revenue';

		$known = $this->view( 'SELECT id, revenue FROM ' . self::videos(), 'Sort check', $options );

		$this->assertTrue( isset( $unknown->data[0]['id'] ), 'the unknown column leaves rows' );
		$this->assertSame( self::$video_rows, (int) $unknown->data[0]['id'], 'the report order, id DESC, applies' );
		$this->assertTrue( isset( $known->data[0]['revenue'], $known->data[1]['revenue'] ) );
		$this->assertLessThanOrEqual( (float) $known->data[1]['revenue'], (float) $known->data[0]['revenue'], 'revenue ASC' );
	}

	/**
	 * The ORDER BY writes the column as the report does.
	 */
	public function test_a_sort_column_is_matched_whatever_its_case() {
		$_GET['order']     = 'Revenue';
		$_GET['order_dir'] = 'ASC';

		$report = $this->view( 'SELECT id, revenue AS Revenue FROM ' . self::videos(), 'Sort alias' );

		$this->assertTrue( isset( $report->data[0]['Revenue'], $report->data[1]['Revenue'] ) );
		$this->assertSame( -13.5, (float) $report->data[0]['Revenue'] );
		$this->assertLessThanOrEqual( (float) $report->data[1]['Revenue'], (float) $report->data[0]['Revenue'] );
	}
}
