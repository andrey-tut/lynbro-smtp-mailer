<?php
/**
 * Guard against the previous "Lynbro SMTP" plugin erasing our settings.
 *
 * Before it was renamed, this plugin lived in `wp-content/plugins/lynbro-smtp/`.
 * Those old copies (1.0.x and 1.1.0) store their settings in the same option,
 * `lynbro_smtp_settings`, and their uninstall.php deletes it. Deleting the old
 * plugin with the Delete link on the Plugins screen therefore wiped the
 * settings this plugin reads, and all site mail fell back to PHP mail().
 *
 * The guard:
 * - keeps a copy of the settings in `lynbro_smtp_settings_backup` (not autoloaded),
 *   refreshed on every save;
 * - puts the settings back the moment another plugin's uninstall deletes them,
 *   and on the next admin page load if they disappeared some other way while
 *   this plugin is active;
 * - while the old folder is still on disk, shows a warning under the old
 *   plugin's row on the Plugins screen and on this plugin's own screen.
 *
 * Our own uninstall.php runs with this plugin inactive, so nothing here stops
 * the owner from removing our data on purpose.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

define( 'LYNBRO_SMTP_BACKUP_OPTION', 'lynbro_smtp_settings_backup' );
define( 'LYNBRO_SMTP_RESTORED_OPTION', 'lynbro_smtp_settings_restored' );
define( 'LYNBRO_SMTP_LEGACY_PLUGIN', 'lynbro-smtp/lynbro-smtp.php' );

/**
 * Copy the current settings into the backup option.
 *
 * @return void
 */
function lynbro_smtp_backup_settings() {
	$current = get_option( LYNBRO_SMTP_OPTION, null );
	if ( ! is_array( $current ) || ! $current ) {
		return;
	}
	if ( get_option( LYNBRO_SMTP_BACKUP_OPTION, null ) === $current ) {
		return;
	}
	update_option( LYNBRO_SMTP_BACKUP_OPTION, $current, false );
}
add_action( 'add_option_' . LYNBRO_SMTP_OPTION, 'lynbro_smtp_backup_settings', 20, 0 );
add_action( 'update_option_' . LYNBRO_SMTP_OPTION, 'lynbro_smtp_backup_settings', 20, 0 );

/**
 * Put the settings back from the backup when they are gone.
 *
 * @param string $why Short reason kept in the plugin state ("uninstall:<plugin>" or "missing").
 * @return bool True when something was restored.
 */
function lynbro_smtp_restore_settings( $why ) {
	$backup = get_option( LYNBRO_SMTP_BACKUP_OPTION, null );
	if ( ! is_array( $backup ) || ! $backup ) {
		return false;
	}
	if ( false !== get_option( LYNBRO_SMTP_OPTION, false ) ) {
		return false;
	}
	// The backup is already sanitized: store it as it is, past the settings
	// sanitizer (which would re-read the now missing option for the passwords).
	$as_is = static function () use ( $backup ) {
		return $backup;
	};
	add_filter( 'sanitize_option_' . LYNBRO_SMTP_OPTION, $as_is, PHP_INT_MAX );
	add_option( LYNBRO_SMTP_OPTION, $backup );
	remove_filter( 'sanitize_option_' . LYNBRO_SMTP_OPTION, $as_is, PHP_INT_MAX );
	// Kept apart from lynbro_smtp_state: the old 1.1.0 uninstall deletes that one too.
	update_option(
		LYNBRO_SMTP_RESTORED_OPTION,
		array(
			'at'  => time(),
			'why' => substr( (string) $why, 0, 120 ),
		),
		false
	);
	return true;
}

/**
 * Another plugin's uninstall.php just deleted our settings: restore them at once,
 * so mail keeps going through SMTP even before anyone opens wp-admin again.
 *
 * @param string $option Deleted option name.
 * @return void
 */
function lynbro_smtp_on_deleted_option( $option ) {
	if ( LYNBRO_SMTP_OPTION !== $option || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		return;
	}
	$uninstalling = (string) WP_UNINSTALL_PLUGIN;
	if ( plugin_basename( LYNBRO_SMTP_FILE ) === $uninstalling ) {
		return;
	}
	lynbro_smtp_restore_settings( 'uninstall:' . $uninstalling );
}
add_action( 'deleted_option', 'lynbro_smtp_on_deleted_option' );

/**
 * On every admin load: seed the backup, or restore the settings if they vanished
 * while this plugin was active.
 *
 * @return void
 */
function lynbro_smtp_guard_admin_init() {
	if ( false === get_option( LYNBRO_SMTP_OPTION, false ) ) {
		lynbro_smtp_restore_settings( 'missing' );
		return;
	}
	if ( false === get_option( LYNBRO_SMTP_BACKUP_OPTION, false ) ) {
		lynbro_smtp_backup_settings();
	}
}
add_action( 'admin_init', 'lynbro_smtp_guard_admin_init', 5 );

/**
 * Whether the previous "Lynbro SMTP" plugin folder is still on disk.
 *
 * @return bool
 */
function lynbro_smtp_legacy_installed() {
	return file_exists( trailingslashit( WP_PLUGIN_DIR ) . LYNBRO_SMTP_LEGACY_PLUGIN );
}

/**
 * The warning text about the old plugin.
 *
 * @return string
 */
function lynbro_smtp_legacy_warning_text() {
	return sprintf(
		/* translators: %s: folder name, wp-content/plugins/lynbro-smtp */
		__( 'This is the previous version of Lynbro SMTP. Lynbro SMTP Mailer has replaced it and uses the same settings. Remove the folder %s with your host\'s file manager, FTP or SSH — do not use Delete here: its uninstall erases the shared SMTP settings. Lynbro SMTP Mailer keeps a backup and puts them back, but the old plugin also empties the email log.', 'lynbro-smtp-mailer' ),
		'wp-content/plugins/lynbro-smtp'
	);
}

/**
 * Warning row under the old plugin on the Plugins screen.
 *
 * @param string $file        Plugin basename of the row.
 * @param array  $plugin_data Plugin header data (unused).
 * @return void
 */
function lynbro_smtp_legacy_plugin_row( $file, $plugin_data = array() ) {
	unset( $plugin_data );
	if ( LYNBRO_SMTP_LEGACY_PLUGIN !== $file || ! current_user_can( 'delete_plugins' ) ) {
		return;
	}
	$columns = 3;
	if ( function_exists( 'get_column_headers' ) ) {
		$screen = get_current_screen();
		if ( $screen ) {
			$columns = max( 3, count( get_column_headers( $screen ) ) );
		}
	}
	printf(
		'<tr class="plugin-update-tr lynbro-smtp-legacy-row"><td colspan="%1$d" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>%2$s</p></div></td></tr>',
		(int) $columns,
		esc_html( lynbro_smtp_legacy_warning_text() )
	);
}
add_action( 'after_plugin_row_' . LYNBRO_SMTP_LEGACY_PLUGIN, 'lynbro_smtp_legacy_plugin_row', 10, 2 );

/**
 * Notices on this plugin's own screen: old folder still present, settings restored.
 *
 * @return void
 */
function lynbro_smtp_render_guard_notices() {
	if ( lynbro_smtp_legacy_installed() ) {
		printf( '<div class="notice notice-warning inline lynbro-smtp-notice"><p>%s</p></div>', esc_html( lynbro_smtp_legacy_warning_text() ) );
	}
	$restored = get_option( LYNBRO_SMTP_RESTORED_OPTION, array() );
	if ( is_array( $restored ) && ! empty( $restored['at'] ) && time() - (int) $restored['at'] < 14 * DAY_IN_SECONDS ) {
		printf(
			'<div class="notice notice-info inline lynbro-smtp-notice"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: date and time */
					__( 'Your SMTP settings were deleted by another plugin on %s and have been restored from the backup. Nothing for you to do; send a test email if you want to be sure.', 'lynbro-smtp-mailer' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $restored['at'] )
				)
			)
		);
	}
}
