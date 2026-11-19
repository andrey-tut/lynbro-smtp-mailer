<?php
/**
 * WP-CLI: `wp lynbro-smtp test|log|resend|purge|status`.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage Lynbro SMTP from the command line.
 */
class Lynbro_SMTP_CLI {

	/**
	 * Send a test email and print the SMTP conversation.
	 *
	 * ## OPTIONS
	 *
	 * <to>
	 * : Recipient address.
	 *
	 * [--backup]
	 * : Test the backup connection instead of the main one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lynbro-smtp test you@example.com
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function test( $args, $assoc_args ) {
		$to = sanitize_email( (string) $args[0] );
		if ( ! is_email( $to ) ) {
			WP_CLI::error( 'Invalid recipient.' );
		}
		$res = lynbro_smtp_send_test( $to, isset( $assoc_args['backup'] ) ? 'backup' : 'primary' );
		foreach ( $res['transcript'] as $line ) {
			WP_CLI::log( $line );
		}
		if ( $res['sent'] ) {
			WP_CLI::success( 'Sent via ' . ( '' !== $res['via'] ? $res['via'] : 'wp_mail' ) . '.' );
			return;
		}
		foreach ( $res['hints'] as $hint ) {
			WP_CLI::warning( $hint );
		}
		WP_CLI::error( '' !== $res['error'] ? $res['error'] : 'Not sent.' );
	}

	/**
	 * List log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : sent, failed, blocked or pending.
	 *
	 * [--search=<text>]
	 * : Match recipient, subject or error.
	 *
	 * [--limit=<n>]
	 * : How many. Default 20.
	 *
	 * [--format=<format>]
	 * : table, csv, json. Default table.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function log( $args, $assoc_args ) {
		unset( $args );
		$res = lynbro_smtp_log_query(
			array(
				'status'   => $assoc_args['status'] ?? '',
				'search'   => $assoc_args['search'] ?? '',
				'per_page' => (int) ( $assoc_args['limit'] ?? 20 ),
			)
		);
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $res['rows'], array( 'id', 'created_at', 'status', 'via', 'to_email', 'subject', 'error' ) );
	}

	/**
	 * Resend a logged email.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Log entry id.
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @return void
	 */
	public function resend( $args ) {
		$res = lynbro_smtp_resend( absint( $args[0] ) );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::success( 'Resent.' );
	}

	/**
	 * Delete log entries older than the retention period, or all with --all.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Empty the whole log.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function purge( $args, $assoc_args ) {
		unset( $args );
		$n = isset( $assoc_args['all'] ) ? lynbro_smtp_log_delete() : lynbro_smtp_cleanup();
		WP_CLI::success( sprintf( '%d entries deleted.', $n ) );
	}

	/**
	 * Show the configuration (password never printed) and the last 7 days.
	 *
	 * @return void
	 */
	public function status() {
		$o = lynbro_smtp_get_options();
		$c = lynbro_smtp_log_counts( gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) );
		$rows = array(
			array(
				'key'   => 'host',
				'value' => '' !== $o['host'] ? $o['host'] . ':' . $o['port'] . ' (' . $o['encryption'] . ')' : '(not set — PHP mail)',
			),
			array(
				'key'   => 'password',
				'value' => lynbro_smtp_password_broken() ? 'unreadable — enter again' : ( '' !== lynbro_smtp_password() ? 'set' : 'empty' ),
			),
			array(
				'key'   => 'from',
				'value' => $o['from_email'],
			),
			array(
				'key'   => 'mode',
				'value' => $o['mode'],
			),
			array(
				'key'   => 'backup',
				'value' => $o['backup_enabled'] ? $o['backup_type'] : 'off',
			),
			array(
				'key'   => 'last 7 days',
				'value' => sprintf( 'sent %d, failed %d, blocked %d', $c['sent'], $c['failed'], $c['blocked'] ),
			),
		);
		WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
	}
}
WP_CLI::add_command( 'lynbro-smtp', 'Lynbro_SMTP_CLI' );
