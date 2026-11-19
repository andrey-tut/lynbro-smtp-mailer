<?php
/**
 * Provider presets: host, port and encryption for the common SMTP services,
 * plus one line on what the username and password are for each.
 *
 * @package LynbroSMTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Known providers. `user` and `pass` are hints shown under the fields.
 *
 * @return array<string,array<string,mixed>>
 */
function lynbro_smtp_providers() {
	$providers = array(
		'custom'    => array(
			'label' => __( 'Other SMTP server', 'lynbro-smtp-mailer' ),
			'host'  => '',
			'port'  => 587,
			'enc'   => 'tls',
			'hint'  => __( 'Use the SMTP details from your host or email provider.', 'lynbro-smtp-mailer' ),
		),
		'php'       => array(
			'label' => __( 'This server\'s own mail (PHP mail(), no SMTP)', 'lynbro-smtp-mailer' ),
			'host'  => '',
			'port'  => 587,
			'enc'   => 'none',
			'hint'  => __( 'For servers that are set up to send mail themselves. No login needed: the From address and the Return-Path below are still set on every email, so SPF and DMARC can line up. The domain\'s SPF record must include this server.', 'lynbro-smtp-mailer' ),
		),
		'gmail'     => array(
			'label' => 'Gmail / Google Workspace',
			'host'  => 'smtp.gmail.com',
			'port'  => 587,
			'enc'   => 'tls',
			'hint'  => __( 'Username: your full Gmail address. Password: an app password (Google Account → Security → 2-Step Verification → App passwords), not your normal password.', 'lynbro-smtp-mailer' ),
		),
		'outlook'   => array(
			'label' => 'Outlook.com / Microsoft 365',
			'host'  => 'smtp.office365.com',
			'port'  => 587,
			'enc'   => 'tls',
			'hint'  => __( 'Microsoft 365 needs "Authenticated SMTP" enabled for the mailbox. Microsoft is retiring password sign-in for SMTP; if login fails, use a relay service such as Brevo or your host\'s SMTP.', 'lynbro-smtp-mailer' ),
		),
		'brevo'     => array(
			'label' => 'Brevo (Sendinblue)',
			'host'  => 'smtp-relay.brevo.com',
			'port'  => 587,
			'enc'   => 'tls',
			'hint'  => __( 'Username: the SMTP login shown under SMTP & API. Password: an SMTP key, not your account password.', 'lynbro-smtp-mailer' ),
		),
		'sendgrid'  => array(
			'label' => 'SendGrid',
			'host'  => 'smtp.sendgrid.net',
			'port'  => 587,
			'enc'   => 'tls',
			'hint'  => __( 'Username: the word "apikey". Password: your SendGrid API key.', 'lynbro-smtp-mailer' ),
		),
		'mailgun'   => array(
			'label' => 'Mailgun',
			'host'  => 'smtp.mailgun.org',
			'port'  => 587,
			'enc'   => 'tls',
			'hint'  => __( 'EU accounts use smtp.eu.mailgun.org. Username and password: the SMTP credentials of your sending domain.', 'lynbro-smtp-mailer' ),
		),
		'ses'       => array(
			'label' => 'Amazon SES',
			'host'  => '',
			'port'  => 587,
			'enc'   => 'tls',
			'hint'  => __( 'Host: the SMTP endpoint of your SES region, shown in the SES console under SMTP settings. Username and password: SMTP credentials created in the SES console (not your AWS access keys).', 'lynbro-smtp-mailer' ),
		),
		'postmark'  => array(
			'label' => 'Postmark',
			'host'  => 'smtp.postmarkapp.com',
			'port'  => 587,
			'enc'   => 'tls',
			'hint'  => __( 'Username and password: both are your Server API token.', 'lynbro-smtp-mailer' ),
		),
		'zoho'      => array(
			'label' => 'Zoho Mail',
			'host'  => 'smtp.zoho.eu',
			'port'  => 465,
			'enc'   => 'ssl',
			'hint'  => __( 'Use smtp.zoho.com for accounts outside the EU. With two-factor sign-in, create an app-specific password.', 'lynbro-smtp-mailer' ),
		),
		'mailjet'   => array(
			'label' => 'Mailjet',
			'host'  => 'in-v3.mailjet.com',
			'port'  => 587,
			'enc'   => 'tls',
			'hint'  => __( 'Username: API key. Password: secret key.', 'lynbro-smtp-mailer' ),
		),
		'smtp2go'   => array(
			'label' => 'SMTP2GO',
			'host'  => 'mail.smtp2go.com',
			'port'  => 587,
			'enc'   => 'tls',
			'hint'  => __( 'Username and password: an SMTP user created under Sending → SMTP Users.', 'lynbro-smtp-mailer' ),
		),
		'simply'    => array(
			'label' => 'Simply.com',
			'host'  => 'websmtp.simply.com',
			'port'  => 587,
			'enc'   => 'tls',
			'hint'  => __( 'Username: the full mailbox address. Password: the mailbox password.', 'lynbro-smtp-mailer' ),
		),
		'one'       => array(
			'label' => 'one.com',
			'host'  => 'send.one.com',
			'port'  => 465,
			'enc'   => 'ssl',
			'hint'  => __( 'Username: the full mailbox address. Password: the mailbox password.', 'lynbro-smtp-mailer' ),
		),
		'ionos'     => array(
			'label' => 'IONOS',
			'host'  => 'smtp.ionos.com',
			'port'  => 587,
			'enc'   => 'tls',
			'hint'  => __( 'Use smtp.ionos.de or smtp.ionos.co.uk for those regions. Username: the full mailbox address.', 'lynbro-smtp-mailer' ),
		),
	);

	/**
	 * Filter the provider presets.
	 *
	 * @param array<string,array<string,mixed>> $providers Presets keyed by id.
	 */
	return (array) apply_filters( 'lynbro_smtp_providers', $providers );
}
