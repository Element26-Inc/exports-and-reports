<?php
/**
 * The list screen: the heading, the header callback, the export message, the filters and the search, the
 * table (the reorder form on the reorder screen) and the totals.
 *
 * @package Exports_And_Reports
 * @since   1.0.0
 *
 * @var Exports_Reports_Admin_UI $this    The screen.
 * @var int                      $reorder 1 on the reorder screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$reordering   = 1 === $reorder && false !== $this->reorder;
$searchable   = ( ! empty( $this->data ) || false !== $this->search_query || false !== $this->default_none ) && false !== $this->search;
$screen_title = ( $reordering ? $this->heading['reorder'] : $this->heading['manage'] ) . ' ' . $this->items;
$back         = $reordering;

?>
<div class="wrap wp-admin-ui-wrap">
	<?php
	require __DIR__ . '/heading.php';

	if ( isset( $this->custom['header'] ) && function_exists( "{$this->custom['header']}" ) ) {
		echo wp_kses( (string) call_user_func( $this->custom['header'], $this ), $this->allowed_html() );
	}

	if ( false !== $this->export && 'export' === $this->action ) {
		$this->export();
	}

	if ( $searchable ) {
		?>
		<form id="posts-filter" action="" method="get">
			<p class="search-box">
				<?php require __DIR__ . '/list-filters.php'; ?>
			</p>
			<?php
			if ( ! $reordering ) {
				require __DIR__ . '/list-body.php';
			}
			?>
		</form>
		<?php
		if ( $reordering ) {
			require __DIR__ . '/list-body.php'; // The reorder form is a form of its own, after the search form.
		}
	} else {
		?>
		<br class="clear" />
		<?php
		require __DIR__ . '/list-body.php';
	}

	if ( ! empty( $this->data ) && 1 !== $reorder && false !== $this->totals ) {
		?>
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
