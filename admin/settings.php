<?php
/**
 * Admin page (Lynbro → SMTP).
 *
 * Tabs: Settings · Test & diagnostics · Email log · Tools · Feedback · About.
 * The Settings API handles nonce and sanitizing of the settings form; every
 * other action is an admin-post or AJAX handler with its own nonce and a
 * manage_options check.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the setting.
 *
 * @return void
 */
function lynbro_smtp_register_settings() {
	register_setting(
		'lynbro_smtp',
		LYNBRO_SMTP_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'lynbro_smtp_sanitize_options',
			'default'           => lynbro_smtp_default_options(),
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', 'lynbro_smtp_register_settings' );

/**
 * Attach the page under the shared Lynbro menu.
 *
 * @return void
 */
function lynbro_smtp_add_page() {
	Lynbro_Admin_Menu::add(
		array(
			'slug'       => 'lynbro-smtp',
			'page_title' => __( 'SMTP', 'lynbro-smtp-mailer' ),
			'menu_title' => __( 'SMTP', 'lynbro-smtp-mailer' ),
			'capability' => 'manage_options',
			'callback'   => 'lynbro_smtp_render_page',
		)
	);
}
add_action( 'admin_menu', 'lynbro_smtp_add_page' );

/**
 * Admin assets, on our page only.
 *
 * @param string $hook Admin page hook suffix.
 * @return void
 */
function lynbro_smtp_admin_assets( $hook ) {
	if ( false === strpos( (string) $hook, 'lynbro-smtp' ) ) {
		return;
	}
	wp_enqueue_style( 'lynbro-smtp-admin', LYNBRO_SMTP_URL . 'admin/admin.css', array(), LYNBRO_SMTP_VERSION );
	wp_enqueue_script( 'lynbro-smtp-admin', LYNBRO_SMTP_URL . 'admin/smtp-admin.js', array(), LYNBRO_SMTP_VERSION, true );

	$presets = array();
	foreach ( lynbro_smtp_providers() as $id => $p ) {
		$presets[ $id ] = array(
			'host' => (string) $p['host'],
			'port' => (int) $p['port'],
			'enc'  => (string) $p['enc'],
			'hint' => (string) $p['hint'],
		);
	}
	wp_localize_script(
		'lynbro-smtp-admin',
		'lynbroSmtp',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'lynbro_smtp_admin' ),
			'presets' => $presets,
			'i18n'    => array(
				'sending'  => __( 'Sending…', 'lynbro-smtp-mailer' ),
				'checking' => __( 'Checking…', 'lynbro-smtp-mailer' ),
				'sent'     => __( 'Sent. Check the inbox (and the spam folder) of the recipient.', 'lynbro-smtp-mailer' ),
				'failed'   => __( 'Not sent.', 'lynbro-smtp-mailer' ),
				'open'     => __( 'open', 'lynbro-smtp-mailer' ),
				'closed'   => __( 'blocked', 'lynbro-smtp-mailer' ),
				'network'  => __( 'Request failed:', 'lynbro-smtp-mailer' ),
				'via'      => __( 'Delivered through:', 'lynbro-smtp-mailer' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'lynbro_smtp_admin_assets' );

/**
 * Current tab.
 *
 * @return string
 */
function lynbro_smtp_current_tab() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
	return in_array( $tab, array( 'settings', 'test', 'log', 'tools', 'feedback', 'about' ), true ) ? $tab : 'settings';
}

/**
 * Render the page.
 *
 * @return void
 */
function lynbro_smtp_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'lynbro-smtp-mailer' ) );
	}
	$tab  = lynbro_smtp_current_tab();
	$base = admin_url( 'admin.php?page=lynbro-smtp' );
	$tabs = array(
		'settings' => __( 'Settings', 'lynbro-smtp-mailer' ),
		'test'     => __( 'Test & diagnostics', 'lynbro-smtp-mailer' ),
		'log'      => __( 'Email log', 'lynbro-smtp-mailer' ),
		'tools'    => __( 'Tools', 'lynbro-smtp-mailer' ),
		'feedback' => __( 'Feedback', 'lynbro-smtp-mailer' ),
		'about'    => __( 'About', 'lynbro-smtp-mailer' ),
	);
	?>
	<div class="wrap lynbro-wrap">
		<div class="lynbro-hero">
			<div>
				<h1><?php esc_html_e( 'Lynbro SMTP', 'lynbro-smtp-mailer' ); ?></h1>
				<p><?php esc_html_e( 'Send every WordPress email through your own SMTP server — with an email log, resend, a backup connection and failure alerts. All free, no upsells.', 'lynbro-smtp-mailer' ); ?></p>
			</div>
		</div>

		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php
		lynbro_smtp_render_flash();
		switch ( $tab ) {
			case 'test':
				lynbro_smtp_render_tab_test();
				break;
			case 'log':
				lynbro_smtp_render_tab_log();
				break;
			case 'tools':
				lynbro_smtp_render_tab_tools();
				break;
			case 'feedback':
				lynbro_smtp_render_tab_feedback();
				break;
			case 'about':
				lynbro_smtp_render_tab_about();
				break;
			default:
				lynbro_smtp_render_tab_settings();
		}
		lynbro_smtp_render_review_prompt();
		?>
	</div>
	<?php
}

/**
 * One-line result of the last admin-post action (?lynbro_smtp_msg=code).
 *
 * @return void
 */
function lynbro_smtp_render_flash() {
	settings_errors( LYNBRO_SMTP_OPTION );
	lynbro_smtp_render_guard_notices();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only message code.
	$code     = isset( $_GET['lynbro_smtp_msg'] ) ? sanitize_key( wp_unslash( $_GET['lynbro_smtp_msg'] ) ) : '';
	$messages = array(
		'resent'      => array( 'success', __( 'The email was sent again.', 'lynbro-smtp-mailer' ) ),
		'resend_fail' => array( 'error', __( 'The email could not be sent again — see the newest log entry for the reason.', 'lynbro-smtp-mailer' ) ),
		'no_content'  => array( 'warning', __( 'The content of this email was not kept, so it cannot be resent.', 'lynbro-smtp-mailer' ) ),
		'deleted'     => array( 'success', __( 'Log entries deleted.', 'lynbro-smtp-mailer' ) ),
		'imported'    => array( 'success', __( 'Settings imported. Enter the SMTP password once and save — passwords are never copied from other plugins.', 'lynbro-smtp-mailer' ) ),
	);
	if ( isset( $messages[ $code ] ) ) {
		printf( '<div class="notice notice-%1$s inline lynbro-smtp-notice"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
	}
}

/**
 * Name attribute for a setting.
 *
 * @param string $key Setting key.
 * @return string
 */
function lynbro_smtp_name( $key ) {
	return LYNBRO_SMTP_OPTION . '[' . $key . ']';
}

/**
 * "Set in wp-config.php" badge, when a constant fixes the field.
 *
 * @param string $key Setting key.
 * @return void
 */
function lynbro_smtp_constant_badge( $key ) {
	if ( lynbro_smtp_is_constant( $key ) ) {
		$map = lynbro_smtp_constant_map();
		printf(
			' <span class="lynbro-badge installed" title="%1$s">%2$s</span>',
			esc_attr( $map[ $key ] ),
			esc_html__( 'Set in wp-config.php', 'lynbro-smtp-mailer' )
		);
	}
}

/**
 * Settings tab.
 *
 * @return void
 */
function lynbro_smtp_render_tab_settings() {
	$o         = lynbro_smtp_get_options();
	$providers = lynbro_smtp_providers();
	$conflicts = lynbro_smtp_conflicts();
	$imports   = lynbro_smtp_import_sources();
	$last_fail = lynbro_smtp_state( 'last_failure' );
	$ro        = static function ( $key ) {
		return lynbro_smtp_is_constant( $key ) ? ' readonly' : '';
	};
	$hint      = isset( $providers[ $o['provider'] ] ) ? (string) $providers[ $o['provider'] ]['hint'] : '';
	?>
	<div class="lynbro-section lynbro-smtp-status">
		<h2><?php esc_html_e( 'Status', 'lynbro-smtp-mailer' ); ?></h2>
		<ul class="lynbro-smtp-checks">
			<?php if ( lynbro_smtp_uses_php_mail( $o ) ) : ?>
				<li class="ok">
					<?php
					/* translators: %s: From email address. */
					printf( esc_html__( 'Email is sent with this server\'s own mail (PHP mail()), from %s, with a matching Return-Path.', 'lynbro-smtp-mailer' ), '<code>' . esc_html( is_email( $o['from_email'] ) ? $o['from_email'] : (string) get_option( 'admin_email' ) ) . '</code>' );
					?>
				</li>
				<?php if ( empty( $o['return_path'] ) ) : ?>
					<li class="warn"><?php esc_html_e( 'Return-Path is off: the server puts its own address on the envelope, so SPF checks that domain instead of yours.', 'lynbro-smtp-mailer' ); ?></li>
				<?php endif; ?>
			<?php elseif ( lynbro_smtp_is_configured( $o ) ) : ?>
				<li class="ok">
					<?php
					/* translators: 1: host, 2: port. */
					printf( esc_html__( 'Email is sent through %1$s on port %2$s.', 'lynbro-smtp-mailer' ), '<code>' . esc_html( $o['host'] ) . '</code>', '<code>' . esc_html( (string) $o['port'] ) . '</code>' );
					?>
				</li>
			<?php else : ?>
				<li class="warn"><?php esc_html_e( 'No SMTP server set yet — WordPress still sends with PHP mail(). Pick your provider below, fill in the login and save.', 'lynbro-smtp-mailer' ); ?></li>
			<?php endif; ?>
			<?php if ( 'block' === $o['mode'] ) : ?>
				<li class="warn"><?php esc_html_e( 'Delivery is switched off: emails are logged but not sent.', 'lynbro-smtp-mailer' ); ?></li>
			<?php elseif ( 'redirect' === $o['mode'] ) : ?>
				<li class="warn">
					<?php
					/* translators: %s: email address. */
					printf( esc_html__( 'Test mode: every email goes to %s instead of its recipients.', 'lynbro-smtp-mailer' ), '<code>' . esc_html( $o['redirect_to'] ) . '</code>' );
					?>
				</li>
			<?php endif; ?>
			<?php if ( lynbro_smtp_password_broken() ) : ?>
				<li class="bad"><?php esc_html_e( 'The saved password can no longer be read because the site\'s security keys changed. Enter it again and save.', 'lynbro-smtp-mailer' ); ?></li>
			<?php endif; ?>
			<?php if ( is_array( $last_fail ) && ( time() - (int) $last_fail['time'] ) < DAY_IN_SECONDS ) : ?>
				<li class="bad">
					<?php
					/* translators: 1: time ago, 2: error message. */
					printf( esc_html__( 'Last failure %1$s ago: %2$s', 'lynbro-smtp-mailer' ), esc_html( human_time_diff( (int) $last_fail['time'] ) ), esc_html( (string) $last_fail['error'] ) );
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=lynbro-smtp&tab=log&status=failed' ) ); ?>"><?php esc_html_e( 'Show failed emails', 'lynbro-smtp-mailer' ); ?></a>
				</li>
			<?php endif; ?>
			<?php if ( $conflicts ) : ?>
				<li class="warn">
					<?php
					/* translators: %s: list of plugin names. */
					printf( esc_html__( 'Also active: %s. Two mail plugins fight over the same email — deactivate the other one.', 'lynbro-smtp-mailer' ), esc_html( implode( ', ', $conflicts ) ) );
					?>
				</li>
			<?php endif; ?>
		</ul>
		<?php if ( $imports && ! lynbro_smtp_is_configured( $o ) ) : ?>
			<p class="lynbro-tools-row">
				<?php foreach ( $imports as $id => $src ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lynbro_smtp_import&source=' . rawurlencode( $id ) ), 'lynbro_smtp_import' ) ); ?>">
						<?php
						/* translators: %s: plugin name. */
						printf( esc_html__( 'Import settings from %s', 'lynbro-smtp-mailer' ), esc_html( $src['label'] ) );
						?>
					</a>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>
	</div>

	<form action="options.php" method="post" class="lynbro-smtp-form" autocomplete="off">
		<?php settings_fields( 'lynbro_smtp' ); ?>

		<div class="lynbro-section">
			<h2><?php esc_html_e( 'SMTP server', 'lynbro-smtp-mailer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="lynbro-smtp-provider"><?php esc_html_e( 'Provider', 'lynbro-smtp-mailer' ); ?></label></th>
					<td>
						<select id="lynbro-smtp-provider" name="<?php echo esc_attr( lynbro_smtp_name( 'provider' ) ); ?>">
							<?php foreach ( $providers as $id => $p ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $o['provider'], $id ); ?>><?php echo esc_html( $p['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description" id="lynbro-smtp-provider-hint"><?php echo esc_html( $hint ); ?></p>
					</td>
				</tr>
				<tr class="lynbro-smtp-conn"<?php echo esc_attr( lynbro_smtp_uses_php_mail( $o ) ? ' hidden' : '' ); ?>>
					<th scope="row"><label for="lynbro-smtp-host"><?php esc_html_e( 'Host', 'lynbro-smtp-mailer' ); ?></label><?php lynbro_smtp_constant_badge( 'host' ); ?></th>
					<td><input type="text" id="lynbro-smtp-host" class="regular-text" name="<?php echo esc_attr( lynbro_smtp_name( 'host' ) ); ?>" value="<?php echo esc_attr( $o['host'] ); ?>" placeholder="smtp.example.com"<?php echo esc_attr( $ro( 'host' ) ); ?>></td>
				</tr>
				<tr class="lynbro-smtp-conn"<?php echo esc_attr( lynbro_smtp_uses_php_mail( $o ) ? ' hidden' : '' ); ?>>
					<th scope="row"><label for="lynbro-smtp-port"><?php esc_html_e( 'Port and encryption', 'lynbro-smtp-mailer' ); ?></label><?php lynbro_smtp_constant_badge( 'port' ); ?></th>
					<td>
						<input type="number" id="lynbro-smtp-port" class="small-text" min="1" max="65535" name="<?php echo esc_attr( lynbro_smtp_name( 'port' ) ); ?>" value="<?php echo esc_attr( (string) $o['port'] ); ?>"<?php echo esc_attr( $ro( 'port' ) ); ?>>
						<select id="lynbro-smtp-enc" name="<?php echo esc_attr( lynbro_smtp_name( 'encryption' ) ); ?>" aria-label="<?php esc_attr_e( 'Encryption', 'lynbro-smtp-mailer' ); ?>">
							<option value="tls" <?php selected( $o['encryption'], 'tls' ); ?>><?php esc_html_e( 'TLS (STARTTLS) — usually port 587', 'lynbro-smtp-mailer' ); ?></option>
							<option value="ssl" <?php selected( $o['encryption'], 'ssl' ); ?>><?php esc_html_e( 'SSL — usually port 465', 'lynbro-smtp-mailer' ); ?></option>
							<option value="none" <?php selected( $o['encryption'], 'none' ); ?>><?php esc_html_e( 'None — only for a server on the same network', 'lynbro-smtp-mailer' ); ?></option>
						</select>
					</td>
				</tr>
				<tr class="lynbro-smtp-conn"<?php echo esc_attr( lynbro_smtp_uses_php_mail( $o ) ? ' hidden' : '' ); ?>>
					<th scope="row"><?php esc_html_e( 'Authentication', 'lynbro-smtp-mailer' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( lynbro_smtp_name( 'auth' ) ); ?>" value="1" <?php checked( ! empty( $o['auth'] ) ); ?>> <?php esc_html_e( 'The server needs a username and password (almost always)', 'lynbro-smtp-mailer' ); ?></label></td>
				</tr>
				<tr class="lynbro-smtp-conn"<?php echo esc_attr( lynbro_smtp_uses_php_mail( $o ) ? ' hidden' : '' ); ?>>
					<th scope="row"><label for="lynbro-smtp-user"><?php esc_html_e( 'Username', 'lynbro-smtp-mailer' ); ?></label><?php lynbro_smtp_constant_badge( 'username' ); ?></th>
					<td><input type="text" id="lynbro-smtp-user" class="regular-text" name="<?php echo esc_attr( lynbro_smtp_name( 'username' ) ); ?>" value="<?php echo esc_attr( $o['username'] ); ?>" autocomplete="off"<?php echo esc_attr( $ro( 'username' ) ); ?>></td>
				</tr>
				<tr class="lynbro-smtp-conn"<?php echo esc_attr( lynbro_smtp_uses_php_mail( $o ) ? ' hidden' : '' ); ?>>
					<th scope="row"><label for="lynbro-smtp-pass"><?php esc_html_e( 'Password', 'lynbro-smtp-mailer' ); ?></label><?php lynbro_smtp_constant_badge( 'password' ); ?></th>
					<td>
						<?php if ( lynbro_smtp_is_constant( 'password' ) ) : ?>
							<p class="description"><?php esc_html_e( 'The password comes from LYNBRO_SMTP_PASS in wp-config.php and is not stored in the database.', 'lynbro-smtp-mailer' ); ?></p>
						<?php else : ?>
							<input type="password" id="lynbro-smtp-pass" class="regular-text" name="<?php echo esc_attr( lynbro_smtp_name( 'password_new' ) ); ?>" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== (string) $o['password'] ? __( 'Saved — leave empty to keep it', 'lynbro-smtp-mailer' ) : '' ); ?>">
							<?php if ( '' !== (string) $o['password'] ) : ?>
								<label class="lynbro-smtp-inline"><input type="checkbox" name="<?php echo esc_attr( lynbro_smtp_name( 'password_clear' ) ); ?>" value="1"> <?php esc_html_e( 'Remove the saved password', 'lynbro-smtp-mailer' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Stored encrypted with your site\'s security keys and never shown again. To keep it out of the database entirely, define it in wp-config.php (see About).', 'lynbro-smtp-mailer' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr class="lynbro-smtp-conn"<?php echo esc_attr( lynbro_smtp_uses_php_mail( $o ) ? ' hidden' : '' ); ?>>
					<th scope="row"><?php esc_html_e( 'Certificate', 'lynbro-smtp-mailer' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( lynbro_smtp_name( 'verify_peer' ) ); ?>" value="1" <?php checked( ! empty( $o['verify_peer'] ) ); ?>> <?php esc_html_e( 'Verify the server certificate', 'lynbro-smtp-mailer' ); ?></label>
						<span class="lynbro-badge"><?php esc_html_e( 'Default', 'lynbro-smtp-mailer' ); ?></span>
						<p class="description"><?php esc_html_e( 'Untick only for your own server with a self-signed certificate.', 'lynbro-smtp-mailer' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="lynbro-section">
			<h2><?php esc_html_e( 'Sender', 'lynbro-smtp-mailer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="lynbro-smtp-from"><?php esc_html_e( 'From email', 'lynbro-smtp-mailer' ); ?></label><?php lynbro_smtp_constant_badge( 'from_email' ); ?></th>
					<td>
						<input type="email" id="lynbro-smtp-from" class="regular-text" name="<?php echo esc_attr( lynbro_smtp_name( 'from_email' ) ); ?>" value="<?php echo esc_attr( $o['from_email'] ); ?>"<?php echo esc_attr( $ro( 'from_email' ) ); ?>>
						<p><label><input type="checkbox" name="<?php echo esc_attr( lynbro_smtp_name( 'force_from_email' ) ); ?>" value="1" <?php checked( ! empty( $o['force_from_email'] ) ); ?>> <?php esc_html_e( 'Force this address on every email, whatever other plugins set', 'lynbro-smtp-mailer' ); ?></label> <span class="lynbro-badge"><?php esc_html_e( 'Recommended', 'lynbro-smtp-mailer' ); ?></span></p>
						<p class="description"><?php esc_html_e( 'Use an address on a domain your SMTP account may send for, or providers reject or spam-folder the email.', 'lynbro-smtp-mailer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lynbro-smtp-from-name"><?php esc_html_e( 'From name', 'lynbro-smtp-mailer' ); ?></label><?php lynbro_smtp_constant_badge( 'from_name' ); ?></th>
					<td>
						<input type="text" id="lynbro-smtp-from-name" class="regular-text" name="<?php echo esc_attr( lynbro_smtp_name( 'from_name' ) ); ?>" value="<?php echo esc_attr( $o['from_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"<?php echo esc_attr( $ro( 'from_name' ) ); ?>>
						<p><label><input type="checkbox" name="<?php echo esc_attr( lynbro_smtp_name( 'force_from_name' ) ); ?>" value="1" <?php checked( ! empty( $o['force_from_name'] ) ); ?>> <?php esc_html_e( 'Force this name too (off: forms and shops keep their own sender name)', 'lynbro-smtp-mailer' ); ?></label></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lynbro-smtp-reply"><?php esc_html_e( 'Default Reply-To', 'lynbro-smtp-mailer' ); ?></label></th>
					<td>
						<input type="email" id="lynbro-smtp-reply" class="regular-text" name="<?php echo esc_attr( lynbro_smtp_name( 'reply_to' ) ); ?>" value="<?php echo esc_attr( $o['reply_to'] ); ?>">
						<p class="description"><?php esc_html_e( 'Used only when an email has no Reply-To of its own, so contact-form replies still go to the visitor.', 'lynbro-smtp-mailer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Return-Path', 'lynbro-smtp-mailer' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( lynbro_smtp_name( 'return_path' ) ); ?>" value="1" <?php checked( ! empty( $o['return_path'] ) ); ?>> <?php esc_html_e( 'Send bounces to the From address', 'lynbro-smtp-mailer' ); ?></label> <span class="lynbro-badge"><?php esc_html_e( 'Default', 'lynbro-smtp-mailer' ); ?></span></td>
				</tr>
			</table>
		</div>

		<div class="lynbro-section">
			<h2><?php esc_html_e( 'Backup connection', 'lynbro-smtp-mailer' ); ?></h2>
			<p class="desc"><?php esc_html_e( 'When the main server fails, the same email is sent again through the backup at once — the visitor never sees an error.', 'lynbro-smtp-mailer' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Backup', 'lynbro-smtp-mailer' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( lynbro_smtp_name( 'backup_enabled' ) ); ?>" value="1" <?php checked( ! empty( $o['backup_enabled'] ) ); ?>> <?php esc_html_e( 'Use a backup when the main server fails', 'lynbro-smtp-mailer' ); ?></label>
						<p>
							<label><input type="radio" name="<?php echo esc_attr( lynbro_smtp_name( 'backup_type' ) ); ?>" value="smtp" <?php checked( $o['backup_type'], 'smtp' ); ?>> <?php esc_html_e( 'Another SMTP server', 'lynbro-smtp-mailer' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( lynbro_smtp_name( 'backup_type' ) ); ?>" value="php" <?php checked( $o['backup_type'], 'php' ); ?>> <?php esc_html_e( 'PHP mail() of this server (better than nothing; may land in spam)', 'lynbro-smtp-mailer' ); ?></label>
						</p>
					</td>
				</tr>
				<tr class="lynbro-smtp-backup-smtp">
					<th scope="row"><label for="lynbro-smtp-bhost"><?php esc_html_e( 'Backup host', 'lynbro-smtp-mailer' ); ?></label></th>
					<td>
						<input type="text" id="lynbro-smtp-bhost" class="regular-text" name="<?php echo esc_attr( lynbro_smtp_name( 'backup_host' ) ); ?>" value="<?php echo esc_attr( $o['backup_host'] ); ?>">
						<input type="number" class="small-text" min="1" max="65535" name="<?php echo esc_attr( lynbro_smtp_name( 'backup_port' ) ); ?>" value="<?php echo esc_attr( (string) $o['backup_port'] ); ?>" aria-label="<?php esc_attr_e( 'Backup port', 'lynbro-smtp-mailer' ); ?>">
						<select name="<?php echo esc_attr( lynbro_smtp_name( 'backup_enc' ) ); ?>" aria-label="<?php esc_attr_e( 'Backup encryption', 'lynbro-smtp-mailer' ); ?>">
							<option value="tls" <?php selected( $o['backup_enc'], 'tls' ); ?>>TLS</option>
							<option value="ssl" <?php selected( $o['backup_enc'], 'ssl' ); ?>>SSL</option>
							<option value="none" <?php selected( $o['backup_enc'], 'none' ); ?>><?php esc_html_e( 'None', 'lynbro-smtp-mailer' ); ?></option>
						</select>
					</td>
				</tr>
				<tr class="lynbro-smtp-backup-smtp">
					<th scope="row"><label for="lynbro-smtp-buser"><?php esc_html_e( 'Backup login', 'lynbro-smtp-mailer' ); ?></label></th>
					<td>
						<input type="text" id="lynbro-smtp-buser" class="regular-text" name="<?php echo esc_attr( lynbro_smtp_name( 'backup_username' ) ); ?>" value="<?php echo esc_attr( $o['backup_username'] ); ?>" placeholder="<?php esc_attr_e( 'Username', 'lynbro-smtp-mailer' ); ?>" autocomplete="off">
						<input type="password" class="regular-text" name="<?php echo esc_attr( lynbro_smtp_name( 'backup_password_new' ) ); ?>" value="" autocomplete="new-password" aria-label="<?php esc_attr_e( 'Backup password', 'lynbro-smtp-mailer' ); ?>" placeholder="<?php echo esc_attr( '' !== (string) $o['backup_password'] ? __( 'Saved — leave empty to keep it', 'lynbro-smtp-mailer' ) : __( 'Password', 'lynbro-smtp-mailer' ) ); ?>">
						<?php if ( '' !== (string) $o['backup_password'] ) : ?>
							<label class="lynbro-smtp-inline"><input type="checkbox" name="<?php echo esc_attr( lynbro_smtp_name( 'backup_password_clear' ) ); ?>" value="1"> <?php esc_html_e( 'Remove the saved password', 'lynbro-smtp-mailer' ); ?></label>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<div class="lynbro-section">
			<h2><?php esc_html_e( 'Delivery mode', 'lynbro-smtp-mailer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Mode', 'lynbro-smtp-mailer' ); ?></th>
					<td>
						<label><input type="radio" name="<?php echo esc_attr( lynbro_smtp_name( 'mode' ) ); ?>" value="send" <?php checked( $o['mode'], 'send' ); ?>> <?php esc_html_e( 'Send normally', 'lynbro-smtp-mailer' ); ?></label> <span class="lynbro-badge"><?php esc_html_e( 'Default', 'lynbro-smtp-mailer' ); ?></span><br>
						<label><input type="radio" name="<?php echo esc_attr( lynbro_smtp_name( 'mode' ) ); ?>" value="redirect" <?php checked( $o['mode'], 'redirect' ); ?>> <?php esc_html_e( 'Test mode: send everything to one address (Cc and Bcc are dropped)', 'lynbro-smtp-mailer' ); ?></label>
						<input type="email" class="regular-text" name="<?php echo esc_attr( lynbro_smtp_name( 'redirect_to' ) ); ?>" value="<?php echo esc_attr( $o['redirect_to'] ); ?>" aria-label="<?php esc_attr_e( 'Redirect address', 'lynbro-smtp-mailer' ); ?>" placeholder="you@example.com"><br>
						<label><input type="radio" name="<?php echo esc_attr( lynbro_smtp_name( 'mode' ) ); ?>" value="block" <?php checked( $o['mode'], 'block' ); ?>> <?php esc_html_e( 'Do not send any email (staging copies) — emails are still logged', 'lynbro-smtp-mailer' ); ?></label>
					</td>
				</tr>
			</table>
		</div>

		<div class="lynbro-section">
			<h2><?php esc_html_e( 'Email log', 'lynbro-smtp-mailer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Log', 'lynbro-smtp-mailer' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( lynbro_smtp_name( 'log_enabled' ) ); ?>" value="1" <?php checked( ! empty( $o['log_enabled'] ) ); ?>> <?php esc_html_e( 'Keep a log of every email (recipient, subject, status, error)', 'lynbro-smtp-mailer' ); ?></label> <span class="lynbro-badge"><?php esc_html_e( 'Default', 'lynbro-smtp-mailer' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Keep message content', 'lynbro-smtp-mailer' ); ?></th>
					<td>
						<label><input type="radio" name="<?php echo esc_attr( lynbro_smtp_name( 'keep_content' ) ); ?>" value="failed" <?php checked( $o['keep_content'], 'failed' ); ?>> <?php esc_html_e( 'Only of failed emails, so they can be resent', 'lynbro-smtp-mailer' ); ?></label> <span class="lynbro-badge"><?php esc_html_e( 'Default', 'lynbro-smtp-mailer' ); ?></span><br>
						<label><input type="radio" name="<?php echo esc_attr( lynbro_smtp_name( 'keep_content' ) ); ?>" value="all" <?php checked( $o['keep_content'], 'all' ); ?>> <?php esc_html_e( 'Of every email (lets you resend anything; the log then holds password-reset links and order details)', 'lynbro-smtp-mailer' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( lynbro_smtp_name( 'keep_content' ) ); ?>" value="none" <?php checked( $o['keep_content'], 'none' ); ?>> <?php esc_html_e( 'Never', 'lynbro-smtp-mailer' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lynbro-smtp-ret"><?php esc_html_e( 'Keep entries for', 'lynbro-smtp-mailer' ); ?></label></th>
					<td><input type="number" id="lynbro-smtp-ret" class="small-text" min="1" max="365" name="<?php echo esc_attr( lynbro_smtp_name( 'retention_days' ) ); ?>" value="<?php echo esc_attr( (string) $o['retention_days'] ); ?>"> <?php esc_html_e( 'days, then they are deleted automatically', 'lynbro-smtp-mailer' ); ?></td>
				</tr>
			</table>
		</div>

		<div class="lynbro-section">
			<h2><?php esc_html_e( 'Failure alerts', 'lynbro-smtp-mailer' ); ?></h2>
			<p class="desc"><?php esc_html_e( 'At most one alert per hour. The alert email is sent with PHP mail() of this server, because the SMTP server may be the thing that is down.', 'lynbro-smtp-mailer' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="lynbro-smtp-alert"><?php esc_html_e( 'Alert email', 'lynbro-smtp-mailer' ); ?></label></th>
					<td><input type="email" id="lynbro-smtp-alert" class="regular-text" name="<?php echo esc_attr( lynbro_smtp_name( 'alert_email' ) ); ?>" value="<?php echo esc_attr( $o['alert_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="lynbro-smtp-hook"><?php esc_html_e( 'Webhook URL', 'lynbro-smtp-mailer' ); ?></label></th>
					<td>
						<input type="url" id="lynbro-smtp-hook" class="regular-text" name="<?php echo esc_attr( lynbro_smtp_name( 'alert_webhook' ) ); ?>" value="<?php echo esc_attr( $o['alert_webhook'] ); ?>" placeholder="https://hooks.slack.com/…">
						<p class="description"><?php esc_html_e( 'Optional. A Slack or Discord incoming webhook, or your own HTTPS endpoint (JSON with "text" and "content"). The plugin posts the error text there — nothing else, and only when you set a URL.', 'lynbro-smtp-mailer' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button(); ?>
	</form>
	<?php
}

/**
 * Tools tab: import, export/reset (shared shell), log maintenance.
 *
 * @return void
 */
function lynbro_smtp_render_tab_tools() {
	$imports = lynbro_smtp_import_sources();
	?>
	<div class="lynbro-section">
		<h2><?php esc_html_e( 'Import from another SMTP plugin', 'lynbro-smtp-mailer' ); ?></h2>
		<?php if ( $imports ) : ?>
			<p class="desc"><?php esc_html_e( 'Copies host, port, encryption, username and sender. Passwords stay where they are — enter yours once after importing.', 'lynbro-smtp-mailer' ); ?></p>
			<p class="lynbro-tools-row">
				<?php foreach ( $imports as $id => $src ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lynbro_smtp_import&source=' . rawurlencode( $id ) ), 'lynbro_smtp_import' ) ); ?>">
						<?php
						/* translators: %s: plugin name. */
						printf( esc_html__( 'Import from %s', 'lynbro-smtp-mailer' ), esc_html( $src['label'] ) );
						?>
					</a>
				<?php endforeach; ?>
			</p>
		<?php else : ?>
			<p class="desc"><?php esc_html_e( 'No settings of WP Mail SMTP, FluentSMTP, Easy WP SMTP or Post SMTP were found on this site.', 'lynbro-smtp-mailer' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="lynbro-section">
		<h2><?php esc_html_e( 'Email log', 'lynbro-smtp-mailer' ); ?></h2>
		<p class="lynbro-tools-row">
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lynbro_smtp_export_log' ), 'lynbro_smtp_export_log' ) ); ?>"><?php esc_html_e( 'Download the log (CSV)', 'lynbro-smtp-mailer' ); ?></a>
			<a class="button lynbro-smtp-danger" data-confirm="<?php esc_attr_e( 'Delete every log entry?', 'lynbro-smtp-mailer' ); ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lynbro_smtp_delete_log&all=1' ), 'lynbro_smtp_delete_log' ) ); ?>"><?php esc_html_e( 'Empty the log', 'lynbro-smtp-mailer' ); ?></a>
		</p>
	</div>
	<?php
	if ( function_exists( 'lynbro_render_tools_section' ) ) {
		lynbro_render_tools_section( array( LYNBRO_SMTP_OPTION ), 'lynbro-smtp' );
	}
}

/**
 * Feedback tab: links only, nothing is posted from the site.
 *
 * @return void
 */
function lynbro_smtp_render_tab_feedback() {
	?>
	<div class="lynbro-section">
		<h2><?php esc_html_e( 'Is it working for you?', 'lynbro-smtp-mailer' ); ?></h2>
		<p class="desc"><?php esc_html_e( 'Lynbro SMTP is free, with nothing locked. If it got your email delivered, a short review helps other people find it.', 'lynbro-smtp-mailer' ); ?></p>
		<p class="lynbro-tools-row">
			<a class="button button-primary" href="https://wordpress.org/support/plugin/lynbro-smtp-mailer/reviews/#new-post" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Leave a review on WordPress.org', 'lynbro-smtp-mailer' ); ?></a>
		</p>
	</div>
	<div class="lynbro-section">
		<h2><?php esc_html_e( 'Something missing or broken?', 'lynbro-smtp-mailer' ); ?></h2>
		<p class="desc"><?php esc_html_e( 'Bug reports and ideas are read by the people who build the plugin. Opening these links sends nothing from your site.', 'lynbro-smtp-mailer' ); ?></p>
		<p class="lynbro-tools-row">
			<a class="button" href="https://wordpress.org/support/plugin/lynbro-smtp-mailer/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ask in the support forum', 'lynbro-smtp-mailer' ); ?></a>
			<a class="button" href="https://plugins.lynbro.dk/roadmap" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Suggest a feature (public roadmap)', 'lynbro-smtp-mailer' ); ?></a>
		</p>
		<p class="lynbro-muted"><?php esc_html_e( 'Posting a log line? Remove addresses first — the Test tab already hides the password.', 'lynbro-smtp-mailer' ); ?></p>
	</div>
	<?php
}

/**
 * Owner-only guard for admin-post and AJAX handlers.
 *
 * @return void
 */
function lynbro_smtp_require_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'lynbro-smtp-mailer' ), 403 );
	}
}

/**
 * Redirect back to a tab with a message code.
 *
 * @param string $tab  Tab.
 * @param string $code Message code.
 * @return void
 */
function lynbro_smtp_back( $tab, $code ) {
	wp_safe_redirect( admin_url( 'admin.php?page=lynbro-smtp&tab=' . rawurlencode( $tab ) . '&lynbro_smtp_msg=' . rawurlencode( $code ) ) );
	exit;
}

/**
 * Import handler.
 *
 * @return void
 */
function lynbro_smtp_handle_import() {
	lynbro_smtp_require_admin();
	check_admin_referer( 'lynbro_smtp_import' );
	$source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
	lynbro_smtp_import_apply( $source );
	lynbro_smtp_back( 'settings', 'imported' );
}
add_action( 'admin_post_lynbro_smtp_import', 'lynbro_smtp_handle_import' );

/**
 * AJAX: send a test email.
 *
 * @return void
 */
function lynbro_smtp_ajax_test() {
	check_ajax_referer( 'lynbro_smtp_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'lynbro-smtp-mailer' ) ), 403 );
	}
	$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
	if ( ! is_email( $to ) ) {
		wp_send_json_error( array( 'message' => __( 'Enter a valid recipient address.', 'lynbro-smtp-mailer' ) ) );
	}
	$connection = ( isset( $_POST['connection'] ) && 'backup' === $_POST['connection'] ) ? 'backup' : 'primary';
	$res              = lynbro_smtp_send_test( $to, $connection );
	$res['via']       = '' !== $res['via'] ? lynbro_smtp_via_label( $res['via'] ) : '';
	wp_send_json_success( $res );
}
add_action( 'wp_ajax_lynbro_smtp_test', 'lynbro_smtp_ajax_test' );

/**
 * AJAX: check outgoing ports to the saved SMTP host.
 *
 * @return void
 */
function lynbro_smtp_ajax_ports() {
	check_ajax_referer( 'lynbro_smtp_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'lynbro-smtp-mailer' ) ), 403 );
	}
	$opts = lynbro_smtp_get_options();
	$host = (string) $opts['host']; // Only the saved host: this is not a general port scanner.
	$out  = array();
	foreach ( array_unique( array( (int) $opts['port'], 587, 465, 25, 2525 ) ) as $port ) {
		$out[] = array_merge( array( 'port' => $port ), lynbro_smtp_port_check( $host, $port ) );
	}
	wp_send_json_success(
		array(
			'host'  => $host,
			'ports' => $out,
		)
	);
}
add_action( 'wp_ajax_lynbro_smtp_ports', 'lynbro_smtp_ajax_ports' );

/**
 * Keep both passwords out of the shared Tools export: the file is meant to be
 * copied to other sites and pasted into support threads.
 *
 * @return void
 */
function lynbro_smtp_export_without_passwords() {
	add_filter(
		'option_' . LYNBRO_SMTP_OPTION,
		static function ( $value ) {
			if ( is_array( $value ) ) {
				$value['password']        = '';
				$value['backup_password'] = '';
			}
			return $value;
		}
	);
}
add_action( 'admin_post_lynbro_tools_export', 'lynbro_smtp_export_without_passwords', 1 );
