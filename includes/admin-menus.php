<?php
/**
 * The admin menus, and the admin pages' scripts and styles.
 *
 * @package Exports_And_Reports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin runs the administrator's report queries and keeps its own tables; there is nothing to cache.

/**
 * The Reports Admin menu: Manage Groups, Manage Reports and Settings, for users with the settings
 * capability or full access (super admins count as full access).
 */
function exports_reports_admin_menu() {
	if ( defined( 'EXPORTS_REPORTS_DISABLE_MENU' ) ) {
		return;
	}

	$has_full_access = is_super_admin() || exports_reports_current_user_can_any( 'exports_reports_full_access' );

	$min_cap = exports_reports_current_user_can_which( exports_reports_capabilities() );

	if ( empty( $min_cap ) ) {
		$min_cap = 'exports_reports_full_access';
	}

	if ( $has_full_access || exports_reports_current_user_can_any( $min_cap ) || exports_reports_current_user_can_any( 'exports_reports_settings' ) ) {
		add_menu_page( __( 'Reports Admin', 'exports-and-reports' ), __( 'Reports Admin', 'exports-and-reports' ), $has_full_access ? 'read' : $min_cap, 'exports-reports-admin', '', EXPORTS_REPORTS_URL . 'assets/images/16.png' );
		add_submenu_page( 'exports-reports-admin', __( 'Manage Groups', 'exports-and-reports' ), __( 'Manage Groups', 'exports-and-reports' ), $has_full_access ? 'read' : 'exports_reports_settings', 'exports-reports-admin', 'exports_reports_groups' );
		add_submenu_page( 'exports-reports-admin', __( 'Manage Reports', 'exports-and-reports' ), __( 'Manage Reports', 'exports-and-reports' ), $has_full_access ? 'read' : 'exports_reports_settings', 'exports-reports-admin-reports', 'exports_reports_reports' );
		add_submenu_page( 'exports-reports-admin', __( 'Settings', 'exports-and-reports' ), __( 'Settings', 'exports-and-reports' ), $has_full_access ? 'read' : 'exports_reports_settings', 'exports-reports-admin-settings', 'exports_reports_settings' );
	}
}

/**
 * The Reports menu: the enabled groups the current user may view, by role access or full access.
 */
function exports_reports_menu() {
	/** @var wpdb $wpdb */ global $wpdb;

	if ( defined( 'EXPORTS_REPORTS_DISABLE_MENU' ) ) {
		return;
	}

	$has_full_access = is_super_admin() || exports_reports_current_user_can_any( 'exports_reports_full_access' );

	$init = false;

	$groups = $wpdb->get_results( "SELECT `id`, `role_access`, `name` FROM {$wpdb->exports_reports_groups} WHERE `disabled` = 0 ORDER BY `weight`, `name`" );

	if ( ! empty( $groups ) ) {
		foreach ( $groups as $group ) {
			$reports = $wpdb->get_results( $wpdb->prepare( "SELECT `id`, `role_access` FROM {$wpdb->exports_reports_reports} WHERE `disabled` = 0 AND `group` = %d ORDER BY `weight`, `name`", $group->id ) );

			if ( ! empty( $reports ) ) {
				foreach ( $reports as $report ) {
					if ( $has_full_access || exports_reports_current_user_can_any( 'exports_reports_view' ) || exports_reports_current_user_can_any( 'exports_reports_view_group_' . $group->id ) || exports_reports_current_user_can_any( 'exports_reports_view_report_' . $report->id ) ) {
						$menu_page = 'exports-reports-group-' . $group->id;

						if ( ! $init ) {
							add_menu_page( __( 'Reports', 'exports-and-reports' ), __( 'Reports', 'exports-and-reports' ), 'read', $menu_page, '', EXPORTS_REPORTS_URL . 'assets/images/16.png' );

							$init = $menu_page;
						}

						add_submenu_page( $init, $group->name, $group->name, 'read', $menu_page, 'exports_reports_view' );

						break;
					}

					$roles = array_filter( array_unique( array_merge( explode( ',', $group->role_access ), explode( ',', $report->role_access ) ) ) );

					if ( empty( $roles ) ) {
						continue;
					}

					foreach ( $roles as $role ) {
						if ( exports_reports_has_role( $role ) ) {
							$menu_page = 'exports-reports-group-' . $group->id;

							if ( ! $init ) {
								add_menu_page( __( 'Reports', 'exports-and-reports' ), __( 'Reports', 'exports-and-reports' ), 'read', $menu_page, '', EXPORTS_REPORTS_URL . 'assets/images/16.png' );

								$init = $menu_page;
							}

							add_submenu_page( $init, $group->name, $group->name, 'read', $menu_page, 'exports_reports_view' );

							break 2;
						}
					}
				}
			}
		}
	}
}

/**
 * Registers the admin script and style, and enqueues the report form's editor assets on the Manage Reports page.
 *
 * @param string $hook_suffix The admin page, as admin_enqueue_scripts passes it.
 */
function exports_reports_assets( $hook_suffix ) {
	wp_register_script( 'exports-reports-admin', EXPORTS_REPORTS_URL . 'assets/js/admin.js', array( 'jquery' ), EXPORTS_REPORTS_VERSION, true );
	wp_register_style( 'exports-reports-admin', EXPORTS_REPORTS_URL . 'assets/css/admin.css', array(), EXPORTS_REPORTS_VERSION );

	if ( get_plugin_page_hookname( 'exports-reports-admin-reports', 'exports-reports-admin' ) === $hook_suffix ) {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_script( 'exports-reports-report-fields', EXPORTS_REPORTS_URL . 'assets/js/report-fields.js', array( 'jquery', 'jquery-ui-sortable' ), EXPORTS_REPORTS_VERSION, true );
		exports_reports_sql_editor();
	}
}

/**
 * CodeMirror with SQL highlighting on the report form's query fields.
 *
 * Nothing is enqueued when the user has turned syntax highlighting off in their profile.
 */
function exports_reports_sql_editor() {
	$settings = wp_enqueue_code_editor( array( 'type' => 'text/x-sql' ) );

	if ( false === $settings ) {
		return;
	}

	wp_add_inline_script(
		'code-editor',
		sprintf(
			'jQuery( function() { [ "admin_ui_sql_query", "admin_ui_sql_query_count" ].forEach( function( id ) { var field = document.getElementById( id ); if ( field ) { wp.codeEditor.initialize( field, %s ); } } ); } );',
			wp_json_encode( $settings )
		)
	);
}
