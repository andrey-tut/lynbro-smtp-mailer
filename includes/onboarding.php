<?php
/**
 * The one thing this plugin asks of you, asked on its own screen.
 *
 * Guideline 11 says a plugin must not hijack the dashboard. The cheapest way to
 * comply is to own no global hook at all: there is no `admin_notices` here, no
 * redirect after activation, nothing that appears on a screen belonging to
 * WordPress or to somebody else's plugin. The review request is rendered by our
 * own settings page, once, after a week of actual use, and can be turned off for
 * good.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * How long the plugin must have been installed before the request may appear.
 */
if ( ! defined( 'LYNBRO_SMTP_REVIEW_DELAY' ) ) {
	define( 'LYNBRO_SMTP_REVIEW_DELAY', 7 * DAY_IN_SECONDS );
}

/**
 * Remember when the plugin was first activated. Called from activation.
 *
 * @return void
 */
function lynbro_smtp_record_install_time() {
	if ( ! get_option( 'lynbro_smtp_installed_at' ) ) {
		add_option( 'lynbro_smtp_installed_at', time(), '', false );
	}
}

/**
 * Whether the review request has earned the right to be shown.
 *
 * @return bool
 */
function lynbro_smtp_should_ask_for_review() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	if ( get_user_meta( get_current_user_id(), 'lynbro_smtp_review_dismissed', true ) ) {
		return false;
	}

	$installed = (int) get_option( 'lynbro_smtp_installed_at', 0 );
	if ( ! $installed ) {
		// Upgraded from a version that did not record this — start the clock now.
		lynbro_smtp_record_install_time();
		return false;
	}
	if ( ( time() - $installed ) < LYNBRO_SMTP_REVIEW_DELAY ) {
		return false;
	}

	// Only ask people for whom the plugin has actually done something.
	$sent = lynbro_smtp_log_counts( gmdate( 'Y-m-d H:i:s', $installed ) );
	return $sent['sent'] >= 5;
}

/**
 * Render the review request. Called from our settings page, nowhere else.
 *
 * @return void
 */
function lynbro_smtp_render_review_prompt() {
	if ( ! lynbro_smtp_should_ask_for_review() ) {
		return;
	}

	$dismiss = wp_nonce_url(
		add_query_arg( 'lynbro_smtp_dismiss_review', '1' ),
		'lynbro_smtp_dismiss_review'
	);
	?>
	<div class="lynbro-section lynbro-smtp-review">
		<p>
			<?php esc_html_e( 'Is Lynbro SMTP getting your email delivered? A few words on WordPress.org help others find a mail plugin without upsells.', 'lynbro-smtp-mailer' ); ?>
		</p>
		<p class="lynbro-tools-row">
			<a class="button button-primary" href="https://wordpress.org/support/plugin/lynbro-smtp-mailer/reviews/#new-post" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Write a review', 'lynbro-smtp-mailer' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'No thanks', 'lynbro-smtp-mailer' ); ?></a>
		</p>
	</div>
	<?php
}

/**
 * Handle "No thanks" — hide the request for this user, permanently.
 *
 * @return void
 */
function lynbro_smtp_dismiss_review() {
	if ( ! isset( $_GET['lynbro_smtp_dismiss_review'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked immediately below.
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'lynbro_smtp_dismiss_review' );

	update_user_meta( get_current_user_id(), 'lynbro_smtp_review_dismissed', 1 );

	wp_safe_redirect( remove_query_arg( array( 'lynbro_smtp_dismiss_review', '_wpnonce' ) ) );
	exit;
}
add_action( 'admin_init', 'lynbro_smtp_dismiss_review' );
