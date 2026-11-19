<?php
/**
 * Admin — About tab: what the plugin does with data, wp-config constants,
 * WP-CLI, version and links.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the About tab.
 *
 * @return void
 */
function lynbro_smtp_render_tab_about() {
	$config = "define( 'LYNBRO_SMTP_HOST', 'smtp.example.com' );\ndefine( 'LYNBRO_SMTP_PORT', 587 );\ndefine( 'LYNBRO_SMTP_ENCRYPTION', 'tls' ); // tls, ssl or none\ndefine( 'LYNBRO_SMTP_USER', 'you@example.com' );\ndefine( 'LYNBRO_SMTP_PASS', 'app-password' );\ndefine( 'LYNBRO_SMTP_FROM_EMAIL', 'shop@example.com' );\ndefine( 'LYNBRO_SMTP_FROM_NAME', 'My Shop' );";
	$cli    = "wp lynbro-smtp test you@example.com\nwp lynbro-smtp test you@example.com --backup\nwp lynbro-smtp log --status=failed\nwp lynbro-smtp resend 42\nwp lynbro-smtp purge\nwp lynbro-smtp status";
	?>
	<div class="lynbro-section">
		<h2><?php esc_html_e( 'About Lynbro SMTP', 'lynbro-smtp-mailer' ); ?></h2>
		<p class="desc"><?php esc_html_e( 'WordPress sends email with PHP mail() by default, which inboxes treat as suspicious. Lynbro SMTP sends it through a real, authenticated mail server instead — the one of your host, your mailbox or a sending service — so password resets, orders and contact forms arrive.', 'lynbro-smtp-mailer' ); ?></p>
		<p class="desc"><?php esc_html_e( 'Everything is in the free plugin: the log, resend, the backup connection, alerts, diagnostics and WP-CLI. There is no paid version.', 'lynbro-smtp-mailer' ); ?></p>
	</div>

	<div class="lynbro-section">
		<h2><?php esc_html_e( 'How your data is handled', 'lynbro-smtp-mailer' ); ?></h2>
		<ul class="lynbro-smtp-list">
			<li><?php esc_html_e( 'Email goes straight from your server to the SMTP server you set. The plugin contacts no Lynbro service and sends no statistics anywhere.', 'lynbro-smtp-mailer' ); ?></li>
			<li><?php esc_html_e( 'The SMTP password is encrypted with your site\'s security keys, never shown again and never written to the log or the settings export.', 'lynbro-smtp-mailer' ); ?></li>
			<li><?php esc_html_e( 'The log keeps recipients, subject and status in your own database. Message content only for failed emails by default, and every entry is deleted after the retention period.', 'lynbro-smtp-mailer' ); ?></li>
			<li><?php esc_html_e( 'Log entries are included in Tools → Export Personal Data and Erase Personal Data.', 'lynbro-smtp-mailer' ); ?></li>
			<li><?php esc_html_e( 'A failure webhook, if you set one, receives only the error text and a link to your log.', 'lynbro-smtp-mailer' ); ?></li>
		</ul>
	</div>

	<div class="lynbro-section">
		<h2><?php esc_html_e( 'Keep the login in wp-config.php', 'lynbro-smtp-mailer' ); ?></h2>
		<p class="desc"><?php esc_html_e( 'Any of these constants overrides the field in Settings, and the value never reaches the database or its backups:', 'lynbro-smtp-mailer' ); ?></p>
		<pre class="lynbro-smtp-body"><?php echo esc_html( $config ); ?></pre>
	</div>

	<div class="lynbro-section">
		<h2><?php esc_html_e( 'WP-CLI', 'lynbro-smtp-mailer' ); ?></h2>
		<pre class="lynbro-smtp-body"><?php echo esc_html( $cli ); ?></pre>
	</div>

	<div class="lynbro-section">
		<h2><?php esc_html_e( 'Version & links', 'lynbro-smtp-mailer' ); ?></h2>
		<p><?php esc_html_e( 'Version', 'lynbro-smtp-mailer' ); ?> <code><?php echo esc_html( LYNBRO_SMTP_VERSION ); ?></code></p>
		<p class="lynbro-tools-row">
			<a class="button button-primary" href="https://plugins.lynbro.dk/lynbro-smtp" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Plugin home page', 'lynbro-smtp-mailer' ); ?></a>
			<a class="button" href="https://lynbro.dk" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Lynbro', 'lynbro-smtp-mailer' ); ?></a>
		</p>
	</div>
	<?php
}
