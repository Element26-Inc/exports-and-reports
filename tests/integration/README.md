# Integration run: report queries and exports

`tests/integration/report-queries.php` is a WP-CLI script that drives `Exports_Reports_Admin_UI`
the way the report page, the exports and the REST routes do, against a real database. It seeds a
small table prefixed with the site's table prefix, runs the cases below (one line each; the exit
code is non-zero when a case fails) and removes the table and the files it wrote.

- Tables: created with the site's character set and collation; a three-byte utf8 table is
  converted on upgrade.
- Report queries through `get_data( true )`: a plain `SELECT`, a common table expression, a
  dynamic statement (`EXECUTE IMMEDIATE`, MariaDB) and a tab-indented definition, each with the
  `LIMIT %%LIMIT%%` placeholder; a multi-statement definition prepared through the
  `wp_admin_ui_get_data_sql` filter, the count query through `wp_admin_ui_get_data_sql_count`, and
  a query the callback declines (nothing runs, no export is written).
- Delimited exports: CSV and TSV streamed from an unbuffered result and buffered byte for byte the
  same, every value quoted, the byte order mark first, formula-like text neutralised unless
  `wp_admin_ui_export_escape_formulas` says otherwise; streaming only with plain columns, a custom
  display column through the buffered path.
- Writers: XLSX, XML and JSON exports checked for their labels and rows; a two-page PDF with each
  engine the PHP version allows (tc-lib-pdf on 8.2 and later, TCPDF below), the producer string
  telling them apart, and the default engine following the PHP version.
- Export files: ASCII file names for a report named outside ASCII; the export directory carries
  `.htaccess` and `index.php`; downloads go through the plugin after the link nonce, or the log
  row and the report access rules, and refuse anything else; `.txt` exports are served; clearing
  the directory removes export files only; the daily cleanup has a handler, deactivation clears
  it and the next admin request puts it back.
- REST API: the report refused without the token or a user; full and paged data with the token
  parameter and the bearer token; the report access rules for users; a disabled report hidden;
  export and download through the routes; `api.php` (deprecated) answering through them with the
  old response shape.
- Access: administrators hold `exports_reports_full_access` through the capability filter, not
  user meta; display functions limited to plugin functions unless a filter allows a PHP function;
  an array filter value filtering on each value.
- Request handling: the report field editor rendering the saved fields and saving the posted rows
  in order, unslashed and sanitised; `save()`, `reorder()` and the settings form carrying and
  verifying their nonces; an empty token saved from the settings page turning token access off;
  request values unslashed and sanitised on the way in.
- Uninstall: tables, options, the cleanup event and export files removed.

Locally, on any WordPress with the plugin active:

```sh
wp eval-file tests/integration/report-queries.php
```

In CI, `.github/workflows/report-queries.yml` installs WordPress against a MariaDB 11.4 service
with WP-CLI and runs the script on every pull request that touches PHP: on WordPress 6.0 with
PHP 7.2 (the plugin's floor) and with PHP 7.3, and on the current WordPress with PHP 8.3.
