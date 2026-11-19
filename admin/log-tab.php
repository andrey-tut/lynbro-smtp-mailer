<?php
/**
 * Admin — Email log tab, and its resend / delete / CSV handlers.
 *
 * Every handler checks manage_options and its own nonce. The log is never
 * reachable without both — a log of emails is a log of password-reset links.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Human label of a status.
 *
 * @param string $status Status code.
 * @return string
 */
function lynbro_smtp_status_label( $status ) {
	$labels = array(
		'sent'    => __( 'Sent', 'lynbro-smtp-mailer' ),
		'failed'  => __( 'Failed', 'lynbro-smtp-mailer' ),
		'blocked' => __( 'Not sent (delivery off)', 'lynbro-smtp-mailer' ),
		'pending' => __( 'Unknown', 'lynbro-smtp-mailer' ),
	);
	return $labels[ $status ] ?? $status;
}

/**
 * Human label of the connection used.
 *
 * @param string $via Connection code.
 * @return string
 */
function lynbro_smtp_via_label( $via ) {
	$labels = array(
		'primary'  => __( 'Main SMTP', 'lynbro-smtp-mailer' ),
		'backup'   => __( 'Backup SMTP', 'lynbro-smtp-mailer' ),
		'php'      => __( 'PHP mail()', 'lynbro-smtp-mailer' ),
		'redirect' => __( 'Test mode (redirected)', 'lynbro-smtp-mailer' ),
		'blocked'  => '—',
	);
	return $labels[ $via ] ?? '—';
}

/**
 * Log tab.
 *
 * @return void
 */
function lynbro_smtp_render_tab_log() {
	$opts = lynbro_smtp_get_options();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	// phpcs:enable
	$per    = 25;
	$res    = lynbro_smtp_log_query(
		array(
			'status'   => $status,
			'search'   => $search,
			'page'     => $paged,
			'per_page' => $per,
		)
	);
	$pages  = max( 1, (int) ceil( $res['total'] / $per ) );
	$base   = admin_url( 'admin.php?page=lynbro-smtp&tab=log' );
	$counts = lynbro_smtp_log_counts( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) );
	?>
	<?php if ( empty( $opts['log_enabled'] ) ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'The log is switched off in Settings — no new emails are recorded.', 'lynbro-smtp-mailer' ); ?></p></div>
	<?php endif; ?>

	<div class="lynbro-smtp-figures">
		<div class="lynbro-smtp-figure"><span class="n"><?php echo esc_html( number_format_i18n( $counts['sent'] ) ); ?></span><span class="l"><?php esc_html_e( 'Sent, 30 days', 'lynbro-smtp-mailer' ); ?></span></div>
		<div class="lynbro-smtp-figure <?php echo $counts['failed'] ? 'is-warn' : ''; ?>"><span class="n"><?php echo esc_html( number_format_i18n( $counts['failed'] ) ); ?></span><span class="l"><?php esc_html_e( 'Failed, 30 days', 'lynbro-smtp-mailer' ); ?></span></div>
		<div class="lynbro-smtp-figure"><span class="n"><?php echo esc_html( number_format_i18n( $counts['blocked'] ) ); ?></span><span class="l"><?php esc_html_e( 'Held back, 30 days', 'lynbro-smtp-mailer' ); ?></span></div>
	</div>

	<form method="get" class="lynbro-smtp-filter">
		<input type="hidden" name="page" value="lynbro-smtp">
		<input type="hidden" name="tab" value="log">
		<select name="status" aria-label="<?php esc_attr_e( 'Status', 'lynbro-smtp-mailer' ); ?>">
			<option value=""><?php esc_html_e( 'All statuses', 'lynbro-smtp-mailer' ); ?></option>
			<?php foreach ( array( 'sent', 'failed', 'blocked' ) as $st ) : ?>
				<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>><?php echo esc_html( lynbro_smtp_status_label( $st ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Recipient, subject or error', 'lynbro-smtp-mailer' ); ?>" aria-label="<?php esc_attr_e( 'Search the log', 'lynbro-smtp-mailer' ); ?>">
		<button class="button"><?php esc_html_e( 'Filter', 'lynbro-smtp-mailer' ); ?></button>
		<?php if ( '' !== $status || '' !== $search ) : ?>
			<a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Clear', 'lynbro-smtp-mailer' ); ?></a>
		<?php endif; ?>
	</form>

	<?php if ( ! $res['rows'] ) : ?>
		<div class="lynbro-section lynbro-smtp-empty">
			<p><?php echo ( '' !== $status || '' !== $search ) ? esc_html__( 'No emails match this filter.', 'lynbro-smtp-mailer' ) : esc_html__( 'No emails logged yet. Send a test from the Test & diagnostics tab to see the first entry here.', 'lynbro-smtp-mailer' ); ?></p>
		</div>
		<?php
		return;
	endif;
	?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="lynbro_smtp_delete_log">
		<?php wp_nonce_field( 'lynbro_smtp_delete_log' ); ?>
		<table class="widefat striped lynbro-smtp-log">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" class="lynbro-smtp-checkall" aria-label="<?php esc_attr_e( 'Select all', 'lynbro-smtp-mailer' ); ?>"></td>
					<th scope="col"><?php esc_html_e( 'Date', 'lynbro-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'lynbro-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'To', 'lynbro-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Subject', 'lynbro-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sent through', 'lynbro-smtp-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'lynbro-smtp-mailer' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $res['rows'] as $row ) : ?>
				<?php
				$id     = (int) $row['id'];
				$when   = get_date_from_gmt( (string) $row['created_at'], 'Y-m-d H:i:s' );
				$resend = wp_nonce_url( admin_url( 'admin-post.php?action=lynbro_smtp_resend&id=' . $id ), 'lynbro_smtp_resend_' . $id );
				?>
				<tr class="status-<?php echo esc_attr( (string) $row['status'] ); ?>">
					<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $id ); ?>" aria-label="<?php esc_attr_e( 'Select', 'lynbro-smtp-mailer' ); ?>"></th>
					<td><?php echo esc_html( $when ); ?></td>
					<td><span class="lynbro-smtp-pill <?php echo esc_attr( (string) $row['status'] ); ?>"><?php echo esc_html( lynbro_smtp_status_label( (string) $row['status'] ) ); ?></span></td>
					<td class="lynbro-smtp-break">
						<?php echo esc_html( (string) $row['to_email'] ); ?>
						<?php if ( '' !== (string) $row['cc'] ) : ?>
							<br><small><?php echo esc_html( 'Cc: ' . $row['cc'] ); ?></small>
						<?php endif; ?>
						<?php if ( '' !== (string) $row['bcc'] ) : ?>
							<br><small><?php echo esc_html( 'Bcc: ' . $row['bcc'] ); ?></small>
						<?php endif; ?>
					</td>
					<td class="lynbro-smtp-break">
						<details>
							<summary><?php echo esc_html( '' !== (string) $row['subject'] ? (string) $row['subject'] : __( '(no subject)', 'lynbro-smtp-mailer' ) ); ?></summary>
							<?php if ( '' !== (string) $row['error'] ) : ?>
								<p class="lynbro-smtp-error"><?php echo esc_html( (string) $row['error'] ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== (string) $row['reply_to'] ) : ?>
								<p><small><?php echo esc_html( 'Reply-To: ' . $row['reply_to'] ); ?></small></p>
							<?php endif; ?>
							<?php if ( '' !== (string) $row['attachments'] ) : ?>
								<p><small>
									<?php
									/* translators: %s: file names. */
									printf( esc_html__( 'Attachments (not kept): %s', 'lynbro-smtp-mailer' ), esc_html( (string) $row['attachments'] ) );
									?>
								</small></p>
							<?php endif; ?>
							<?php if ( (int) $row['resent_of'] ) : ?>
								<p><small>
									<?php
									/* translators: %d: log entry number. */
									printf( esc_html__( 'Resend of entry #%d', 'lynbro-smtp-mailer' ), (int) $row['resent_of'] );
									?>
								</small></p>
							<?php endif; ?>
							<?php if ( (int) $row['has_message'] ) : ?>
								<?php $full = lynbro_smtp_log_get( $id ); ?>
								<pre class="lynbro-smtp-body"><?php echo esc_html( mb_substr( (string) ( $full['message'] ?? '' ), 0, 20000 ) ); ?></pre>
							<?php else : ?>
								<p class="lynbro-muted"><?php esc_html_e( 'Content not kept.', 'lynbro-smtp-mailer' ); ?></p>
							<?php endif; ?>
						</details>
					</td>
					<td><?php echo esc_html( lynbro_smtp_via_label( (string) $row['via'] ) ); ?></td>
					<td>
						<?php if ( (int) $row['has_message'] ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $resend ); ?>"><?php esc_html_e( 'Resend', 'lynbro-smtp-mailer' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="lynbro-tools-row">
			<button class="button" data-confirm="<?php esc_attr_e( 'Delete the selected entries?', 'lynbro-smtp-mailer' ); ?>"><?php esc_html_e( 'Delete selected', 'lynbro-smtp-mailer' ); ?></button>
			<span class="lynbro-muted">
				<?php
				/* translators: 1: number of entries, 2: retention days. */
				printf( esc_html__( '%1$s entries · deleted automatically after %2$s days', 'lynbro-smtp-mailer' ), esc_html( number_format_i18n( $res['total'] ) ), esc_html( (string) $opts['retention_days'] ) );
				?>
			</span>
		</p>
	</form>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
	<?php
}

/**
 * Resend handler.
 *
 * @return void
 */
function lynbro_smtp_handle_resend() {
	lynbro_smtp_require_admin();
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	check_admin_referer( 'lynbro_smtp_resend_' . $id );
	$res = lynbro_smtp_resend( $id );
	if ( is_wp_error( $res ) ) {
		lynbro_smtp_back( 'log', 'lynbro_smtp_no_content' === $res->get_error_code() ? 'no_content' : 'resend_fail' );
	}
	lynbro_smtp_back( 'log', 'resent' );
}
add_action( 'admin_post_lynbro_smtp_resend', 'lynbro_smtp_handle_resend' );

/**
 * Delete handler: selected ids (POST) or everything (?all=1).
 *
 * @return void
 */
function lynbro_smtp_handle_delete() {
	lynbro_smtp_require_admin();
	check_admin_referer( 'lynbro_smtp_delete_log' );
	if ( ! empty( $_GET['all'] ) ) {
		lynbro_smtp_log_delete();
	} else {
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		lynbro_smtp_log_delete( $ids );
	}
	lynbro_smtp_back( 'log', 'deleted' );
}
add_action( 'admin_post_lynbro_smtp_delete_log', 'lynbro_smtp_handle_delete' );

/**
 * CSV export of the log (without message content).
 *
 * @return void
 */
function lynbro_smtp_handle_export() {
	lynbro_smtp_require_admin();
	check_admin_referer( 'lynbro_smtp_export_log' );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="lynbro-smtp-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to the response.
	fputcsv( $out, array( 'id', 'date_utc', 'status', 'via', 'to', 'cc', 'bcc', 'reply_to', 'subject', 'attachments', 'error', 'resent_of' ) );
	$page = 1;
	do {
		$res = lynbro_smtp_log_query(
			array(
				'page'     => $page,
				'per_page' => 500,
			)
		);
		foreach ( $res['rows'] as $r ) {
			$line = array( $r['id'], $r['created_at'], $r['status'], $r['via'], $r['to_email'], $r['cc'], $r['bcc'], $r['reply_to'], $r['subject'], $r['attachments'], $r['error'], $r['resent_of'] );
			// Spreadsheet formula injection: a cell starting with = + - @ is data, not a formula.
			$line = array_map(
				static function ( $v ) {
					$v = (string) $v;
					return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
				},
				$line
			);
			fputcsv( $out, $line );
		}
		++$page;
	} while ( count( $res['rows'] ) === 500 );
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen above.
	exit;
}
add_action( 'admin_post_lynbro_smtp_export_log', 'lynbro_smtp_handle_export' );
