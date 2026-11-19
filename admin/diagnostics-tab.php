<?php
/**
 * Admin — Test & diagnostics tab.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the tab.
 *
 * @return void
 */
function lynbro_smtp_render_tab_test() {
	$o      = lynbro_smtp_get_options();
	$dns    = lynbro_smtp_dns_check( is_email( $o['from_email'] ) ? (string) $o['from_email'] : (string) get_option( 'admin_email' ) );
	$mailer = $GLOBALS['lynbro_smtp_mailer'] ?? null;
	$backup = ( $mailer instanceof Lynbro_SMTP_Mailer ) && $mailer->backup_ready( $o );
	?>
	<div class="lynbro-section">
		<h2><?php esc_html_e( 'Send a test email', 'lynbro-smtp-mailer' ); ?></h2>
		<?php if ( lynbro_smtp_uses_php_mail( $o ) ) : ?>
			<p class="desc"><?php esc_html_e( 'This site sends with the server\'s own mail (PHP mail()). The test shows whether the server accepted the email; check its headers in the inbox (Authentication-Results: spf=pass, dmarc=pass).', 'lynbro-smtp-mailer' ); ?></p>
		<?php elseif ( ! lynbro_smtp_is_configured( $o ) ) : ?>
			<p class="desc"><?php esc_html_e( 'No SMTP server is set yet, so the test goes out with PHP mail(). Fill in Settings first to test SMTP.', 'lynbro-smtp-mailer' ); ?></p>
		<?php endif; ?>
		<p class="lynbro-tools-row">
			<label for="lynbro-smtp-test-to" class="screen-reader-text"><?php esc_html_e( 'Recipient', 'lynbro-smtp-mailer' ); ?></label>
			<input type="email" id="lynbro-smtp-test-to" class="regular-text" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
			<button type="button" class="button button-primary lynbro-smtp-test" data-connection="primary"><?php esc_html_e( 'Send test', 'lynbro-smtp-mailer' ); ?></button>
			<?php if ( $backup && 'smtp' === $o['backup_type'] ) : ?>
				<button type="button" class="button lynbro-smtp-test" data-connection="backup"><?php esc_html_e( 'Test the backup connection', 'lynbro-smtp-mailer' ); ?></button>
			<?php endif; ?>
		</p>
		<div id="lynbro-smtp-test-result" class="lynbro-smtp-result" aria-live="polite"></div>
		<details id="lynbro-smtp-transcript-wrap" hidden>
			<summary><?php esc_html_e( 'SMTP conversation (password hidden)', 'lynbro-smtp-mailer' ); ?></summary>
			<pre id="lynbro-smtp-transcript" class="lynbro-smtp-body"></pre>
		</details>
		<p class="lynbro-muted"><?php esc_html_e( 'Test emails ignore test mode and "do not send" mode, so you can check the connection on a staging copy.', 'lynbro-smtp-mailer' ); ?></p>
	</div>

	<div class="lynbro-section">
		<h2><?php esc_html_e( 'Can this server reach the SMTP server?', 'lynbro-smtp-mailer' ); ?></h2>
		<p class="desc"><?php esc_html_e( 'Many hosts block outgoing mail ports. This checks the usual ones to your saved host, from this web server.', 'lynbro-smtp-mailer' ); ?></p>
		<p><button type="button" class="button" id="lynbro-smtp-ports" <?php disabled( ! lynbro_smtp_is_configured( $o ) || lynbro_smtp_uses_php_mail( $o ) ); ?>><?php esc_html_e( 'Check ports', 'lynbro-smtp-mailer' ); ?></button></p>
		<ul id="lynbro-smtp-ports-out" class="lynbro-smtp-checks"></ul>
	</div>

	<div class="lynbro-section">
		<h2>
			<?php
			/* translators: %s: domain name. */
			printf( esc_html__( 'Sender domain: %s', 'lynbro-smtp-mailer' ), '<code>' . esc_html( $dns['domain'] ) . '</code>' );
			?>
		</h2>
		<?php if ( ! $dns['checked'] ) : ?>
			<p class="desc"><?php esc_html_e( 'DNS lookups are not available on this server.', 'lynbro-smtp-mailer' ); ?></p>
		<?php else : ?>
			<ul class="lynbro-smtp-checks">
				<li class="<?php echo '' !== $dns['spf'] ? 'ok' : 'warn'; ?>">
					<strong>SPF</strong>
					<?php if ( '' !== $dns['spf'] ) : ?>
						<code class="lynbro-smtp-break"><?php echo esc_html( $dns['spf'] ); ?></code>
						<?php if ( lynbro_smtp_uses_php_mail( $o ) ) : ?>
							<br><small><?php esc_html_e( 'Make sure it includes this web server (its IP address with ip4: or ip6:, or a: / mx: that resolves to it).', 'lynbro-smtp-mailer' ); ?></small>
						<?php else : ?>
							<br><small><?php esc_html_e( 'Make sure it includes your SMTP provider (for example include:spf.brevo.com).', 'lynbro-smtp-mailer' ); ?></small>
						<?php endif; ?>
					<?php else : ?>
						<?php esc_html_e( 'No SPF record. Add one at your DNS host that names your SMTP provider, or many inboxes will distrust the email.', 'lynbro-smtp-mailer' ); ?>
					<?php endif; ?>
				</li>
				<li class="<?php echo '' !== $dns['dmarc'] ? 'ok' : 'warn'; ?>">
					<strong>DMARC</strong>
					<?php if ( '' !== $dns['dmarc'] ) : ?>
						<code class="lynbro-smtp-break"><?php echo esc_html( $dns['dmarc'] ); ?></code>
					<?php else : ?>
						<?php esc_html_e( 'No DMARC record. Gmail and Yahoo require one for bulk senders; even a simple "v=DMARC1; p=none" helps.', 'lynbro-smtp-mailer' ); ?>
					<?php endif; ?>
				</li>
				<li class="info"><strong>DKIM</strong> <?php esc_html_e( 'DKIM is set up at your SMTP provider (they give you the DNS record). It cannot be checked from here without the selector name.', 'lynbro-smtp-mailer' ); ?></li>
			</ul>
		<?php endif; ?>
	</div>

	<div class="lynbro-section">
		<h2><?php esc_html_e( 'Environment', 'lynbro-smtp-mailer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'OpenSSL', 'lynbro-smtp-mailer' ); ?></th><td><?php echo esc_html( extension_loaded( 'openssl' ) ? OPENSSL_VERSION_TEXT : __( 'missing — TLS and SSL will not work', 'lynbro-smtp-mailer' ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'PHP', 'lynbro-smtp-mailer' ); ?></th><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Other mail plugins', 'lynbro-smtp-mailer' ); ?></th><td><?php echo esc_html( lynbro_smtp_conflicts() ? implode( ', ', lynbro_smtp_conflicts() ) : __( 'none found', 'lynbro-smtp-mailer' ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Scheduled cleanup', 'lynbro-smtp-mailer' ); ?></th><td><?php echo esc_html( wp_next_scheduled( 'lynbro_smtp_cleanup' ) ? __( 'scheduled', 'lynbro-smtp-mailer' ) : __( 'not scheduled — deactivate and activate the plugin', 'lynbro-smtp-mailer' ) ); ?></td></tr>
		</table>
	</div>
	<?php
}
