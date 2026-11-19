<?php
/**
 * Plugin Name:       Lynbro SMTP Mailer
 * Plugin URI:        https://plugins.lynbro.dk/lynbro-smtp-mailer
 * Description:       Sends all WordPress email through your own SMTP server, with a searchable email log, resend, a backup connection, failure alerts and connection diagnostics. No upsells.
 * Version:           1.1.6
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            LynBro ApS
 * Author URI:        https://lynbro.dk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lynbro-smtp-mailer
 * Domain Path:       /languages
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

define( 'LYNBRO_SMTP_VERSION', '1.1.6' );
define( 'LYNBRO_SMTP_FILE', __FILE__ );
define( 'LYNBRO_SMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'LYNBRO_SMTP_URL', plugin_dir_url( __FILE__ ) );
define( 'LYNBRO_SMTP_OPTION', 'lynbro_smtp_settings' );
define( 'LYNBRO_SMTP_LOG_OPTION', 'lynbro_smtp_log' ); // 1.0.x text log, migrated and removed on upgrade.
define( 'LYNBRO_SMTP_STATE_OPTION', 'lynbro_smtp_state' );
define( 'LYNBRO_SMTP_DB_VERSION', 1 );

require_once LYNBRO_SMTP_DIR . 'includes/lynbro-core/lynbro-shell.php';
require_once LYNBRO_SMTP_DIR . 'includes/options.php';
require_once LYNBRO_SMTP_DIR . 'includes/providers.php';
require_once LYNBRO_SMTP_DIR . 'includes/log.php';
require_once LYNBRO_SMTP_DIR . 'includes/class-mailer.php';
require_once LYNBRO_SMTP_DIR . 'includes/alerts.php';
require_once LYNBRO_SMTP_DIR . 'includes/diagnostics.php';
require_once LYNBRO_SMTP_DIR . 'includes/import.php';
require_once LYNBRO_SMTP_DIR . 'includes/onboarding.php';
require_once LYNBRO_SMTP_DIR . 'includes/legacy-guard.php';

if ( is_admin() ) {
	require_once LYNBRO_SMTP_DIR . 'admin/settings.php';
	require_once LYNBRO_SMTP_DIR . 'admin/log-tab.php';
	require_once LYNBRO_SMTP_DIR . 'admin/diagnostics-tab.php';
	require_once LYNBRO_SMTP_DIR . 'admin/about-tab.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once LYNBRO_SMTP_DIR . 'includes/cli.php';
}

/**
 * Activation: create the log table, schedule the cleanup, remember the install time.
 *
 * @return void
 */
function lynbro_smtp_activate() {
	lynbro_smtp_install_table();
	lynbro_smtp_schedule_cleanup();
	lynbro_smtp_record_install_time();
	lynbro_smtp_backup_settings();
}
register_activation_hook( __FILE__, 'lynbro_smtp_activate' );

/**
 * Deactivation: stop the scheduled cleanup. Data stays until uninstall.
 *
 * @return void
 */
function lynbro_smtp_deactivate() {
	wp_clear_scheduled_hook( 'lynbro_smtp_cleanup' );
}
register_deactivation_hook( __FILE__, 'lynbro_smtp_deactivate' );

/**
 * Upgrades happen without activation, so check the schema on every admin load.
 *
 * @return void
 */
function lynbro_smtp_maybe_upgrade() {
	if ( (int) get_option( 'lynbro_smtp_db_version', 0 ) >= LYNBRO_SMTP_DB_VERSION ) {
		return;
	}
	lynbro_smtp_install_table();
	lynbro_smtp_schedule_cleanup();
	lynbro_smtp_record_install_time();
	// The 1.0.x text log cannot be mapped onto columns reliably; it is dropped.
	delete_option( LYNBRO_SMTP_LOG_OPTION );
}
// init, not admin_init: on a network the plugin may be network-activated, which
// runs no activation on subsites, and a subsite's tables would only appear once
// someone opened its admin — every write before that failed. One autoloaded
// option read per request; the install runs once per site.
add_action( 'init', 'lynbro_smtp_maybe_upgrade' );

/**
 * Boot the mailer as early as plugins are ready, so mail sent by other plugins
 * on `plugins_loaded` or later goes through SMTP too.
 *
 * @return void
 */
function lynbro_smtp_boot() {
	$GLOBALS['lynbro_smtp_mailer'] = new Lynbro_SMTP_Mailer();
}
add_action( 'plugins_loaded', 'lynbro_smtp_boot', 5 );

/**
 * "Settings" link on the Plugins screen.
 *
 * @param array<int,string> $links Existing links.
 * @return array<int,string>
 */
function lynbro_smtp_action_links( $links ) {
	$url = admin_url( 'admin.php?page=lynbro-smtp' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'lynbro-smtp-mailer' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'lynbro_smtp_action_links' );
