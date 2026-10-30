# Integration run: report queries

`tests/integration/report-queries.php` runs report definitions of several shapes through
`Exports_Reports_Admin_UI::get_data( true )`, the query path the view and the exports share,
against a real database: a plain `SELECT`, a common table expression, a dynamic statement
(`EXECUTE IMMEDIATE`, MariaDB), a tab-indented definition with the `LIMIT %%LIMIT%%` placeholder,
a multi-statement definition prepared through the `wp_admin_ui_get_data_sql` filter, and the CSV
export written both streamed and buffered. It creates and drops one table prefixed with the
site's table prefix.

Locally, on any WordPress with the plugin active:

```sh
wp eval-file tests/integration/report-queries.php
```

In CI, `.github/workflows/report-queries.yml` installs WordPress against a MariaDB 11.4 service
with WP-CLI and runs the script on every pull request that touches PHP.
