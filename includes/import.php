<?php
/**
 * One-click import of the connection settings of other SMTP plugins.
 *
 * Only plain settings are read (host, port, encryption, username, sender).
 * Passwords are not: every one of those plugins encrypts them with its own
 * key, and reading another plugin's secrets is not ours to do. The owner
 * types the password once after the import.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings found in other SMTP plugins' options, keyed by plugin id.
 *
 * @return array<string,array<string,mixed>>
 */
function lynbro_smtp_import_sources() {
	$found = array();

	// WP Mail SMTP and Easy WP SMTP 2.x share one layout. Only the "Other SMTP" mailer is plain SMTP:
	// with an API mailer (Gmail, SendGrid API…) the smtp block may still hold a stale host.
	foreach ( array(
		'wp-mail-smtp' => array( 'wp_mail_smtp', 'WP Mail SMTP' ),
		'easy-wp-smtp' => array( 'easy_wp_smtp', 'Easy WP SMTP' ),
	) as $id => $src ) {
		$o = get_option( $src[0] );
		if ( ! is_array( $o ) ) {
			continue;
		}
		$mailer = (string) ( $o['mail']['mailer'] ?? 'smtp' );
		$sender = array(
			'from_email'       => (string) ( $o['mail']['from_email'] ?? '' ),
			'from_name'        => (string) ( $o['mail']['from_name'] ?? '' ),
			'force_from_email' => ! empty( $o['mail']['from_email_force'] ),
			'force_from_name'  => ! empty( $o['mail']['from_name_force'] ),
			'return_path'      => ! empty( $o['mail']['return_path'] ),
		);
		if ( 'mail' === $mailer && is_email( $sender['from_email'] ) ) {
			// Their default mailer: PHP mail() with a forced sender — our "server's own mail".
			$found[ $id ] = array_merge(
				array(
					'label'      => $src[1],
					'provider'   => 'php',
					'host'       => '',
					'port'       => 587,
					'encryption' => 'none',
					'username'   => '',
				),
				$sender
			);
			continue;
		}
		if ( 'smtp' !== $mailer || empty( $o['smtp']['host'] ) ) {
			continue;
		}
		$found[ $id ] = array_merge(
			array(
				'label'      => $src[1],
				'host'       => (string) $o['smtp']['host'],
				'port'       => (int) ( $o['smtp']['port'] ?? 587 ),
				'encryption' => (string) ( $o['smtp']['encryption'] ?? 'tls' ),
				'username'   => (string) ( $o['smtp']['user'] ?? '' ),
			),
			$sender
		);
	}

	$fluent = get_option( 'fluentmail-settings' );
	if ( is_array( $fluent ) && ! empty( $fluent['connections'] ) && is_array( $fluent['connections'] ) ) {
		foreach ( $fluent['connections'] as $conn ) {
			$settings = is_array( $conn ) ? ( $conn['provider_settings'] ?? array() ) : array();
			if ( 'smtp' === ( $settings['provider'] ?? '' ) && ! empty( $settings['host'] ) ) {
				$found['fluent-smtp'] = array(
					'label'      => 'FluentSMTP',
					'host'       => (string) $settings['host'],
					'port'       => (int) ( $settings['port'] ?? 587 ),
					'encryption' => (string) ( $settings['encryption'] ?? 'tls' ),
					'username'   => (string) ( $settings['username'] ?? '' ),
					'from_email' => (string) ( $settings['sender_email'] ?? '' ),
					'from_name'  => (string) ( $settings['sender_name'] ?? '' ),
				);
				break;
			}
		}
	}

	$easy = get_option( 'swpsmtp_options' ); // Easy WP SMTP 1.x, before its 2.0 migration.
	if ( empty( $found['easy-wp-smtp'] ) && is_array( $easy ) && ! empty( $easy['smtp_settings']['host'] ) ) {
		$found['easy-wp-smtp'] = array(
			'label'      => 'Easy WP SMTP',
			'host'       => (string) $easy['smtp_settings']['host'],
			'port'       => (int) ( $easy['smtp_settings']['port'] ?? 587 ),
			'encryption' => (string) ( $easy['smtp_settings']['type_encryption'] ?? 'tls' ),
			'username'   => (string) ( $easy['smtp_settings']['username'] ?? '' ),
			'from_email' => (string) ( $easy['from_email_field'] ?? '' ),
			'from_name'  => (string) ( $easy['from_name_field'] ?? '' ),
		);
	}

	$post = get_option( 'postman_options' );
	if ( is_array( $post ) && ! empty( $post['hostname'] ) && in_array( (string) ( $post['transport_type'] ?? 'smtp' ), array( 'smtp', '' ), true ) ) {
		$found['post-smtp'] = array(
			'label'      => 'Post SMTP',
			'host'       => (string) $post['hostname'],
			'port'       => (int) ( $post['port'] ?? 587 ),
			'encryption' => (string) ( $post['enc_type'] ?? 'tls' ),
			'username'   => (string) ( $post['basic_auth_username'] ?? '' ),
			'from_email' => (string) ( $post['sender_email'] ?? '' ),
			'from_name'  => (string) ( $post['sender_name'] ?? ( $post['from_name'] ?? '' ) ),
		);
	}

	foreach ( $found as $id => $src ) {
		$enc = strtolower( (string) $src['encryption'] );
		if ( in_array( $enc, array( 'starttls', 'tls' ), true ) ) {
			$enc = 'tls';
		} elseif ( in_array( $enc, array( 'ssl', 'smtps' ), true ) ) {
			$enc = 'ssl';
		} else {
			$enc = 'none';
		}
		$found[ $id ]['encryption'] = $enc;
	}
	return $found;
}

/**
 * Apply one import source over the current settings.
 *
 * @param string $id Source id from lynbro_smtp_import_sources().
 * @return bool
 */
function lynbro_smtp_import_apply( $id ) {
	$sources = lynbro_smtp_import_sources();
	if ( ! isset( $sources[ $id ] ) ) {
		return false;
	}
	$src  = $sources[ $id ];
	$opts = lynbro_smtp_stored_options();
	foreach ( array( 'host', 'port', 'encryption', 'username', 'from_email', 'from_name' ) as $key ) {
		if ( '' !== (string) $src[ $key ] ) {
			$opts[ $key ] = $src[ $key ];
		}
	}
	foreach ( array( 'force_from_email', 'force_from_name', 'return_path' ) as $key ) {
		if ( isset( $src[ $key ] ) ) {
			$opts[ $key ] = (bool) $src[ $key ];
		}
	}
	$opts['auth']     = '' !== (string) $src['username'];
	$opts['provider'] = isset( $src['provider'] ) ? (string) $src['provider'] : 'custom';
	update_option( LYNBRO_SMTP_OPTION, lynbro_smtp_sanitize_options( $opts ) );
	return true;
}
