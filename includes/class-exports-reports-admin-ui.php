<?php
/**
 * The admin UI of Exports and Reports: the manage, edit and reorder screens of the groups and the
 * reports, the report page with its filters and pagination, and the exports. Grown from the Admin UI
 * class for WordPress plugins (1.11.7) by Scott Kingsley Clark.
 *
 * @link    https://www.scottkclark.com/
 * @author  Scott Kingsley Clark
 * @package Exports_And_Reports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin runs the administrator's report queries and keeps its own tables; there is nothing to cache.

if ( ! defined( 'EXPORTS_REPORTS_EXPORT_DIR' ) ) {
	define( 'EXPORTS_REPORTS_EXPORT_DIR', defined( 'EXPORTS_REPORTS_EXPORT_DIR' ) ? EXPORTS_REPORTS_EXPORT_DIR : wp_get_upload_dir()['basedir'] . '/exports-and-reports' );
}

/**
 * Class Exports_Reports_Admin_UI
 *
 * One instance per screen: the options name the table, the columns and what the screen allows, and the
 * instance renders the list, the form and the exports for it.
 */
class Exports_Reports_Admin_UI {

	// base
	public $table              = false;
	public $identifier         = 'id';
	public $sql                = false;
	public $sql_count          = false;
	public $id                 = false;
	public $action             = 'manage';
	public $do                 = false;
	public $search             = true;
	public $filters            = array();
	public $search_query       = false;
	public $pagination         = true;
	public $page               = 1;
	public $limit              = 25;
	public $order              = false;
	public $order_dir          = 'DESC';
	public $order_default      = false;
	public $order_dir_default  = 'DESC';
	public $order_from_request = false;
	public $reorder_order      = false;
	public $reorder_order_dir  = 'ASC';
	public $api                = false;
	/** @var int The report the instance shows: Exports and Reports sets it; 0 for an instance without one. */
	public $report_id = 0;
	public $num       = '';

	// ui
	public $item    = 'Item';
	public $items   = 'Items';
	public $heading = array(
		'manage'    => 'Manage',
		'add'       => 'Add New',
		'edit'      => 'Edit',
		'duplicate' => 'Duplicate',
		'view'      => 'View',
		'reorder'   => 'Reorder',
	);
	public $icon    = false;
	public $css     = false;

	// actions
	public $add       = true;
	public $view      = false;
	public $edit      = true;
	public $duplicate = false;
	public $delete    = true;
	public $save      = true;
	public $readonly  = false;
	public $export    = false;
	public $reorder   = false;

	// array of custom functions to run for actions
	public $custom = array();

	// data related
	public $total = 0;

	/** @var bool|array $totals */
	public $totals = false;

	public $columns   = array();
	public $data      = array();
	public $sum_data  = array();
	public $full_data = array();
	// Delimited exports read their rows from an unbuffered result; see export() and export_sv().
	public $stream_export   = false;
	public $stream_sql      = '';
	public $row             = array();
	public $temp_row        = array();
	public $default_none    = false;
	public $search_columns  = array();
	public $form_columns    = array();
	public $view_columns    = array();
	public $export_columns  = array();
	public $reorder_columns = array();
	public $insert_id       = 0;
	public $related         = array();

	// export related
	public $exported_file    = false;
	public $export_url       = false;
	public $export_type      = false;
	public $export_delimiter = false;
	public $page_orientation = 'P';

	public $base_dir   = '';
	public $base_url   = '';
	public $assets_url = '';

	/**
	 * @param bool|array $options
	 */
	public function __construct( $options = false ) {
		/**
		 * Fires before the admin UI reads its options.
		 *
		 * @param bool|array $options The options as given.
		 */
		do_action( 'exports_reports_pre_init', $options );
		do_action_deprecated( 'wp_admin_ui_pre_init', array( $options ), '0.9.5', 'exports_reports_pre_init' );
		/**
		 * Filters the options of a report screen before they take effect.
		 *
		 * @since 1.0.0 The options and the instance, one by one; the array form stays with wp_admin_ui_options.
		 *
		 * @param array                   $options The options the screen was given.
		 * @param Exports_Reports_Admin_UI $admin   The screen.
		 */
		$options          = apply_filters( 'exports_reports_options', $options, $this );
		$options          = $this->legacy_hook( 'options', array( $options ) );
		$this->base_dir   = __DIR__;
		$this->base_url   = EXPORTS_REPORTS_URL;
		$this->export_url = admin_url( 'admin-ajax.php' ) . '?action=' . EXPORTS_REPORTS_EXPORT_ACTION . '&exports_and_reports_download=1&_wpnonce=' . wp_create_nonce( EXPORTS_REPORTS_EXPORT_NONCE ) . '&exports_and_reports_export=';
		$this->assets_url = EXPORTS_REPORTS_URL . 'assets';
		$this->item       = __( 'Item', 'exports-and-reports' );
		$this->items      = __( 'Items', 'exports-and-reports' );
		$this->heading    = array(
			'manage'    => __( 'Manage', 'exports-and-reports' ),
			'add'       => __( 'Add New', 'exports-and-reports' ),
			'edit'      => __( 'Edit', 'exports-and-reports' ),
			'duplicate' => __( 'Duplicate', 'exports-and-reports' ),
			'view'      => __( 'View', 'exports-and-reports' ),
			'reorder'   => __( 'Reorder', 'exports-and-reports' ),
		);
		// The options first: what the request names below (the action, the page, the sort column) stands above them.
		if ( false !== $options && ! empty( $options ) ) {
			if ( ! is_array( $options ) ) {
				parse_str( $options, $options );
			}
			foreach ( $options as $option => $value ) {
				$this->{$option} = $value;
			}
		}
		$id = $this->get_var( 'id' );
		if ( false !== $id ) {
			$this->id = $id;
		}
		$action = $this->get_var(
			'action',
			false,
			array(
				'add',
				'edit',
				'duplicate',
				'view',
				'delete',
				'manage',
				'reorder',
				'export',
			)
		);
		if ( false !== $action ) {
			$this->action = $action;
		}
		$do = $this->get_var( 'do', false, array( 'save', 'create' ) );
		if ( false !== $do ) {
			$this->do = $do;
		}
		$search_query = $this->get_var( 'search_query' );
		if ( false !== $search_query ) {
			$this->search_query = $search_query;
		}
		if ( false !== $this->get_var( 'pg' ) ) {
			$this->page = max( 1, absint( $this->get_var( 'pg' ) ) );
		}
		if ( false !== $this->get_var( 'limit' ) ) {
			$this->limit = absint( $this->get_var( 'limit' ) );
		}
		$this->order_default     = $this->order;
		$this->order_dir_default = $this->order_dir;
		$order                   = $this->get_var( 'order' );
		if ( is_string( $order ) && '' !== $order ) {
			$order = preg_replace( '/([- ])/', '_', trim( $order ) );
			$order = preg_replace( '/([^0-9a-z_])/', '', strtolower( $order ) );
			$order = preg_replace( '/(_){2,}/', '_', $order );

			$this->order              = $order;
			$this->order_from_request = true;
		}
		$order_dir = $this->get_var( 'order_dir', false, array( 'ASC', 'DESC' ) );
		if ( false !== $order_dir ) {
			$this->order_dir = $order_dir;
		}
		if ( 'export' === $this->action ) {
			$export_type = $this->get_var(
				'export_type',
				false,
				array(
					'csv',
					'tsv',
					'pipe',
					'xlsx',
					'custom',
					'xml',
					'json',
					'pdf',
				)
			);
			if ( false !== $export_type ) {
				$this->export_type = $export_type;
			}
			$export_delimiter = $this->get_var( 'export_delimiter' );
			if ( 'custom' === $this->export_type && false !== $export_delimiter ) {
				$this->export_delimiter = $export_delimiter;
			}
		}

		if ( false !== $this->readonly ) {
			$this->add     = false;
			$this->edit    = false;
			$this->delete  = false;
			$this->save    = false;
			$this->reorder = false;
		}
		if ( false !== $this->reorder && false === $this->reorder_order ) {
			$this->reorder_order = $this->reorder;
		}
		if ( ! empty( $this->columns ) ) {
			$this->columns = $this->setup_columns();
		}
		if ( ! empty( $this->filters ) && ! is_array( $this->filters ) ) {
			$this->filters = explode( ',', $this->filters );
		}
		/**
		 * Fires once a report screen is set up.
		 *
		 * @since 1.0.0 The options and the instance, one by one.
		 *
		 * @param array                   $options The options.
		 * @param Exports_Reports_Admin_UI $admin   The screen.
		 */
		do_action( 'exports_reports_post_init', $options, $this );
		$this->legacy_hook( 'post_init', array( $options ) );
	}

	/**
	 * A request value, unslashed and sanitised (see clean_input()), or $fallback when it is absent or not among $allowed.
	 *
	 * @param string            $index    The request key.
	 * @param mixed             $fallback What to return when the key is absent or not allowed.
	 * @param bool|string|array $allowed  The accepted values, or false for any.
	 * @param bool|string|array $source   The array to read: 'post' for $_POST, an array of your own, or $_GET.
	 *
	 * @return mixed The value, as filtered by exports_reports_get_var.
	 */
	public function get_var( $index, $fallback = false, $allowed = false, $source = false ) {
		if ( ! is_array( $source ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- a read accessor; save(), reorder() and the delete action verify their nonces before anything is written.
			$source = ( 'post' === $source ) ? $_POST : $_GET;
		}
		if ( false !== $allowed && ! is_array( $allowed ) ) {
			$allowed = array( $allowed );
		}
		$value = $fallback;
		if ( isset( $source[ $index ] ) ) {
			$candidate = $this->clean_input( $source[ $index ] );
			if ( false === $allowed || in_array( $candidate, $allowed, true ) ) {
				$value = $candidate;
			}
		}

		/**
		 * Filters a request value as the screen reads it.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed                   $value    The value, or the fallback.
		 * @param string                  $index    The request key.
		 * @param mixed                   $fallback The fallback.
		 * @param array|false             $allowed  The values allowed, or false for any.
		 * @param array|false             $source   The array read instead of the request, or false.
		 * @param Exports_Reports_Admin_UI $admin    The screen.
		 */
		$value = apply_filters( 'exports_reports_get_var', $value, $index, $fallback, $allowed, $source, $this );

		return $this->legacy_hook( 'get_var', array( $value, $index, $fallback, $allowed, $source ) );
	}

	/**
	 * Unslashes and sanitises a request value: text through sanitize_text_field(), arrays recursively, keys included.
	 *
	 * @param mixed $value A request value.
	 *
	 * @return string|array
	 */
	public function clean_input( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				$clean[ sanitize_text_field( (string) $key ) ] = $this->clean_input( $item );
			}

			return $clean;
		}

		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * The query string, unslashed and sanitised: the state that links and hidden fields carry forward.
	 *
	 * @return array
	 */
	public function query_vars() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation state; nothing is written from it.
		return $this->clean_input( $_GET );
	}

	/**
	 * The request URI, unslashed and escaped for use in a URL; empty on the command line.
	 *
	 * @return string
	 */
	public function request_uri() {
		return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	}

	/**
	 * @param bool|array $exclude
	 */
	public function hidden_vars( $exclude = false ) {
		/**
		 * Filters the request keys the search form does not carry as hidden fields.
		 *
		 * @since 1.0.0
		 *
		 * @param array|false             $exclude The keys, or false for the default list.
		 * @param Exports_Reports_Admin_UI $admin   The screen.
		 */
		$exclude = apply_filters( 'exports_reports_hidden_vars', $exclude, $this );
		$exclude = $this->legacy_hook( 'hidden_vars', array( $exclude ) );
		if ( false === $exclude ) {
			$exclude = array();
		}
		if ( ! is_array( $exclude ) ) {
			$exclude = explode( ',', $exclude );
		}
		foreach ( $this->query_vars() as $k => $v ) {
			if ( ! is_scalar( $v ) || in_array( $k, $exclude, true ) ) {
				continue;
			}
			?>
			<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>" />
			<?php
		}
	}

	/**
	 * Runs the wp_admin_ui_<name> hook of the versions before 0.9.5, deprecated, after the
	 * exports_reports_<name> hook has run. Its callbacks receive the arguments the way they always did:
	 * as one array indexed from 1 and, second, an array of this instance and the hook name. The first
	 * argument comes back as they left it.
	 *
	 * @since 1.0.0 The exports_reports_<name> hooks pass their arguments one by one; only the old names keep the array.
	 *
	 * @param string $name The hook name without its prefix.
	 * @param array  $args The arguments of the exports_reports_<name> hook, without this instance.
	 *
	 * @return mixed The first argument, or null when the hook has none.
	 */
	public function legacy_hook( $name, array $args = array() ) {
		$first = isset( $args[0] ) ? $args[0] : null;

		if ( ! has_filter( 'wp_admin_ui_' . $name ) ) {
			return $first;
		}

		$legacy = array();

		foreach ( array_values( $args ) as $position => $arg ) {
			$legacy[ $position + 1 ] = $arg;
		}

		$legacy = apply_filters_deprecated( 'wp_admin_ui_' . $name, array( $legacy, array( &$this, 'wp_admin_ui_' . $name ) ), '0.9.5', 'exports_reports_' . $name );

		return isset( $legacy[1] ) ? $legacy[1] : $first;
	}

	/**
	 * Whether a function named in a report definition may be called back with a value: a function
	 * WordPress, a plugin or a theme defines. PHP's own functions are not called: none of them takes the
	 * arguments the callbacks receive, and the variadic ones among them could reach anything. The
	 * exports_reports_allowed_callback filter decides for a name this rule does not settle the way a site needs.
	 *
	 * @param mixed $callback The function name.
	 *
	 * @return bool
	 */
	public function is_allowed_callback( $callback ) {
		$allowed = false;

		if ( is_string( $callback ) && '' !== $callback && function_exists( $callback ) ) {
			$allowed = ! ( new ReflectionFunction( $callback ) )->isInternal();
		}

		/**
		 * Filters whether a function named in a report definition is called back.
		 *
		 * @since 1.0.0 The arguments one by one; the array form stays with wp_admin_ui_allowed_callback.
		 *
		 * @param bool                    $allowed  Whether it is called.
		 * @param string                  $callback The function name.
		 * @param Exports_Reports_Admin_UI $admin    The screen.
		 */
		$allowed = apply_filters( 'exports_reports_allowed_callback', $allowed, $callback, $this );

		return false !== $this->legacy_hook( 'allowed_callback', array( $allowed, $callback ) );
	}

	/**
	 * A request value as a list of non-empty strings: a multiple select sends an array, the other
	 * inputs a string, and the SQL and the form read both the same way.
	 *
	 * @param string      $name    The query variable.
	 * @param bool|string $fallback The value when the request has none.
	 *
	 * @return string[]
	 */
	public function filter_values( $name, $fallback = false ) {
		$value  = $this->get_var( $name, $fallback );
		$values = array();

		if ( false === $value || null === $value ) {
			return $values;
		}

		foreach ( (array) $value as $item ) {
			if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
				$values[] = (string) $item;
			}
		}

		return $values;
	}

	/**
	 * The name of a new export file: the report's name as a slug of ASCII letters, digits and
	 * underscores ('export' when nothing of it survives), the time, a random suffix and the extension.
	 * A name the download link can carry as it is: export.php and the REST route read it back through
	 * sanitize_file_name().
	 *
	 * @param string $extension The extension, without the dot.
	 *
	 * @return string
	 */
	public function export_file_name( $extension ) {
		$slug = preg_replace( '/%[0-9a-f]{2}/i', '', sanitize_title( (string) $this->items ) );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( (string) $slug ) ), '_' );

		if ( '' === $slug ) {
			$slug = 'export';
		}

		return $slug . '_' . date_i18n( 'm-d-Y_H-i-s' ) . '_' . wp_generate_password( 8, false ) . '.' . $extension;
	}

	/**
	 * The file an export writes: export_file_name() after the exports_reports_export_file filter.
	 *
	 * @since 0.9.5
	 *
	 * @param string $extension The extension, without the dot.
	 *
	 * @return string The file name, in the export directory.
	 */
	public function export_file( $extension ) {
		/**
		 * Filters the name of the file an export writes.
		 *
		 * @param string                   $export_file The name export_file_name() made.
		 * @param string                   $export_type The export type (csv, tsv, pipe, custom, xml, xlsx, json, pdf).
		 * @param string                   $extension   The file extension.
		 * @param string                   $items       The report's plural label.
		 * @param Exports_Reports_Admin_UI $admin       The report.
		 */
		$export_file = apply_filters( 'exports_reports_export_file', $this->export_file_name( $extension ), $this->export_type, $extension, $this->items, $this );

		return apply_filters_deprecated( 'wp_admin_ui_export_file', array( $export_file, $this->export_type, $this->export_type, $this->items, $this ), '0.9.5', 'exports_reports_export_file' );
	}

	/**
	 * The columns an export writes, each with its attributes, in order.
	 *
	 * @since 0.9.5
	 *
	 * @return array<string, array> Keyed by column.
	 */
	public function exported_columns() {
		$columns = array();

		foreach ( (array) $this->export_columns as $key => $attributes ) {
			if ( ! is_array( $attributes ) ) {
				$key        = $attributes;
				$attributes = $this->setup_column( $key );
			}

			if ( false !== $attributes['export'] ) {
				$columns[ $key ] = $attributes;
			}
		}

		return $columns;
	}

	/**
	 * One row of an export: the exported columns' values, formatted by field_value() and the column's
	 * display function.
	 *
	 * @since 0.9.5
	 *
	 * @param array $item    The row as the query returned it.
	 * @param array $columns exported_columns().
	 *
	 * @return array<string, mixed> Keyed by column.
	 */
	public function export_row( array $item, array $columns ) {
		$row = array();

		foreach ( $columns as $key => $attributes ) {
			$item[ $key ] = $this->field_value( $item[ $key ], $key, $attributes );

			if ( false !== $attributes['custom_display'] && function_exists( "{$attributes['custom_display']}" ) ) {
				$item[ $key ] = $attributes['custom_display']( $item[ $key ], $item, $key, $attributes, $this );
			}

			$row[ $key ] = $item[ $key ];
		}

		return $row;
	}

	/**
	 * Tells the user an export is written: the message with the download and removal links, and the
	 * script that opens the download.
	 *
	 * @since 0.9.5
	 *
	 * @param string $export_file The file name, in the export directory.
	 * @param string $format      The format, for the message (csv, pdf, ...).
	 */
	public function export_ready( $export_file, $format ) {
		$download = $this->export_url . rawurlencode( $export_file );

		$this->message(
			sprintf(
				/* translators: 1: a link to the export file, 2: a link that removes it. */
				__( '<strong>Success:</strong> Your export is ready, the download should begin in a few moments. If it does not, %1$s.<br /><br />When you are done with your export, %2$s; otherwise the export will be deleted within 24 hours of generation.', 'exports-and-reports' ),
				/* translators: %s: the export format (CSV, PDF, ...). */
				'<a href="' . esc_url( $download ) . '" target="_blank">' . esc_html( sprintf( __( 'click here to download your %s export file', 'exports-and-reports' ), strtoupper( $format ) ) ) . '</a>',
				'<a href="' . esc_url( $this->export_link( array( 'remove_export' => $export_file ) ) ) . '">' . esc_html__( 'click here to remove it', 'exports-and-reports' ) . '</a>'
			)
		);
		wp_print_inline_script_tag( 'window.open( ' . wp_json_encode( $download ) . ' );' );
	}

	/**
	 * The extensions export files have.
	 *
	 * @return string[]
	 */
	public static function export_file_extensions() {
		return array( 'csv', 'tsv', 'txt', 'xlsx', 'xml', 'json', 'pdf' );
	}

	/**
	 * Whether a file name is one an export writes: an export extension, and not one of the files that
	 * keep the directory from being served.
	 *
	 * @param mixed $file The file name.
	 *
	 * @return bool
	 */
	public static function is_export_file_name( $file ) {
		$file = (string) $file;

		return '' !== $file && ! in_array( $file, array( 'index.php', '.htaccess' ), true ) && in_array( strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ), self::export_file_extensions(), true );
	}

	/**
	 * A link that runs or removes an export: var_update() with the action and the nonce export() checks.
	 *
	 * @param array $args The query arguments (export_type, or remove_export).
	 *
	 * @return string
	 */
	public function export_link( $args ) {
		return $this->var_update( array_merge( array( 'action' => 'export' ), $args, array( '_wpnonce' => wp_create_nonce( 'exports-reports-export-request' ) ) ) );
	}

	/**
	 * Whether the request carries the nonce export_link() put in it. The REST route does not come through
	 * here: its token or user is the credential.
	 *
	 * @return bool
	 */
	public function verify_export_request() {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'exports-reports-export-request' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array|bool  $vars      The query variables to set, or false for the current ones.
	 * @param array|bool  $allowed   Excluded variables to keep anyway.
	 * @param bool|string $url       The URL to build on, or false for the current request.
	 * @param bool        $exclusive Whether to drop every current variable first.
	 *
	 * @return string
	 */
	public function var_update( $vars = false, $allowed = false, $url = false, $exclusive = false ) {
		$excluded = array(
			'do',
			'id',
			'pg',
			'search_query',
			'order',
			'order_dir',
			'limit',
			'action',
			'exports_and_reports_download',
			'exports_and_reports_export',
			'export_type',
			'export_delimiter',
			'remove_export',
			'_wpnonce',
			'updated',
			'duplicate',
		);
		if ( false === $allowed ) {
			$allowed = array();
		}
		$get = $this->query_vars();
		if ( is_array( $vars ) ) {
			if ( false === $exclusive ) {
				foreach ( $excluded as $exclusion ) {
					if ( isset( $get[ $exclusion ] ) && ! isset( $vars[ $exclusion ] ) && ! in_array( $exclusion, $allowed, true ) ) {
						unset( $get[ $exclusion ] );
					}
				}
			} else {
				$get = array();
			}
			foreach ( $vars as $key => $val ) {
				if ( 0 < strlen( $val ) ) {
					$get[ $key ] = $val;
				} elseif ( isset( $get[ $key ] ) ) {
					unset( $get[ $key ] );
				}
			}
		}
		if ( false === $url ) {
			$url = '';
		} else {
			$url = explode( '?', $this->request_uri() );
			$url = explode( '#', $url[0] );
			$url = $url[0];
		}

		/**
		 * Filters a link of the screen after its query has been built.
		 *
		 * @since 1.0.0
		 *
		 * @param string                  $link    The link.
		 * @param array                   $vars    The query variables set or removed.
		 * @param array|false             $allowed The variables carried over from the request, or false for all.
		 * @param string                  $url     The link without its query.
		 * @param Exports_Reports_Admin_UI $admin   The screen.
		 */
		$link = apply_filters( 'exports_reports_var_update', $url . '?' . http_build_query( $get ), $vars, $allowed, $url, $this );

		return $this->legacy_hook( 'var_update', array( $link, $vars, $allowed, $url ) );
	}

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

		foreach ( (array) $this->columns as $key => $column ) {
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

	public function catch_columns( $full = false ) {
		$data = $this->data;
		if ( $full ) {
			$data = $this->full_data;
		}
		if ( ! empty( $data ) && is_array( $data ) && isset( $data[0] ) ) {
			$data = current( $data );
		}
		$columns = array();
		foreach ( (array) $data as $column => $value ) {
			$column = trim( str_replace( '`', '', $column ) );
			if ( 0 < strlen( $column ) ) {
				$columns[] = $column;
			}
		}
		$this->columns = $this->setup_columns( $columns, 'columns', true );
	}

	/**
	 * @param null|array $columns
	 * @param string     $which
	 * @param bool       $init
	 *
	 * @return bool
	 */
	public function setup_columns( $columns = null, $which = 'columns', $init = false ) {
		if ( null === $columns ) {
			$columns = $this->{$which};
			if ( 'columns' === $which ) {
				$init = true;
			}
		}
		if ( ! empty( $columns ) ) {
			$new_columns = array();
			$filterable  = false;
			if ( empty( $this->filters ) && ( empty( $this->search_columns ) || 'search_columns' === $which ) && false !== $this->search ) {
				$filterable    = true;
				$this->filters = array();
			}
			foreach ( $columns as $column => $attributes ) {
				if ( ! is_array( $attributes ) ) {
					$column = $attributes;
				}
				$attributes = $this->setup_column( $column, $attributes, $filterable );
				if ( 'search_columns' === $which && false === $attributes['search'] ) {
					continue;
				}
				$new_columns[ $column ] = $attributes;
			}
			$columns = $new_columns;
		}
		if ( false !== $init ) {
			if ( ! empty( $this->form_columns ) && ( $this->edit || $this->add || $this->duplicate ) ) {
				$this->form_columns = $this->setup_columns( $this->form_columns, 'form_columns' );
			} else {
				$this->form_columns = $columns;
			}
			if ( ! empty( $this->view_columns ) && $this->view ) {
				$this->view_columns = $this->setup_columns( $this->view_columns, 'view_columns' );
			} else {
				$this->view_columns = $this->form_columns;
			}
			if ( ! empty( $this->search_columns ) && $this->search ) {
				$this->search_columns = $this->setup_columns( $this->search_columns, 'search_columns' );
			} else {
				$this->search_columns = $columns;
			}
			if ( ! empty( $this->export_columns ) && $this->export ) {
				$this->export_columns = $this->setup_columns( $this->export_columns, 'export_columns' );
			} else {
				$this->export_columns = $columns;
			}
			if ( ! empty( $this->reorder_columns ) && $this->reorder ) {
				$this->reorder_columns = $this->setup_columns( $this->reorder_columns, 'reorder_columns' );
			} else {
				$this->reorder_columns = $columns;
			}
		}

		/**
		 * Filters the column definitions once they are complete.
		 *
		 * @since 1.0.0
		 *
		 * @param array                   $columns The columns, by name.
		 * @param string                  $which   Which set: columns, reorder_columns, form_columns, ...
		 * @param bool                    $init    Whether the screen is being set up.
		 * @param Exports_Reports_Admin_UI $admin   The screen.
		 */
		$columns = apply_filters( 'exports_reports_setup_columns', $columns, $which, $init, $this );

		return $this->legacy_hook( 'setup_columns', array( $columns, $which, $init ) );
	}

	public function setup_column( $column = null, $attributes = null, $filterable = false ) {
		// Available Attributes
		// type = field type
		// type = date (data validation as date)
		// type = time (data validation as time)
		// type = datetime (data validation as datetime)
		// date_touch = use current timestamp when saving (even if readonly, if type is date-related)
		// date_touch_on_create = use current timestamp when saving ONLY on create (even if readonly, if type is date-related)
		// date_ongoing = use this additional column to search between as if the first is the "start" and the date_ongoing is the "end" for filter
		// type = text / other (single line text box)
		// type = desc (textarea)
		// type = number (data validation as int float)
		// type = decimal (data validation as decimal)
		// type = password (single line password box)
		// type = bool (checkbox)
		// type = related (select box)
		// related = table to relate to (if type=related) OR custom array of (key=>label or comma separated values) items
		// related_field = field name on table to show (if type=related) - default "name"
		// related_multiple = true (ability to select multiple values if type=related)
		// related_sql = custom where / order by SQL (if type=related)
		// readonly = true (shows as text)
		// display = false (doesn't show on form, but can be saved)
		// search = this field is searchable
		// filter = this field will be independently searchable (by default, searchable fields are searched by the primary search box)
		// comments = comments to show for field
		// comments_top = true (shows comments above field instead of below)
		// real_name = the real name of the field (if using an alias for 'name')
		// group_related = true (uses HAVING instead of WHERE for filtering column)
		if ( ! is_array( $attributes ) ) {
			if ( null !== $attributes ) {
				$column = $attributes;
			}
			$attributes = array();
		}
		if ( ! isset( $attributes['real_name'] ) ) {
			$attributes['real_name'] = false;
		}
		if ( ! isset( $attributes['label'] ) ) {
			$attributes['label'] = ucwords( str_replace( '_', ' ', $column ) );
		}
		if ( ! isset( $attributes['type'] ) ) {
			$attributes['type'] = 'text';
		}
		if ( 'related' !== $attributes['type'] || ! isset( $attributes['custom_relate'] ) ) {
			$attributes['custom_relate'] = false;
		}
		if ( 'related' !== $attributes['type'] || ! isset( $attributes['related'] ) ) {
			$attributes['related'] = false;
		}
		if ( 'related' !== $attributes['type'] || ! isset( $attributes['related_id'] ) ) {
			$attributes['related_id'] = 'id';
		}
		if ( 'related' !== $attributes['type'] || ! isset( $attributes['related_field'] ) ) {
			$attributes['related_field'] = 'name';
		}
		if ( 'related' !== $attributes['type'] || ! isset( $attributes['related_multiple'] ) ) {
			$attributes['related_multiple'] = false;
		}
		if ( 'related' !== $attributes['type'] || ! isset( $attributes['related_sql'] ) ) {
			$attributes['related_sql'] = false;
		}
		if ( 'related' === $attributes['type'] && ( is_array( $attributes['related'] ) || strpos( $attributes['related'], ',' ) ) ) {
			if ( ! is_array( $attributes['related'] ) ) {
				$related_items = array();
				foreach ( explode( ',', $attributes['related'] ) as $label ) {
					$related_items[ $label ] = ucwords( str_replace( '_', ' ', $label ) );
				}
				$attributes['related'] = $related_items;
			}
			if ( empty( $attributes['related'] ) ) {
				$attributes['related'] = false;
			}
		}
		if ( ! isset( $attributes['readonly'] ) ) {
			$attributes['readonly'] = false;
		}
		if ( ! isset( $attributes['date_touch'] ) || ! in_array(
			$attributes['type'],
			array(
				'date',
				'time',
				'datetime',
			),
			true
		) ) {
			$attributes['date_touch'] = false;
		}
		if ( ! isset( $attributes['date_touch_on_create'] ) || ! in_array(
			$attributes['type'],
			array(
				'date',
				'time',
				'datetime',
			),
			true
		) ) {
			$attributes['date_touch_on_create'] = false;
		}
		if ( ! isset( $attributes['display'] ) ) {
			$attributes['display'] = true;
		}
		if ( ! isset( $attributes['search'] ) || false === $this->search ) {
			$attributes['search'] = ( false !== $this->search ? true : false );
		}
		if ( ! isset( $attributes['filter'] ) || false === $this->search ) {
			$attributes['filter'] = false;
		}
		if ( false !== $attributes['filter'] && false !== $filterable ) {
			$this->filters[] = $column;
		}
		if ( false === $attributes['filter'] || ! isset( $attributes['filter_label'] ) || ! in_array( $column, $this->filters, true ) ) {
			$attributes['filter_label'] = $attributes['label'];
		}
		if ( false === $attributes['filter'] || ! isset( $attributes['filter_default'] ) || ! in_array( $column, $this->filters, true ) ) {
			$attributes['filter_default'] = false;
		}
		if ( false === $attributes['filter'] || ! isset( $attributes['date_ongoing'] ) || ! in_array(
			$attributes['type'],
			array(
				'date',
				'time',
				'datetime',
			),
			true
		) || ! in_array( $column, $this->filters, true ) ) {
			$attributes['date_ongoing'] = false;
		}
		if ( false === $attributes['filter'] || ! in_array(
			$attributes['type'],
			array(
				'date',
				'time',
				'datetime',
			),
			true
		) || ! isset( $attributes['filter_ongoing_default'] ) || ! in_array( $column, $this->filters, true ) ) {
			$attributes['filter_ongoing_default'] = false;
		}
		if ( ! isset( $attributes['export'] ) ) {
			$attributes['export'] = true;
		}
		if ( ! isset( $attributes['total_field'] ) ) {
			$attributes['total_field'] = false;
		}
		if ( ! isset( $attributes['group_related'] ) ) {
			$attributes['group_related'] = false;
		}
		if ( ! isset( $attributes['comments'] ) ) {
			$attributes['comments'] = '';
		}
		if ( ! isset( $attributes['comments_top'] ) ) {
			$attributes['comments_top'] = false;
		}
		if ( ! isset( $attributes['custom_input'] ) ) {
			$attributes['custom_input'] = false;
		}
		if ( ! isset( $attributes['custom_display'] ) ) {
			$attributes['custom_display'] = false;
		}
		if ( false !== $attributes['custom_display'] && ! $this->is_allowed_callback( $attributes['custom_display'] ) ) {
			$attributes['custom_display'] = false;
		}
		if ( ! isset( $attributes['custom_form_display'] ) ) {
			$attributes['custom_form_display'] = false;
		}
		if ( ! isset( $attributes['custom_view'] ) ) {
			$attributes['custom_view'] = $attributes['custom_display'];
		}
		if ( ! isset( $attributes['width'] ) ) {
			$attributes['width'] = '';
		}

		/**
		 * Filters one column definition once it is complete.
		 *
		 * @since 1.0.0
		 *
		 * @param array                   $attributes The column.
		 * @param string                  $column     Its name.
		 * @param bool                    $filterable Whether the column may filter the list.
		 * @param Exports_Reports_Admin_UI $admin      The screen.
		 */
		$attributes = apply_filters( 'exports_reports_setup_column', $attributes, $column, $filterable, $this );

		return $this->legacy_hook( 'setup_column', array( $attributes, $column, $filterable ) );
	}

	/**
	 * The HTML a display function, a view function or the header callback may return: the tags of post
	 * content, which the exports_reports_allowed_html filter may extend.
	 *
	 * @since 0.9.5
	 *
	 * @return array<string, array<string, bool>> As wp_kses() takes it.
	 */
	public function allowed_html() {
		/**
		 * Filters the HTML the report screens let a callback's output keep.
		 *
		 * @param array                    $allowed wp_kses_allowed_html( 'post' ).
		 * @param Exports_Reports_Admin_UI $admin   The report.
		 */
		return apply_filters( 'exports_reports_allowed_html', wp_kses_allowed_html( 'post' ), $this );
	}

	public function message( $msg ) {
		/**
		 * Filters a message before the screen shows it.
		 *
		 * @since 1.0.0
		 *
		 * @param string                  $msg   The message, HTML.
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		$msg = apply_filters( 'exports_reports_message', $msg, $this );
		$msg = $this->legacy_hook( 'message', array( $msg ) );
		?>
		<div id="message" class="updated fade"><p><?php echo wp_kses_post( $msg ); ?></p></div>
		<?php
	}

	public function error( $msg ) {
		/**
		 * Filters an error message before the screen shows it.
		 *
		 * @since 1.0.0
		 *
		 * @param string                  $msg   The message, HTML.
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		$msg = apply_filters( 'exports_reports_error', $msg, $this );
		$msg = $this->legacy_hook( 'error', array( $msg ) );
		?>
		<div id="message" class="error fade"><p><?php echo wp_kses_post( $msg ); ?></p></div>
		<?php
		return false;
	}

	public function go() {
		/**
		 * Fires before the screen dispatches the request.
		 *
		 * @since 1.0.0
		 *
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		do_action( 'exports_reports_go', $this );
		$this->legacy_hook( 'go' );

		if ( false !== $this->css ) {
			wp_enqueue_style( 'exports-reports-admin-ui-css', $this->css, array(), EXPORTS_REPORTS_VERSION );
		}

		wp_enqueue_script( 'exports-reports-admin-ui', $this->assets_url . '/js/admin-ui.js', array( 'jquery' ), EXPORTS_REPORTS_VERSION, true );

		if ( isset( $this->custom[ $this->action ] ) && function_exists( "{$this->custom[$this->action]}" ) ) {
			call_user_func( $this->custom[ $this->action ], $this );
		} elseif ( 'add' === $this->action && $this->add ) {
			if ( 'create' === $this->do && $this->save && ! empty( $_POST ) && ! empty( $_POST['_wpnonce'] ) && false !== wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'exports-reports-form-' . $this->do ) ) {
				$this->save( 1 );
				if ( false === $this->api ) {
					$this->manage();
				}
			} else {
				$this->add();
			}
		} elseif ( ( 'edit' === $this->action && $this->edit ) || ( 'duplicate' === $this->action && $this->duplicate ) ) {
			if ( 'save' === $this->do && $this->save && ! empty( $_POST ) && ! empty( $_POST['_wpnonce'] ) && false !== wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'exports-reports-form-' . $this->do ) ) {
				$this->save();
			}
			$this->edit( ( 'duplicate' === $this->action && $this->duplicate ? 1 : 0 ) );
		} elseif ( 'delete' === $this->action && $this->delete && ! empty( $_GET['_wpnonce'] ) && false !== wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'exports-reports-' . $this->action ) ) {
			$this->delete();
			if ( false === $this->api ) {
				$this->manage();
			}
		} elseif ( 'reorder' === $this->action && $this->reorder ) {
			if ( false === $this->table ) {
				return $this->error( __( '<strong>Error:</strong> Invalid Configuration - Missing "table" definition.', 'exports-and-reports' ) );
			}
			if ( false === $this->identifier ) {
				return $this->error( __( '<strong>Error:</strong> Invalid Configuration - Missing "identifier" definition.', 'exports-and-reports' ) );
			}
			if ( 'save' === $this->do ) {
				$this->reorder();
			}
			if ( false === $this->api ) {
				$this->manage( 1 );
			}
		} elseif ( 'save' === $this->do && $this->save && ! empty( $_POST ) && ! empty( $_POST['_wpnonce'] ) && false !== wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'exports-reports-form-' . $this->do ) ) {
			$this->save();
			if ( false === $this->api ) {
				$this->manage();
			}
		} elseif ( 'create' === $this->do && $this->save && ! empty( $_POST ) && ! empty( $_POST['_wpnonce'] ) && false !== wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'exports-reports-form-' . $this->do ) ) {
			$this->save( 1 );
			if ( false === $this->api ) {
				$this->manage();
			}
		} elseif ( 'view' === $this->action && $this->view ) {
			$this->view();
		} elseif ( false === $this->api ) {
			$this->manage();
		}
	}

	public function add() {
		/**
		 * Fires before the add form.
		 *
		 * @since 1.0.0
		 *
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		do_action( 'exports_reports_add', $this );
		$this->legacy_hook( 'add' );
		?>
		<div class="wrap wp-admin-ui-wrap">
			<div id="icon-edit-pages" class="icon32"
				<?php
				if ( false !== $this->icon ) {
					?>
					style="background-image:url(<?php echo esc_url( $this->icon ); ?>);"<?php } ?>>
				<br /></div>
			<h2><?php echo esc_html( $this->heading['add'] ); ?> <?php echo esc_html( $this->item ); ?>
				<small>(<a href="
				<?php
					echo esc_url(
						$this->var_update(
							array(
								'action' => 'manage',
								'id'     => '',
							)
						)
					);
				?>
									">&laquo; <?php esc_html_e( 'Back to Manage', 'exports-and-reports' ); ?></a>) </small>
			</h2>
			<?php $this->form( 1 ); ?>
		</div>
		<?php
	}

	public function edit( $duplicate = 0 ) {
		if ( ! $this->duplicate ) {
			$duplicate = 0;
		}
		/**
		 * Fires before the edit form.
		 *
		 * @since 1.0.0
		 *
		 * @param int                     $duplicate 1 when the form duplicates the row.
		 * @param Exports_Reports_Admin_UI $admin     The screen.
		 */
		do_action( 'exports_reports_edit', $duplicate, $this );
		$this->legacy_hook( 'edit', array( $duplicate ) );
		if ( isset( $this->custom['edit'] ) && function_exists( "{$this->custom['edit']}" ) ) {
			call_user_func( $this->custom['edit'], $this, $duplicate );
		}
		?>
		<div class="wrap wp-admin-ui-wrap">
			<div id="icon-edit-pages" class="icon32"
				<?php
				if ( false !== $this->icon ) {
					?>
					style="background-image:url(<?php echo esc_url( $this->icon ); ?>);"<?php } ?>>
				<br /></div>
			<h2><?php echo esc_html( $duplicate ? $this->heading['duplicate'] : $this->heading['edit'] ); ?> <?php echo esc_html( $this->item ); ?>
				<small>(<a href="
				<?php
					echo esc_url(
						$this->var_update(
							array(
								'action' => 'manage',
								'id'     => '',
							)
						)
					);
				?>
									">&laquo; <?php esc_html_e( 'Back to Manage', 'exports-and-reports' ); ?></a>) </small>
			</h2>
			<?php $this->form( 0, $duplicate ); ?>
		</div>
		<?php
	}

	public function form( $create = 0, $duplicate = 0 ) {
		/**
		 * Fires before the form fields.
		 *
		 * @since 1.0.0
		 *
		 * @param int                     $create    1 when the form adds a row.
		 * @param int                     $duplicate 1 when the form duplicates the row.
		 * @param Exports_Reports_Admin_UI $admin     The screen.
		 */
		do_action( 'exports_reports_form', $create, $duplicate, $this );
		$this->legacy_hook( 'form', array( $create, $duplicate ) );
		if ( isset( $this->custom['form'] ) && function_exists( "{$this->custom['form']}" ) ) {
			return call_user_func( $this->custom['form'], $this, $create );
		}
		if ( false === $this->table && false === $this->sql ) {
			return $this->error( __( '<strong>Error:</strong> Invalid Configuration - Missing "table" definition.', 'exports-and-reports' ) );
		}
		global $wpdb;
		if ( empty( $this->form_columns ) ) {
			$this->form_columns = $this->columns;
		}
		$submit = 'Add ' . $this->item;
		$id     = '';
		$vars   = array(
			'action' => 'manage',
			'do'     => 'create',
			'id'     => '',
		);
		if ( 0 === $create ) {
			if ( empty( $this->row ) ) {
				$this->get_row();
			}
			if ( empty( $this->row ) ) {
				/* translators: %s: the item's name, as the screen calls it (Report, Report Group). */
				return $this->error( sprintf( __( '<strong>Error:</strong> %s not found.', 'exports-and-reports' ), $this->item ) );
			}
			if ( 0 === $duplicate ) {
				$submit = 'Save Changes';
				$id     = $this->row[ $this->identifier ];
				$vars   = array(
					'action' => 'edit',
					'do'     => 'save',
					'id'     => $id,
				);
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( $this->var_update( $vars ) ); ?>" class="wp_admin_ui">
			<table class="form-table">
				<?php
				foreach ( $this->form_columns as $column => $attributes ) {
					if ( ! is_array( $attributes ) ) {
						$column     = $attributes;
						$attributes = $this->setup_column( $column );
					}
					if ( ! isset( $this->row[ $column ] ) ) {
						$this->row[ $column ] = '';
					}
					if ( false === $attributes['display'] ) {
						continue;
					}
					?>
					<tr valign="top">
					<th scope="row">
						<label for="admin_ui_<?php echo esc_attr( $column ); ?>"><?php echo esc_html( $attributes['label'] ); ?></label>
					</th>
					<td>
					<?php
					if ( ! empty( $attributes['comments'] ) && ! empty( $attributes['comments_top'] ) ) {
						?>
						<span class="description"><?php echo wp_kses_post( $attributes['comments'] ); ?></span>
						<?php
						if ( ! in_array( $attributes['type'], array( 'desc', 'code' ), true ) ) {
							echo '<br />';
						}
					}
					if ( false !== $attributes['custom_input'] && function_exists( "{$attributes['custom_input']}" ) ) {
						$attributes['custom_input']( $column, $attributes, $this );
						?>
						</td>
						</tr>
						<?php
						continue;
					}
					if ( false !== $attributes['custom_form_display'] && function_exists( "{$attributes['custom_form_display']}" ) ) {
						$this->row[ $column ] = $attributes['custom_form_display']( $column, $attributes, $this );
					}
					if ( false !== $attributes['readonly'] ) {
						?>
						<div id="admin_ui_<?php echo esc_attr( $column ); ?>"><?php echo esc_html( $this->row[ $column ] ); ?></div>
						<?php
					} elseif ( 'bool' === $attributes['type'] ) {
						?>
						<input type="checkbox" name="<?php echo esc_attr( $column ); ?>" id="admin_ui_<?php echo esc_attr( $column ); ?>" value="1"<?php checked( 1, (int) $this->row[ $column ] ); ?> />
						<?php
					} elseif ( 'password' === $attributes['type'] ) {
						?>
						<input type="password" name="<?php echo esc_attr( $column ); ?>" id="admin_ui_<?php echo esc_attr( $column ); ?>" value="<?php echo esc_attr( $this->row[ $column ] ); ?>" class="regular-text" />
						<?php
					} elseif ( 'desc' === $attributes['type'] || 'code' === $attributes['type'] ) {
						?>
						<textarea name="<?php echo esc_attr( $column ); ?>" id="admin_ui_<?php echo esc_attr( $column ); ?>" rows="10" cols="50"><?php echo esc_textarea( $this->row[ $column ] ); ?></textarea>
						<?php
					} elseif ( in_array( $attributes['type'], array( 'date', 'datetime', 'time' ), true ) ) {
						$html_input_type = $attributes['type'];

						if ( 'datetime' === $html_input_type ) {
							$html_input_type = 'datetime-local';
						}
						?>
						<input type="<?php echo esc_attr( $html_input_type ); ?>" name="<?php echo esc_attr( $column ); ?>" id="admin_ui_<?php echo esc_attr( $column ); ?>" value="<?php echo esc_attr( str_replace( ' ', 'T', $this->row[ $column ] ) ); ?>" />
						<?php
					} elseif ( 'related' === $attributes['type'] && false !== $attributes['related'] ) {
						if ( ! is_array( $attributes['related'] ) ) {
							$related = $this->rows( 'SELECT id,`' . $this->sanitize( (string) $attributes['related_field'] ) . '` FROM ' . (string) $attributes['related'] . ( ! empty( $attributes['related_sql'] ) ? ' ' . (string) $attributes['related_sql'] : '' ) );
							$choices = array();
							foreach ( $related as $option ) {
								$choices[ (string) $option->id ] = (string) $option->{$attributes['related_field']};
							}
						} else {
							$choices = array();
							foreach ( (array) $attributes['related'] as $option_id => $option ) {
								$choices[ (string) $option_id ] = (string) $option;
							}
						}
						$selected_options = array_map( 'strval', explode( ',', (string) $this->row[ $column ] ) );
						if ( false !== $attributes['related_multiple'] ) {
							?>
							<fieldset class="er-choices" id="admin_ui_<?php echo esc_attr( $column ); ?>">
								<?php foreach ( $choices as $option_id => $option ) { ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $column ); ?>[]" value="<?php echo esc_attr( $option_id ); ?>"<?php checked( in_array( $option_id, $selected_options, true ) ); ?> /> <?php echo esc_html( $option ); ?></label>
								<?php } ?>
							</fieldset>
							<?php
						} else {
							?>
							<select name="<?php echo esc_attr( $column ); ?>" id="admin_ui_<?php echo esc_attr( $column ); ?>">
								<?php foreach ( $choices as $option_id => $option ) { ?>
									<option value="<?php echo esc_attr( $option_id ); ?>"<?php selected( in_array( $option_id, $selected_options, true ) ); ?>><?php echo esc_html( $option ); ?></option>
								<?php } ?>
							</select>
							<?php
						}
					} else {
						?>
						<input type="text" name="<?php echo esc_attr( $column ); ?>" id="admin_ui_<?php echo esc_attr( $column ); ?>" value="<?php echo esc_attr( $this->row[ $column ] ); ?>" class="regular-text" />
						<?php
					}
					if ( ! empty( $attributes['comments'] ) && false === $attributes['comments_top'] ) {
						if ( ! in_array( $attributes['type'], array( 'desc', 'code' ), true ) ) {
							echo '<br />';
						}
						?>
						<span class="description"><?php echo wp_kses_post( $attributes['comments'] ); ?></span>
						<?php
					}
					?>
					</td>
					</tr>
					<?php
				}
				?>
			</table>
			<p class="submit">
				<input type="submit" name="Submit" class="button-primary" value="<?php echo esc_attr( $submit ); ?>" />
				<?php wp_nonce_field( 'exports-reports-form-' . $vars['do'] ); ?>
			</p>
		</form>
		<?php
	}

	public function view() {
		/**
		 * Fires before the view screen.
		 *
		 * @since 1.0.0
		 *
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		do_action( 'exports_reports_view', $this );
		$this->legacy_hook( 'view' );
		if ( isset( $this->custom['view'] ) && function_exists( "{$this->custom['view']}" ) ) {
			return call_user_func( $this->custom['view'], $this );
		}
		if ( false === $this->table && false === $this->sql ) {
			return $this->error( __( '<strong>Error:</strong> Invalid Configuration - Missing "table" definition.', 'exports-and-reports' ) );
		}
		global $wpdb;
		if ( empty( $this->row ) ) {
			$this->get_row();
		}
		if ( empty( $this->row ) ) {
			/* translators: %s: the item's name, as the screen calls it (Report, Report Group). */
				return $this->error( sprintf( __( '<strong>Error:</strong> %s not found.', 'exports-and-reports' ), $this->item ) );
		}
		?>
		<div class="wrap wp-admin-ui-wrap">
			<div id="icon-edit-pages" class="icon32"
				<?php
				if ( false !== $this->icon ) {
					?>
					style="background-image:url(<?php echo esc_url( $this->icon ); ?>);"<?php } ?>>
				<br /></div>
			<h2><?php echo esc_html( $this->heading['view'] ); ?> <?php echo esc_html( $this->item ); ?>
				<small>(<a href="
				<?php
					echo esc_url(
						$this->var_update(
							array(
								'action' => 'manage',
								'id'     => '',
							)
						)
					);
				?>
									">&laquo; <?php esc_html_e( 'Back to Manage', 'exports-and-reports' ); ?></a>) </small>
			</h2>
			<table class="form-table">
				<?php
				foreach ( $this->view_columns as $column => $attributes ) {
					if ( ! is_array( $attributes ) ) {
						$column     = $attributes;
						$attributes = $this->setup_column( $column );
					}
					if ( ! isset( $this->row[ $column ] ) ) {
						$this->row[ $column ] = '';
					}
					if ( false === $attributes['display'] ) {
						continue;
					}
					?>
					<tr valign="top">
					<th scope="row">
						<label for="admin_ui_<?php echo esc_attr( $column ); ?>"><?php echo esc_html( $attributes['label'] ); ?></label>
					</th>
					<td>
					<?php
					if ( false !== $attributes['custom_view'] && is_callable( $attributes['custom_view'] ) ) {
						echo wp_kses( (string) call_user_func( $attributes['custom_view'], $this->row[ $column ], $this->row, $column, $attributes, $this ), $this->allowed_html() );
						?>
						</td>
						</tr>
						<?php
						continue;
					}
					if ( 'date' === $attributes['type'] ) {
						$this->row[ $column ] = date_i18n( 'Y/m/d', strtotime( $this->row[ $column ] ) );
					} elseif ( 'time' === $attributes['type'] ) {
						$this->row[ $column ] = date_i18n( 'g:i:s A', strtotime( $this->row[ $column ] ) );
					} elseif ( 'datetime' === $attributes['type'] ) {
						$this->row[ $column ] = date_i18n( 'Y/m/d g:i:s A', strtotime( $this->row[ $column ] ) );
					} elseif ( 'bool' === $attributes['type'] ) {
						$this->row[ $column ] = ( 1 === (int) $this->row[ $column ] ? __( 'Yes', 'exports-and-reports' ) : __( 'No', 'exports-and-reports' ) );
					} elseif ( 'number' === $attributes['type'] ) {
						$this->row[ $column ] = (int) ( $this->row[ $column ] );
					} elseif ( 'decimal' === $attributes['type'] ) {
						$this->row[ $column ] = number_format( $this->row[ $column ], 2 );
					} elseif ( 'related' === $attributes['type'] && false !== $attributes['related'] ) {
						$old_value            = $this->row[ $column ];
						$this->row[ $column ] = '';
						if ( ! empty( $old_value ) ) {
							$this->row[ $column ] = array();
							if ( ! is_array( $attributes['related'] ) ) {
								$related = $this->rows( 'SELECT `id`,`' . $this->sanitize( (string) $attributes['related_field'] ) . '` FROM ' . (string) $attributes['related'] . ' WHERE `id` IN (' . $this->sanitize( (string) $old_value ) . ')' . ( ! empty( $attributes['related_sql'] ) ? ' ' . (string) $attributes['related_sql'] : '' ) );

								foreach ( $related as $option ) {
									$this->row[ $column ][] = esc_html( $option->{$attributes['related_field']} );
								}
							} else {
								$related          = $attributes['related'];
								$selected_options = explode( ',', $old_value );
								foreach ( $related as $option_id => $option ) {
									if ( in_array( (string) $option_id, $selected_options, true ) ) {
										$this->row[ $column ][] = esc_html( $option );
									}
								}
							}
							$this->row[ $column ] = '<ul><li>' . implode( '</li><li>', $this->row[ $column ] ) . '</li></ul>';
						} else {
							$this->row[ $column ] = 'N/A';
						}
					}
					?>
					<div id="admin_ui_<?php echo esc_attr( $column ); ?>"><?php echo wp_kses_post( $this->row[ $column ] ); ?></div>
					<?php
					if ( ! empty( $attributes['comments'] ) && false === $attributes['comments_top'] ) {
						?>
						<span class="description"><?php echo wp_kses_post( $attributes['comments'] ); ?></span>
						<?php
					}
					?>
					</td>
					</tr>
					<?php
				}
				?>
			</table>
		</div>
		<?php
	}

	public function delete( $id = false ) {
		/**
		 * Fires before a row is deleted.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed                   $id    The identifier.
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		do_action( 'exports_reports_pre_delete', $id, $this );
		$this->legacy_hook( 'pre_delete', array( $id ) );
		if ( isset( $this->custom['delete'] ) && function_exists( "{$this->custom['delete']}" ) ) {
			return call_user_func( $this->custom['delete'], $this );
		}
		if ( false === $this->table && false === $this->sql ) {
			return $this->error( __( '<strong>Error:</strong> Invalid Configuration - Missing "table" definition.', 'exports-and-reports' ) );
		}
		if ( false === $this->id && false === $id ) {
			return $this->error( __( '<strong>Error:</strong> Invalid Configuration - Missing "id" definition.', 'exports-and-reports' ) );
		}
		if ( false === $id ) {
			$id = $this->id;
		}
		global $wpdb;
		$check = $this->execute( "DELETE FROM $this->table WHERE `id` = " . (int) $id );
		if ( $check ) {
			/* translators: %s: the item's name, as the screen calls it (Report, Report Group). */
			$this->message( sprintf( __( '<strong>Deleted:</strong> %s has been deleted.', 'exports-and-reports' ), $this->item ) );
		} else {
			/* translators: %s: the item's name, as the screen calls it (Report, Report Group). */
			$this->error( sprintf( __( '<strong>Error:</strong> %s has not been deleted.', 'exports-and-reports' ), $this->item ) );
		}
		/**
		 * Fires once a row has been deleted.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed                   $id    The identifier.
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		do_action( 'exports_reports_post_delete', $id, $this );
		$this->legacy_hook( 'post_delete', array( $id ) );
	}

	public function save( $create = 0 ) {
		if ( ! isset( $_POST['_wpnonce'] ) || false === wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'exports-reports-form-' . $this->do ) ) {
			return $this->error( __( '<strong>Error:</strong> The form has expired; reload the page and try again.', 'exports-and-reports' ) );
		}
		/**
		 * Fires before the posted form is saved.
		 *
		 * @since 1.0.0
		 *
		 * @param int                     $create 1 when a row is added.
		 * @param Exports_Reports_Admin_UI $admin  The screen.
		 */
		do_action( 'exports_reports_pre_save', $create, $this );
		$this->legacy_hook( 'pre_save', array( $create ) );
		if ( isset( $this->custom['save'] ) && function_exists( "{$this->custom['save']}" ) ) {
			return call_user_func( $this->custom['save'], $this, $create );
		}
		global $wpdb;
		$action = 'saved';
		if ( 1 === $create ) {
			$action = 'created';
		}
		// Each column is sanitised below by its type.
		$posted     = wp_unslash( $_POST );
		$column_sql = array();
		$data       = array();
		foreach ( $this->form_columns as $column => $attributes ) {
			if ( ! is_array( $attributes ) ) {
				$column     = $attributes;
				$attributes = $this->setup_column( $column );
			}
			$vartype = '%s';
			if ( false === $attributes['display'] || false !== $attributes['readonly'] ) {
				if ( ! in_array( $attributes['type'], array( 'date', 'time', 'datetime' ), true ) ) {
					continue;
				}
				if ( false === $attributes['date_touch'] && ( false === $attributes['date_touch_on_create'] || 1 !== $create || $this->id > 0 ) ) {
					continue;
				}
			}
			if ( in_array( $attributes['type'], array( 'date', 'time', 'datetime' ), true ) ) {
				$format = 'Y-m-d H:i:s';
				if ( 'date' === $attributes['type'] ) {
					$format = 'Y-m-d';
				}
				if ( 'time' === $attributes['type'] ) {
					$format = 'H:i:s';
				}
				if ( false !== $attributes['date_touch'] || ( false !== $attributes['date_touch_on_create'] && 1 === $create && $this->id < 1 ) ) {
					$value = date_i18n( $format );
				} else {
					$posted_date = isset( $posted[ $column ] ) ? sanitize_text_field( (string) $posted[ $column ] ) : '';
					$value       = date_i18n( $format, strtotime( ( 'time' === $attributes['type'] ? date_i18n( 'Y-m-d ' ) : '' ) . $posted_date ) );
				}
			} else {
				if ( ! isset( $posted[ $column ] ) ) {
					$posted[ $column ] = '';
				}
				if ( 'bool' === $attributes['type'] ) {
					$vartype = '%d';
					$value   = ( 1 === (int) $posted[ $column ] ) ? 1 : 0;
				} elseif ( 'number' === $attributes['type'] ) {
					$vartype = '%d';
					$value   = number_format( (float) $posted[ $column ], 0, '', '' );
				} elseif ( 'decimal' === $attributes['type'] ) {
					$vartype = '%f';
					$value   = number_format( (float) $posted[ $column ], 2, '.', '' );
				} elseif ( 'related' === $attributes['type'] ) {
					if ( is_array( $posted[ $column ] ) ) {
						$value = implode( ',', array_map( 'sanitize_text_field', $posted[ $column ] ) );
					} else {
						$value = sanitize_text_field( (string) $posted[ $column ] );
					}
				} elseif ( in_array( $attributes['type'], array( 'desc', 'code', 'password' ), true ) ) {
					// Queries, descriptions and passwords are stored as typed.
					$value = is_array( $posted[ $column ] ) ? '' : (string) $posted[ $column ];
				} else {
					$value = sanitize_text_field( is_array( $posted[ $column ] ) ? '' : (string) $posted[ $column ] );
				}
			}
			if ( isset( $attributes['custom_save'] ) && false !== $attributes['custom_save'] && function_exists( "{$attributes['custom_save']}" ) ) {
				$value = $attributes['custom_save']( $value, $column, $attributes, $this );
			}
			if ( '%d' === $vartype ) {
				$literal = $wpdb->prepare( '%d', $value );
			} elseif ( '%f' === $vartype ) {
				$literal = $wpdb->prepare( '%f', $value );
			} else {
				$literal = $this->quote( (string) $value );
			}
			$column_sql[]    = $this->backtick( $column ) . ' = ' . $literal;
			$data[ $column ] = $value;
		}
		$column_sql = implode( ',', $column_sql );
		if ( 0 === $create && $this->id > 0 ) {
			$this->insert_id = $this->id;
			$check           = $this->execute( "UPDATE $this->table SET $column_sql WHERE `id` = " . (int) $this->id );
		} else {
			$check = $this->execute( "INSERT INTO $this->table SET $column_sql" );
		}
		if ( $check ) {
			if ( 0 === $this->insert_id ) {
				$this->insert_id = $wpdb->insert_id;
			}
			if ( 'created' === $action ) {
				/* translators: %s: the item's name, as the screen calls it (Report, Report Group). */
				$this->message( sprintf( __( '<strong>Success!</strong> %s created successfully.', 'exports-and-reports' ), $this->item ) );
			} else {
				/* translators: %s: the item's name, as the screen calls it (Report, Report Group). */
				$this->message( sprintf( __( '<strong>Success!</strong> %s saved successfully.', 'exports-and-reports' ), $this->item ) );
			}
		} elseif ( 'created' === $action ) {
			/* translators: %s: the item's name, as the screen calls it (Report, Report Group). */
			$this->error( sprintf( __( '<strong>Error:</strong> %s has not been created.', 'exports-and-reports' ), $this->item ) );
		} else {
			/* translators: %s: the item's name, as the screen calls it (Report, Report Group). */
			$this->error( sprintf( __( '<strong>Error:</strong> %s has not been saved.', 'exports-and-reports' ), $this->item ) );
		}
		/**
		 * Fires once the posted form has been saved.
		 *
		 * @since 1.0.0
		 *
		 * @param int                     $id     The identifier of the row.
		 * @param array                   $data   The values saved.
		 * @param int                     $create 1 when the row was added.
		 * @param Exports_Reports_Admin_UI $admin  The screen.
		 */
		do_action( 'exports_reports_post_save', $this->insert_id, $data, $create, $this );
		$this->legacy_hook( 'post_save', array( $this->insert_id, $data, $create ) );
	}

	public function reorder() {
		if ( ! isset( $_POST['_wpnonce'] ) || false === wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'exports-reports-reorder' ) ) {
			return $this->error( __( '<strong>Error:</strong> The order was not saved: the page has expired, reload it and try again.', 'exports-and-reports' ) );
		}
		/**
		 * Fires before the posted order is saved.
		 *
		 * @since 1.0.0
		 *
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		do_action( 'exports_reports_pre_reorder', $this );
		$this->legacy_hook( 'pre_reorder' );
		if ( isset( $this->custom['reorder'] ) && function_exists( "{$this->custom['reorder']}" ) ) {
			return call_user_func( $this->custom['reorder'], $this );
		}
		global $wpdb;
		if ( false === $this->table ) {
			return $this->error( __( '<strong>Error:</strong> Invalid Configuration - Missing "table" definition.', 'exports-and-reports' ) );
		}
		if ( false === $this->identifier ) {
			return $this->error( __( '<strong>Error:</strong> Invalid Configuration - Missing "identifier" definition.', 'exports-and-reports' ) );
		}
		$order_ids = ( isset( $_POST['order'] ) && is_array( $_POST['order'] ) ) ? array_map( 'absint', wp_unslash( $_POST['order'] ) ) : array();
		if ( ! empty( $order_ids ) ) {
			foreach ( $order_ids as $order => $id ) {
				$updated = $wpdb->update(
					$this->table,
					array( $this->reorder => (int) $order ),
					array( $this->identifier => $id ),
					array(
						'%s',
						'%d',
					),
					array( '%d' )
				);
			}
			$this->message( __( '<strong>Success!</strong> Order updated successfully.', 'exports-and-reports' ) );
		} else {
			$this->error( __( '<strong>Error:</strong> Order has not been updated.', 'exports-and-reports' ) );
		}
		/**
		 * Fires once the posted order has been saved.
		 *
		 * @since 1.0.0
		 *
		 * @param int                     $id    The identifier of the last row saved.
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		do_action( 'exports_reports_post_reorder', $this->insert_id, $this );
		$this->legacy_hook( 'post_reorder', array( $this->insert_id ) );
	}

	public function field_value( $value, $field_name, $attributes ) {
		global $wpdb;
		if ( 'date' === $attributes['type'] ) {
			if ( 'N/A' === $value || '0000-00-00' === $value || '0000-00-00 00:00:00' === $value ) {
				$value = 'N/A';
			} else {
				$value = date_i18n( 'Y/m/d', strtotime( $value ) );
			}
		} elseif ( 'time' === $attributes['type'] ) {
			if ( 'N/A' === $value || '00:00:00' === $value || '0000-00-00 00:00:00' === $value ) {
				$value = 'N/A';
			} else {
				$value = date_i18n( 'g:i:s A', strtotime( $value ) );
			}
		} elseif ( 'datetime' === $attributes['type'] ) {
			if ( 'N/A' === $value || '0000-00-00' === $value || '0000-00-00 00:00:00' === $value ) {
				$value = 'N/A';
			} else {
				$value = date_i18n( 'Y/m/d g:i:s A', strtotime( $value ) );
			}
		} elseif ( 'related' === $attributes['type'] && false !== $attributes['related'] ) {
			$column_data = array();
			if ( ! is_array( $attributes['related'] ) ) {
				$selected_options = explode( ',', trim( $value ) );
				if ( ! empty( $this->related ) && isset( $this->related[ $attributes['related'] . '_' . $attributes['related_field'] . '_' . $attributes['related_id'] ] ) && ! empty( $this->related[ $attributes['related'] . '_' . $attributes['related_field'] . '_' . $attributes['related_id'] ] ) ) {
					foreach ( $selected_options as $option ) {
						if ( $attributes['related_id'] === $attributes['related_field'] ) {
							if ( isset( $this->related[ $attributes['related'] . '_' . $attributes['related_field'] . '_' . $attributes['related_id'] ][ $option ] ) ) {
								$column_data[ $option ] = $option;
							}
						} elseif ( in_array( $option, array_map( 'strval', $this->related[ $attributes['related'] . '_' . $attributes['related_field'] . '_' . $attributes['related_id'] ] ), true ) ) {
							$column_data[ $option ] = $this->related[ $attributes['related'] . '_' . $attributes['related_field'] . '_' . $attributes['related_id'] ][ $option ];
						}
					}
				}
				if ( empty( $column_data ) ) {
					$limited = ' WHERE ' . $this->backtick( $attributes['related_id'] ) . ' IN (' . implode( ', ', array_map( array( $this, 'quote' ), $selected_options ) ) . ')';

					$related = $this->rows( 'SELECT ' . $this->backtick( $attributes['related_id'] ) . ',' . $this->backtick( $attributes['related_field'] ) . ' FROM ' . $attributes['related'] . ( ! empty( $attributes['related_sql'] ) ? ' ' . $attributes['related_sql'] : $limited ) );

					foreach ( $related as $option ) {
						if ( in_array( (string) $option->{$attributes['related_id']}, $selected_options, true ) ) {
							$column_data[ $option->{$attributes['related_id']} ] = $option->{$attributes['related_field']};
							if ( ! isset( $this->related[ $attributes['related'] . '_' . $attributes['related_field'] . '_' . $attributes['related_id'] ] ) ) {
								$this->related[ $attributes['related'] . '_' . $attributes['related_field'] . '_' . $attributes['related_id'] ] = array();
							}
							$this->related[ $attributes['related'] . '_' . $attributes['related_field'] . '_' . $attributes['related_id'] ][ $option->{$attributes['related_id']} ] = $option->{$attributes['related_field']};
						}
					}
				}
			} else {
				$related          = $attributes['related'];
				$selected_options = explode( ',', $value );
				foreach ( $related as $option_id => $option ) {
					if ( in_array( (string) $option_id, $selected_options, true ) ) {
						$column_data[ $option_id ] = $option;
					}
				}
			}
			$value = implode( ', ', $column_data );
		} elseif ( 'bool' === $attributes['type'] ) {
			$value = ( 1 === (int) $value ? __( 'Yes', 'exports-and-reports' ) : __( 'No', 'exports-and-reports' ) );
		} elseif ( 'number' === $attributes['type'] ) {
			$value = number_format( $value, 0 );
		} elseif ( 'decimal' === $attributes['type'] ) {
			$value = number_format( $value, 2 );
		}
		if ( false !== $attributes['custom_relate'] ) {
			$table = $attributes['custom_relate'];
			$on    = $field_name;
			$is    = $value;
			$what  = array( 'name' );
			if ( is_array( $table ) ) {
				if ( isset( $table['on'] ) ) {
					$on = $table['on'];
				}
				if ( isset( $table['is'] ) && isset( $this->row[ $table['is'] ] ) ) {
					$is = $this->row[ $table['is'] ];
				}
				if ( isset( $table['what'] ) ) {
					$what = array();
					if ( is_array( $table['what'] ) ) {
						foreach ( $table['what'] as $wha ) {
							$what[] = $wha;
						}
					} else {
						$what[] = $table['what'];
					}
				}
				if ( isset( $table['table'] ) ) {
					$table = $table['table'];
				}
			}
			$sql = 'SELECT ' . implode( ',', array_map( array( $this, 'backtick' ), $what ) ) . ' FROM ' . $this->backtick( $table ) . ' WHERE ' . $this->backtick( $on ) . ' = ' . $this->quote( (string) $is );

			$results = current( $this->rows( $sql, ARRAY_A ) );

			if ( ! empty( $results ) ) {
				$val = array();
				foreach ( $what as $wha ) {
					if ( isset( $results[ $wha ] ) ) {
						$val[] = $results[ $wha ];
					}
				}
				if ( ! empty( $val ) ) {
					$value = implode( ' ', $val );
				}
			}
		}

		return $value;
	}

	/**
	 * The PDF engine: tc-lib-pdf on PHP 8.2 and later, TCPDF below that (tc-lib-pdf's floor is 8.2). The
	 * exports_reports_export_pdf_engine filter may choose 'tcpdf' on a newer PHP; it cannot choose tc-lib-pdf on an older one.
	 *
	 * @return string 'tc-lib-pdf' or 'tcpdf'.
	 */
	public static function pdf_engine() {
		/**
		 * Filters the PDF engine.
		 *
		 * @param string $engine 'tc-lib-pdf' on PHP 8.2 and later, 'tcpdf' below.
		 */
		$engine = apply_filters( 'exports_reports_export_pdf_engine', PHP_VERSION_ID >= 80200 ? 'tc-lib-pdf' : 'tcpdf' );
		$engine = apply_filters_deprecated( 'wp_admin_ui_export_pdf_engine', array( $engine ), '0.9.5', 'exports_reports_export_pdf_engine' );

		return ( 'tcpdf' === $engine || PHP_VERSION_ID < 80200 ) ? 'tcpdf' : 'tc-lib-pdf';
	}

	public function export() {
		/**
		 * Fires before an export is written.
		 *
		 * @since 1.0.0
		 *
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		do_action( 'exports_reports_pre_export', $this );
		$this->legacy_hook( 'pre_export' );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$url         = explode( '/', $this->request_uri() );
		$url         = array_reverse( $url );
		$url         = $url[0];
		$credentials = request_filesystem_credentials( $url, '', false, ABSPATH );
		if ( false === $credentials ) {
			$this->error( __( '<strong>Error:</strong> Your hosting configuration does not allow access to add files to your site.', 'exports-and-reports' ) );

			return false;
		}
		if ( ! WP_Filesystem( $credentials, ABSPATH ) ) {
			request_filesystem_credentials( $url, '', true, ABSPATH ); // The connection failed: ask for the credentials again.
			$this->error( __( '<strong>Error:</strong> Your hosting configuration does not allow access to add files to your site.', 'exports-and-reports' ) );

			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		/**
		 * @var WP_Filesystem_Base $wp_filesystem
		 */
		global $wp_filesystem;

		WP_Filesystem();

		if ( ! $this->api && ! $this->verify_export_request() ) {
			$this->error( __( '<strong>Error:</strong> The export link has expired. Go back to the report and use its export buttons again.', 'exports-and-reports' ) );

			return false;
		}
		if ( isset( $this->custom['export'] ) && function_exists( "{$this->custom['export']}" ) ) {
			return call_user_func( $this->custom['export'], $this );
		}
		if ( empty( $this->full_data ) ) {
			// Delimited exports read their rows in export_sv(); the other formats load the whole result here.
			$this->stream_sql    = '';
			$this->stream_export = in_array( $this->export_type, array( 'csv', 'tsv', 'pipe', 'custom' ), true ) && $this->can_stream_export();
			$data                = $this->get_data( true );
			$this->stream_export = false;

			if ( false === $data ) {
				return false;
			}
		}
		$dir = dirname( EXPORTS_REPORTS_EXPORT_DIR );
		if ( ! file_exists( EXPORTS_REPORTS_EXPORT_DIR ) ) {
			if ( ! $wp_filesystem->is_writable( $dir ) || ! $wp_filesystem->mkdir( EXPORTS_REPORTS_EXPORT_DIR ) ) {
				/* translators: %s: the export directory. */
				$this->error( sprintf( __( '<strong>Error:</strong> Your export directory (<strong>%s</strong>) did not exist and could not be created by the web server. Check the directory permissions and try again.', 'exports-and-reports' ), EXPORTS_REPORTS_EXPORT_DIR ) );

				return false;
			}
		}
		if ( ! $wp_filesystem->is_writable( EXPORTS_REPORTS_EXPORT_DIR ) ) {
			/* translators: %s: the export directory. */
			$this->error( sprintf( __( '<strong>Error:</strong> Your export directory (<strong>%s</strong>) needs to be writable for this plugin to work. Double-check it and try again.', 'exports-and-reports' ), EXPORTS_REPORTS_EXPORT_DIR ) );

			return false;
		}
		self::protect_export_dir();
		$remove_export = str_replace( '/', '', sanitize_file_name( (string) $this->get_var( 'remove_export', '' ) ) );
		if ( ! $this->api && '' !== $remove_export ) {
			$remove_export_path = EXPORTS_REPORTS_EXPORT_DIR . '/' . $remove_export;

			if ( ! self::is_export_file_name( $remove_export ) ) {
				$this->error( __( '<strong>Error:</strong> That is not an export file.', 'exports-and-reports' ) );

				return false;
			}

			/**
			 * Fires before an export file is removed.
			 *
			 * @since 1.0.0
			 *
			 * @param string                  $file  The file name.
			 * @param string                  $path  Its path.
			 * @param Exports_Reports_Admin_UI $admin The screen.
			 */
			do_action( 'exports_reports_pre_remove_export', $remove_export, $remove_export_path, $this );
			$this->legacy_hook( 'pre_remove_export', array( $remove_export, $remove_export_path ) );

			if ( $wp_filesystem->exists( $remove_export_path ) ) {
				$remove = $wp_filesystem->delete( $remove_export_path );
				if ( $remove ) {
					/**
					 * Fires once an export file has been removed, or could not be.
					 *
					 * @since 1.0.0
					 *
					 * @param string                  $file    The file name.
					 * @param bool                    $removed Whether the file is gone.
					 * @param string                  $path    Its path.
					 * @param Exports_Reports_Admin_UI $admin   The screen.
					 */
					do_action( 'exports_reports_post_remove_export', $remove_export, true, $remove_export_path, $this );
					$this->legacy_hook( 'post_remove_export', array( $remove_export, true, $remove_export_path ) );
					$this->message( __( '<strong>Success:</strong> Export removed successfully.', 'exports-and-reports' ) );

					return;
				} else {
					/**
					 * This action is documented above.
					 */
					do_action( 'exports_reports_post_remove_export', $remove_export, false, $remove_export_path, $this );
					$this->legacy_hook( 'post_remove_export', array( $remove_export, false, $remove_export_path ) );
					/* translators: %s: the export directory. */
					$this->error( sprintf( __( '<strong>Error:</strong> Your export directory (<strong>%s</strong>) needs to be writable for this plugin to work. Double-check it and try again.', 'exports-and-reports' ), EXPORTS_REPORTS_EXPORT_DIR ) );

					return false;
				}
			} else {
				$this->error( __( '<strong>Error:</strong> That file does not exist in the export directory.', 'exports-and-reports' ) );

				return false;
			}
		} elseif ( in_array( $this->export_type, array( 'csv', 'tsv', 'pipe', 'custom' ), true ) ) {
			$export_file = $this->export_sv();
		} elseif ( 'xml' === $this->export_type ) {
			$export_file          = $this->export_file( $this->export_type );
			$export_file_location = EXPORTS_REPORTS_EXPORT_DIR . '/' . $export_file;
			$columns              = $this->exported_columns();
			$file_contents        = '<?xml version="1.0" encoding="' . get_bloginfo( 'charset' ) . '" ?>' . "\r\n<items count=\"" . count( $this->full_data ) . "\">\r\n";

			foreach ( $this->full_data as $item ) {
				$file_contents .= "\t<item>\r\n";

				foreach ( $this->export_row( $item, $columns ) as $key => $value ) {
					$file_contents .= "\t\t<{$key}><![CDATA[" . $value . "]]></{$key}>\r\n";
				}

				$file_contents .= "\t</item>\r\n";
			}

			$file_contents .= '</items>';

			$wp_filesystem->put_contents( $export_file_location, $file_contents );
			$this->export_ready( $export_file, $this->export_type );
		} elseif ( 'xlsx' === $this->export_type ) {
			$export_file          = $this->export_file( $this->export_type );
			$export_file_location = EXPORTS_REPORTS_EXPORT_DIR . '/' . $export_file;
			$columns              = $this->exported_columns();
			$header               = array();

			foreach ( $columns as $attributes ) {
				$header[ $attributes['label'] ] = 'string';
			}

			$writer = new ExportsReports__Prefixed__XLSXWriter();
			$writer->writeSheetHeader(
				'Sheet1',
				$header,
				array(
					'fill'   => '#eee',
					'halign' => 'center',
					'border' => 'left,right,top,bottom',
				)
			);

			foreach ( $this->full_data as $item ) {
				$row = array();

				foreach ( $this->export_row( $item, $columns ) as $key => $value ) {
					$value = wp_strip_all_tags( (string) $value );

					// The cell is text unless the column is a number.
					$row[] = ! empty( $columns[ $key ]['type'] ) && 'number' === $columns[ $key ]['type'] ? (int) $value : $value;
				}

				$writer->writeSheetRow( 'Sheet1', $row );
			}

			$wp_filesystem->put_contents( $export_file_location, (string) $writer->writeToString() );
			$this->export_ready( $export_file, $this->export_type );
		} elseif ( 'json' === $this->export_type ) {
			$export_file          = $this->export_file( $this->export_type );
			$export_file_location = EXPORTS_REPORTS_EXPORT_DIR . '/' . $export_file;
			$columns              = $this->exported_columns();
			$rows                 = array();

			foreach ( $this->full_data as $item ) {
				$rows[] = $this->export_row( $item, $columns );
			}

			$data = array(
				'items' => array(
					'count' => count( $rows ),
					'item'  => $rows,
				),
			);

			$wp_filesystem->put_contents( $export_file_location, wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			$this->export_ready( $export_file, $this->export_type );
		} elseif ( 'pdf' === $this->export_type ) {
			if ( 'tcpdf' === self::pdf_engine() ) {
				require_once $this->base_dir . '/class-exports-reports-admin-ui-export-pdf-legacy.php';
				$pdf_class = 'Exports_Reports_Admin_UI_Export_PDF_Legacy';
			} else {
				require_once $this->base_dir . '/class-exports-reports-admin-ui-export-pdf.php';
				$pdf_class = 'Exports_Reports_Admin_UI_Export_PDF';
			}

			/**
			 * Filters the PDF export before the plugin renders it: a callback that writes the file into the
			 * export directory itself returns its name.
			 *
			 * @param string|null              $export_file Null: the plugin renders the report.
			 * @param Exports_Reports_Admin_UI $admin       The report.
			 */
			$export_file = apply_filters( 'exports_reports_export_pdf_report_file', null, $this );
			$export_file = apply_filters_deprecated( 'wp_admin_ui_export_pdf_report_file', array( $export_file, $this ), '0.9.5', 'exports_reports_export_pdf_report_file' );

			if ( ! $export_file ) {
				$export_file = $pdf_class::CreateReport( $this );
			}

			$this->export_ready( $export_file, $this->export_type );
		} else {
			$this->error( __( '<strong>Error:</strong> Invalid export type.', 'exports-and-reports' ) );

			return false;
		}

		$this->exported_file = $export_file;

		/**
		 * Fires once an export file has been written.
		 *
		 * @since 1.0.0
		 *
		 * @param string                  $file  The file name.
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		do_action( 'exports_reports_post_export', $export_file, $this );
		$this->legacy_hook( 'post_export', array( $export_file ) );
	}

	/**
	 * Keeps the export directory from being served directly: an .htaccess that denies every request
	 * (Apache 2.4 and 2.2 syntax) and an index.php, written once. Downloads go through
	 * exports_reports_send_export_file() after a nonce or token check; nginx needs the equivalent
	 * location rule in the server configuration (see the readme).
	 *
	 * @since 0.9.5
	 */
	public static function protect_export_dir() {
		/** @var WP_Filesystem_Base $wp_filesystem */
		global $wp_filesystem;

		$rules = array(
			'.htaccess' => "# Exports and Reports: export files are served by the plugin after an access check.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		);

		foreach ( $rules as $name => $contents ) {
			$path = EXPORTS_REPORTS_EXPORT_DIR . '/' . $name;

			if ( ! $wp_filesystem->exists( $path ) ) {
				$wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
			}
		}
	}

	/**
	 * Whether export_sv() can read the rows from an unbuffered result: wpdb must be on mysqli, and no column
	 * may run a query while the result is open, as a "related" lookup or a custom display callback might.
	 *
	 * @return bool
	 */
	public function can_stream_export() {
		global $wpdb;

		if ( ! isset( $wpdb->dbh ) || ! $wpdb->dbh instanceof mysqli ) {
			return false;
		}

		foreach ( $this->exported_columns() as $attributes ) {
			if ( ! empty( $attributes['custom_display'] ) || ! empty( $attributes['custom_relate'] ) || ( isset( $attributes['type'], $attributes['related'] ) && 'related' === $attributes['type'] && false !== $attributes['related'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Yield the rows of an unbuffered result one at a time.
	 *
	 * @param mysqli_result $stream The result.
	 * @return Generator<int, array<string, string|null>>
	 */
	protected function stream_rows( $stream ) {
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_assoc
		$row = mysqli_fetch_assoc( $stream );

		while ( is_array( $row ) ) {
			yield $row;

			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_assoc
			$row = mysqli_fetch_assoc( $stream );
		}
	}

	/**
	 * Write one line of a delimited export with every value quoted.
	 *
	 * @param resource $fp        The open export file.
	 * @param array    $values    The values of the line.
	 * @param string   $delimiter The delimiter between values.
	 */
	public function write_sv_line( $fp, $values, $delimiter ) {
		$quoted = array();

		foreach ( $values as $value ) {
			$quoted[] = '"' . str_replace( '"', '""', (string) $value ) . '"';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $fp, implode( $delimiter, $quoted ) . "\n" );
	}

	public function export_sv() {
		global $wpdb;

		$file_ext = array(
			'csv'    => 'csv',
			'tsv'    => 'tsv',
			'pipe'   => 'txt',
			'custom' => 'txt',
		);

		$file_delimiter = array(
			'csv'  => ',',
			'tsv'  => "\t",
			'pipe' => '|',
		);

		$export_delimiter = ',';
		$export_ext       = 'csv';

		if ( $this->export_delimiter ) {
			$export_delimiter = $this->export_delimiter;
		} elseif ( isset( $file_delimiter[ $this->export_type ] ) ) {
			$export_delimiter = $file_delimiter[ $this->export_type ];
		}

		if ( isset( $file_ext[ $this->export_type ] ) ) {
			$export_ext = $file_ext[ $this->export_type ];
		}

		$export_file          = $this->export_file( $export_ext );
		$export_file_location = EXPORTS_REPORTS_EXPORT_DIR . '/' . $export_file;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp     = fopen( $export_file_location, 'a+' );
		$head   = array();
		$stream = null;

		/**
		 * Filters whether values a spreadsheet would evaluate as formulas are written as text.
		 *
		 * @since 1.0.0 The arguments one by one; the array form stays with wp_admin_ui_export_escape_formulas.
		 *
		 * @param bool                    $escape True by default.
		 * @param Exports_Reports_Admin_UI $admin  The screen.
		 */
		$escape_formulas = apply_filters( 'exports_reports_export_escape_formulas', true, $this ); // Before the unbuffered result is open: a callback may query the database.
		$escape_formulas = false !== $this->legacy_hook( 'export_escape_formulas', array( $escape_formulas ) );

		if ( '' !== $this->stream_sql ) {
			// Unbuffered, on wpdb's connection: the statement may read temporary tables and session variables
			// a filter callback created there. One row is in memory at a time.
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- an unbuffered result on wpdb's connection; the statement is the report's own query.
			$stream = mysqli_query( $wpdb->dbh, $this->stream_sql, MYSQLI_USE_RESULT );

			if ( ! $stream instanceof mysqli_result ) {
				// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_error
				$wpdb->last_error = mysqli_error( $wpdb->dbh );
				$this->stream_sql = '';
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				wp_delete_file( $export_file_location );
				$this->error( '<strong>' . esc_html__( 'Error:', 'exports-and-reports' ) . '</strong> ' . esc_html( $wpdb->last_error ) );

				return false;
			}

			if ( empty( $this->columns ) ) {
				$names = array();

				// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_fields
				foreach ( mysqli_fetch_fields( $stream ) as $field ) {
					$name = trim( str_replace( '`', '', $field->name ) );

					if ( 0 < strlen( $name ) ) {
						$names[] = $name;
					}
				}

				$this->columns = $this->setup_columns( $names, 'columns', true );
			}
		}

		$columns = $this->exported_columns();

		foreach ( $columns as $attributes ) {
			$head[] = $attributes['label'];
		}

		// A UTF-8 byte order mark so spreadsheet applications read the encoding right. With the first label
		// quoted it also keeps Excel from taking a file that starts with "ID" for SYLK.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $fp, "\xEF\xBB\xBF" );
		$this->write_sv_line( $fp, $head, $export_delimiter );

		$rows = $stream instanceof mysqli_result ? $this->stream_rows( $stream ) : $this->full_data;

		foreach ( $rows as $item ) {
			$column_values = array();

			foreach ( $this->export_row( $item, $columns ) as $value ) {
				$column_value = trim( str_replace( array( "\r", "\n" ), ' ', (string) $value ) );

				// A value a spreadsheet would evaluate as a formula is wrapped in a string formula, which shows the
				// text and stays inert. The leading characters are the ones spreadsheets act on; the tab and carriage
				// return of the usual list cannot reach this point, the trim and the line-break replacement above take
				// them. Numbers are left alone: a negative amount starts with "-".
				if ( $escape_formulas && '' !== $column_value && ! is_numeric( $column_value ) && false !== strpos( '=+-@', $column_value[0] ) ) {
					$column_value = '="" & "' . str_replace( '"', '""', $column_value ) . '"';
				}

				$column_values[] = $column_value;
			}

			$this->write_sv_line( $fp, $column_values, $export_delimiter );
		}

		if ( $stream instanceof mysqli_result ) {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_error
			$stream_error = mysqli_error( $wpdb->dbh );
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_free_result
			mysqli_free_result( $stream );
			$this->stream_sql = '';

			if ( '' !== $stream_error ) {
				$wpdb->last_error = $stream_error;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $fp );
				wp_delete_file( $export_file_location );
				$this->error( '<strong>' . esc_html__( 'Error:', 'exports-and-reports' ) . '</strong> ' . esc_html( $stream_error ) );

				return false;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		$this->export_ready( $export_file, $export_ext );

		return $export_file;
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

	public function get_row( $id = false ) {
		if ( isset( $this->custom['row'] ) && function_exists( "{$this->custom['row']}" ) ) {
			return call_user_func( $this->custom['row'], $this );
		}
		if ( false === $this->table && false === $this->sql ) {
			return $this->error( __( '<strong>Error:</strong> Invalid Configuration - Missing "table" definition.', 'exports-and-reports' ) );
		}
		if ( false === $this->id && false === $id ) {
			return $this->error( __( '<strong>Error:</strong> Invalid Configuration - Missing "id" definition.', 'exports-and-reports' ) );
		}
		if ( false === $id ) {
			$id = $this->id;
		}
		global $wpdb;

		$sql = "SELECT * FROM `{$this->table}` WHERE `id` = " . (int) $id;

		$row = current( $this->rows( $sql, ARRAY_A ) );

		/**
		 * Filters the row the edit and view screens load.
		 *
		 * @since 1.0.0
		 *
		 * @param array|false             $row   The row, or false when there is none.
		 * @param mixed                   $id    Its identifier.
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		$row = apply_filters( 'exports_reports_get_row', $row, $id, $this );
		$row = $this->legacy_hook( 'get_row', array( $row, $id ) );
		if ( ! empty( $row ) ) {
			$this->row = $row;
		}

		return $row;
	}

	public function get_data( $full = false ) {
		if ( isset( $this->custom['data'] ) && function_exists( "{$this->custom['data']}" ) ) {
			return call_user_func( $this->custom['data'], $this );
		}
		if ( false === $this->table && false === $this->sql ) {
			return $this->error( __( '<strong>Error:</strong> Invalid Configuration - Missing "table" definition.', 'exports-and-reports' ) );
		}

		/** @global wpdb $wpdb */ global $wpdb;
		$this->sql_count = trim( $this->sql_count );
		if ( empty( $this->sql_count ) ) {
			$this->sql_count = false;
		}
		if ( false === $this->sql ) {
			$this->sql = "SELECT * FROM $this->table";
		}
		$sql = ' ' . str_replace( array( "\n", "\r", "\t", '  ' ), ' ', ' ' . $this->sql ) . ' ';
		// The author's own SQL_CALC_FOUND_ROWS, if any, comes off; the select list is read before anything is added to it.
		$sql = preg_replace( '/ SELECT SQL_CALC_FOUND_ROWS /i', ' SELECT ', $sql, 1 );
		preg_match( '/SELECT (.*) FROM/i', $sql, $selectmatches );
		// What goes after the first SELECT: the row count for the page view (an export reads every row and needs
		// none; a totals query feeds the sum row, not the count), then a SUM() per total field.
		$select_extras = $full ? '' : 'SQL_CALC_FOUND_ROWS';
		$totals        = array();
		foreach ( $this->columns as $key => $column ) {
			$attributes = $column;
			if ( ! is_array( $attributes ) ) {
				$attributes = $this->setup_column( $column );
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
		if ( false !== $this->search && ! empty( $this->search_columns ) ) {
			if ( false !== $this->search_query && 0 < strlen( $this->search_query ) ) {
				foreach ( $this->search_columns as $key => $column ) {
					$attributes = $column;
					if ( ! is_array( $attributes ) ) {
						$attributes = $this->setup_column( $column );
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
					if ( isset( $this->filters[ $column ] ) ) {
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
					$like             = $columnfield . ' LIKE ' . $this->quote( '%' . $wpdb->esc_like( $this->search_query ) . '%' );

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
			foreach ( $this->filters as $filter ) {
				if ( ! isset( $this->search_columns[ $filter ] ) ) {
					continue;
				}

				$filter_column = $this->search_columns[ $filter ];
				$filter_values = $this->filter_values( 'filter_' . $filter, $filter_column['filter_default'] );

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
					$start_values = $this->filter_values( 'filter_' . $filter . '_start', $filter_column['filter_default'] );
					$end_values   = $this->filter_values( 'filter_' . $filter . '_end', $filter_column['filter_ongoing_default'] );
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
					$filter_sql = apply_filters( 'exports_reports_data_filter_sql', $filter_sql, $filter, $filter_column, $filterfield, $related_filterfield, $search_value, $this );
					$filter_sql = apply_filters_deprecated( 'wp_admin_ui_data_filter_sql', array( $filter_sql, $filter, $filter_column, $filterfield, $related_filterfield, $search_value, $this ), '0.9.5', 'exports_reports_data_filter_sql' );

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
		if ( $this->order_from_request && ( ! empty( $this->columns ) || $select_known ) ) {
			// The request's column, lower-cased on the way in, against the report's columns as the report writes
			// them. A report whose columns are not known before it runs (no field definitions, a SELECT list that
			// names fewer columns than its result) takes the name as the identifier it was sanitized to; the
			// database checks it.
			$sort_column = false;

			foreach ( array_merge( $this->column_names(), $select_names ) as $name ) {
				if ( strtolower( $name ) === $this->order ) {
					$sort_column = $name;
					break;
				}
			}

			if ( false === $sort_column ) {
				// The request named a column the report does not have: the report's own order applies.
				$this->order              = $this->order_default;
				$this->order_dir          = $this->order_dir_default;
				$this->order_from_request = false;
			} else {
				$this->order = $sort_column;
			}
		}
		if ( false !== $this->order && ( false === $this->reorder || 'reorder' !== $this->action ) ) {
			$ordersql = trim( ( $this->order_from_request ? $this->backtick( $this->order ) : $this->order ) . ' ' . $this->order_dir );
		} elseif ( false !== $this->reorder && 'reorder' === $this->action ) {
			$ordersql = trim( $this->reorder_order . ' ' . $this->reorder_order_dir );
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
		if ( false !== $this->pagination && ! $full ) {
			$start = ( $this->page - 1 ) * $this->limit;

			$limitsql .= (int) $start . ',' . (int) $this->limit;
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
		if ( false !== $this->sql_count ) {
			// The count query takes the same conditions, placed by its own shape, and one row.
			$sql_count = ' ' . preg_replace( '/\s+/', ' ', ' ' . $this->sql_count ) . ' ';
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
		/**
		 * Filters the report query right before it runs, placeholders substituted.
		 *
		 * The callback may rewrite the statement, run preparation statements of its own on wpdb's
		 * connection first (temporary tables, session variables the statement reads), or return
		 * false to run nothing once it has reported why. A literal % in a quoted value stands behind
		 * wpdb's placeholder escape, which wpdb removes when it runs the statement.
		 *
		 * @since 1.0.0 The arguments one by one; the array form stays with wp_admin_ui_get_data_sql.
		 *
		 * @param string|false            $sql   The statement.
		 * @param bool                    $full  True when every row is read, as for an export.
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		$sql = apply_filters( 'exports_reports_get_data_sql', $sql, $full, $this );
		$sql = $this->legacy_hook( 'get_data_sql', array( $sql, $full ) );

		if ( false !== $this->sql_count && ! empty( $sql_count ) ) {
			/** Filters the count query right before it runs; same arguments as exports_reports_get_data_sql. */
			$sql_count = apply_filters( 'exports_reports_get_data_sql_count', $sql_count, $full, $this );
			$sql_count = $this->legacy_hook( 'get_data_sql_count', array( $sql_count, $full ) );
		}

		if ( false === $sql ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) && 1 === (int) $this->get_var( 'debug', 0 ) ) {
			echo "<textarea cols='130' rows='30'>" . esc_textarea( $wpdb->remove_placeholder_escape( $sql ) ) . '</textarea>';
		}
		if ( false !== $this->default_none && false === $this->search_query && false === $full && empty( $wheresql ) && empty( $havingsql ) ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) && 1 === (int) $this->get_var( 'debug', 0 ) ) {
			$wpdb->show_errors( true );
		}

		$results = array();
		$total   = 0;

		if ( $this->stream_export ) {
			// export_sv() reads the result row by row.
			$this->stream_sql = $wpdb->remove_placeholder_escape( $sql ); // mysqli runs it without wpdb, which would remove the escape.
		} else {
			$results = $this->rows( $sql, ARRAY_A );

			$total = current( $wpdb->get_col( 'SELECT FOUND_ROWS()' ) );
		}

		/**
		 * Filters the row count of the report, for the paging; a statement that does not begin with
		 * SELECT has no count of its own and supplies it here.
		 *
		 * @since 1.0.0
		 *
		 * @param int                     $total The count.
		 * @param bool                    $full  Whether every row was asked for (an export).
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		$total = apply_filters( 'exports_reports_get_data_total', $total, $full, $this );
		$total = $this->legacy_hook( 'get_data_total', array( $total, $full ) );

		if ( is_numeric( $total ) ) {
			$this->total = $total;
		}

		$this->set_filter_lookups();

		if ( $full ) {
			$this->full_data = $results;
		} else {
			$this->data = $results;
		}
		$totals = false;
		if ( ! empty( $results ) ) {
			foreach ( $this->columns as $key => $column ) {
				if ( false === $totals ) {
					$totals = current( $results );
				}
				if ( empty( $totals ) || ! is_array( $totals ) ) {
					continue;
				}
				$attributes = $column;
				if ( ! is_array( $attributes ) ) {
					$attributes = $this->setup_column( $column );
				}
				if ( true !== $attributes['total_field'] ) {
					continue;
				}
				if ( is_array( $column ) ) {
					$column = $key;
				}
				if ( false === $this->totals ) {
					$this->totals = array();
				}
				$this->totals[ $column ] = $totals[ 'exports_reports_total_' . $column ];
			}
		}
		if ( empty( $this->columns ) ) {
			$this->catch_columns( $full );
		}
		if ( $full ) {
			return $results;
		}

		if ( false !== $this->sql_count && ! empty( $sql_count ) ) {
			$this->sum_data = $this->rows( $sql_count, ARRAY_A );
		}

		return $results;
	}

	public function set_filter_lookups() {
		/** @global wpdb $wpdb */ global $wpdb;

		foreach ( $this->filters as $filter ) {
			if ( ! isset( $this->search_columns[ $filter ] ) ) {
				continue;
			}

			$filter_column = $this->search_columns[ $filter ];

			if ( 'related' === $filter_column['type'] && false !== $filter_column['related'] ) {
				if ( is_array( $filter_column['related'] ) ) {
					// A related array is already key => label.
					$related_lookup = $filter_column['related'];
				} else {
					$lookup_results = $this->rows( 'SELECT `' . $filter_column['related_id'] . '`,`' . $filter_column['related_field'] . '` FROM ' . $filter_column['related'] . ( ! empty( $filter_column['related_sql'] ) ? ' ' . $filter_column['related_sql'] : '' ), ARRAY_A );

					$id_field       = $filter_column['related_id'];
					$label_field    = $filter_column['related_field'];
					$related_lookup = array();

					foreach ( $lookup_results as $this_pair ) {
						$related_lookup[ $this_pair[ $id_field ] ] = $this_pair[ $label_field ];
					}
				}

				$this->search_columns[ $filter ]['related_lookup'] = $related_lookup;
			}
		}
	}

	public function manage( $reorder = 0 ) {
		/** @global wpdb $wpdb */ global $wpdb;
		/**
		 * Fires before the list screen.
		 *
		 * @since 1.0.0
		 *
		 * @param int                     $reorder 1 on the reorder screen.
		 * @param Exports_Reports_Admin_UI $admin   The screen.
		 */
		do_action( 'exports_reports_manage', $reorder, $this );
		$this->legacy_hook( 'manage', array( $reorder ) );
		if ( isset( $this->custom['manage'] ) && function_exists( "{$this->custom['manage']}" ) ) {
			return call_user_func( $this->custom['manage'], $this, $reorder );
		}
		?>
		<div class="wrap wp-admin-ui-wrap">
			<div id="icon-edit-pages" class="icon32"
				<?php
				if ( false !== $this->icon ) {
					?>
					style="background-image:url(<?php echo esc_url( $this->icon ); ?>);"<?php } ?>>
				<br /></div>
			<h2><?php echo esc_html( 0 === $reorder || false === $this->reorder ? $this->heading['manage'] : $this->heading['reorder'] ); ?> <?php echo esc_html( $this->items ); ?>
				<?php if ( 1 === $reorder && false !== $this->reorder ) { ?>
					<small>(<a href="
					<?php
					echo esc_url(
						$this->var_update(
							array(
								'action' => 'manage',
								'id'     => '',
							)
						)
					);
					?>
										">&laquo; <?php esc_html_e( 'Back to Manage', 'exports-and-reports' ); ?></a>)</small><?php } ?></h2>
			<?php
			if ( isset( $this->custom['header'] ) && function_exists( "{$this->custom['header']}" ) ) {
				echo wp_kses( (string) call_user_func( $this->custom['header'], $this ), $this->allowed_html() );
			}
			if ( empty( $this->data ) ) {
				$this->get_data();
			}
			if ( empty( $this->columns ) ) {
				$this->catch_columns();
			}
			if ( false !== $this->export && 'export' === $this->action ) {
				$this->export();
			}
			if ( ( ! empty( $this->data ) || false !== $this->search_query || false !== $this->default_none ) && false !== $this->search ) {
				?>
				<form id="posts-filter" action="" method="get">
					<p class="search-box">
						<?php
						$excluded_filters = array();
						foreach ( $this->filters as $filter ) {
							$excluded_filters[] = 'filter_' . $filter . '_start';
							$excluded_filters[] = 'filter_' . $filter . '_end';
							$excluded_filters[] = 'filter_' . $filter;
						}
						$excluded_filters = array_merge( $excluded_filters, array( 'search_query' ) );
						$this->hidden_vars( $excluded_filters );
						foreach ( $this->filters as $filter ) {
							if ( ! isset( $this->search_columns[ $filter ] ) ) {
								continue;
							}

							$filter_column = $this->search_columns[ $filter ];
							$date_exists   = false;

							if ( in_array( $filter_column['type'], array( 'date', 'datetime', 'time' ), true ) ) {
								$html_input_type = $filter_column['type'];

								if ( 'datetime' === $html_input_type ) {
									$html_input_type = 'datetime-local';
								}

								$start = implode( ', ', $this->filter_values( 'filter_' . $filter . '_start', $filter_column['filter_default'] ) );
								$end   = implode( ', ', $this->filter_values( 'filter_' . $filter . '_end', $filter_column['filter_ongoing_default'] ) );
								?>
								&nbsp;&nbsp;
								<label for="admin_ui_filter_<?php echo esc_attr( $filter ); ?>_start"><?php echo esc_html( $filter_column['filter_label'] ); ?>:</label>
								<input type="<?php echo esc_attr( $html_input_type ); ?>" name="filter_<?php echo esc_attr( $filter ); ?>_start" class="admin_ui_filter admin_ui_date" id="admin_ui_filter_<?php echo esc_attr( $filter ); ?>_start" value="<?php echo esc_attr( $start ); ?>" />
								<label for="admin_ui_filter_<?php echo esc_attr( $filter ); ?>_end">to</label>
								<input type="<?php echo esc_attr( $html_input_type ); ?>" name="filter_<?php echo esc_attr( $filter ); ?>_end" class="admin_ui_filter admin_ui_date" id="admin_ui_filter_<?php echo esc_attr( $filter ); ?>_end" value="<?php echo esc_attr( $end ); ?>" />
								<?php
							} elseif ( 'related' === $filter_column['type'] && false !== $filter_column['related'] ) {
								if ( ! is_array( $filter_column['related'] ) ) {
									$related  = $this->rows( 'SELECT ' . $this->backtick( $filter_column['related_id'] ) . ',' . $this->backtick( $filter_column['related_field'] ) . ' FROM ' . $filter_column['related'] . ( ! empty( $filter_column['related_sql'] ) ? ' ' . $filter_column['related_sql'] : '' ) );
									$selected = $this->filter_values( 'filter_' . $filter, $filter_column['filter_default'] );
									?>
									<label for="admin_ui_filter_<?php echo esc_attr( $filter ); ?>"><?php echo esc_html( $filter_column['filter_label'] ); ?>:</label>
									<select name="filter_<?php echo esc_attr( $filter ); ?><?php echo( false !== $filter_column['related_multiple'] ? '[]' : '' ); ?>" id="admin_ui_filter_<?php echo esc_attr( $filter ); ?>"<?php echo( false !== $filter_column['related_multiple'] ? ' size="10" class="admin_ui_filter_multiple" multiple' : '' ); ?>>
										<option value="">-- Show All --</option>
										<?php
										foreach ( $related as $option ) {
											?>
											<option value="<?php echo esc_attr( $option->{$filter_column['related_id']} ); ?>"<?php selected( in_array( (string) $option->{$filter_column['related_id']}, $selected, true ) ); ?>><?php echo esc_html( $option->{$filter_column['related_field']} ); ?></option>
											<?php
										}
										?>
									</select>
									<?php
								} else {
									$related  = $filter_column['related'];
									$selected = $this->filter_values( 'filter_' . $filter, $filter_column['filter_default'] );
									?>
									<label for="admin_ui_filter_<?php echo esc_attr( $filter ); ?>"><?php echo esc_html( $filter_column['filter_label'] ); ?>:</label>
									<select name="filter_<?php echo esc_attr( $filter ); ?><?php echo( false !== $filter_column['related_multiple'] ? '[]' : '' ); ?>" id="admin_ui_filter_<?php echo esc_attr( $filter ); ?>"<?php echo( false !== $filter_column['related_multiple'] ? ' size="10" class="admin_ui_filter_multiple" multiple' : '' ); ?>>
										<option value="">-- Show All --</option>
										<?php
										foreach ( $related as $option_id => $option ) {
											?>
											<option value="<?php echo esc_attr( $option_id ); ?>"<?php selected( in_array( (string) $option_id, $selected, true ) ); ?>><?php echo esc_html( $option ); ?></option>
											<?php
										}
										?>
									</select>
									<?php
								}
							} elseif ( 'bool' === $filter_column['type'] ) {
								$selected = $this->filter_values( 'filter_' . $filter, $filter_column['filter_default'] );
								$selected = array() === $selected ? '' : ( 1 === (int) $selected[0] ? '1' : '0' );
								?>
								<label for="admin_ui_filter_<?php echo esc_attr( $filter ); ?>"><?php echo esc_html( $filter_column['filter_label'] ); ?>:</label>
								<select name="filter_<?php echo esc_attr( $filter ); ?>" id="admin_ui_filter_<?php echo esc_attr( $filter ); ?>">
									<option value=""><?php esc_html_e( '-- Show All --', 'exports-and-reports' ); ?></option>
									<option value="1"<?php selected( '1', $selected ); ?>><?php esc_html_e( 'Yes', 'exports-and-reports' ); ?></option>
									<option value="0"<?php selected( '0', $selected ); ?>><?php esc_html_e( 'No', 'exports-and-reports' ); ?></option>
								</select>
								<?php
							} else {
								?>
								<label for="admin_ui_filter_<?php echo esc_attr( $filter ); ?>"><?php echo esc_html( $filter_column['filter_label'] ); ?>:</label>
								<input type="text" name="filter_<?php echo esc_attr( $filter ); ?>" class="admin_ui_filter" id="admin_ui_filter_<?php echo esc_attr( $filter ); ?>" value="<?php echo esc_attr( implode( ', ', $this->filter_values( 'filter_' . $filter, $filter_column['filter_default'] ) ) ); ?>" />
								<?php
							}
						}
						?>
						&nbsp;&nbsp;
						<label<?php echo( empty( $this->filters ) ? ' class="screen-reader-text"' : '' ); ?> for="page-search-input"><?php esc_html_e( 'Search:', 'exports-and-reports' ); ?></label>
						<input type="text" name="search_query" id="page-search-input" value="<?php echo esc_attr( $this->search_query ); ?>" />
						<input type="submit" value="<?php esc_attr_e( 'Search', 'exports-and-reports' ); ?>" class="button" />
						<?php
						if ( false !== $this->search_query ) {
							$clear_filters = array();
							foreach ( $this->filters as $filter ) {
								$clear_filters[ 'filter_' . $filter . '_start' ] = '';
								$clear_filters[ 'filter_' . $filter . '_end' ]   = '';
								$clear_filters[ 'filter_' . $filter ]            = '';
							}
							?>
							&nbsp;&nbsp;&nbsp;
							<small>[<a href="
							<?php
								echo esc_url(
									$this->var_update(
										$clear_filters,
										array(
											'order',
											'order_dir',
											'limit',
										)
									)
								);
							?>
												"><?php esc_html_e( 'Reset Filters', 'exports-and-reports' ); ?></a>] </small>
							<?php
						}
						?>
					</p>
				</form>
				<?php
			} else {
				?>
				<br class="clear" />
				<br class="clear" />
				<?php
			}
			?>
			<div class="tablenav">
				<?php
				if ( ! empty( $this->data ) && false !== $this->pagination ) {
					?>
					<div class="tablenav-pages">
						<?php esc_html_e( 'Show per page:', 'exports-and-reports' ); ?><?php $this->limit(); ?> &nbsp;|&nbsp;
						<?php $this->pagination( true ); ?>
					</div>
					<?php
				}
				if ( 1 === $reorder ) {
					?>
					<button type="submit" form="exports-reports-reorder" class="button"><?php esc_html_e( 'Update Order', 'exports-and-reports' ); ?></button>
					<a class="button" href="<?php echo esc_url( $this->var_update( array( 'action' => 'manage' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'exports-and-reports' ); ?></a>
					<?php
				} elseif ( $this->add || $this->export ) {
					?>
					<div class="alignleft actions">
						<?php if ( $this->add ) { ?>
							<a class="button" href="<?php echo esc_url( $this->var_update( array( 'action' => 'add' ) ) ); ?>">
								<?php
								/* translators: %s: the item's name, as the screen calls it (Report, Report Group). */
								echo esc_html( sprintf( __( 'Add New %s', 'exports-and-reports' ), $this->item ) );
								?>
							</a>
						<?php } ?>

						<?php if ( $this->reorder ) { ?>
							<a class="button" href="<?php echo esc_url( $this->var_update( array( 'action' => 'reorder' ) ) ); ?>"><?php esc_html_e( 'Reorder', 'exports-and-reports' ); ?></a>
						<?php } ?>

						<?php if ( $this->export && ! empty( $this->data ) ) { ?>
							<?php
							$export_format_types = array(
								'CSV'  => 'csv',
								'TSV'  => 'tsv',
								'TXT'  => 'pipe',
								'XLSX' => 'xlsx',
								'XML'  => 'xml',
								'JSON' => 'json',
								'PDF'  => 'pdf',
							);
							$export_format_links = array();
							foreach ( $export_format_types as $export_format_text => $export_format_type ) {
								$export_format_links[ $export_format_text ] = $this->export_link( array( 'export_type' => $export_format_type ) );
							}
							?>
							<strong><?php esc_html_e( 'Export:', 'exports-and-reports' ); ?></strong>
							<?php foreach ( $export_format_links as $export_format_text => $export_format_link ) { ?>
								<a class="button" href="<?php echo esc_url( $export_format_link ); ?>"><?php echo esc_html( $export_format_text ); ?></a>
							<?php } ?>
						<?php } ?>
					</div>
					<?php
				}
				?>
				<br class="clear" />
			</div>
			<div class="clear"></div>
			<?php
			if ( empty( $this->data ) && false !== $this->default_none && false === $this->search_query ) {
				?>
				<p>
					<?php
					if ( $this->export ) {
						esc_html_e( 'Please use the search filter(s) above to display data, or click on an Export to download a full copy of the data.', 'exports-and-reports' );
					} else {
						esc_html_e( 'Please use the search filter(s) above to display data.', 'exports-and-reports' );
					}
					?>
				</p>
			<?php } else { ?>
				<?php $this->table( $reorder ); ?>
			<?php } ?>

			<?php if ( ! empty( $this->data ) ) { ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php $this->pagination(); ?>
						<br class="clear" />
					</div>
				</div>
			<?php } ?>

			<?php if ( ! empty( $this->data ) && 1 !== $reorder && false !== $this->totals ) { ?>
				<h3 class="admin_ui_totals_heading"><?php esc_html_e( 'Totals', 'exports-and-reports' ); ?></h3>
				<table class="widefat fixed admin_ui_table admin_ui_totals" cellspacing="0">
					<thead>
					<tr>
						<?php
						$columns = array();
						foreach ( $this->columns as $column => $attributes ) {
							if ( ! isset( $this->totals[ $column ] ) ) {
								continue;
							}
							$label = ucwords( str_replace( '_', ' ', $column ) );
							if ( false !== $this->get_var( 'label', false, false, $attributes ) ) {
								$label = $attributes['label'];
							}
							?>
							<th scope="col" class="manage-column"><?php echo wp_kses_post( $label ); ?></th>
							<?php
							if ( 'number' === $attributes['type'] ) {
								$total = (int) $this->totals[ $column ];
							} elseif ( 'decimal' === $attributes['type'] ) {
								$total = number_format( $this->totals[ $column ], 2 );
							} else {
								$total = $this->totals[ $column ];
							}
							$columns[ $column ] = $total;
						}
						?>
					</tr>
					</thead>
					<tbody>
					<tr>
						<?php
						foreach ( $columns as $column => $total ) {
							?>
							<td><?php echo esc_html( $total ); ?></td>
							<?php
						}
						?>
					</tr>
					</tbody>
				</table>
				<?php
			}
			?>
		</div>
		<?php
	}

	public function table( $reorder = 0 ) {
		if ( 1 === $reorder && false !== $this->reorder ) {
			wp_enqueue_script( 'jquery-ui-sortable' );
		}
		/**
		 * Fires before the table.
		 *
		 * @since 1.0.0
		 *
		 * @param int                     $reorder 1 on the reorder screen.
		 * @param Exports_Reports_Admin_UI $admin   The screen.
		 */
		do_action( 'exports_reports_table', $reorder, $this );
		$this->legacy_hook( 'table', array( $reorder ) );
		if ( isset( $this->custom['table'] ) && function_exists( "{$this->custom['table']}" ) ) {
			return call_user_func( $this->custom['table'], $this, $reorder );
		}
		if ( empty( $this->data ) ) {
			?>
			<p><?php esc_html_e( 'No items found', 'exports-and-reports' ); ?></p>
			<?php
			return false;
		}
		if ( 1 === $reorder && $this->reorder ) {
			?>
		<form action="
			<?php
			echo esc_url(
				$this->var_update(
					array(
						'action' => 'reorder',
						'do'     => 'save',
					)
				)
			);
			?>
						" method="post" id="exports-reports-reorder" class="admin_ui_reorder_form">
			<?php wp_nonce_field( 'exports-reports-reorder' ); ?>
			<?php
		}
			$column_index = 'columns';
		if ( 1 === $reorder ) {
			$column_index = 'reorder_columns';
		}
		if ( false === $this->{$column_index} || empty( $this->{$column_index} ) ) {
			return $this->error( __( '<strong>Error:</strong> Invalid Configuration - Missing "columns" definition.', 'exports-and-reports' ) );
		}
		?>
			<table class="widefat striped page fixed admin_ui_table wp-list-table" cellspacing="0"<?php echo( 1 === $reorder && $this->reorder ? ' id="admin_ui_reorder"' : '' ); ?>>
				<thead>
				<tr>
					<?php
					$name_column = false;
					$columns     = array();
					if ( ! empty( $this->{$column_index} ) ) {
						foreach ( $this->columns as $column => $attributes ) {
							if ( ! is_array( $attributes ) ) {
								$column     = $attributes;
								$attributes = $this->setup_column( $column );
							}
							if ( false === $attributes['display'] ) {
								continue;
							}
							if ( false === $name_column ) {
								$id = 'title';
							} else {
								$id = '';
							}
							if ( false !== $this->get_var( 'type', false, false, $attributes ) ) {
								if ( 'other' === $attributes['type'] ) {
									$id = '';
								}
								if ( 'date' === $attributes['type'] || 'datetime' === $attributes['type'] || 'time' === $attributes['type'] ) {
									$id = 'date';
								}
							}
							if ( false === $name_column && 'title' === $id ) {
								$name_column = true;
							}
							$label = ucwords( str_replace( '_', ' ', $column ) );
							if ( false !== $this->get_var( 'label', false, false, $attributes ) ) {
								$label = $attributes['label'];
							}
							$columns[ $column ] = array(
								'label' => $label,
								'id'    => $id,
							);
							$columns[ $column ] = array_merge( $columns[ $column ], $attributes );
							$dir                = 'ASC';
							if ( $this->order === $column && 'ASC' === $this->order_dir ) {
								$dir = 'DESC';
							}

							$width = '';

							if ( ! empty( $columns[ $column ]['width'] ) ) {
								$width = ' width="' . esc_attr( $columns[ $column ]['width'] ) . '"';
							}
							?>
							<th scope="col" id="<?php echo esc_attr( $id ); ?>" class="manage-column column-<?php echo esc_attr( $id ); ?>"<?php echo esc_html( $width ); ?>>
								<a href="
								<?php
								echo esc_url(
									$this->var_update(
										array(
											'order'     => $column,
											'order_dir' => $dir,
										),
										array( 'limit', 'search_query' )
									)
								);
								?>
											"><?php echo wp_kses_post( $label ); ?></a>
							</th>
							<?php
						}
					}
					?>
				</tr>
				</thead>
				<tfoot>
				<tr>
					<?php if ( ! empty( $columns ) ) { ?>
						<?php foreach ( $columns as $column => $attributes ) { ?>
							<th scope="col" class="manage-column column-<?php echo esc_attr( $attributes['id'] ); ?>"><?php echo wp_kses_post( $attributes['label'] ); ?></th>
						<?php } ?>
					<?php } ?>
				</tr>
				</tfoot>
				<tbody<?php echo( 1 === $reorder && $this->reorder ? ' class="sortable"' : '' ); ?>>
				<?php
				if ( ! empty( $this->data ) ) {
					foreach ( $this->data as $row ) {
						$id = 0;

						if ( ! empty( $row[ $this->identifier ] ) ) {
							$id = $row[ $this->identifier ];
						}
						?>
						<tr id="item-<?php echo esc_attr( $id ); ?>" class="iedit">
							<?php
							foreach ( $columns as $column => $attributes ) {
								if ( false === $attributes['display'] ) {
									continue;
								}
								$row[ $column ] = $this->field_value( $row[ $column ], $column, $attributes );
								if ( false !== $attributes['custom_display'] && function_exists( "{$attributes['custom_display']}" ) ) {
									$row[ $column ] = $attributes['custom_display']( $row[ $column ], $row, $column, $attributes, $this );
								}
								if ( 'title' === $attributes['id'] ) {
									if ( $this->view && ( 0 === $reorder || false === $this->reorder ) ) {
										?>
										<td class="post-title page-title column-title">
										<strong><a class="row-title" href="
										<?php
											echo esc_url(
												$this->var_update(
													array(
														'action' => 'view',
														'id'     => $id,
													)
												)
											);
										?>
																			" title="View &#8220;<?php echo esc_attr( wp_strip_all_tags( (string) $row[ $column ] ) ); ?>&#8221;"><?php echo wp_kses( (string) $row[ $column ], $this->allowed_html() ); ?></a></strong>
										<?php
									} elseif ( $this->edit && ( 0 === $reorder || false === $this->reorder ) ) {
										?>
										<td class="post-title page-title column-title">
										<strong><a class="row-title" href="
										<?php
											echo esc_url(
												$this->var_update(
													array(
														'action' => 'edit',
														'id'     => $id,
													)
												)
											);
										?>
																			" title="Edit &#8220;<?php echo esc_attr( wp_strip_all_tags( (string) $row[ $column ] ) ); ?>&#8221;"><?php echo wp_kses( (string) $row[ $column ], $this->allowed_html() ); ?></a></strong>
										<?php
									} else {
										?>
										<td class="post-title page-title column-title<?php echo( 1 === $reorder && $this->reorder ? ' dragme' : '' ); ?>">
										<strong>
											<?php
											echo wp_kses( (string) $row[ $column ], $this->allowed_html() );
											?>
										</strong>
										<?php
									}
									if ( 0 === $reorder || false === $this->reorder ) {
										$actions = array();
										if ( $this->view ) {
											$actions['view'] = '<span class="view"><a href="' . esc_url(
												$this->var_update(
													array(
														'action' => 'view',
														'id'     => $id,
													)
												)
											) . '" title="' . esc_attr__( 'View this item', 'exports-and-reports' ) . '">' . esc_html__( 'View', 'exports-and-reports' ) . '</a></span>';
										}
										if ( $this->edit ) {
											$actions['edit'] = '<span class="edit"><a href="' . esc_url(
												$this->var_update(
													array(
														'action' => 'edit',
														'id'     => $id,
													)
												)
											) . '" title="' . esc_attr__( 'Edit this item', 'exports-and-reports' ) . '">' . esc_html__( 'Edit', 'exports-and-reports' ) . '</a></span>';
										}
										if ( $this->duplicate ) {
											$actions['duplicate'] = '<span class="edit"><a href="' . esc_url(
												$this->var_update(
													array(
														'action' => 'duplicate',
														'id'     => $id,
													)
												)
											) . '" title="' . esc_attr__( 'Duplicate this item', 'exports-and-reports' ) . '">' . esc_html__( 'Duplicate', 'exports-and-reports' ) . '</a></span>';
										}
										if ( $this->delete ) {
											/* translators: %s: the item's title, as the row shows it. */
											$confirm           = sprintf( __( 'You are about to delete "%s". Cancel to stop, OK to delete.', 'exports-and-reports' ), wp_strip_all_tags( (string) $row[ $column ] ) );
											$actions['delete'] = '<span class="delete"><a class="submitdelete" title="' . esc_attr__( 'Delete this item', 'exports-and-reports' ) . '" href="' . esc_url(
												$this->var_update(
													array(
														'action'   => 'delete',
														'id'       => $id,
														'_wpnonce' => wp_create_nonce( 'exports-reports-delete' ),
													)
												)
											) . '" data-exports-reports-confirm="' . esc_attr( $confirm ) . '">' . esc_html__( 'Delete', 'exports-and-reports' ) . '</a></span>';
										}
										if ( is_array( $this->custom ) ) {
											foreach ( $this->custom as $custom_action => $custom_data ) {
												if ( is_array( $custom_data ) && isset( $custom_data['link'] ) ) {
													if ( ! in_array(
														$custom_action,
														array(
															'add',
															'view',
															'edit',
															'duplicate',
															'delete',
															'save',
															'readonly',
															'export',
															'reorder',
														),
														true
													) ) {
														if ( ! isset( $custom_data['label'] ) ) {
															$custom_data['label'] = ucwords( str_replace( '_', ' ', $custom_action ) );
														}
														$actions[ $custom_action ] = '<span class="edit"><a href="' . esc_url( $this->parse_template_string( $custom_data['link'], $row ) ) . '" title="' . esc_attr( $custom_data['label'] ) . ' this item">' . wp_kses_post( $custom_data['label'] ) . '</a></span>';
													}
												}
											}
										}
										/**
										 * Filters the links under a row's title.
										 *
										 * @since 1.0.0 The row is passed.
										 *
										 * @param string[]                $actions The links, HTML, by action.
										 * @param array                   $row     The row.
										 * @param Exports_Reports_Admin_UI $admin   The screen.
										 */
										$actions = apply_filters( 'exports_reports_row_actions', $actions, $row, $this );
										$actions = $this->legacy_hook( 'row_actions', array( $actions, $row ) );
										?>
										<div class="row-actions">
											<?php
											if ( isset( $this->custom['actions_start'] ) && function_exists( "{$this->custom['actions_start']}" ) ) {
												call_user_func( $this->custom['actions_start'], $this, $row );
											}
											echo wp_kses_post( implode( ' | ', $actions ) );
											if ( isset( $this->custom['actions_end'] ) && function_exists( "{$this->custom['actions_end']}" ) ) {
												call_user_func( $this->custom['actions_end'], $this, $row );
											}
											?>
										</div>
										<?php
									} else {
										?>
										<input type="hidden" name="order[]" value="<?php echo esc_attr( $id ); ?>" />
										<?php
									}
									?>
									</td>
									<?php
								} elseif ( 'date' === $attributes['type'] ) {
									?>
									<td class="date column-date">
										<abbr title="<?php echo esc_attr( $row[ $column ] ); ?>"><?php echo wp_kses( (string) $row[ $column ], $this->allowed_html() ); ?></abbr>
									</td>
									<?php
								} elseif ( 'time' === $attributes['type'] ) {
									?>
									<td class="date column-date">
										<abbr title="<?php echo esc_attr( $row[ $column ] ); ?>"><?php echo wp_kses( (string) $row[ $column ], $this->allowed_html() ); ?></abbr>
									</td>
									<?php
								} elseif ( 'datetime' === $attributes['type'] ) {
									?>
									<td class="date column-date">
										<abbr title="<?php echo esc_attr( $row[ $column ] ); ?>"><?php echo wp_kses( (string) $row[ $column ], $this->allowed_html() ); ?></abbr>
									</td>
									<?php
								} elseif ( 'related' === $attributes['type'] && false !== $attributes['related'] ) {
									?>
									<td class="author column-author"><?php echo wp_kses( (string) $row[ $column ], $this->allowed_html() ); ?></td>
									<?php
								} elseif ( 'bool' === $attributes['type'] ) {
									?>
									<td class="author column-author"><?php echo wp_kses( (string) $row[ $column ], $this->allowed_html() ); ?></td>
									<?php
								} elseif ( 'number' === $attributes['type'] ) {
									?>
									<td class="author column-author"><?php echo wp_kses( (string) $row[ $column ], $this->allowed_html() ); ?></td>
									<?php
								} elseif ( 'decimal' === $attributes['type'] ) {
									?>
									<td class="author column-author"><?php echo wp_kses( (string) $row[ $column ], $this->allowed_html() ); ?></td>
									<?php
								} else {
									?>
									<td class="author column-author"><?php echo wp_kses( (string) $row[ $column ], $this->allowed_html() ); ?></td>
									<?php
								}
							}
							?>
						</tr>
						<?php
					}
				}
				?>
				<?php if ( 0 < count( $this->sum_data ) && ceil( $this->total / $this->limit ) === $this->page ) { // The sum row, on the last page only. ?>
					<tr id="exports-and-reports-sums" class="iedit">
						<?php foreach ( $columns as $column => $attributes ) { ?>
							<td class="exports-and-reports-sum-<?php echo esc_attr( $column ); ?>">
								<strong><?php echo esc_html( isset( $this->sum_data[0][ $column ] ) ? $this->sum_data[0][ $column ] : '' ); ?></strong>
							</td>
						<?php } ?>
					</tr>
				<?php } ?>
				</tbody>
			</table>
			<?php if ( 1 === $reorder && false !== $this->reorder ) { ?>
		</form>
	<?php } ?>
		<?php
	}

	/**
	 * @param bool $header
	 *
	 * @return mixed
	 */
	public function pagination( $header = false ) {
		/**
		 * Fires before the page links.
		 *
		 * @since 1.0.0
		 *
		 * @param Exports_Reports_Admin_UI $admin The screen.
		 */
		do_action( 'exports_reports_pagination', $this );
		$this->legacy_hook( 'pagination' );
		if ( isset( $this->custom['pagination'] ) && function_exists( "{$this->custom['pagination']}" ) ) {
			return call_user_func( $this->custom['pagination'], $this );
		}

		$page          = $this->page;
		$rows_per_page = $this->limit;
		$total_rows    = $this->total;
		$total_pages   = (int) ceil( $total_rows / $rows_per_page );

		$request_uri = $this->var_update(
			array( 'pg' => '' ),
			array(
				'limit',
				'order',
				'order_dir',
				'search_query',
			)
		);

		$append = false;

		if ( false !== strpos( $request_uri, '?' ) ) {
			$append = true;
		}

		if ( $header || 1 !== $total_rows ) {
			$singular_label = strtolower( $this->item );
			$plural_label   = strtolower( $this->items );

			$label_n = 1 === (int) $total_rows ? $singular_label : $plural_label;
			?>
			<span class="displaying-num"><?php echo esc_html( number_format_i18n( $total_rows ) . ' ' . $label_n ); ?></span>
			<?php
		}

		if ( 1 < $total_pages ) {
			?>
			<a class="first-page<?php echo ( 1 < $this->page ) ? '' : ' disabled'; ?>" title="<?php esc_attr_e( 'Go to the first page', 'exports-and-reports' ); ?>" href="<?php echo esc_url( $request_uri . ( $append ? '&' : '?' ) . 'pg' . $this->num . '=1' ); ?>">&laquo;</a>
			<a class="prev-page<?php echo ( 1 < $this->page ) ? '' : ' disabled'; ?>" title="<?php esc_attr_e( 'Go to the previous page', 'exports-and-reports' ); ?>" href="<?php echo esc_html( $request_uri . ( $append ? '&' : '?' ) . 'pg' . $this->num . '=' . max( $this->page - 1, 1 ) ); ?>">&lsaquo;</a>
			<?php
			if ( true === $header ) {
				?>
				<span class="paging-input"><input class="current-page" title="<?php esc_attr_e( 'Current page', 'exports-and-reports' ); ?>" type="text" name="pg<?php echo esc_attr( $this->num ); ?>" value="<?php echo esc_attr( $this->page ); ?>" size="<?php echo esc_attr( (string) strlen( (string) $total_pages ) ); ?>"> <?php esc_html_e( 'of', 'exports-and-reports' ); ?>
					<span class="total-pages"><?php echo esc_html( (string) $total_pages ); ?></span></span>
				<?php
			} else {
				?>
				<span class="paging-input"><?php echo esc_html( $this->page ); ?> <?php esc_html_e( 'of', 'exports-and-reports' ); ?>
					<span class="total-pages"><?php echo esc_html( number_format_i18n( $total_pages ) ); ?></span></span>
				<?php
			}
			?>
			<a class="next-page<?php echo ( $this->page < $total_pages ) ? '' : ' disabled'; ?>" title="<?php esc_attr_e( 'Go to the next page', 'exports-and-reports' ); ?>" href="<?php echo esc_url( $request_uri . ( $append ? '&' : '?' ) . 'pg' . $this->num . '=' . min( $this->page + 1, $total_pages ) ); ?>">&rsaquo;</a>
			<a class="last-page<?php echo ( $this->page < $total_pages ) ? '' : ' disabled'; ?>" title="<?php esc_attr_e( 'Go to the last page', 'exports-and-reports' ); ?>'" href="<?php echo esc_url( $request_uri . ( $append ? '&' : '?' ) . 'pg' . $this->num . '=' . $total_pages ); ?>">&raquo;</a>
			<?php
		}
	}

	public function limit( $options = false ) {
		/**
		 * Filters the page sizes offered.
		 *
		 * @since 1.0.0 A filter; the value the callbacks return is used.
		 *
		 * @param int[]|false             $options The sizes, or false for the default list.
		 * @param Exports_Reports_Admin_UI $admin   The screen.
		 */
		$options = apply_filters( 'exports_reports_limit', $options, $this );
		$options = $this->legacy_hook( 'limit', array( $options ) );
		if ( isset( $this->custom['limit'] ) && function_exists( "{$this->custom['limit']}" ) ) {
			return call_user_func( $this->custom['limit'], $this );
		}
		if ( false === $options || ! is_array( $options ) || empty( $options ) ) {
			$options = array( 10, 25, 50, 100, 200 );
		}
		if ( ! in_array( $this->limit, array_map( 'intval', $options ), true ) ) {
			$this->limit = $options[1];
		}
		foreach ( $options as $option ) {
			if ( $this->limit === (int) $option ) {
				echo ' <span class="page-numbers current">' . esc_html( $option ) . '</span>';
			} else {
				echo ' <a href="' . esc_url(
					$this->var_update(
						array( 'limit' => $option ),
						array(
							'order',
							'order_dir',
							'search_query',
						)
					)
				) . '">' . esc_html( $option ) . '</a>';
			}
		}
	}

	public function parse_template_string( $in, $row = false ) {
		if ( false !== $row ) {
			$this->temp_row = $this->row;
			$this->row      = $row;
		}
		$out = preg_replace_callback( '/({@(.*?)})/m', array( $this, 'parse_magic_tags' ), $in );
		if ( false !== $row ) {
			$this->row = $this->temp_row;
		}

		return $out;
	}

	public function parse_magic_tags( $in ) {
		$name   = $in[2];
		$helper = '';
		if ( false !== strpos( $name, ',' ) ) {
			[ $name, $helper ] = explode( ',', $name );
			$name              = trim( $name );
			$helper            = trim( $helper );
		}
		$value = $this->row[ $name ];
		// A helper named after the comma ({@field,helper}) formats the value.
		if ( '' !== $helper && $this->is_allowed_callback( $helper ) ) {
			$value = $helper( $value, $name, $this->row );
		}

		return $value;
	}
}
