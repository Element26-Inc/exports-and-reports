<?php
/**
 * The statements of a report screen: the SQL helpers and the assembly of the report query and its count
 * query from the screen's settings and the request.
 *
 * @package Exports_And_Reports
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the class runs the administrator's report queries and the screens' own statements; there is nothing to cache.

/**
 * Builds and runs the statements of an Exports_Reports_Admin_UI screen.
 *
 * The screen keeps the settings (the query as the administrator wrote it, the columns, the filters) and
 * the request (the page, the sort, the search); this class turns them into the statement that runs and
 * runs statements on wpdb. The screen's own quote(), rows(), execute() and the other helpers forward here.
 *
 * @since 1.0.0
 */
class Exports_Reports_Query {

	/**
	 * The screen.
	 *
	 * @var Exports_Reports_Admin_UI
	 */
	public $admin;

	/**
	 * @param Exports_Reports_Admin_UI $admin The screen whose statements these are.
	 */
	public function __construct( Exports_Reports_Admin_UI $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Escapes a value, or each value of an array or object, for a statement that is not prepared: esc_sql() on
	 * the trimmed text, numbers as they are.
	 *
	 * @param mixed $input The value.
	 *
	 * @return mixed
	 */
	public function sanitize( $input ) {
		$output = array();

		if ( is_numeric( $input ) ) {
			$output = $input;
		} elseif ( is_object( $input ) ) {
			$input = (array) $input;
			foreach ( $input as $key => $val ) {
				$output[ $key ] = $this->sanitize( $val );
			}
			$output = (object) $output;
		} elseif ( is_array( $input ) ) {
			foreach ( $input as $key => $val ) {
				$output[ $key ] = $this->sanitize( $val );
			}
		} elseif ( ! empty( $input ) ) {
			$output = esc_sql( trim( $input ) );
		}

		return $output;
	}

	/**
	 * A column or table name as a quoted identifier: backticks around it, a backtick inside it doubled.
	 *
	 * @param string $name The name, from the report definition.
	 *
	 * @return string
	 */
	public function backtick( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/**
	 * The names of the report's columns, whether they are set up already (keyed arrays) or still a list of names.
	 *
	 * @return string[]
	 */
	public function column_names() {
		$names = array();

		foreach ( (array) $this->admin->columns as $key => $column ) {
			$names[] = (string) ( is_array( $column ) ? $key : $column );
		}

		return $names;
	}

	/**
	 * A value as a SQL string literal, quoted and escaped by $wpdb->prepare(). A literal % in it stands behind
	 * wpdb's placeholder escape until the statement runs through wpdb, as rows() and execute() do; a statement
	 * run anywhere else takes $wpdb->remove_placeholder_escape() first.
	 *
	 * @since 0.9.5
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	public function quote( $value ) {
		global $wpdb;

		return (string) $wpdb->prepare( '%s', $value );
	}

	/**
	 * Runs a statement and returns its rows. The one place the plugin runs SQL it did not prepare in full: the
	 * report's query and count query as the administrator wrote them, with the plugin's clauses around prepared
	 * values; a lookup on a related table the definition names; the screen's own table. Only a user with the
	 * plugin's settings capability writes a definition.
	 *
	 * @since 0.9.5
	 *
	 * @param string $sql    The statement.
	 * @param string $output OBJECT or ARRAY_A, as $wpdb->get_results() takes it.
	 *
	 * @return array<int, mixed>
	 */
	public function rows( $sql, $output = OBJECT ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the administrator's own statement, or one of the plugin's with its values prepared; see the method's description.
		return $wpdb->get_results( $sql, $output );
	}

	/**
	 * Runs a statement that writes to the screen's own table (the form's INSERT, UPDATE and DELETE), its values
	 * quoted by quote() or cast.
	 *
	 * @since 0.9.5
	 *
	 * @param string $sql The statement.
	 *
	 * @return int|bool Rows affected, or false on error.
	 */
	public function execute( $sql ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the screen's own table; the values are quoted or cast by the caller.
		return $wpdb->query( $sql );
	}

	/**
	 * Removes the slashes a request adds, from a value or from each value of an array or object.
	 *
	 * @param mixed $input The value.
	 *
	 * @return mixed
	 */
	public function unsanitize( $input ) {
		$output = array();
		if ( is_object( $input ) ) {
			$input = (array) $input;
			foreach ( $input as $key => $val ) {
				$output[ $key ] = $this->unsanitize( $val );
			}
			$output = (object) $output;
		} elseif ( is_array( $input ) ) {
			foreach ( $input as $key => $val ) {
				$output[ $key ] = $this->unsanitize( $val );
			}
		} elseif ( empty( $input ) ) {
			$output = $input;
		} else {
			$output = stripslashes( $input );
		}

		return $output;
	}

	/**
	 * The WHERE or HAVING text the filters add to a statement: the clause itself when the statement has
	 * none, otherwise the conditions for its %%WHERE%% or %%HAVING%% placeholder, joined to the statement's
	 * own with AND.
	 *
	 * @since 0.9.5
	 *
	 * @param string   $sql        The statement.
	 * @param string   $keyword    'WHERE' or 'HAVING'.
	 * @param string[] $conditions The conditions, values prepared.
	 *
	 * @return string '' when there is no condition.
	 */
	public function clause_sql( $sql, $keyword, array $conditions ) {
		if ( empty( $conditions ) ) {
			return '';
		}

		$conditions = ' (' . implode( ' AND ', $conditions ) . ') ';

		if ( false === stripos( $sql, ' ' . $keyword . ' ' ) ) {
			return ' ' . $keyword . $conditions;
		}

		if ( false !== stripos( $sql, ' ' . $keyword . ' %%' . $keyword . '%% ' ) || false === stripos( $sql, ' %%' . $keyword . '%% ' ) ) {
			return $conditions . 'AND ';
		}

		return ' AND' . $conditions;
	}

	/**
	 * Puts the plugin's clauses into a statement. A %%WHERE%%, %%HAVING%%, %%ORDERBY%% or %%LIMIT%%
	 * placeholder the definition lacks is added for a clause that has text: right after the statement's own
	 * keyword, before the first clause that follows it in SQL order, or at the end. The filters' own
	 * %%name%% variables are substituted, then each placeholder is replaced by its clause.
	 *
	 * @since 0.9.5
	 *
	 * @param string                $sql       The statement, a space at each end.
	 * @param array<string, string> $clauses   The clauses by placeholder name (WHERE, HAVING, ORDERBY, LIMIT), '' for none: the
	 *                                         first three with their keyword when the statement has none, the LIMIT as the page alone.
	 * @param array<string, string> $variables The filters' conditions by the %%name%% the definition wrote for them.
	 *
	 * @return string
	 */
	public function place_clauses( $sql, array $clauses, array $variables = array() ) {
		$keywords  = array(
			'WHERE'   => ' WHERE ',
			'HAVING'  => ' HAVING ',
			'ORDERBY' => ' ORDER BY ',
			'LIMIT'   => ' LIMIT ',
		);
		$followers = array(
			'WHERE'   => array( ' GROUP BY ', ' ORDER BY ', ' LIMIT ' ),
			'HAVING'  => array( ' ORDER BY ', ' LIMIT ' ),
			'ORDERBY' => array( ' LIMIT ' ),
			'LIMIT'   => array(),
		);
		// The WHERE, HAVING and ORDER BY clauses bring their keyword when the statement has none; the LIMIT clause is the page alone.
		$appended = array(
			'WHERE'   => ' %%WHERE%% ',
			'HAVING'  => ' %%HAVING%% ',
			'ORDERBY' => ' %%ORDERBY%% ',
			'LIMIT'   => ' LIMIT %%LIMIT%% ',
		);

		foreach ( $clauses as $name => $clause ) {
			$placeholder = '%%' . $name . '%%';

			if ( '' === $clause || false !== stripos( $sql, $placeholder ) ) {
				continue;
			}

			if ( false !== stripos( $sql, $keywords[ $name ] ) ) {
				$sql = str_ireplace( $keywords[ $name ], $keywords[ $name ] . $placeholder . ' ', $sql );
				continue;
			}

			foreach ( $followers[ $name ] as $follower ) {
				if ( false !== stripos( $sql, $follower ) ) {
					$sql = str_ireplace( $follower, ' ' . $placeholder . $follower, $sql );
					continue 2;
				}
			}

			$sql .= $appended[ $name ];
		}

		foreach ( $variables as $name => $value ) {
			$sql = str_ireplace( '%%' . $name . '%%', $value, $sql );
		}

		foreach ( $clauses as $name => $clause ) {
			$sql = str_ireplace( '%%' . $name . '%%', $clause, $sql );
		}

		return $sql;
	}

	/**
	 * The report query, and its count query when the report has one, for a page or for every row:
	 * SQL_CALC_FOUND_ROWS and the SUM() of each total field in the select list, the search and the filters
	 * as prepared conditions in the WHERE or HAVING clause (or at the %%name%% placeholder the definition
	 * wrote for them), the sort column checked against the report's columns, the page's LIMIT. The screen's
	 * sql_count, order, order_dir and order_from_request settings are normalised on the way.
	 *
	 * @since 1.0.0 Out of Exports_Reports_Admin_UI::get_data().
	 *
	 * @param bool $full Whether every row is wanted, as for an export: no count and no LIMIT.
	 *
	 * @return array{sql: string, sql_count: string, where: string, having: string}
	 */
	public function statement( $full = false ) {
		/** @global wpdb $wpdb */ global $wpdb;

		$admin = $this->admin;

		$admin->sql_count = trim( $admin->sql_count );
		if ( empty( $admin->sql_count ) ) {
			$admin->sql_count = false;
		}
		if ( false === $admin->sql ) {
			$admin->sql = "SELECT * FROM {$admin->table}";
		}
		$sql = ' ' . str_replace( array( "\n", "\r", "\t", '  ' ), ' ', ' ' . $admin->sql ) . ' ';
		// The author's own SQL_CALC_FOUND_ROWS, if any, comes off; the select list is read before anything is added to it.
		$sql = preg_replace( '/ SELECT SQL_CALC_FOUND_ROWS /i', ' SELECT ', $sql, 1 );
		preg_match( '/SELECT (.*) FROM/i', $sql, $selectmatches );
		// What goes after the first SELECT: the row count for the page view (an export reads every row and needs
		// none; a totals query feeds the sum row, not the count), then a SUM() per total field.
		$select_extras = $full ? '' : 'SQL_CALC_FOUND_ROWS';
		$totals        = array();
		foreach ( $admin->columns as $key => $column ) {
			$attributes = $column;
			if ( ! is_array( $attributes ) ) {
				$attributes = $admin->setup_column( $column );
			}
			if ( true !== $attributes['total_field'] ) {
				continue;
			}
			if ( is_array( $column ) ) {
				$column = $key;
			}
			$columnfield = '`' . $column . '`';
			if ( false !== $attributes['real_name'] ) {
				$columnfield = $attributes['real_name'];
			}
			$totals[] = 'SUM(' . $columnfield . ') AS ' . $this->backtick( 'exports_reports_total_' . $column );
		}
		if ( ! empty( $totals ) ) {
			$select_extras .= ' ' . implode( ',', $totals ) . ', ';
		}
		$sql               = preg_replace( '/ SELECT /i', " SELECT {$select_extras} ", $sql, 1 );
		$wheresql          = '';
		$havingsql         = '';
		$ordersql          = '';
		$limitsql          = '';
		$other_sql         = array();
		$having_sql        = array();
		$replace_variables = array();
		$selects           = array();
		// The columns the SELECT list names, for the sort check: its aliases and its bare column names. A list
		// with a * or an unaliased expression names fewer columns than the result has, so it settles nothing.
		$select_names = array();
		$select_known = isset( $selectmatches[1] ) && '' !== trim( $selectmatches[1] );
		if ( $select_known ) {
			$theselects = explode( ', ', $selectmatches[1] );
			if ( 1 === count( $theselects ) ) {
				$theselects = explode( ',', $selectmatches[1] );
			}
			foreach ( $theselects as $selected ) {
				$selectfield = preg_split( '/ AS /i', $selected );
				if ( count( $selectfield ) === 2 ) {
					$field             = trim( trim( $selectfield[1] ), '`' );
					$real_field        = trim( trim( $selectfield[0] ), '`' );
					$selects[ $field ] = $real_field;
					$select_names[]    = $field;
				} elseif ( preg_match( '/^`?(?:[a-z0-9_]+`?\.`?)?([a-z0-9_]+)`?$/i', trim( $selected ), $bare ) ) {
					$select_names[] = $bare[1];
				} else {
					$select_known = false;
				}
			}
		}
		if ( false !== $admin->search && ! empty( $admin->search_columns ) ) {
			if ( false !== $admin->search_query && 0 < strlen( $admin->search_query ) ) {
				foreach ( $admin->search_columns as $key => $column ) {
					$attributes = $column;
					if ( ! is_array( $attributes ) ) {
						$attributes = $admin->setup_column( $column );
					}
					if ( false === $attributes['search'] ) {
						continue;
					}
					if ( in_array( $attributes['type'], array( 'date', 'time', 'datetime' ), true ) ) {
						continue;
					}
					if ( is_array( $column ) ) {
						$column = $key;
					}
					if ( isset( $admin->filters[ $column ] ) ) {
						continue;
					}
					$columnfield = '`' . $column . '`';
					if ( isset( $selects[ $column ] ) ) {
						$columnfield = '`' . $selects[ $column ] . '`';
					}
					if ( false !== $attributes['real_name'] ) {
						$columnfield = $attributes['real_name'];
					}

					$filter_field_key = str_replace( '`', '', $columnfield );
					$like             = $columnfield . ' LIKE ' . $this->quote( '%' . $wpdb->esc_like( $admin->search_query ) . '%' );

					if ( false !== $attributes['group_related'] ) {
						$having_sql[ $filter_field_key ] = $like;
					} else {
						$other_sql[ $filter_field_key ] = $like;
					}
				}
				if ( ! empty( $other_sql ) ) {
					foreach ( $other_sql as $key => $value ) {
						if ( false !== stripos( $sql, '%%' . $key . '%%' ) ) {
							$replace_variables[ $key ] = $value;

							unset( $other_sql[ $key ] );
						}
					}
				}
				if ( ! empty( $other_sql ) ) {
					$other_sql = array( '(' . implode( ' OR ', $other_sql ) . ')' );
				}
				if ( ! empty( $having_sql ) ) {
					foreach ( $having_sql as $key => $value ) {
						if ( false !== stripos( $sql, '%%' . $key . '%%' ) ) {
							$replace_variables[ $key ] = $value;

							unset( $having_sql[ $key ] );
						}
					}
				}
				if ( ! empty( $having_sql ) ) {
					$having_sql = array( '(' . implode( ' OR ', $having_sql ) . ')' );
				}
			}
			foreach ( $admin->filters as $filter ) {
				if ( ! isset( $admin->search_columns[ $filter ] ) ) {
					continue;
				}

				$filter_column = $admin->search_columns[ $filter ];
				$filter_values = $admin->filter_values( 'filter_' . $filter, $filter_column['filter_default'] );

				$filterfield         = '`' . $filter . '`';
				$filter_sql          = false;
				$search_value        = false;
				$related_filterfield = false;

				if ( isset( $selects[ $filter ] ) ) {
					$filterfield = '`' . $selects[ $filter ] . '`';
				}
				if ( false !== $filter_column['real_name'] ) {
					$filterfield = $filter_column['real_name'];
				}
				if ( in_array( $filter_column['type'], array( 'date', 'datetime' ), true ) ) {
					$start        = date_i18n( 'Y-m-d' ) . ( 'datetime' === $filter_column['type'] ? ' 00:00:00' : '' );
					$end          = date_i18n( 'Y-m-d' ) . ( 'datetime' === $filter_column['type'] ? ' 23:59:59' : '' );
					$start_values = $admin->filter_values( 'filter_' . $filter . '_start', $filter_column['filter_default'] );
					$end_values   = $admin->filter_values( 'filter_' . $filter . '_end', $filter_column['filter_ongoing_default'] );
					if ( array() === $start_values && array() === $end_values ) {
						continue;
					}
					if ( array() !== $start_values ) {
						$start = date_i18n( 'Y-m-d', strtotime( $start_values[0] ) ) . ( 'datetime' === $filter_column['type'] ? ' 00:00:00' : '' );
					}
					if ( array() !== $end_values ) {
						$end = date_i18n( 'Y-m-d', strtotime( $end_values[0] ) ) . ( 'datetime' === $filter_column['type'] ? ' 23:59:59' : '' );
					}
					$from = $this->quote( $start );
					$to   = $this->quote( $end );

					if ( false !== $filter_column['date_ongoing'] ) {
						$date_ongoing = $filter_column['date_ongoing'];
						if ( isset( $selects[ $date_ongoing ] ) ) {
							$date_ongoing = $selects[ $date_ongoing ];
						}

						$filter_sql = "(($filterfield <= $from OR ($filterfield >= $from AND $filterfield <= $to)) AND ($date_ongoing >= $from OR ($date_ongoing >= $from AND $date_ongoing <= $to)))";
					} else {
						$filter_sql = "($filterfield BETWEEN $from AND $to)";
					}
				} elseif ( array() !== $filter_values && 'related' === $filter_column['type'] && false !== $filter_column['related'] ) {
					if ( ! is_array( $filter_column['related'] ) ) {
						$search_values = array();
						foreach ( $filter_values as $filter_value ) {
							$search_values[] = preg_match( '/[^0-9]/', $filter_value ) ? $wpdb->prepare( '%s', $filter_value ) : (string) (int) $filter_value;
						}
						$search_value        = implode( ', ', $search_values );
						$related_filterfield = '`' . $filter . '`.`' . $filter_column['related_id'] . '`';
						if ( isset( $selects[ $filter ] ) ) {
							$related_filterfield = '`' . $selects[ $filter ] . '`.`' . $filter_column['related_id'] . '`';
						}
						if ( false !== $filter_column['real_name'] ) {
							$related_filterfield = $filter_column['real_name'];
						}

						$filter_sql = 1 === count( $search_values ) ? "{$related_filterfield} = {$search_value}" : "{$related_filterfield} IN ({$search_value})";
					} else {
						$likes = array();
						foreach ( $filter_values as $filter_value ) {
							$likes[] = $filterfield . ' LIKE ' . $this->quote( '%' . $wpdb->esc_like( $filter_value ) . '%' );
						}
						$filter_sql = 1 === count( $likes ) ? $likes[0] : '(' . implode( ' OR ', $likes ) . ')';
					}
				} elseif ( 'bool' === $filter_column['type'] && array() !== $filter_values ) {
					$filter_sql = "$filterfield = " . (int) $filter_values[0];
				} elseif ( array() !== $filter_values ) {
					$filter_sql = $filterfield . ' LIKE ' . $this->quote( '%' . $wpdb->esc_like( $filter_values[0] ) . '%' );
				}

				if ( $filter_sql ) {
					/**
					 * Filters the condition a filter value adds to the query.
					 *
					 * @param string                   $filter_sql          The condition, values prepared: a literal % in a value
					 *                                                      stands behind wpdb's placeholder escape until the query runs.
					 * @param string                   $filter              The filter's column.
					 * @param array                    $filter_column       The column's attributes.
					 * @param string                   $filterfield         The column as an identifier.
					 * @param string|bool              $related_filterfield The related column, or false.
					 * @param string|array             $search_value        The value from the request.
					 * @param Exports_Reports_Admin_UI $admin               The report.
					 */
					$filter_sql = apply_filters( 'exports_reports_data_filter_sql', $filter_sql, $filter, $filter_column, $filterfield, $related_filterfield, $search_value, $admin );
					$filter_sql = apply_filters_deprecated( 'wp_admin_ui_data_filter_sql', array( $filter_sql, $filter, $filter_column, $filterfield, $related_filterfield, $search_value, $admin ), '0.9.5', 'exports_reports_data_filter_sql' );

					$filter_field_key = str_replace( '`', '', $filterfield );

					if ( false !== $filter_column['group_related'] ) {
						$having_sql[ $filter ] = $filter_sql;
					} else {
						$other_sql[ $filter ] = $filter_sql;
					}
				}
			}
			// A filter the definition wrote a %%name%% placeholder for goes there; the others join the WHERE or HAVING clause.
			foreach ( $other_sql + $having_sql as $key => $value ) {
				if ( false !== stripos( $sql, '%%' . $key . '%%' ) ) {
					$replace_variables[ $key ] = $value;

					unset( $other_sql[ $key ], $having_sql[ $key ] );
				}
			}
			$wheresql  = $this->clause_sql( $sql, 'WHERE', $other_sql );
			$havingsql = $this->clause_sql( $sql, 'HAVING', $having_sql );
		}
		if ( $admin->order_from_request && ( ! empty( $admin->columns ) || $select_known ) ) {
			// The request's column, lower-cased on the way in, against the report's columns as the report writes
			// them. A report whose columns are not known before it runs (no field definitions, a SELECT list that
			// names fewer columns than its result) takes the name as the identifier it was sanitized to; the
			// database checks it.
			$sort_column = false;

			foreach ( array_merge( $this->column_names(), $select_names ) as $name ) {
				if ( strtolower( $name ) === $admin->order ) {
					$sort_column = $name;
					break;
				}
			}

			if ( false === $sort_column ) {
				// The request named a column the report does not have: the report's own order applies.
				$admin->order              = $admin->order_default;
				$admin->order_dir          = $admin->order_dir_default;
				$admin->order_from_request = false;
			} else {
				$admin->order = $sort_column;
			}
		}
		if ( false !== $admin->order && ( false === $admin->reorder || 'reorder' !== $admin->action ) ) {
			$ordersql = trim( ( $admin->order_from_request ? $this->backtick( $admin->order ) : $admin->order ) . ' ' . $admin->order_dir );
		} elseif ( false !== $admin->reorder && 'reorder' === $admin->action ) {
			$ordersql = trim( $admin->reorder_order . ' ' . $admin->reorder_order_dir );
		}
		if ( ! empty( $ordersql ) ) {
			if ( false === stripos( $sql, ' ORDER BY ' ) ) {
				$ordersql = ' ORDER BY ' . $ordersql;
			} elseif ( false !== stripos( $sql, ' ORDER BY %%ORDERBY%% ' ) ) {
				$ordersql = $ordersql . ', ';
			} elseif ( false !== stripos( $sql, ' %%ORDERBY%% ' ) ) {
				$ordersql = ',' . $ordersql;
			} else {
				$ordersql = $ordersql . ', ';
			}
		}
		if ( false !== $admin->pagination && ! $full ) {
			$start = ( $admin->page - 1 ) * $admin->limit;

			$limitsql .= (int) $start . ',' . (int) $admin->limit;
		} else {
			$sql = preg_replace( '/\s+LIMIT\s+%%LIMIT%%\s*/i', ' ', $sql );
		}
		$sql = $this->place_clauses(
			$sql,
			array(
				'WHERE'   => $wheresql,
				'HAVING'  => $havingsql,
				'ORDERBY' => $ordersql,
				'LIMIT'   => $limitsql,
			),
			$replace_variables
		);
		if ( false !== $admin->sql_count ) {
			// The count query takes the same conditions, placed by its own shape, and one row.
			$sql_count = ' ' . preg_replace( '/\s+/', ' ', ' ' . $admin->sql_count ) . ' ';
			$sql_count = str_ireplace( ' SELECT SQL_CALC_FOUND_ROWS ', ' SELECT ', $sql_count );
			$sql_count = $this->place_clauses(
				$sql_count,
				array(
					'WHERE'  => $this->clause_sql( $sql_count, 'WHERE', $other_sql ),
					'HAVING' => $this->clause_sql( $sql_count, 'HAVING', $having_sql ),
					'LIMIT'  => '1',
				),
				$replace_variables
			);
		}

		return array(
			'sql'       => $sql,
			'sql_count' => isset( $sql_count ) ? $sql_count : '',
			'where'     => $wheresql,
			'having'    => $havingsql,
		);
	}
}
