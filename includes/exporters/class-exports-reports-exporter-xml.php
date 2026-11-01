<?php
/**
 * The XML export.
 *
 * @package Exports_And_Reports
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes an XML document: one item element per row, each value in a CDATA section under its column name.
 *
 * @since 1.0.0 Out of Exports_Reports_Admin_UI::export().
 */
class Exports_Reports_Exporter_XML extends Exports_Reports_Exporter {

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
		$file_contents        = '<?xml version="1.0" encoding="' . get_bloginfo( 'charset' ) . '" ?>' . "\r\n<items count=\"" . count( $this->admin->full_data ) . "\">\r\n";

		foreach ( $this->admin->full_data as $item ) {
			$file_contents .= "\t<item>\r\n";

			foreach ( $this->admin->export_row( $item, $columns ) as $key => $value ) {
				$file_contents .= "\t\t<{$key}><![CDATA[" . $value . "]]></{$key}>\r\n";
			}

			$file_contents .= "\t</item>\r\n";
		}

		$file_contents .= '</items>';

		$wp_filesystem->put_contents( $export_file_location, $file_contents );

		return $export_file;
	}
}
