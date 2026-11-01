<?php
/**
 * The JSON export.
 *
 * @package Exports_And_Reports
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a JSON document: the rows under items.item with their count.
 *
 * @since 1.0.0 Out of Exports_Reports_Admin_UI::export().
 */
class Exports_Reports_Exporter_JSON extends Exports_Reports_Exporter {

	/**
	 * Writes the file from the rows the screen loaded.
	 *
	 * @return string The file name.
	 */
	public function write() {
		/** @var WP_Filesystem_Base $wp_filesystem */ global $wp_filesystem;

		$export_file          = $this->file();
		$export_file_location = $this->path( $export_file );
		$columns              = $this->admin->exported_columns();
		$rows                 = array();

		foreach ( $this->admin->full_data as $item ) {
			$rows[] = $this->admin->export_row( $item, $columns );
		}

		$data = array(
			'items' => array(
				'count' => count( $rows ),
				'item'  => $rows,
			),
		);

		$wp_filesystem->put_contents( $export_file_location, wp_json_encode( $data, JSON_PRETTY_PRINT ) );

		return $export_file;
	}
}
