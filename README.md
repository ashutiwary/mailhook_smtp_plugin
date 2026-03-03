# MailHook SMTP

A lightweight WordPress SMTP plugin that reconfigures WordPress to send all emails through your proper SMTP server instead of the unreliable PHP `mail()` function. Reliable, secure, and easy to set up.

## 🚀 Features

- **Flexible Configuration**: Configure any SMTP server (Gmail, Outlook, SendGrid, Amazon SES, Brevo, Fastmail, etc.).
- **Security First**: Passwords are securely encrypted using AES-256-CBC before being saved to the WordPress database.
- **Email Templates**: Choose between Modern, Classic, or Custom HTML wrapper templates to perfectly brand your emails globally.
- **Email Logs**: Full-featured email logging to track sent messages, statuses, and diagnose delivery errors directly from your dashboard.
- **Customizations**: Override default 'From Email' and 'From Name' across all WordPress emails.
- **Test Email Verification**: Easily verify your SMTP credentials and delivery with the built-in one-click test email feature.
- **Modern Interface**: Clean, native-looking WordPress admin settings interface.
- **Lightweight**: Zero bloat, no annoying dashboard widgets, no upsells — strictly what you need to send emails reliably.

## 🛠️ Installation

1. Clone or download this repository.
2. Place the `MailHook smtp` folder inside your WordPress `/wp-content/plugins/` directory.
3. Reactivate the plugin through the **Plugins** menu in WordPress.
4. Go to **Settings → MailHook** to configure your SMTP credentials and options.

## ⚙️ Quick Setup (Gmail Example)

1. Navigate to **Settings → MailHook**.
2. **Host**: `smtp.gmail.com`
3. **Port**: `587`
4. **Encryption**: `TLS`
5. Enable **Authentication**, then enter your Gmail address and a generated Google App Password (not your primary login password).
6. Save your settings.
7. Switch to the **Test Email** tab to send a test message and confirm everything is working!

## ❓ Frequently Asked Questions

**Does this work with Gmail or Workspace?**  
Yes! Make sure to use `smtp.gmail.com`, port 587, TLS encryption, and generate a [Google App Password](https://myaccount.google.com/apppasswords).

**Does this work on a Localhost (e.g., Local by Flywheel, XAMPP)?**  
Yes! As long as your local machine is connected to the internet, MailHook will route emails to the external SMTP server seamlessly.

**Is my SMTP password stored securely?**  
Yes. MailHook uses OpenSSL (AES-256-CBC) to encrypt your SMTP password before writing it to the database, ensuring it is not stored in plain-text.

**Will this override emails sent by other plugins?**  
Yes. MailHook hooks directly into the WordPress `phpmailer_init` action. It intercepts every standardized `wp_mail()` call — ensuring WooCommerce emails, form builder plugins, password resets, and notifications are all sent via your SMTP server.

## 📜 License

This project is licensed under the [GPL-2.0-or-later License](https://www.gnu.org/licenses/gpl-2.0.html).
