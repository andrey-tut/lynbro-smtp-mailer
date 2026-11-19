<?php
/**
 * Uninstall Lynbro SMTP: settings, state, log table and the scheduled cleanup.
 *
 * @package LynbroSMTP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'lynbro_smtp_settings', 'lynbro_smtp_log', 'lynbro_smtp_state', 'lynbro_smtp_db_version', 'lynbro_smtp_installed_at', 'lynbro_smtp_settings_backup', 'lynbro_smtp_settings_restored' ) as $lynbro_smtp_option ) {
	delete_option( $lynbro_smtp_option );
}
delete_metadata( 'user', 0, 'lynbro_smtp_review_dismissed', '', true );
wp_clear_scheduled_hook( 'lynbro_smtp_cleanup' );

$lynbro_smtp_table = $wpdb->prefix . 'lynbro_smtp_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing our own table on uninstall.
$wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $lynbro_smtp_table ) );
