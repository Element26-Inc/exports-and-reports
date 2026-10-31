=== Exports and Reports ===
Contributors: sc0ttkclark
Donate link: https://github.com/sponsors/sc0ttkclark
Tags: exporting, reporting, csv, pdf, xml
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.9.5
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Define custom exports / reports for users, based off of any custom MySQL SELECT query you define.

== Description ==

Define custom exports / reports for users, based off of any MySQL SELECT query you create. This plugin interacts with your SELECT query and does all the hard work for you: exporting, pagination, ordering, searching/filtering, and display formatting for you.

All you do is install the plugin, create your Groups, create your Reports, and hand it off to your clients. Exportable reports in CSV, TSV, XLSX, XML, JSON, PDF and custom delimiter separated formats, from the admin screens or through the REST API with a token, for cronjobs.

Requires PHP 7.4 and WordPress 6.0 or later. The PDF export needs PHP 8.2 (the PDF library's floor); the other formats work on 7.4.

Please submit bug reports or contribute your own enhancements/fixes on [GitHub](https://github.com/sc0ttkclark/exports-and-reports).

== Frequently Asked Questions ==

**What are Groups?**

Groups are groupings of Reports that are given their own menu item in the "Reports" menu.

**What is a Report?**

A Report is defined by a Custom MySQL query and can be configured to display however you wish using additional field definitions. Exports can be disabled per report.

**My report isn't working**

As an admin, add &debug=1 to the end of the report URL to see the query that this plugin uses, take that query and use it in your own MySQL client or PHPMyAdmin to see if there are any errors in your own query.

= Where do the export files go, and who can download them? =

In wp-content/exports by default. Every download goes through the plugin after a nonce or API token check, so the directory never needs to be reachable by URL. The plugin writes an .htaccess that keeps Apache from serving it; nginx ignores .htaccess, so either add the rule to the server block:

`location ~* /wp-content/exports/ { deny all; }`

or define WP_ADMIN_UI_EXPORT_DIR in wp-config.php with a writable directory outside the web root, which needs no server rule at all. The Settings page shows the directory in use.

== Installation ==

1. Download the plugin zip from the [GitHub releases](https://github.com/sc0ttkclark/exports-and-reports/releases) (the plugin is not listed on wordpress.org at the moment)
1. Upload it through Plugins >> Add New >> Upload Plugin, or unpack it into your `wp-content/plugins/` folder
1. Activate this plugin
1. Create your Groups and Reports under Reports Admin; the Settings page shows the export directory and the API URLs

The plugin's bundled libraries (`vendor/`) are part of the zip and of the repository, so a checkout works without Composer.

== Features ==

= Administration =
* Create and Manage Groups
* Create and Manage Reports
* Limit which User Roles have access to a Group or Report
* Ability to clear entire export directory (based on logged export files)
* Daily Export Cleanup via wp_cron
* The export directory is not served by the web server; downloads go through the plugin after an access check
* Deletes its tables, options and export files on uninstall
* WP Admin UI - A class for plugins to manage data using the WordPress UI appearance

= Reporting =
* Filter by Date
* Automatic Pagination
* Show only the fields you want to show
* Pre-display modification through custom defined function per field or row

= Exporting =
* CSV - Comma-separated Values (w/ Excel support)
* TSV - Tab-separated Values (w/ Excel support)
* TXT - Pipe-separated Values (w/ Excel support)
* XLSX - Excel format, using [PHP_XLSXWriter](https://github.com/mk-j/PHP_XLSXWriter)
* XML - XML 1.0 UTF-8 data
* JSON - JSON format
* PDF - PDF printer friendly views, using [tc-lib-pdf](https://github.com/tecnickcom/tc-lib-pdf)
* Custom - Custom delimiter separated Values (Update the report screen URL parameters to `&action=export&export_type=custom&export_delimiter=#` and change # to whatever delimiter you want)

= REST API / Cronjob =
* Run the Export action for a specific report to any supported export type, and download the file
* Get paginated / full data from a report in JSON format
* Routes under /wp-json/exports-reports/v1/reports/ID/ (data, export, exports/FILE), for a logged-in user who may view the report or for the API token from the Settings page, sent as "Authorization: Bearer TOKEN" or as ?token=; the Settings page shows the URLs
* The pre-0.9.5 api.php endpoint still answers for existing cronjobs and goes away in 1.0

== Screenshots ==

1. View the report, export data, search content, filter by date, and paginate through the records
2. Create and manage report groups that can contain their own sets of reports
3. Create and manage reports
4. Specify the SQL query to use to select data from the database -- you can customize a special query used only for counting the total number of records
5. Specify the fields to display on the report and what kind of data they are
6. The list of supported data types for each field
7. Set advanced field options to further customize how fields will be displayed, exported, searched, filtered, or related to other tables
8. Manage plugin settings and get useful URLs for API access

== Changelog ==

= 0.9.5 =
* Security: the Settings page, the reorder form and the save path verify a nonce; the report field editor sanitises its inputs; the export directory is no longer served by the web server (an .htaccess and an index.php are written, downloads go through the plugin after a nonce or token check).
* Added a REST API (`exports-reports/v1/reports/{id}/data`, `/export`, `/exports/{file}`): the cronjob token as a bearer token or as the `token` parameter, or the logged-in user's access. `api.php` answers through it and is deprecated.
* Added the `wp_admin_ui_get_data_sql` and `wp_admin_ui_get_data_sql_count` filters, applied to the report query and its count query right before they run.
* CSV, TSV and pipe exports stream from an unbuffered result, so memory no longer grows with the table; every value is quoted, a UTF-8 byte order mark is written, and formula-like values are wrapped as text (`wp_admin_ui_export_escape_formulas` turns that off).
* Tabs around the `%%LIMIT%%` placeholder are handled like newlines.
* PDF exports use tc-lib-pdf instead of the deprecated TCPDF and need PHP 8.2; the rest of the plugin still runs on PHP 7.4.
* The plugin's tables use the site's character set and collation; existing tables are converted on upgrade.
* Added uninstall.php. The capabilities filter is `exports_reports_capabilities`; the misspelled `export_reports_capabilites` still works with a deprecation notice.
* The report form's field editor is rebuilt (one card per field with a drag handle, advanced options folded), the query fields use the WordPress code editor with SQL highlighting, and multiple-choice settings are checkbox lists.
* Requires WordPress 6.0; tested up to 7.1.

= 0.9.4 =
* Fixed PHP compatibility issues.
* Fixed compatibility issues when on sites that load their own XLSXWriter class.
* Updated XLSXWriter and TCPDF to the latest versions.

= 0.9.3 =
* Fixed issue with overriding the filtered content for a value.
* Fixed problems reported with opening CSV in certain spreadsheet applications which read some CSV values as actual formulas.

= 0.9.1 =
* Fixed issue with exporting via API. #33 #26 #25 (props @liedekef)
* Fixed reports so when they are hidden then they will no longer show in the dropdowns. #33 #25 (props @liedekef)
* Fixed access when mixing group permissions with individual report permissions. #29 #28 (props @dxdc)
* Fixed PHP notices for page orientation. #29 #27 (props @dxdc)

= 0.9.0 =
* Resolve PHP warning with TCPDF library.
* Now ensuring robots.txt blocks the exports directory from being indexing in certain circumstances.
* Updated compatibility with WP 5.9 and PHP 8.0

= 0.8.8 =
* Fixed an issue with the XLSX PHP library having deprecated notices, updated it to latest released version.

= 0.8.7 =
* Fixed a problem with backslashes in URLs for Windows file exports, props @ZelonGames [#16]
* Removed jQuery UI Datepicker functionality and replaced with HTML input types date, time, and datetime-local. [#10]
* Added support for numeric values in XLSX exports instead of treating them as strings for the export. [#18]
* Updated compatibility with WP 5.7

= 0.8.6 =
* Fixed a problem where the SQL upgrade would try to run after it's already done the install and is already up-to-date
* Updated compatibility with WP 5.5

= 0.8.5 =
* Updated compatibility with WP 5.4

= 0.8.4 =
* Fix some output escaping in the WP_Admin_UI class

= 0.8.3 =
* Fixed multiple group pages not showing the correct group view for first group in list [#8]
* Fixed export variable notices (props @rdaniel0) [#12]

= 0.8.2 =
* Added new filter `wp_admin_ui_export_pdf_report_file` to override the default `WP_Admin_UI_Export_PDF::CreateReport( $this )` process when creating PDF reports.
* Fixed PDF report titles that said 'item' instead of the report title.

= 0.8.1 =
* Moved install logic from init into activation hook, some times the plugin's version option was coming up empty and causing DB table resets.
* Implemented dbDelta function for schema modifications.

= 0.8.0 =
* Added ability to export to TXT file (pipe-delimited)
* Added ability to export to XLSX file
* Added ability to export to PDF file
* Lots of escaping improvements
* PHPCS fixes and formatting
* Other miscelaneous fixes and improvements to code quality

= 0.7.4 =
* Fix for report dropdown link problem introduced in 0.7.3 (props @andrewgosali)

= 0.7.3 =
* Added ability to set the ID field for a related table field (default was `id`, now you can customize it)
* Fix for pagination LIMIT bug (props @andrewgosali)
* Fix for is_plugin_active bug (props @skillio and @cvladan)
* Fix for get_currentuserinfo deprecation error
* Fixes for potential conflicts with other plugins that use the "export" and "download" URL parameters, they are now "exports_and_reports_export" and "exports_and_reports_download"
* Some more minor escaping fixes

= 0.7.2 =
* Fix for files not downloading completely (on some environments)
* Additional escaping fixes for WP_Admin_UI (reported by Sathish Kumar from cybersecurity works)

= 0.7.1 =
* Escaping fixes for WP_Admin_UI (reported by Sathish Kumar from cybersecurity works)

= 0.7.0 =
* Added: Using WP AJAX URL instead of Admin.class.php directly for downloads of exports

= 0.6.4 =
* Added: New constant to change the exports directory (WP_ADMIN_UI_EXPORT_DIR / WP_ADMIN_UI_EXPORT_URL)
* Added: New filter to change the exported filename (wp_admin_ui_export_file, filter is passed filename and export type)
* Added: Ability to use the API to export and then download the file (previously only JSON was available about file)
* Added: New filter to override the WP_Admin_UI options array (exports_reports_report_options, filter is passed an array of options)
* Fixed: JSON API for downloading data (full or paginated) now returns data properly, previously wasn't returning the actual data

= 0.6.3 =
* Security fix for orderby handling

= 0.6.2 =
* Added: Export JSON response now includes export file and message OR Error message from Cronjob URL (props to @adminatvbds for the idea)

= 0.6.1 =
* Fixed: How the token gets generated is more randomized now

= 0.6.0 =
* Feature: Added new Cronjob URL which you can define report or export type (requires WP 3.5+)
* Feature: Added new JSON API URL which you can define report, export type, and optional pagination (requires WP 3.5+)
* Changed: Format of exported file names is now utilizing 24 hour format, along with a random string at the end
* Fix: Tweaks to table markup

= 0.5.3 =
* Fix for exporting and URL paths on Windows, props to @fantomas_bg

= 0.5.2 =
* Bug fixes, added nonces for exports

= 0.5.1 =
* Feature: Reports menu split from Reports Admin so they're two separate menus now to avoid confusion for users
* Feature: Ability to reorder Groups so you can choose the order they appear in the menu
* Feature: Ability to set a separate query for use when getting the 'total' count (for advanced / complex queries which otherwise would be a bad idea to use SQL_CALC_FOUND_ROWS for)
* Feature: Report field settings now have 'Advanced' section that can be expanded to view advanced settings, otherwise you can use the three simple settings which are 'Field Name', 'Data Type', and 'Label (optional)'
* Feature: Now when you don't enter ANY fields in the Field Settings area, the report will pick up fields directly from the query for you when you display a report
* Feature: When making a boolean data type field filterable, a new filter will appear to choose '-- Show All --', 'Yes', and/or 'No'
* Feature: If you're having trouble with a report, you can enable debug mode by adding debug=1 to the Report URL (must be Administrator)
* Fix: Date fields that are empty (0000-00-00 00:00:00) will now show 'N/A' instead
* Fix: Related fields have better handling for SQL building
* Fix: No longer using default ORDER BY when no ORDER BY is found in SQL, causing issues with tables that didn't have a field 'id' set
* Fix: Smarter WHERE and HAVING dynamic building
* Fix: Lots of PHP warnings / notice cleanups and general code tweaks to plugin and WP_Admin_UI class

= 0.4.2 =
* Feature: Added ability to set the a field's filter default value
* Feature: Added additional 'related' field type options (where/order by SQL, related ON field)
* Feature: Added 'default_none' option to DB and Report editor, which allows you to default a report to show no results until a search / filter is done, exports clicked will continue to generate the full export before search and/or filtering
* Feature: EXPORTS_REPORTS_DISABLE_MENU constant added to disable the menu from being output completely (aside from the normal exports_reports_* capabilities you can define under user roles
* Fix: Added comments to SQL Query field in Report editor to explain advanced %%TAGS%% which can be used
* Fix: Reports list now ordered by Group then Weight (same for reordering)
* Fix: Forcing version to be int instead of text when getting version from DB
* Fix: Various minor bug fixes to plugin and WP Admin UI class

= 0.3.3 =
* Bug fix for SQL (another)

= 0.3.2 =
* Bug fix for SQL
* Moved About to bottom of Menu
* Added 'Reset' option in Settings

= 0.3.1 =
* Fixed menu access

= 0.3 =
* Upgraded Admin.class.php with Bug fixes and features (better UI and filtering)
* Export data fixes on CSV / TSV to support Excel
* Redefined Date Data Type into three (Date, Date + Time, Time)
* Filter by Date
* Ability to clear entire export directory (based on logged export files)
* Daily Export Cleanup via wp_cron

= 0.2 =
* First official release to the public as a plugin
