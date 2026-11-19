<?php
/**
 * Settings: defaults, wp-config overrides, sanitizing and password encryption.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default settings. Everything works on these except the connection itself.
 *
 * @return array<string,mixed>
 */
function lynbro_smtp_default_options() {
	return array(
		'provider'         => 'custom',
		'host'             => '',
		'port'             => 587,
		'encryption'       => 'tls',
		'auth'             => true,
		'username'         => '',
		'password'         => '',
		'verify_peer'      => true,
		'from_email'       => '',
		'from_name'        => '',
		'force_from_email' => true,
		'force_from_name'  => false,
		'return_path'      => true,
		'reply_to'         => '',
		'backup_enabled'   => false,
		'backup_type'      => 'smtp',
		'backup_host'      => '',
		'backup_port'      => 587,
		'backup_enc'       => 'tls',
		'backup_username'  => '',
		'backup_password'  => '',
		'mode'             => 'send',
		'redirect_to'      => '',
		'log_enabled'      => true,
		'keep_content'     => 'failed',
		'retention_days'   => 30,
		'alert_email'      => '',
		'alert_webhook'    => '',
	);
}

/**
 * Map of setting key => wp-config.php constant that overrides it.
 *
 * Keeping credentials in wp-config.php keeps them out of the database, out of
 * database backups and out of the settings export.
 *
 * @return array<string,string>
 */
function lynbro_smtp_constant_map() {
	return array(
		'host'       => 'LYNBRO_SMTP_HOST',
		'port'       => 'LYNBRO_SMTP_PORT',
		'encryption' => 'LYNBRO_SMTP_ENCRYPTION',
		'username'   => 'LYNBRO_SMTP_USER',
		'password'   => 'LYNBRO_SMTP_PASS',
		'from_email' => 'LYNBRO_SMTP_FROM_EMAIL',
		'from_name'  => 'LYNBRO_SMTP_FROM_NAME',
	);
}

/**
 * Whether a setting is fixed by a wp-config.php constant.
 *
 * @param string $key Setting key.
 * @return bool
 */
function lynbro_smtp_is_constant( $key ) {
	$map = lynbro_smtp_constant_map();
	return isset( $map[ $key ] ) && defined( $map[ $key ] );
}

/**
 * Stored settings merged over defaults, without constants applied.
 *
 * @return array<string,mixed>
 */
function lynbro_smtp_stored_options() {
	$stored = get_option( LYNBRO_SMTP_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	// 1.0.x had one "force_from" switch for both fields.
	if ( isset( $stored['force_from'] ) && ! isset( $stored['force_from_email'] ) ) {
		$stored['force_from_email'] = ! empty( $stored['force_from'] );
		$stored['force_from_name']  = ! empty( $stored['force_from'] );
	}
	unset( $stored['force_from'] );
	return array_merge( lynbro_smtp_default_options(), $stored );
}

/**
 * Effective settings: stored values with wp-config.php constants on top.
 *
 * The password comes back encrypted (or as the plain constant value marked
 * `password_plain`); call lynbro_smtp_password() to get the usable value.
 *
 * @return array<string,mixed>
 */
function lynbro_smtp_get_options() {
	$opts = lynbro_smtp_stored_options();
	foreach ( lynbro_smtp_constant_map() as $key => $constant ) {
		if ( defined( $constant ) ) {
			$opts[ $key ] = constant( $constant );
		}
	}
	$opts['port']        = (int) $opts['port'];
	$opts['backup_port'] = (int) $opts['backup_port'];
	return $opts;
}

/**
 * The usable password of the primary or backup connection.
 *
 * @param string $which 'primary' or 'backup'.
 * @return string
 */
function lynbro_smtp_password( $which = 'primary' ) {
	if ( 'primary' === $which && defined( 'LYNBRO_SMTP_PASS' ) ) {
		return (string) constant( 'LYNBRO_SMTP_PASS' );
	}
	$opts   = lynbro_smtp_stored_options();
	$stored = 'backup' === $which ? (string) $opts['backup_password'] : (string) $opts['password'];
	return '' === $stored ? '' : lynbro_smtp_decrypt( $stored );
}

/**
 * Whether a stored password exists but can no longer be decrypted (the site's
 * AUTH_KEY changed since it was saved).
 *
 * @param string $which 'primary' or 'backup'.
 * @return bool
 */
function lynbro_smtp_password_broken( $which = 'primary' ) {
	if ( 'primary' === $which && defined( 'LYNBRO_SMTP_PASS' ) ) {
		return false;
	}
	$opts   = lynbro_smtp_stored_options();
	$stored = 'backup' === $which ? (string) $opts['backup_password'] : (string) $opts['password'];
	return '' !== $stored && '' === lynbro_smtp_decrypt( $stored );
}

/**
 * Sanitize the settings form. Unknown keys are dropped; anything that does not
 * parse falls back to the safe default, never to "more permissive".
 *
 * @param mixed $input Raw input.
 * @return array<string,mixed>
 */
function lynbro_smtp_sanitize_options( $input ) {
	$defaults = lynbro_smtp_default_options();
	$existing = lynbro_smtp_stored_options();
	$input    = is_array( $input ) ? $input : array();
	$out      = array();

	$providers       = lynbro_smtp_providers();
	$out['provider'] = isset( $input['provider'], $providers[ $input['provider'] ] ) ? (string) $input['provider'] : 'custom';

	$out['host']        = lynbro_smtp_clean_host( $input['host'] ?? '' );
	$out['port']        = lynbro_smtp_clean_port( $input['port'] ?? 587 );
	$out['encryption']  = lynbro_smtp_clean_enc( $input['encryption'] ?? 'tls' );
	$out['auth']        = ! empty( $input['auth'] );
	$out['username']    = sanitize_text_field( wp_unslash( (string) ( $input['username'] ?? '' ) ) );
	$out['verify_peer'] = ! empty( $input['verify_peer'] );

	$out['password'] = lynbro_smtp_next_password( $input, 'password', $existing['password'] );

	$out['from_email']       = sanitize_email( (string) ( $input['from_email'] ?? '' ) );
	$out['from_name']        = sanitize_text_field( wp_unslash( (string) ( $input['from_name'] ?? '' ) ) );
	$out['force_from_email'] = ! empty( $input['force_from_email'] );
	$out['force_from_name']  = ! empty( $input['force_from_name'] );
	$out['return_path']      = ! empty( $input['return_path'] );
	$out['reply_to']         = sanitize_email( (string) ( $input['reply_to'] ?? '' ) );

	$out['backup_enabled']  = ! empty( $input['backup_enabled'] );
	$out['backup_type']     = 'php' === ( $input['backup_type'] ?? 'smtp' ) ? 'php' : 'smtp';
	$out['backup_host']     = lynbro_smtp_clean_host( $input['backup_host'] ?? '' );
	$out['backup_port']     = lynbro_smtp_clean_port( $input['backup_port'] ?? 587 );
	$out['backup_enc']      = lynbro_smtp_clean_enc( $input['backup_enc'] ?? 'tls' );
	$out['backup_username'] = sanitize_text_field( wp_unslash( (string) ( $input['backup_username'] ?? '' ) ) );
	$out['backup_password'] = lynbro_smtp_next_password( $input, 'backup_password', $existing['backup_password'] );

	$mode               = (string) ( $input['mode'] ?? 'send' );
	$out['mode']        = in_array( $mode, array( 'send', 'redirect', 'block' ), true ) ? $mode : 'send';
	$out['redirect_to'] = sanitize_email( (string) ( $input['redirect_to'] ?? '' ) );
	if ( 'redirect' === $out['mode'] && '' === $out['redirect_to'] ) {
		$out['mode'] = 'send';
		add_settings_error( LYNBRO_SMTP_OPTION, 'redirect', __( 'Redirect mode needs an address to redirect to — mail is sent normally until you add one.', 'lynbro-smtp-mailer' ) );
	}

	$out['log_enabled']    = ! empty( $input['log_enabled'] );
	$keep                  = (string) ( $input['keep_content'] ?? 'failed' );
	$out['keep_content']   = in_array( $keep, array( 'none', 'failed', 'all' ), true ) ? $keep : 'failed';
	$days                  = absint( $input['retention_days'] ?? 30 );
	$out['retention_days'] = max( 1, min( 365, $days ? $days : 30 ) );

	$out['alert_email']   = sanitize_email( (string) ( $input['alert_email'] ?? '' ) );
	$hook                 = esc_url_raw( trim( (string) ( $input['alert_webhook'] ?? '' ) ), array( 'https' ) );
	$out['alert_webhook'] = (string) $hook;

	return array_merge( $defaults, $out );
}

/**
 * The password value to store: a newly typed one (encrypted), an explicit
 * clear, or the one already stored.
 *
 * @param array<string,mixed> $input    Form input.
 * @param string              $key      'password' or 'backup_password'.
 * @param string              $existing Currently stored (encrypted) value.
 * @return string
 */
function lynbro_smtp_next_password( $input, $key, $existing ) {
	if ( ! empty( $input[ $key . '_clear' ] ) ) {
		return '';
	}
	$new = isset( $input[ $key . '_new' ] ) ? (string) wp_unslash( $input[ $key . '_new' ] ) : '';
	if ( '' !== $new ) {
		return lynbro_smtp_encrypt( $new );
	}
	// Programmatic update_option() with an already-encrypted value (import, CLI).
	if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) && preg_match( '/^(enc|b64):/', $input[ $key ] ) ) {
		return $input[ $key ];
	}
	return (string) $existing;
}

/**
 * Hostname or IP only — no scheme, path or spaces.
 *
 * @param mixed $host Raw host.
 * @return string
 */
function lynbro_smtp_clean_host( $host ) {
	$host = strtolower( trim( (string) $host ) );
	$host = preg_replace( '#^[a-z]+://#', '', $host );
	$host = preg_replace( '#[/:].*$#', '', (string) $host );
	return preg_match( '/^[a-z0-9.-]{1,253}$/', (string) $host ) ? (string) $host : '';
}

/**
 * Port 1–65535, default 587.
 *
 * @param mixed $port Raw port.
 * @return int
 */
function lynbro_smtp_clean_port( $port ) {
	$port = absint( $port );
	return ( $port >= 1 && $port <= 65535 ) ? $port : 587;
}

/**
 * Encryption: tls (STARTTLS), ssl (implicit TLS) or none.
 *
 * @param mixed $enc Raw value.
 * @return string
 */
function lynbro_smtp_clean_enc( $enc ) {
	$enc = strtolower( (string) $enc );
	return in_array( $enc, array( 'tls', 'ssl', 'none' ), true ) ? $enc : 'tls';
}

/**
 * Key for the stored password, derived from the site's own secret keys.
 *
 * @return string
 */
function lynbro_smtp_crypto_key() {
	$secret = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
	return substr( hash( 'sha256', 'lynbro-smtp|' . $secret, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
}

/**
 * Legacy 1.0.x key (no context prefix), kept for decrypting old values.
 *
 * @return string
 */
function lynbro_smtp_crypto_key_legacy() {
	$secret = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
	return substr( hash( 'sha256', $secret, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
}

/**
 * Encrypt a password for storage (libsodium secretbox; sodium is bundled with
 * WordPress through its compat layer, so this is always available).
 *
 * @param string $plain Plain password.
 * @return string
 */
function lynbro_smtp_encrypt( $plain ) {
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext to text, not obfuscation.
	return 'enc:' . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, lynbro_smtp_crypto_key() ) );
}

/**
 * Decrypt a stored password. Returns '' when it cannot be decrypted.
 *
 * @param string $stored Stored value.
 * @return string
 */
function lynbro_smtp_decrypt( $stored ) {
	$stored = (string) $stored;
	if ( 0 === strpos( $stored, 'enc:' ) ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see lynbro_smtp_encrypt().
		$raw = base64_decode( substr( $stored, 4 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		foreach ( array( lynbro_smtp_crypto_key(), lynbro_smtp_crypto_key_legacy() ) as $key ) {
			try {
				$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			} catch ( \Throwable $e ) {
				$plain = false;
			}
			if ( false !== $plain ) {
				return $plain;
			}
		}
		return '';
	}
	if ( 0 === strpos( $stored, 'b64:' ) ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 1.0.x fallback format, re-encrypted on next save.
		return (string) base64_decode( substr( $stored, 4 ), true );
	}
	return '';
}

/**
 * Small runtime state (last failure, alert throttle) kept apart from settings,
 * so it never ends up in an export or a settings revision.
 *
 * @param string|null $key   Key to read or write; null returns everything.
 * @param mixed       $value Value to write; omit to read.
 * @return mixed
 */
function lynbro_smtp_state( $key = null, $value = null ) {
	$state = get_option( LYNBRO_SMTP_STATE_OPTION, array() );
	$state = is_array( $state ) ? $state : array();
	if ( null === $key ) {
		return $state;
	}
	if ( func_num_args() < 2 ) {
		return $state[ $key ] ?? null;
	}
	$state[ $key ] = $value;
	update_option( LYNBRO_SMTP_STATE_OPTION, $state, false );
	return $value;
}

/**
 * Whether the primary connection is configured enough to take over wp_mail().
 *
 * @param array<string,mixed>|null $opts Options; null reads them.
 * @return bool
 */
function lynbro_smtp_is_configured( $opts = null ) {
	$opts = null === $opts ? lynbro_smtp_get_options() : $opts;
	return lynbro_smtp_uses_php_mail( $opts ) || '' !== (string) $opts['host'];
}

/**
 * Whether the owner chose the server's own mail (PHP mail()) on purpose.
 *
 * Not the same as "nothing set up": From, Return-Path, Reply-To and the log
 * still apply, so WP Mail SMTP's default mailer has a like-for-like home here.
 *
 * @param array<string,mixed>|null $opts Options, or null for the saved ones.
 * @return bool
 */
function lynbro_smtp_uses_php_mail( $opts = null ) {
	$opts = null === $opts ? lynbro_smtp_get_options() : $opts;
	return 'php' === (string) ( $opts['provider'] ?? '' );
}
