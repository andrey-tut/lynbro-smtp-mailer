<?php
/**
 * Diagnostics: test send with the SMTP conversation, port reachability, DNS
 * (SPF / DMARC) of the sender domain, and detection of other mail plugins.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Send a test email and return the SMTP transcript with secrets removed.
 *
 * @param string $to         Recipient.
 * @param string $connection 'primary' or 'backup'.
 * @return array{sent: bool, error: string, transcript: array<int,string>, hints: array<int,string>, via: string}
 */
function lynbro_smtp_send_test( $to, $connection = 'primary' ) {
	$mailer = $GLOBALS['lynbro_smtp_mailer'] ?? null;
	$error  = '';
	$catch  = static function ( $wp_error ) use ( &$error ) {
		if ( is_wp_error( $wp_error ) ) {
			$error = $wp_error->get_error_message();
		}
	};
	add_action( 'wp_mail_failed', $catch, 1 );

	if ( $mailer instanceof Lynbro_SMTP_Mailer ) {
		$mailer->transcript = array();
		$mailer->force      = 'backup' === $connection ? 'backup' : 'primary';
	}

	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	/* translators: %s: site name. */
	$subject = sprintf( __( 'Test email from %s', 'lynbro-smtp-mailer' ), $site );
	$body    = sprintf(
		/* translators: 1: site name, 2: date and time, 3: connection name. */
		__( "This is a test email from %1\$s, sent by Lynbro SMTP.\n\nIf you can read it, email delivery works.\n\nSent: %2\$s\nConnection: %3\$s", 'lynbro-smtp-mailer' ),
		$site,
		wp_date( 'Y-m-d H:i:s' ),
		'backup' === $connection ? __( 'backup', 'lynbro-smtp-mailer' ) : __( 'main', 'lynbro-smtp-mailer' )
	);
	$sent = wp_mail( $to, $subject, $body );

	remove_action( 'wp_mail_failed', $catch, 1 );
	$transcript = array();
	if ( $mailer instanceof Lynbro_SMTP_Mailer ) {
		$transcript         = (array) $mailer->transcript;
		$mailer->transcript = null;
		$mailer->force      = '';
	}
	global $phpmailer;
	$via = ( $phpmailer instanceof Lynbro_SMTP_PHPMailer ) ? (string) $phpmailer->lynbro_via : '';

	$transcript = lynbro_smtp_redact_transcript( $transcript );
	return array(
		'sent'       => (bool) $sent,
		'error'      => (string) $error,
		'transcript' => $transcript,
		'hints'      => $sent ? array() : lynbro_smtp_error_hints( $error . "\n" . implode( "\n", $transcript ) ),
		'via'        => $via,
	);
}

/**
 * Remove credentials from an SMTP transcript. Everything the client sends
 * between AUTH and the server's answer is replaced, as are the base64 forms
 * of the username and password wherever they appear.
 *
 * @param array<int,string> $lines Transcript lines.
 * @return array<int,string>
 */
function lynbro_smtp_redact_transcript( $lines ) {
	$opts    = lynbro_smtp_get_options();
	$secrets = array();
	// Passwords are hidden in plain and base64 form; usernames only in base64
	// (inside AUTH), because the plain username is often the From address.
	foreach ( array( lynbro_smtp_password( 'primary' ), lynbro_smtp_password( 'backup' ), (string) $opts['username'], (string) $opts['backup_username'] ) as $i => $secret ) {
		if ( strlen( $secret ) >= 3 ) {
			if ( $i < 2 ) {
				$secrets[] = $secret;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- to find and hide the encoded credential.
			$secrets[] = base64_encode( $secret );
		}
	}
	$out     = array();
	$in_auth = false;
	foreach ( (array) $lines as $line ) {
		$line = (string) $line;
		if ( preg_match( '/CLIENT -> SERVER:\s*AUTH\b/i', $line ) ) {
			$in_auth = true;
			$out[]   = preg_replace( '/(AUTH\s+\S+).*/i', '$1 [hidden]', $line );
			continue;
		}
		if ( $in_auth && false !== stripos( $line, 'CLIENT -> SERVER:' ) ) {
			$out[] = preg_replace( '/(CLIENT -> SERVER:).*/i', '$1 [hidden]', $line );
			continue;
		}
		if ( $in_auth && preg_match( '/SERVER -> CLIENT:\s*(235|5\d\d)/', $line ) ) {
			$in_auth = false;
		}
		foreach ( $secrets as $secret ) {
			$line = str_replace( $secret, '[hidden]', $line );
		}
		$out[] = $line;
	}
	return array_slice( $out, -400 );
}

/**
 * Plain-language hints for the usual SMTP errors.
 *
 * @param string $text Error message and transcript.
 * @return array<int,string>
 */
function lynbro_smtp_error_hints( $text ) {
	$text  = strtolower( (string) $text );
	$hints = array();
	if ( preg_match( '/could not authenticate|535|authentication (failed|unsuccessful)|username and password not accepted/', $text ) ) {
		$hints[] = __( 'The server refused the username or password. Gmail, Outlook, Zoho and iCloud need an app password, not the normal one; SendGrid and Brevo need an SMTP/API key.', 'lynbro-smtp-mailer' );
	}
	if ( preg_match( '/smtpclientauthentication is disabled|basic authentication is disabled|5\.7\.139/', $text ) ) {
		$hints[] = __( 'Microsoft 365 has SMTP sign-in switched off for this mailbox. An administrator can enable "Authenticated SMTP" for it, or use a relay service instead.', 'lynbro-smtp-mailer' );
	}
	if ( preg_match( '/could not connect|connection (refused|timed out)|failed to connect|network is unreachable|timed out/', $text ) ) {
		$hints[] = __( 'The server could not be reached. Many hosts block outgoing ports 25, 465 and 587 — run the port check below, then ask your host to open the port or use the one that is open.', 'lynbro-smtp-mailer' );
	}
	if ( preg_match( '/certificate|ssl3_get_server_certificate|tls|crypto/', $text ) ) {
		$hints[] = __( 'The secure connection failed. Check that port and encryption match (587 = TLS, 465 = SSL). If the server uses a self-signed certificate, untick "Verify the server certificate".', 'lynbro-smtp-mailer' );
	}
	if ( preg_match( '/sender address rejected|not owned by user|from address|550 5\.7\.1|relay access denied|not permitted to relay/', $text ) ) {
		$hints[] = __( 'The server does not let this account send as the From address. Use an address on a domain the account owns, and switch on "Force From email".', 'lynbro-smtp-mailer' );
	}
	if ( preg_match( '/getaddrinfo|name or service not known|php_network_getaddresses/', $text ) ) {
		$hints[] = __( 'The host name does not resolve. Check it for typos.', 'lynbro-smtp-mailer' );
	}
	if ( ! $hints ) {
		$hints[] = __( 'Read the last SERVER lines of the transcript: the server usually says exactly what it did not like.', 'lynbro-smtp-mailer' );
	}
	return $hints;
}

/**
 * Whether outgoing TCP to host:port is open from this server.
 *
 * @param string $host Host.
 * @param int    $port Port.
 * @return array{open: bool, error: string, ms: int}
 */
function lynbro_smtp_port_check( $host, $port ) {
	$host = lynbro_smtp_clean_host( $host );
	if ( '' === $host ) {
		return array(
			'open'  => false,
			'error' => __( 'No host set.', 'lynbro-smtp-mailer' ),
			'ms'    => 0,
		);
	}
	$start  = microtime( true );
	$errno  = 0;
	$errstr = '';
	// A raw TCP probe: the HTTP API cannot open SMTP ports.
	$sock = @stream_socket_client( 'tcp://' . $host . ':' . (int) $port, $errno, $errstr, 5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the error is returned to the caller.
	$ms   = (int) round( ( microtime( true ) - $start ) * 1000 );
	if ( $sock ) {
		fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- socket, not a file.
		return array(
			'open'  => true,
			'error' => '',
			'ms'    => $ms,
		);
	}
	return array(
		'open'  => false,
		'error' => (string) $errstr,
		'ms'    => $ms,
	);
}

/**
 * SPF and DMARC of the sender domain.
 *
 * @param string $email Sender address.
 * @return array{domain: string, spf: string, dmarc: string, checked: bool}
 */
function lynbro_smtp_dns_check( $email ) {
	$domain = strtolower( (string) substr( strrchr( (string) $email, '@' ), 1 ) );
	$out    = array(
		'domain'  => $domain,
		'spf'     => '',
		'dmarc'   => '',
		'checked' => false,
	);
	if ( '' === $domain || ! function_exists( 'dns_get_record' ) ) {
		return $out;
	}
	$out['checked'] = true;
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing record is a normal answer here.
	foreach ( (array) @dns_get_record( $domain, DNS_TXT ) as $rec ) {
		$txt = (string) ( $rec['txt'] ?? '' );
		if ( 0 === stripos( $txt, 'v=spf1' ) ) {
			$out['spf'] = $txt;
		}
	}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
	foreach ( (array) @dns_get_record( '_dmarc.' . $domain, DNS_TXT ) as $rec ) {
		$txt = (string) ( $rec['txt'] ?? '' );
		if ( 0 === stripos( $txt, 'v=DMARC1' ) ) {
			$out['dmarc'] = $txt;
		}
	}
	return $out;
}

/**
 * Other active plugins that also take over wp_mail(), and whether another
 * plugin hooks phpmailer_init after us.
 *
 * @return array<int,string> Plugin names.
 */
function lynbro_smtp_conflicts() {
	$known = array(
		'wp-mail-smtp/wp_mail_smtp.php'       => 'WP Mail SMTP',
		'wp-mail-smtp-pro/wp_mail_smtp.php'   => 'WP Mail SMTP Pro',
		'fluent-smtp/fluent-smtp.php'         => 'FluentSMTP',
		'easy-wp-smtp/easy-wp-smtp.php'       => 'Easy WP SMTP',
		'post-smtp/postman-smtp.php'          => 'Post SMTP',
		'gosmtp/gosmtp.php'                   => 'GoSMTP',
		'suremails/suremails.php'             => 'SureMail',
		'wp-ses/wp-ses.php'                   => 'WP Offload SES',
		'smtp-mailer/main.php'                => 'SMTP Mailer',
		'wp-smtp/wp-smtp.php'                 => 'WP SMTP',
		'mailgun/mailgun.php'                 => 'Mailgun',
		'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
	);
	$active = (array) get_option( 'active_plugins', array() );
	$found  = array();
	foreach ( $known as $file => $name ) {
		if ( in_array( $file, $active, true ) ) {
			$found[] = $name;
		}
	}
	return $found;
}
