=== MailHook ===
Contributors: ashutosh
Tags: smtp, email, mail, wp_mail, phpmailer
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight SMTP plugin that reconfigures WordPress to send all emails through your SMTP server — reliable, secure, and easy to set up.

== Description ==

**MailHook** fixes WordPress email deliverability by routing all outgoing emails through a proper SMTP server instead of the unreliable PHP `mail()` function.

**Features:**

* Configure any SMTP server (Gmail, Outlook, SendGrid, Amazon SES, etc.)
* TLS / SSL encryption support
* Encrypted password storage (AES-256-CBC)
* Custom From Email and From Name
* One-click test email
* Clean, modern settings page
* Lightweight — no bloat, no upsells

**Quick Setup (Gmail Example):**

1. Install and activate MailHook
2. Go to Settings → MailHook
3. Enter: Host = `smtp.gmail.com`, Port = `587`, Encryption = TLS
4. Enable authentication, enter your Gmail and App Password
5. Save and send a test email

== Installation ==

1. Upload the `mailhook` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → MailHook to configure your SMTP settings

== Frequently Asked Questions ==

= Does this work with Gmail? =

Yes! Use `smtp.gmail.com`, port 587, TLS encryption, and a Google App Password.

= Does this work on localhost? =

Yes! As long as your local machine can reach the internet, it will connect to external SMTP servers (Gmail, SendGrid, etc.) and send emails.

= Is my password stored securely? =

Yes. Passwords are encrypted using AES-256-CBC before being saved to the database.

= Will this override all WordPress emails? =

Yes. MailHook hooks into `phpmailer_init` which intercepts every `wp_mail()` call — including emails from other plugins and WordPress core (password resets, notifications, etc.).

== Changelog ==

= 1.0.0 =
* Initial release
* SMTP configuration (host, port, encryption, authentication)
* Custom From Email and From Name
* Encrypted password storage
* Test email functionality
* Modern admin settings page
