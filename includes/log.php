<?php
/**
 * Email log: one row per wp_mail() call in a table of its own, with retention.
 *
 * What is stored: recipients, subject, headers, status, which connection sent
 * it and the error. Message content only when the settings say so — by default
 * only for failed mail, so it can be resent, and only until retention removes it.
 * Password-reset links therefore never sit in the log of a site that works.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log table name.
 *
 * @return string
 */
function lynbro_smtp_table() {
	global $wpdb;
	return $wpdb->prefix . 'lynbro_smtp_log';
}

/**
 * Create or update the log table.
 *
 * @return void
 */
function lynbro_smtp_install_table() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = lynbro_smtp_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			via varchar(16) NOT NULL DEFAULT '',
			to_email text NOT NULL,
			cc text NOT NULL,
			bcc text NOT NULL,
			reply_to varchar(255) NOT NULL DEFAULT '',
			subject text NOT NULL,
			headers text NOT NULL,
			message longtext NULL,
			attachments text NOT NULL,
			error text NOT NULL,
			resent_of bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
		) {$charset};"
	);
	update_option( 'lynbro_smtp_db_version', LYNBRO_SMTP_DB_VERSION, false );
}

/**
 * Schedule the daily retention cleanup.
 *
 * @return void
 */
function lynbro_smtp_schedule_cleanup() {
	if ( ! wp_next_scheduled( 'lynbro_smtp_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lynbro_smtp_cleanup' );
	}
}

/**
 * Delete rows older than the retention period.
 *
 * @return int Rows deleted.
 */
function lynbro_smtp_cleanup() {
	global $wpdb;
	$opts   = lynbro_smtp_get_options();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $opts['retention_days'] * DAY_IN_SECONDS );
	$table  = lynbro_smtp_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, name from $wpdb->prefix.
	return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE created_at < %s", $table, $cutoff ) );
}
add_action( 'lynbro_smtp_cleanup', 'lynbro_smtp_cleanup' );

/**
 * Split a To/Cc/Bcc value (string or array) into clean addresses.
 *
 * @param mixed $value Raw recipients.
 * @return array<int,string>
 */
function lynbro_smtp_addresses( $value ) {
	if ( is_string( $value ) ) {
		$value = explode( ',', $value );
	}
	$out = array();
	foreach ( (array) $value as $item ) {
		$item = trim( (string) $item );
		if ( preg_match( '/<([^>]+)>/', $item, $m ) ) {
			$item = $m[1];
		}
		$item = sanitize_email( $item );
		if ( is_email( $item ) ) {
			$out[] = $item;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Pull Cc, Bcc and Reply-To out of wp_mail() headers.
 *
 * @param mixed $headers String or array of headers.
 * @return array{lines: array<int,string>, cc: array<int,string>, bcc: array<int,string>, reply_to: string}
 */
function lynbro_smtp_parse_headers( $headers ) {
	if ( is_string( $headers ) ) {
		$headers = preg_split( "/\r\n|\n|\r/", $headers );
	}
	$out = array(
		'lines'    => array(),
		'cc'       => array(),
		'bcc'      => array(),
		'reply_to' => '',
	);
	foreach ( (array) $headers as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line || false === strpos( $line, ':' ) ) {
			continue;
		}
		$out['lines'][] = $line;
		list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
		switch ( strtolower( $name ) ) {
			case 'cc':
				$out['cc'] = array_merge( $out['cc'], lynbro_smtp_addresses( $value ) );
				break;
			case 'bcc':
				$out['bcc'] = array_merge( $out['bcc'], lynbro_smtp_addresses( $value ) );
				break;
			case 'reply-to':
				$reply           = lynbro_smtp_addresses( $value );
				$out['reply_to'] = $reply ? $reply[0] : '';
				break;
		}
	}
	return $out;
}

/**
 * Insert a log row for a wp_mail() call.
 *
 * @param array<string,mixed> $atts   wp_mail() arguments.
 * @param string              $status Initial status.
 * @param int                 $resent Id of the row this resends, 0 if none.
 * @return int Row id (0 when logging is off or the insert failed).
 */
function lynbro_smtp_log_insert( $atts, $status = 'pending', $resent = 0 ) {
	global $wpdb;
	$opts = lynbro_smtp_get_options();
	if ( empty( $opts['log_enabled'] ) ) {
		return 0;
	}
	$h     = lynbro_smtp_parse_headers( $atts['headers'] ?? array() );
	$files = array();
	foreach ( (array) ( $atts['attachments'] ?? array() ) as $file ) {
		if ( is_string( $file ) && '' !== $file ) {
			$files[] = wp_basename( $file );
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- own table.
	$ok = $wpdb->insert(
		lynbro_smtp_table(),
		array(
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			'status'      => $status,
			'to_email'    => implode( ', ', lynbro_smtp_addresses( $atts['to'] ?? '' ) ),
			'cc'          => implode( ', ', array_unique( $h['cc'] ) ),
			'bcc'         => implode( ', ', array_unique( $h['bcc'] ) ),
			'reply_to'    => $h['reply_to'],
			'subject'     => mb_substr( (string) ( $atts['subject'] ?? '' ), 0, 1000 ),
			'headers'     => mb_substr( implode( "\n", $h['lines'] ), 0, 5000 ),
			'message'     => 'all' === $opts['keep_content'] ? (string) ( $atts['message'] ?? '' ) : null,
			'attachments' => implode( ', ', $files ),
			'error'       => '',
			'resent_of'   => (int) $resent,
		)
	);
	return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * Update a log row.
 *
 * @param int                 $id     Row id.
 * @param array<string,mixed> $fields Columns to set.
 * @return void
 */
function lynbro_smtp_log_update( $id, $fields ) {
	global $wpdb;
	if ( $id <= 0 ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	$wpdb->update( lynbro_smtp_table(), $fields, array( 'id' => (int) $id ) );
}

/**
 * One log row.
 *
 * @param int $id Row id.
 * @return array<string,mixed>|null
 */
function lynbro_smtp_log_get( $id ) {
	global $wpdb;
	$table = lynbro_smtp_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $table, (int) $id ), ARRAY_A );
	return $row ? $row : null;
}

/**
 * Query the log.
 *
 * @param array<string,mixed> $args status, search, page, per_page, since (UTC datetime).
 * @return array{rows: array<int,array<string,mixed>>, total: int}
 */
function lynbro_smtp_log_query( $args = array() ) {
	global $wpdb;
	$table    = lynbro_smtp_table();
	$status   = (string) ( $args['status'] ?? '' );
	$status   = in_array( $status, array( 'sent', 'failed', 'blocked', 'pending' ), true ) ? $status : '';
	$search   = trim( (string) ( $args['search'] ?? '' ) );
	$like     = '%' . $wpdb->esc_like( $search ) . '%';
	$since    = (string) ( $args['since'] ?? '' );
	$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 25 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

	// One fixed statement: an empty filter value switches its condition off.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE (%s = '' OR status = %s) AND (%s = '' OR to_email LIKE %s OR subject LIKE %s OR error LIKE %s) AND (%s = '' OR created_at >= %s)",
			$table, $status, $status, $search, $like, $like, $like, $since, $since
		)
	);
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, created_at, status, via, to_email, cc, bcc, reply_to, subject, attachments, error, resent_of, (message IS NOT NULL) AS has_message FROM %i WHERE (%s = '' OR status = %s) AND (%s = '' OR to_email LIKE %s OR subject LIKE %s OR error LIKE %s) AND (%s = '' OR created_at >= %s) ORDER BY id DESC LIMIT %d OFFSET %d",
			$table, $status, $status, $search, $like, $like, $like, $since, $since, $per_page, ( $page - 1 ) * $per_page
		),
		ARRAY_A
	);
	// phpcs:enable

	return array(
		'rows'  => is_array( $rows ) ? $rows : array(),
		'total' => $total,
	);
}

/**
 * Counts per status since a moment (UTC datetime).
 *
 * @param string $since UTC datetime.
 * @return array<string,int>
 */
function lynbro_smtp_log_counts( $since ) {
	global $wpdb;
	$table = lynbro_smtp_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM %i WHERE created_at >= %s GROUP BY status", $table, $since ), ARRAY_A );
	$out  = array(
		'sent'    => 0,
		'failed'  => 0,
		'blocked' => 0,
		'pending' => 0,
	);
	foreach ( (array) $rows as $row ) {
		$out[ (string) $row['status'] ] = (int) $row['n'];
	}
	return $out;
}

/**
 * Delete log rows: all, or the given ids.
 *
 * @param array<int,int>|null $ids Ids, or null for everything.
 * @return int Rows deleted.
 */
function lynbro_smtp_log_delete( $ids = null ) {
	global $wpdb;
	$table = lynbro_smtp_table();
	if ( null === $ids ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i", $table ) );
	}
	$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	if ( ! $ids ) {
		return 0;
	}
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table; one %d per id.
	return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", array_merge( array( $table ), $ids ) ) );
}

/**
 * Resend a logged email with its stored content.
 *
 * @param int $id Row id.
 * @return true|WP_Error
 */
function lynbro_smtp_resend( $id ) {
	$row = lynbro_smtp_log_get( $id );
	if ( ! $row ) {
		return new WP_Error( 'lynbro_smtp_missing', __( 'That log entry no longer exists.', 'lynbro-smtp-mailer' ) );
	}
	if ( null === $row['message'] ) {
		return new WP_Error( 'lynbro_smtp_no_content', __( 'The content of this email was not kept, so it cannot be resent. Change "Keep message content" in Settings to resend future emails.', 'lynbro-smtp-mailer' ) );
	}
	$headers = '' === (string) $row['headers'] ? array() : explode( "\n", (string) $row['headers'] );
	$GLOBALS['lynbro_smtp_resending'] = (int) $row['id'];
	$sent                             = wp_mail( (string) $row['to_email'], (string) $row['subject'], (string) $row['message'], $headers );
	unset( $GLOBALS['lynbro_smtp_resending'] );
	if ( ! $sent ) {
		return new WP_Error( 'lynbro_smtp_resend_failed', __( 'The email could not be sent again. The new attempt and its error are in the log.', 'lynbro-smtp-mailer' ) );
	}
	return true;
}

/**
 * Personal data exporter: log rows addressed to an email.
 *
 * @param string $email Email address.
 * @param int    $page  Page.
 * @return array<string,mixed>
 */
function lynbro_smtp_privacy_export( $email, $page = 1 ) {
	global $wpdb;
	$table  = lynbro_smtp_table();
	$like   = '%' . $wpdb->esc_like( (string) $email ) . '%';
	$offset = ( max( 1, (int) $page ) - 1 ) * 100;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, status, to_email, subject FROM %i WHERE to_email LIKE %s OR cc LIKE %s OR bcc LIKE %s ORDER BY id LIMIT 100 OFFSET %d", $table, $like, $like, $like, $offset ), ARRAY_A );
	$data = array();
	foreach ( (array) $rows as $row ) {
		$data[] = array(
			'group_id'    => 'lynbro-smtp',
			'group_label' => __( 'Email log', 'lynbro-smtp-mailer' ),
			'item_id'     => 'lynbro-smtp-' . $row['id'],
			'data'        => array(
				array(
					'name'  => __( 'Date (UTC)', 'lynbro-smtp-mailer' ),
					'value' => $row['created_at'],
				),
				array(
					'name'  => __( 'Recipients', 'lynbro-smtp-mailer' ),
					'value' => $row['to_email'],
				),
				array(
					'name'  => __( 'Subject', 'lynbro-smtp-mailer' ),
					'value' => $row['subject'],
				),
				array(
					'name'  => __( 'Status', 'lynbro-smtp-mailer' ),
					'value' => $row['status'],
				),
			),
		);
	}
	return array(
		'data' => $data,
		'done' => count( (array) $rows ) < 100,
	);
}

/**
 * Personal data eraser: delete log rows addressed to an email.
 *
 * @param string $email Email address.
 * @return array<string,mixed>
 */
function lynbro_smtp_privacy_erase( $email ) {
	global $wpdb;
	$table = lynbro_smtp_table();
	$like  = '%' . $wpdb->esc_like( (string) $email ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
	$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE to_email LIKE %s OR cc LIKE %s OR bcc LIKE %s", $table, $like, $like, $like ) );
	return array(
		'items_removed'  => $n > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}

/**
 * Register the exporter and eraser with Tools → Export/Erase Personal Data.
 *
 * @param array<string,array<string,mixed>> $list Registered items.
 * @return array<string,array<string,mixed>>
 */
function lynbro_smtp_register_exporter( $list ) {
	$list['lynbro-smtp'] = array(
		'exporter_friendly_name' => __( 'Lynbro SMTP email log', 'lynbro-smtp-mailer' ),
		'callback'               => 'lynbro_smtp_privacy_export',
	);
	return $list;
}
add_filter( 'wp_privacy_personal_data_exporters', 'lynbro_smtp_register_exporter' );

/**
 * Register the eraser.
 *
 * @param array<string,array<string,mixed>> $list Registered items.
 * @return array<string,array<string,mixed>>
 */
function lynbro_smtp_register_eraser( $list ) {
	$list['lynbro-smtp'] = array(
		'eraser_friendly_name' => __( 'Lynbro SMTP email log', 'lynbro-smtp-mailer' ),
		'callback'             => 'lynbro_smtp_privacy_erase',
	);
	return $list;
}
add_filter( 'wp_privacy_personal_data_erasers', 'lynbro_smtp_register_eraser' );
