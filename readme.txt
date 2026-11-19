=== Lynbro SMTP Mailer ===
Contributors: lynbro
Tags: smtp, email log, email, wp mail, deliverability
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress email through your own SMTP server, with an email log, resend, a backup connection and failure alerts. All free, no upsells.

== Description ==

By default WordPress sends email with PHP `mail()`, which Gmail, Outlook and most
inboxes treat as suspicious. Password resets, WooCommerce orders and contact-form
messages end up in spam, or never arrive at all.

**Lynbro SMTP** sends every WordPress email through a real, authenticated SMTP
server — your host's, your mailbox's, or a sending service such as Brevo, SendGrid,
Mailgun, Amazon SES or Postmark. And when something goes wrong, you find out, and
the email is not lost.

Everything is in this free plugin. There is no Pro version, no nag screens, no
"upgrade" buttons, no weekly emails from us and no other plugins installed behind
your back.

= Why another SMTP plugin? =

The well-known SMTP plugins either sell the email log, resend, backup connection and alerts as a paid
add-on, or give them away with a large interface built around API mailers and OAuth apps. Lynbro SMTP
Mailer does one thing — plain, authenticated SMTP — and adds what those leave out:

* **Private by default:** the log keeps message content only for failed emails, so password-reset links of a working site never sit in your database (one SMTP plugin's log was readable without login in 2025, CVE-2025-11833).
* **Backup inside the same send:** the visitor's form gets "sent", not an error, while the main server is down.
* **Staging modes:** redirect every email to one address with Cc and Bcc removed, or send nothing and only log — safe copies of a live shop.
* **Plain-language diagnostics:** the SMTP conversation with the password hidden, advice for the usual errors, a port check from your server and a local SPF/DMARC check (no external API).
* **Hosts people actually use in Denmark and the EU:** presets for Simply.com, one.com, IONOS, Brevo, Mailgun EU, Zoho EU next to the global ones.

= What you get =

* **Any SMTP server** — presets for Gmail / Google Workspace, Outlook / Microsoft 365, Brevo, SendGrid, Mailgun, Amazon SES, Postmark, Zoho, Mailjet, SMTP2GO, Simply.com, one.com and IONOS, each with a one-line hint on what the username and password are.
* **Email log** — every email with recipients (including Cc and Bcc), subject, status, the connection that sent it and the exact error. Search, filter by status, export to CSV.
* **Resend** — failed emails keep their content, so one click sends them again.
* **Backup connection** — when the main server fails, the same email goes out at once through a second SMTP server or PHP `mail()`, in the same request. The visitor never sees an error.
* **Failure alerts** — an email (sent with PHP `mail()`, because your SMTP server may be what is down) and/or a webhook (Slack and Discord incoming webhooks, or your own endpoint: JSON with `text` and `content`). At most one per hour.
* **Test & diagnostics** — a test email with the full SMTP conversation (password hidden) and plain-language advice for the usual errors; a check of outgoing ports 587, 465, 25 and 2525; the SPF and DMARC records of your sender domain.
* **Staging modes** — send everything to one address (Cc and Bcc dropped, so real customers never get a copy), or send nothing at all and only log.
* **Sender control** — force the From address (and, separately, the From name), set a default Reply-To that is used only when an email has none, and send bounces to the From address.
* **Credentials in wp-config.php** — define `LYNBRO_SMTP_HOST`, `LYNBRO_SMTP_USER`, `LYNBRO_SMTP_PASS` and friends to keep the login out of the database. Otherwise the password is encrypted with your site's keys.
* **Import** host, port and sender from WP Mail SMTP, FluentSMTP, Easy WP SMTP or Post SMTP in one click.
* **WP-CLI** — `wp lynbro-smtp test`, `log`, `resend`, `purge`, `status`.
* **Site Health** test and a small Dashboard widget with the last seven days.

= Private by default =

* The log keeps message content **only for failed emails** (so they can be resent), unless you choose otherwise. Password-reset links of a working site therefore never sit in the log.
* Entries are deleted after 30 days (you choose 1–365).
* The log is visible only to administrators; every action checks permissions and a security token. There is no public endpoint.
* Log entries are part of **Tools → Export / Erase Personal Data**.
* The plugin contacts no Lynbro service and collects no statistics.

== Installation ==

1. Install from **Plugins → Add New** (search for "Lynbro SMTP Mailer") or upload the ZIP, then activate.
2. Open **Lynbro → SMTP**.
3. Pick your provider, enter the username and password, set the From email, and save.
4. Open **Test & diagnostics** and send a test email.

Switching from another SMTP plugin? Use **Import settings from …** on the Settings tab, enter the password once, then deactivate the old plugin.

== Frequently Asked Questions ==

= Which SMTP provider should I use? =

Your host's mail server or your own mailbox works for small sites. For shops and
anything with more than a few dozen emails a day, a sending service (Brevo,
Mailgun EU, Amazon SES, Postmark, SendGrid) delivers better. All of them work with
plain SMTP.

= Gmail or Microsoft 365 says the password is wrong =

Both need an app password, not your normal password, when two-step sign-in is on.
Microsoft 365 also needs "Authenticated SMTP" enabled for the mailbox, and
Microsoft is retiring password sign-in for SMTP — if it is switched off for your
tenant, use a sending service instead. The Test tab tells you which of these it is.

= Does Lynbro SMTP support Gmail or Microsoft OAuth ("Sign in with Google")? =

No. OAuth needs an app verified by Google or Microsoft, which is the most common
source of breakage in other SMTP plugins. App passwords and SMTP relays do the
same job without it.

= Is my SMTP password safe? =

It is encrypted with libsodium using your site's secret keys, never shown again,
never written to the log and left out of the settings export. For the strongest
setup, define it as `LYNBRO_SMTP_PASS` in `wp-config.php` — then it is not in the
database at all.

= What happens when my SMTP server is down? =

With a backup connection set, the email goes through the backup in the same
request. Without one, it is logged as failed with its content kept, an alert goes
out if you set one up, and you can resend it from the log when the server is back.

= My host blocks port 587 / 465 =

Run **Check ports** on the Test tab. Many sending services also listen on 2525.
Otherwise ask your host to open the port.

= Can I use it on a staging copy without emailing real customers? =

Yes. Choose **Test mode** to send every email to your own address (Cc and Bcc are
removed), or **Do not send any email** to only log them.

= Does it work with WooCommerce and form plugins? =

Yes. Lynbro SMTP works through `wp_mail()`, which WooCommerce, Contact Form 7,
WPForms, Gravity Forms, Fluent Forms and almost every other plugin use. Plugins
with their own built-in SMTP client (some booking plugins) bypass `wp_mail()` and
need their own settings.

= Another SMTP plugin is active — is that a problem? =

Yes: two plugins configuring the same email fight over it. Lynbro SMTP lists the
other mail plugins it finds; deactivate them after importing their settings.

= Is there a paid version? =

No, and there will not be one with locked features. Lynbro makes several free
plugins; paid services, if any, live on our website and are never required.

== Screenshots ==

1. Settings — provider presets, login, sender, backup connection, delivery mode, log and alerts.
2. Test & diagnostics — test email with the SMTP conversation, port check, SPF and DMARC.
3. Email log — status, recipients, connection used, error and one-click resend.
4. Port check from the web server to the SMTP host, with SPF and DMARC of the sender domain.

== External Services ==

Lynbro SMTP connects to **no service run by Lynbro**. It connects only to:

* **The SMTP server(s) you enter** in its settings (main and, if set, backup), to deliver your site's outgoing email. Each email's recipients, headers, content and attachments are sent to that server when WordPress sends the email. Which provider that is, and its terms and privacy policy, is your choice.
* **A webhook URL, only if you enter one** under Failure alerts. When an email fails, the plugin sends the error message, your site name and a link to your email log to that URL. Nothing is sent when the field is empty.

Test emails and alerts are sent only when you or a failure trigger them. The plugin
also reads public DNS records (SPF, DMARC) of your sender domain through your
server's normal DNS resolver when you open the Test tab.

**WordPress.org plugin directory (the shared Lynbro "Our plugins" screen).**
Apart from the above, one request goes to WordPress.org, from every Lynbro plugin
alike: when an administrator who may install plugins opens Lynbro → Our plugins,
the screen asks the WordPress.org Plugin Directory API
(https://api.wordpress.org/plugins/info/1.2/) which Lynbro plugins are listed,
through WordPress core's own `plugins_api()` — the same call the Plugins → Add New
screen makes.

* **What is sent:** the query (plugins by the author "lynbro"), plus what WordPress
  core adds to every request to WordPress.org: your WordPress version, the admin
  language and, in the User-Agent, your site address. Nothing about your visitors,
  users or content.
* **When:** only on that screen, at most once a day (the answer is cached for a day;
  after a failed request, an hour). No other screen and no visitor request triggers it.
* Installing a suggested plugin uses WordPress core's own installer, which downloads
  the plugin from downloads.wordpress.org — only when you click "Install now".
* WordPress.org [Terms of Service](https://wordpress.org/about/terms-of-service/) ·
  [Privacy Policy](https://wordpress.org/about/privacy/)

== Privacy ==

The email log is stored in your own database (table `{prefix}lynbro_smtp_log`):
date, recipients, Cc, Bcc, Reply-To, subject, headers, status, connection, error
and — only for failed emails by default — the message content. Entries are deleted
automatically after the retention period (30 days by default) and on uninstall.
Log entries are included in WordPress' personal data export and erasure tools.

== Changelog ==

= 1.1.6 =
* Multisite: the mail log table is created on a site's first request when it is missing (network activation runs no activation on subsites; a mail sent from a subsite before its admin was opened could not be logged).

= 1.1.5 =
* New choice under Provider: "This server's own mail (PHP mail(), no SMTP)". For servers that send mail themselves: no host or login, but the From address, Return-Path (envelope sender), Reply-To, the log and Site Health still apply, so SPF and DMARC can line up with your domain. The SMTP fields hide while it is selected; Tools says to keep the server in the domain's SPF record.
* The green "sent" label in the email log meets WCAG AA contrast (5.9:1, was 4.4:1).
* Import from WP Mail SMTP now also moves its default "PHP mail" setup, and for every import the "force From email / name" and Return-Path switches.

= 1.1.4 =
* Moving from the previous "Lynbro SMTP" plugin is safe: deleting the old plugin on the Plugins screen ran its uninstall, which erased the SMTP settings both plugins share, so all site mail fell back to PHP mail(). The settings now have a backup (not autoloaded) and are put back the moment another plugin's uninstall deletes them, or on the next admin page load if they vanish while this plugin is active. Your own uninstall of Lynbro SMTP Mailer still removes everything.
* While the old plugin's folder is still on disk, a warning under it on the Plugins screen and on the SMTP screen says to remove the folder with the file manager, FTP or SSH instead of using Delete.

= 1.1.3 =
* When the backup connection delivered an email but the failure alert could not be sent with PHP mail(), Site Health reported "Emails failed to send" with the alert's own error and the real cause, the main SMTP server, was lost. The real failure is kept; Site Health now says separately that alerts by email do not get out on this server and suggests the webhook.

= 1.1.2 =
* Readme: External Services now names the one WordPress.org request the shared "Our plugins" screen makes — what is sent, when, and the WordPress.org terms and privacy policy.
* The plugin names and descriptions on "Our plugins" and in the suggestions are translatable.
* Links on Lynbro screens use the darker brand green, which meets WCAG AA contrast on the admin background.

= 1.1.1 =
* Shared Lynbro admin 1.1.0: after two weeks of use and a first success (for example settings saved), this plugin's own screen asks once, politely and without stars, whether it is doing its job. Answers are per user: "Maybe later" or "Don't ask again", and only one Lynbro plugin asks in any 30 days.
* A small "Suggested for this site" block under the plugin's main screen lists Lynbro plugins that fit what the site already uses (WooCommerce, forms, a Danish setup …), with the reason. It can be hidden; nothing installs on its own and nothing is sent anywhere.
* Tab labels on Lynbro screens meet WCAG AA contrast.
* Lynbro admin screens now read the plugin's bundled translations (languages/) when WordPress has no language pack for it, so a Danish admin sees Danish instead of English.

= 1.1.0 =
* New: email log in its own table — Cc, Bcc, Reply-To, status, connection and error; search, filter, paging, CSV export, bulk delete.
* New: resend failed emails from the log or with WP-CLI.
* New: backup connection (second SMTP server or PHP mail()) used inside the same send.
* New: failure alerts by email and webhook, Site Health test, Dashboard widget.
* New: Test & diagnostics tab — SMTP conversation with hidden credentials, error advice, port check, SPF/DMARC check.
* New: provider presets, wp-config.php constants, separate force-From name, default Reply-To, Return-Path.
* New: staging modes (redirect all email to one address, or do not send).
* New: import from WP Mail SMTP, FluentSMTP, Easy WP SMTP and Post SMTP.
* New: WP-CLI commands; personal data export and erasure.
* Fix: the settings page printed a piece of PHP code as text, and the Tools export did not know the plugin's settings.
* Privacy: message content is kept only for failed emails by default; passwords are left out of the settings export.

= 1.0.0 =
* Initial version: SMTP override, encrypted password storage, force-from, test send, rolling delivery log.

== Upgrade Notice ==

= 1.1.0 =
Email log with resend, backup connection, failure alerts and diagnostics. The old text log is replaced by the new log table.
