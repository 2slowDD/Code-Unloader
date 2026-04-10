<?php
/**
 * Code Unloader — Uninstall
 *
 * WordPress calls this file when the admin clicks Delete Plugin.
 * We always clean up ALL plugin data: tables, options, transients.
 * This ensures the site is returned to a clean state with no orphaned
 * dequeue rules still silently affecting page loads.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop all plugin tables — this removes every rule, group, and log entry.
// Assets that were being unloaded will now load normally again.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cu_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cu_rules" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cu_groups" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

// Remove all plugin options
delete_option( 'cdunloader_kill_switch' );
delete_option( 'cdunloader_db_version' );
delete_option( 'cdunloader_uninstall_delete_data' );

// Remove transients
delete_transient( 'code_unloader_source_map' );

// Remove any user meta (dismiss preferences stored per-user)
delete_metadata( 'user', 0, 'cdunloader_dismissed_warning', '', true );
