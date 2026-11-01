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
