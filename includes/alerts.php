<?php
/**
 * Failure alerts (email through PHP mail(), and/or a webhook of the owner's
 * choosing), a Site Health test and a small Dashboard widget.
 *
 * Nothing here uses admin notices: the plugin speaks on its own screen, in
 * Site Health and in a widget the administrator can hide.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LYNBRO_SMTP_ALERT_INTERVAL' ) ) {
	define( 'LYNBRO_SMTP_ALERT_INTERVAL', HOUR_IN_SECONDS );
}

/**
 * Send the alert for a failure, at most once per LYNBRO_SMTP_ALERT_INTERVAL.
 *
 * @param string $message   Error message.
 * @param bool   $recovered Whether the backup delivered the email.
 * @param int    $row       Log row id.
 * @return void
 */
function lynbro_smtp_alert( $message, $recovered, $row ) {
	static $busy = false;
	if ( $busy ) {
		return; // The alert itself failed — do not alert about the alert.
	}
	$opts  = lynbro_smtp_get_options();
	$email = (string) $opts['alert_email'];
	$hook  = (string) $opts['alert_webhook'];
	if ( ! is_email( $email ) && '' === $hook ) {
		return;
	}
	$last = (int) lynbro_smtp_state( 'last_alert' );
	if ( $last && ( time() - $last ) < LYNBRO_SMTP_ALERT_INTERVAL ) {
		return;
	}
	lynbro_smtp_state( 'last_alert', time() );
	$busy = true;

	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$log  = admin_url( 'admin.php?page=lynbro-smtp&tab=log&status=failed' );
	$text = $recovered
		/* translators: 1: site name, 2: error message. */
		? sprintf( __( '%1$s: the main SMTP connection failed and the backup delivered the email. Error: %2$s', 'lynbro-smtp-mailer' ), $site, $message )
		/* translators: 1: site name, 2: error message. */
		: sprintf( __( '%1$s: an email could not be sent. Error: %2$s', 'lynbro-smtp-mailer' ), $site, $message );
	$text .= "\n\n" . $log;
	if ( $row ) {
		/* translators: %d: log entry number. */
		$text .= "\n" . sprintf( __( 'Log entry #%d', 'lynbro-smtp-mailer' ), (int) $row );
	}

	if ( is_email( $email ) ) {
		$mailer = $GLOBALS['lynbro_smtp_mailer'] ?? null;
		if ( $mailer instanceof Lynbro_SMTP_Mailer ) {
			$mailer->force    = 'php';
			$mailer->alerting = true;
		}
		/* translators: %s: site name. */
		wp_mail( $email, sprintf( __( '[%s] Email delivery problem', 'lynbro-smtp-mailer' ), $site ), $text );
		if ( $mailer instanceof Lynbro_SMTP_Mailer ) {
			$mailer->force    = '';
			$mailer->alerting = false;
		}
	}

	if ( '' !== $hook ) {
		wp_remote_post(
			$hook,
			array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				// Slack reads "text", Discord reads "content"; both ignore the other.
				'body'     => wp_json_encode(
					array(
						'text'    => $text,
						'content' => $text,
					)
				),
			)
		);
	}
	$busy = false;
}
add_action( 'lynbro_smtp_failure', 'lynbro_smtp_alert', 10, 3 );

/**
 * Register the Site Health test.
 *
 * @param array<string,mixed> $tests Tests.
 * @return array<string,mixed>
 */
function lynbro_smtp_site_health_tests( $tests ) {
	$tests['direct']['lynbro_smtp'] = array(
		'label' => __( 'Email delivery', 'lynbro-smtp-mailer' ),
		'test'  => 'lynbro_smtp_site_health_result',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'lynbro_smtp_site_health_tests' );

/**
 * Site Health result: configured? any failure in the last 24 hours?
 *
 * @return array<string,mixed>
 */
function lynbro_smtp_site_health_result() {
	$url    = admin_url( 'admin.php?page=lynbro-smtp' );
	$result = array(
		'label'       => __( 'Email is sent through SMTP', 'lynbro-smtp-mailer' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Email', 'lynbro-smtp-mailer' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html__( 'Lynbro SMTP sends this site\'s email through an authenticated SMTP server.', 'lynbro-smtp-mailer' ) . '</p>',
		'actions'     => '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open Lynbro SMTP', 'lynbro-smtp-mailer' ) . '</a></p>',
		'test'        => 'lynbro_smtp',
	);

	if ( ! lynbro_smtp_is_configured() ) {
		$result['status']      = 'recommended';
		$result['label']       = __( 'Email is sent with PHP mail(), not SMTP', 'lynbro-smtp-mailer' );
		$result['description'] = '<p>' . esc_html__( 'Lynbro SMTP is active but no SMTP server is set, so email still leaves through PHP mail() and often lands in spam.', 'lynbro-smtp-mailer' ) . '</p>';
		return $result;
	}
	if ( lynbro_smtp_uses_php_mail() ) {
		$result['label']       = __( 'Email is sent with the server\'s own mail, with a matching sender', 'lynbro-smtp-mailer' );
		$result['description'] = '<p>' . esc_html__( 'Lynbro SMTP sends with PHP mail() by your choice and sets the From address and Return-Path on every email. Keep this server in the domain\'s SPF record.', 'lynbro-smtp-mailer' ) . '</p>';
	} elseif ( lynbro_smtp_password_broken() ) {
		$result['status']      = 'critical';
		$result['label']       = __( 'The saved SMTP password can no longer be read', 'lynbro-smtp-mailer' );
		$result['description'] = '<p>' . esc_html__( 'The site\'s security keys changed after the password was saved. Enter the SMTP password again.', 'lynbro-smtp-mailer' ) . '</p>';
		return $result;
	}
	$fail = lynbro_smtp_state( 'last_failure' );
	if ( is_array( $fail ) && ( time() - (int) $fail['time'] ) < DAY_IN_SECONDS ) {
		$result['status']      = empty( $fail['recovered'] ) ? 'critical' : 'recommended';
		$result['label']       = empty( $fail['recovered'] )
			? __( 'Emails failed to send in the last 24 hours', 'lynbro-smtp-mailer' )
			: __( 'The main SMTP connection failed in the last 24 hours', 'lynbro-smtp-mailer' );
		$result['description'] = '<p>' . esc_html( (string) $fail['error'] ) . '</p>';
	}
	$alert = lynbro_smtp_state( 'alert_failure' );
	if ( is_array( $alert ) && ( time() - (int) $alert['time'] ) < DAY_IN_SECONDS && is_email( lynbro_smtp_get_options()['alert_email'] ) ) {
		if ( 'good' === $result['status'] ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Failure alerts by email do not get out', 'lynbro-smtp-mailer' );
			$result['description'] = '';
		}
		$result['description'] .= '<p>' . esc_html(
			sprintf(
				/* translators: %s: error message. */
				__( 'The alert email is sent with PHP mail() so that it does not depend on the SMTP server, but PHP mail() failed on this server: %s Use a webhook for alerts, or ask your host to enable mail().', 'lynbro-smtp-mailer' ),
				(string) $alert['error']
			)
		) . '</p>';
	}
	return $result;
}

/**
 * Dashboard widget with last-7-days numbers, for administrators.
 *
 * @return void
 */
function lynbro_smtp_dashboard_widget_register() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$opts = lynbro_smtp_get_options();
	if ( empty( $opts['log_enabled'] ) ) {
		return;
	}
	wp_add_dashboard_widget( 'lynbro_smtp_widget', __( 'Email delivery (7 days)', 'lynbro-smtp-mailer' ), 'lynbro_smtp_dashboard_widget' );
}
add_action( 'wp_dashboard_setup', 'lynbro_smtp_dashboard_widget_register' );

/**
 * Render the dashboard widget.
 *
 * @return void
 */
function lynbro_smtp_dashboard_widget() {
	$counts = lynbro_smtp_log_counts( gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) );
	$base   = admin_url( 'admin.php?page=lynbro-smtp&tab=log' );
	?>
	<p>
		<?php
		printf(
			/* translators: 1: number of sent emails, 2: number of failed emails. */
			esc_html__( 'Sent: %1$s · Failed: %2$s', 'lynbro-smtp-mailer' ),
			'<strong>' . esc_html( number_format_i18n( $counts['sent'] ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( $counts['failed'] ) ) . '</strong>'
		);
		?>
	</p>
	<?php if ( ! lynbro_smtp_is_configured() ) : ?>
		<p><?php esc_html_e( 'No SMTP server is set yet — email is sent with PHP mail().', 'lynbro-smtp-mailer' ); ?></p>
	<?php endif; ?>
	<p>
		<a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Email log', 'lynbro-smtp-mailer' ); ?></a>
		<?php if ( $counts['failed'] > 0 ) : ?>
			· <a href="<?php echo esc_url( add_query_arg( 'status', 'failed', $base ) ); ?>"><?php esc_html_e( 'Show failed', 'lynbro-smtp-mailer' ); ?></a>
		<?php endif; ?>
	</p>
	<?php
}
