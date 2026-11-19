<?php
/**
 * PHPMailer with a second chance: when the primary connection throws, the
 * same message is sent once more through the backup connection (another SMTP
 * server or PHP mail()), inside the same wp_mail() call — so the caller gets
 * `true` back and a contact form does not tell the visitor it failed.
 *
 * Loaded only when wp_mail() is about to run. wp_mail() reuses the global
 * $phpmailer when it is already a PHPMailer, which is how this one takes part.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PHPMailer\\PHPMailer\\PHPMailer', false ) ) {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
}
if ( ! class_exists( 'WP_PHPMailer', false ) && file_exists( ABSPATH . WPINC . '/class-wp-phpmailer.php' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-phpmailer.php'; // WordPress 6.8+: translated error messages.
}
if ( ! class_exists( 'Lynbro_SMTP_PHPMailer_Parent', false ) ) {
	class_alias( class_exists( 'WP_PHPMailer', false ) ? 'WP_PHPMailer' : 'PHPMailer\\PHPMailer\\PHPMailer', 'Lynbro_SMTP_PHPMailer_Parent' );
}

/**
 * PHPMailer subclass with the backup retry.
 */
class Lynbro_SMTP_PHPMailer extends Lynbro_SMTP_PHPMailer_Parent {

	/**
	 * Which connection delivered the last message: primary, backup, php or ''.
	 *
	 * @var string
	 */
	public $lynbro_via = '';

	/**
	 * Error of the primary connection when the backup took over.
	 *
	 * @var string
	 */
	public $lynbro_primary_error = '';

	/**
	 * Callback that switches this instance to the backup connection; set by
	 * Lynbro_SMTP_Mailer::configure() for each message, null when there is none.
	 *
	 * @var callable|null
	 */
	public $lynbro_backup = null;

	/**
	 * Send, and on failure retry once through the backup connection.
	 *
	 * @return bool
	 * @throws \PHPMailer\PHPMailer\Exception When both connections fail.
	 */
	public function send() {
		$this->lynbro_primary_error = '';
		try {
			$sent = parent::send();
			if ( $sent || ! is_callable( $this->lynbro_backup ) ) {
				return $sent;
			}
			$error = $this->ErrorInfo; // Exceptions switched off by whoever created us.
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			if ( ! is_callable( $this->lynbro_backup ) ) {
				throw $e;
			}
			$error = $e->getMessage();
		}

		$this->lynbro_primary_error = (string) $error;
		$this->smtpClose();
		$this->lynbro_via = (string) call_user_func( $this->lynbro_backup, $this );
		try {
			$sent = parent::send();
		} catch ( \PHPMailer\PHPMailer\Exception $e2 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by wp_mail(), never printed; escaped where the log shows it.
			throw new \PHPMailer\PHPMailer\Exception( $this->lynbro_combined_error( $e2->getMessage() ) );
		}
		if ( ! $sent ) {
			$this->setError( $this->lynbro_combined_error( $this->ErrorInfo ) );
		}
		return $sent;
	}

	/**
	 * One error line naming both connections.
	 *
	 * @param string $backup_error Error of the backup connection.
	 * @return string
	 */
	private function lynbro_combined_error( $backup_error ) {
		return sprintf(
			/* translators: 1: error of the main connection, 2: error of the backup connection. */
			__( 'Main connection: %1$s — Backup connection: %2$s', 'lynbro-smtp-mailer' ),
			$this->lynbro_primary_error,
			$backup_error
		);
	}
}
