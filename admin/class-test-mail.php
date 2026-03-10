<?php
/**
 * MailHook — Test Email Handler
 *
 * Handles AJAX requests to send a test email
 * using the configured SMTP settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MailHook_Test_Mail {

    public function __construct() {
        add_action( 'wp_ajax_mailhook_send_test', array( $this, 'send_test_email' ) );
    }

    /**
     * Handle the AJAX test email request.
     */
    public function send_test_email() {
        // Verify nonce and bail early on failure (wp_die() is called internally)
        check_ajax_referer( 'mailhook_test', 'nonce' );

        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'mailhook' ) );
            wp_die();
        }

        $to = sanitize_email( $_POST['email'] ?? '' );

        if ( ! is_email( $to ) ) {
            wp_send_json_error( __( 'Please enter a valid email address.', 'mailhook' ) );
        }

        // Check if SMTP is configured
        $settings = get_option( 'mailhook_settings', array() );
        if ( empty( $settings['smtp_host'] ) ) {
            wp_send_json_error( __( 'SMTP host is not configured. Please save your settings first.', 'mailhook' ) );
        }

        // Build test email
        $site_name = get_bloginfo( 'name' );
        $subject   = sprintf( __( '[MailHook] Test Email from %s', 'mailhook' ), $site_name );

        $body = $this->get_test_email_body( $site_name, $settings );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        // Capture any PHPMailer errors
        $error_message = '';
        add_action( 'wp_mail_failed', function( $wp_error ) use ( &$error_message ) {
            $error_message = $wp_error->get_error_message();
        } );

        // Send the email
        $sent = wp_mail( $to, $subject, $body, $headers );

        if ( $sent ) {
            wp_send_json_success(
                sprintf( __( 'Test email sent successfully to %s! Check your inbox.', 'mailhook' ), $to )
            );
        } else {
            $msg = __( 'Failed to send test email.', 'mailhook' );
            if ( ! empty( $error_message ) ) {
                $msg .= ' ' . __( 'Error: ', 'mailhook' ) . $error_message;
            }
            wp_send_json_error( $msg );
        }
    }

    /**
     * Generate a nice HTML body for the test email.
     *
     * @param string $site_name
     * @param array  $settings
     * @return string
     */
    private function get_test_email_body( $site_name, $settings ) {
        $host       = esc_html( $settings['smtp_host'] ?? 'N/A' );
        $port       = esc_html( $settings['smtp_port'] ?? 'N/A' );
        $encryption = strtoupper( esc_html( $settings['smtp_encryption'] ?? 'N/A' ) );
        $timestamp  = current_time( 'F j, Y \a\t g:i A' );

        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
        </head>
        <body style="margin:0; padding:0; background-color:#f4f4f7; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
            <div style="max-width:560px; margin:40px auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,0.08);">
                <!-- Header -->
                <div style="background:linear-gradient(135deg,#0129ac,#011e80); padding:32px; text-align:center;">
                    <h1 style="color:#ffffff; margin:0; font-size:24px;">MailHook</h1>
                    <p style="color:#e1ecff; margin:8px 0 0; font-size:14px;">SMTP Test Email</p>
                </div>
                <!-- Body -->
                <div style="padding:32px;">
                    <h2 style="color:#1e293b; margin:0 0 12px; font-size:20px;">It works!</h2>
                    <p style="color:#475569; line-height:1.6; margin:0 0 20px;">
                        This test email was sent successfully from <strong>' . esc_html( $site_name ) . '</strong>
                        using your SMTP configuration in the MailHook plugin.
                    </p>
                    <!-- Config Summary -->
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin:0 0 20px;">
                        <p style="margin:0 0 8px; color:#64748b; font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Configuration</p>
                        <table style="width:100%; font-size:14px; color:#334155;">
                            <tr><td style="padding:4px 0; color:#64748b;">Host:</td><td style="padding:4px 0;"><strong>' . $host . '</strong></td></tr>
                            <tr><td style="padding:4px 0; color:#64748b;">Port:</td><td style="padding:4px 0;"><strong>' . $port . '</strong></td></tr>
                            <tr><td style="padding:4px 0; color:#64748b;">Encryption:</td><td style="padding:4px 0;"><strong>' . $encryption . '</strong></td></tr>
                            <tr><td style="padding:4px 0; color:#64748b;">Sent at:</td><td style="padding:4px 0;"><strong>' . $timestamp . '</strong></td></tr>
                        </table>
                    </div>
                    <p style="color:#94a3b8; font-size:13px; margin:0;">
                        If you received this email, your SMTP settings are configured correctly.
                    </p>
                </div>
                <!-- Footer -->
                <div style="background:#f8fafc; padding:16px 32px; text-align:center; border-top:1px solid #e2e8f0;">
                    <p style="color:#94a3b8; font-size:12px; margin:0;">
                        Sent by MailHook v' . MAILHOOK_VERSION . ' — WordPress SMTP Plugin
                    </p>
                </div>
            </div>
        </body>
        </html>';
    }
}
