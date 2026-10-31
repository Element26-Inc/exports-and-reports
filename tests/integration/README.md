# Integration run: report queries

`tests/integration/report-queries.php` runs report definitions of several shapes through
`Exports_Reports_Admin_UI::get_data( true )`, the query path the view and the exports share,
against a real database: a plain `SELECT`, a common table expression, a dynamic statement
(`EXECUTE IMMEDIATE`, MariaDB), a tab-indented definition with the `LIMIT %%LIMIT%%` placeholder,
a multi-statement definition prepared through the `wp_admin_ui_get_data_sql` filter, the count
query through `wp_admin_ui_get_data_sql_count`, a query the callback declines, the streaming
preconditions (plain columns only; a custom display column exports through the buffered path),
and the CSV and TSV exports: streamed and buffered byte for byte the same, every value quoted, the
byte order mark first, formula-like text neutralised unless `wp_admin_ui_export_escape_formulas`
says otherwise, numbers and line breaks handled. It creates and drops one table prefixed with the
site's table prefix; a failed case fails the command.

Locally, on any WordPress with the plugin active:

```sh
wp eval-file tests/integration/report-queries.php
```

In CI, `.github/workflows/report-queries.yml` installs WordPress against a MariaDB 11.4 service
with WP-CLI and runs the script on every pull request that touches PHP: on WordPress 6.0 with
PHP 7.2 (the plugin's floor) and with PHP 7.3, and on the current WordPress with PHP 8.3.
