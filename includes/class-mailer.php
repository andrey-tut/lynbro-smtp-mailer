<?php
/**
 * Mail routing: SMTP configuration, sender rules, test/staging modes and the
 * bookkeeping that ties each wp_mail() call to its log row.
 *
 * Order of events inside one wp_mail() call (see wp-includes/pluggable.php):
 * `wp_mail` filter → `pre_wp_mail` → `wp_mail_from(_name)` → `phpmailer_init`
 * → send → `wp_mail_succeeded` or `wp_mail_failed`.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into wp_mail().
 */
class Lynbro_SMTP_Mailer {

	/**
	 * Log row of the wp_mail() call in progress.
	 *
	 * @var int
	 */
	private $row = 0;

	/**
	 * Message body of the call in progress, kept in memory only, so a failed
	 * email can be stored for resending without storing every email.
	 *
	 * @var string
	 */
	private $body = '';

	/**
	 * When set, the next send is a diagnostic test and SMTP debug output is
	 * collected here instead of being echoed.
	 *
	 * @var array<int,string>|null
	 */
	public $transcript = null;

	/**
	 * Force a connection for the next send ('primary', 'backup' or 'php'); used
	 * by the test screen to try one connection on its own, and by alerts.
	 *
	 * @var string
	 */
	public $force = '';

	/**
	 * True while the failure alert itself is being sent. Its own failure (PHP
	 * mail() is often missing) must not replace the failure it reports.
	 *
	 * @var bool
	 */
	public $alerting = false;

	/**
	 * Hook in.
	 */
	public function __construct() {
		add_filter( 'wp_mail', array( $this, 'capture' ), 1000 );
		add_filter( 'pre_wp_mail', array( $this, 'maybe_block' ), 1000, 2 );
		add_filter( 'wp_mail_from', array( $this, 'from_email' ), 1000 );
		add_filter( 'wp_mail_from_name', array( $this, 'from_name' ), 1000 );
		add_action( 'phpmailer_init', array( $this, 'configure' ), 1000 );
		add_action( 'wp_mail_succeeded', array( $this, 'succeeded' ) );
		add_action( 'wp_mail_failed', array( $this, 'failed' ) );
	}

	/**
	 * Start of a wp_mail() call: install our PHPMailer, apply redirect mode,
	 * open the log row.
	 *
	 * @param array<string,mixed> $atts wp_mail() arguments.
	 * @return array<string,mixed>
	 */
	public function capture( $atts ) {
		$opts = lynbro_smtp_get_options();
		$this->install_phpmailer( $opts );

		$this->body = (string) ( $atts['message'] ?? '' );
		$resent     = isset( $GLOBALS['lynbro_smtp_resending'] ) ? (int) $GLOBALS['lynbro_smtp_resending'] : 0;
		$this->row  = lynbro_smtp_log_insert( $atts, 'pending', $resent );

		if ( 'redirect' === $opts['mode'] && is_email( $opts['redirect_to'] ) && null === $this->transcript ) {
			$atts = $this->redirect( $atts, (string) $opts['redirect_to'] );
			lynbro_smtp_log_update( $this->row, array( 'via' => 'redirect' ) );
		}
		return $atts;
	}

	/**
	 * Make the global $phpmailer our subclass, so the backup connection can
	 * take over inside the same send. Skipped when another plugin already put
	 * its own PHPMailer there — then SMTP still works, only without the backup.
	 *
	 * @param array<string,mixed> $opts Options.
	 * @return void
	 */
	private function install_phpmailer( $opts ) {
		global $phpmailer;
		if ( ! lynbro_smtp_is_configured( $opts ) || lynbro_smtp_uses_php_mail( $opts ) || $phpmailer instanceof Lynbro_SMTP_PHPMailer ) {
			return; // PHP mail() has no connection to fail over from.
		}
		if ( is_object( $phpmailer ) ) {
			return; // Someone else's instance; do not replace it under them.
		}
		require_once LYNBRO_SMTP_DIR . 'includes/class-lynbro-smtp-retry-mailer.php';
		$phpmailer = new Lynbro_SMTP_PHPMailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp_mail() reuses this global by design.
		$phpmailer::$validator = static function ( $email ) {
			return (bool) is_email( $email );
		};
	}

	/**
	 * Redirect mode (staging): every email goes to one address; Cc and Bcc are
	 * dropped so real people never receive a copy.
	 *
	 * @param array<string,mixed> $atts wp_mail() arguments.
	 * @param string              $to   Redirect address.
	 * @return array<string,mixed>
	 */
	private function redirect( $atts, $to ) {
		$original = implode( ', ', lynbro_smtp_addresses( $atts['to'] ?? '' ) );
		$headers  = $atts['headers'] ?? array();
		if ( is_string( $headers ) ) {
			$headers = preg_split( "/\r\n|\n|\r/", $headers );
		}
		$kept = array();
		foreach ( (array) $headers as $line ) {
			if ( ! preg_match( '/^\s*(cc|bcc)\s*:/i', (string) $line ) ) {
				$kept[] = $line;
			}
		}
		$kept[]          = 'X-Lynbro-Original-To: ' . str_replace( array( "\r", "\n" ), '', $original );
		$atts['to']      = $to;
		$atts['headers'] = $kept;
		return $atts;
	}

	/**
	 * Block mode: nothing leaves the site; wp_mail() reports success so forms
	 * behave normally. Also the moment to note PHP mail() when SMTP is not set.
	 *
	 * @param null|bool           $pre  Short-circuit value from earlier filters.
	 * @param array<string,mixed> $atts wp_mail() arguments.
	 * @return null|bool
	 */
	public function maybe_block( $pre, $atts ) {
		unset( $atts ); // Already captured in capture().
		if ( null !== $pre ) {
			lynbro_smtp_log_update( $this->row, array( 'status' => $pre ? 'sent' : 'failed' ) );
			return $pre;
		}
		$opts = lynbro_smtp_get_options();
		if ( 'block' === $opts['mode'] && null === $this->transcript ) {
			lynbro_smtp_log_update(
				$this->row,
				array(
					'status'  => 'blocked',
					'via'     => 'blocked',
					'message' => 'none' === $opts['keep_content'] ? null : $this->body,
				)
			);
			$this->row = 0;
			return true;
		}
		return $pre;
	}

	/**
	 * Sender address.
	 *
	 * @param string $current From address set so far.
	 * @return string
	 */
	public function from_email( $current ) {
		$opts = lynbro_smtp_get_options();
		$from = (string) $opts['from_email'];
		if ( ! is_email( $from ) ) {
			return $current;
		}
		if ( ! empty( $opts['force_from_email'] ) || 0 === strpos( (string) $current, 'wordpress@' ) ) {
			return $from;
		}
		return $current;
	}

	/**
	 * Sender name.
	 *
	 * @param string $current From name set so far.
	 * @return string
	 */
	public function from_name( $current ) {
		$opts = lynbro_smtp_get_options();
		$name = (string) $opts['from_name'];
		if ( '' === $name ) {
			return $current;
		}
		if ( ! empty( $opts['force_from_name'] ) || 'WordPress' === $current ) {
			return $name;
		}
		return $current;
	}

	/**
	 * Point PHPMailer at the SMTP server.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer PHPMailer instance.
	 * @return void
	 */
	public function configure( $mailer ) {
		$opts = lynbro_smtp_get_options();
		if ( ! lynbro_smtp_is_configured( $opts ) ) {
			lynbro_smtp_log_update( $this->row, array( 'via' => 'php' ) );
			return;
		}

		if ( 'php' === $this->force ) {
			$mailer->isMail(); // Alerts about a broken SMTP server cannot travel through it.
			lynbro_smtp_log_update( $this->row, array( 'via' => 'php' ) );
			if ( $mailer instanceof Lynbro_SMTP_PHPMailer ) {
				$mailer->lynbro_via    = 'php';
				$mailer->lynbro_backup = null;
			}
			return;
		}

		$use_backup = 'backup' === $this->force;
		if ( lynbro_smtp_uses_php_mail( $opts ) && ! $use_backup ) {
			// The owner chose the server's own mail. PHPMailer may still hold SMTP
			// settings from an earlier send in this request, so say it explicitly.
			$mailer->isMail();
			$via = 'php';
			lynbro_smtp_log_update( $this->row, array( 'via' => 'php' ) );
		} elseif ( $use_backup ) {
			$via = $this->apply_backup( $mailer, $opts );
		} else {
			$this->apply_connection( $mailer, $opts['host'], $opts['port'], $opts['encryption'], ! empty( $opts['auth'] ) ? (string) $opts['username'] : '', lynbro_smtp_password( 'primary' ), ! empty( $opts['verify_peer'] ) );
			$via = 'primary';
		}

		if ( $mailer instanceof Lynbro_SMTP_PHPMailer ) {
			$mailer->lynbro_via    = $via;
			$mailer->lynbro_backup = null;
			if ( ! $use_backup && '' === $this->force && $this->backup_ready( $opts ) ) {
				$self                  = $this;
				$mailer->lynbro_backup = static function ( $m ) use ( $self, $opts ) {
					return $self->apply_backup( $m, $opts );
				};
			}
		}

		if ( ! empty( $opts['return_path'] ) ) {
			$mailer->Sender = $mailer->From; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		}
		if ( is_email( $opts['reply_to'] ) && ! $mailer->getReplyToAddresses() ) {
			$mailer->addReplyTo( (string) $opts['reply_to'] );
		}
		$mailer->XMailer = ' '; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API; a blank value drops the header.

		if ( null !== $this->transcript ) {
			$self                  = $this;
			$mailer->SMTPDebug     = 3; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
			$mailer->Debugoutput   = static function ( $line ) use ( $self ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
				$self->transcript[] = rtrim( (string) $line );
			};
		} else {
			$mailer->SMTPDebug = 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		}
	}

	/**
	 * Whether a usable backup connection is configured.
	 *
	 * @param array<string,mixed> $opts Options.
	 * @return bool
	 */
	public function backup_ready( $opts ) {
		if ( empty( $opts['backup_enabled'] ) ) {
			return false;
		}
		return 'php' === $opts['backup_type'] || '' !== (string) $opts['backup_host'];
	}

	/**
	 * Switch a PHPMailer instance to the backup connection.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer PHPMailer instance.
	 * @param array<string,mixed>            $opts   Options.
	 * @return string 'backup' or 'php'.
	 */
	public function apply_backup( $mailer, $opts ) {
		if ( 'php' === $opts['backup_type'] ) {
			$mailer->isMail();
			return 'php';
		}
		$this->apply_connection( $mailer, $opts['backup_host'], $opts['backup_port'], $opts['backup_enc'], (string) $opts['backup_username'], lynbro_smtp_password( 'backup' ), ! empty( $opts['verify_peer'] ) );
		return 'backup';
	}

	/**
	 * Set SMTP connection properties.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer      PHPMailer instance.
	 * @param string                         $host        Host.
	 * @param int                            $port        Port.
	 * @param string                         $enc         tls|ssl|none.
	 * @param string                         $user        Username ('' = no authentication).
	 * @param string                         $pass        Password.
	 * @param bool                           $verify_peer Verify the server certificate.
	 * @return void
	 */
	private function apply_connection( $mailer, $host, $port, $enc, $user, $pass, $verify_peer ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		$mailer->isSMTP();
		$mailer->Host        = (string) $host;
		$mailer->Port        = (int) $port;
		$mailer->SMTPSecure  = 'none' === $enc ? '' : (string) $enc;
		$mailer->SMTPAutoTLS = 'none' !== $enc;
		$mailer->SMTPAuth    = '' !== $user;
		$mailer->Username    = $user;
		$mailer->Password    = $pass;
		$mailer->Timeout     = 15;
		$mailer->SMTPOptions = $verify_peer ? array() : array(
			'ssl' => array(
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'allow_self_signed' => true,
			),
		);
		// phpcs:enable
	}

	/**
	 * The send went through.
	 *
	 * @param array<string,mixed> $mail_data Data passed by wp_mail().
	 * @return void
	 */
	public function succeeded( $mail_data ) {
		unset( $mail_data );
		global $phpmailer;
		$row           = $this->row;
		$primary_error = '';
		$fields        = array( 'status' => 'sent' );
		if ( $phpmailer instanceof Lynbro_SMTP_PHPMailer ) {
			if ( '' !== $phpmailer->lynbro_via ) {
				$fields['via'] = $phpmailer->lynbro_via;
			}
			$primary_error = (string) $phpmailer->lynbro_primary_error;
			if ( '' !== $primary_error ) {
				$fields['error'] = mb_substr( $primary_error, 0, 2000 );
			}
		}
		$current = lynbro_smtp_log_get( $row );
		if ( $current && 'redirect' === $current['via'] ) {
			unset( $fields['via'] );
		}
		lynbro_smtp_log_update( $row, $fields );
		lynbro_smtp_state( 'last_sent', time() );
		$this->row  = 0;
		$this->body = '';
		// Last: the alert sends an email of its own, which reuses this object.
		if ( '' !== $primary_error ) {
			$this->note_failure( $primary_error, true, $row );
		}
	}

	/**
	 * The send failed (both connections, if there is a backup).
	 *
	 * @param WP_Error $error Error from wp_mail().
	 * @return void
	 */
	public function failed( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}
		global $phpmailer;
		$message = (string) $error->get_error_message();
		$opts    = lynbro_smtp_get_options();
		$fields  = array(
			'status'  => 'failed',
			'error'   => mb_substr( $message, 0, 2000 ),
			'message' => 'none' === $opts['keep_content'] ? null : $this->body,
		);
		if ( $phpmailer instanceof Lynbro_SMTP_PHPMailer && '' !== $phpmailer->lynbro_via ) {
			$fields['via'] = $phpmailer->lynbro_via;
		}
		$current = lynbro_smtp_log_get( $this->row );
		if ( $current && 'redirect' === $current['via'] ) {
			unset( $fields['via'] );
		}
		lynbro_smtp_log_update( $this->row, $fields );
		$row        = $this->row;
		$this->row  = 0;
		$this->body = '';
		$this->note_failure( $message, false, $row );
	}

	/**
	 * Remember a failure for the dashboard and send the alert.
	 *
	 * @param string $message   Error message.
	 * @param bool   $recovered The backup delivered the email anyway.
	 * @param int    $row       Log row id.
	 * @return void
	 */
	private function note_failure( $message, $recovered, $row = 0 ) {
		if ( $this->alerting ) {
			// The alert email failed: keep the real failure for Site Health and
			// remember separately that alerts by email do not get out.
			lynbro_smtp_state(
				'alert_failure',
				array(
					'time'  => time(),
					'error' => mb_substr( (string) $message, 0, 500 ),
				)
			);
			return;
		}
		lynbro_smtp_state(
			'last_failure',
			array(
				'time'      => time(),
				'error'     => mb_substr( (string) $message, 0, 500 ),
				'recovered' => (bool) $recovered,
			)
		);
		if ( null === $this->transcript ) {
			/**
			 * Fires when an email failed (or needed the backup connection).
			 *
			 * @param string $message   Error message.
			 * @param bool   $recovered Whether the backup delivered it.
			 * @param int    $row       Log row id (0 when logging is off).
			 */
			do_action( 'lynbro_smtp_failure', (string) $message, (bool) $recovered, (int) $row );
		}
	}
}
