<?php
/**
 * MailHook — Email Alerts
 *
 * Hooks into wp_mail_failed to send alert notifications when
 * an email fails to send.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MailHook_Alerts {

    /**
     * Stored plugin settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Prevent infinite loops if the alert email itself fails to send.
     *
     * @var bool
     */
    private static $is_sending_alert = false;

    public function __construct() {
        $this->settings = get_option( 'mailhook_settings', array() );

        // Only register hooks if alerts are enabled and at least one email is configued
        if ( isset( $this->settings['alerts_enabled'] ) && $this->settings['alerts_enabled'] === '1' ) {
            $emails = $this->get_alert_emails();
            if ( ! empty( $emails ) ) {
                add_action( 'wp_mail_failed', array( $this, 'send_failure_alert' ) );
            }
        }

        // Register AJAX action for testing alert sending from admin settings
        add_action( 'wp_ajax_mailhook_send_test_alert', array( $this, 'ajax_send_test_alert' ) );
    }

    /**
     * Retrieve valid alert emails.
     *
     * @return array
     */
    private function get_alert_emails() {
        if ( ! isset( $this->settings['alert_emails'] ) || ! is_array( $this->settings['alert_emails'] ) ) {
            return array();
        }

        $emails = array();
        foreach ( $this->settings['alert_emails'] as $email ) {
            $clean_email = sanitize_email( $email );
            if ( is_email( $clean_email ) ) {
                $emails[] = $clean_email;
            }
        }
        return array_slice( $emails, 0, 3 ); // Max 3
    }

    /**
     * Handle the wp_mail_failed action and send an alert email.
     *
     * @param WP_Error $wp_error The error object.
     */
    public function send_failure_alert( $wp_error ) {
        // Prevent infinite loops where the alert email fails, triggering another alert, ad infinitum.
        if ( self::$is_sending_alert ) {
            return;
        }

        // If a backup retry is in progress, don't send an alert for the primary failure yet.
        // Unless this specific error is tagged as the backup failure itself.
        if ( class_exists( 'MailHook_Backup' ) && MailHook_Backup::is_retrying() ) {
            if ( ! isset( $wp_error->errors['mailhook_is_backup_failure'] ) ) {
                return;
            }
        }

        $emails = $this->get_alert_emails();
        if ( empty( $emails ) ) {
            return;
        }

        $error_data = $wp_error->get_error_data();
        $error_message = $wp_error->get_error_message();
        
        // Extract original email data safely
        $original_to = 'Unknown';
        if ( isset( $error_data['to'] ) ) {
            $original_to = is_array( $error_data['to'] ) ? implode( ', ', $error_data['to'] ) : wp_strip_all_tags( $error_data['to'] );
        }
        
        $original_subject = 'Unknown';
        if ( isset( $error_data['subject'] ) ) {
            $original_subject = wp_strip_all_tags( $error_data['subject'] );
        }

        $site_name = get_bloginfo( 'name' );
        $site_url  = get_bloginfo( 'url' );
        $logs_url  = admin_url( 'admin.php?page=mailhook-logs' );

        $subject = sprintf( '[%s] MailHook: Email Delivery Failed', $site_name );

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;'>
                <div style='background-color: #ef4444; color: #ffffff; padding: 20px; text-align: center;'>
                    <h2 style='margin: 0;'>Email Delivery Failed</h2>
                </div>
                <div style='padding: 24px;'>
                    <p>An email failed to send from your WordPress site <strong>{$site_name}</strong> ({$site_url}).</p>
                    
                    <h3 style='border-bottom: 1px solid #eee; padding-bottom: 8px;'>Error Details:</h3>
                    <p style='background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 12px; font-family: monospace;'>
                        {$error_message}
                    </p>

                    <h3 style='border-bottom: 1px solid #eee; padding-bottom: 8px;'>Original Email Info:</h3>
                    <ul style='list-style-type: none; padding: 0;'>
                        <li style='margin-bottom: 8px;'><strong>To:</strong> {$original_to}</li>
                        <li><strong>Subject:</strong> {$original_subject}</li>
                    </ul>

                    <div style='margin-top: 30px; text-align: center;'>
                        <a href='{$logs_url}' style='background-color: #0129ac; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: bold;'>View Email Logs</a>
                    </div>
                </div>
                <div style='background-color: #f8f9fa; padding: 16px; text-align: center; font-size: 12px; color: #777;'>
                    This automated alert was sent by the MailHook SMTP plugin.
                </div>
            </div>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Flag that we are currently sending an alert to prevent recursion
        self::$is_sending_alert = true;
        
        // Attempt to send the alert email
        wp_mail( $emails, $subject, $message, $headers );
        
        // Release the flag
        self::$is_sending_alert = false;
    }

    /**
     * AJAX handler to send a test alert.
     */
    public function ajax_send_test_alert() {
        check_ajax_referer( 'mailhook_test', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'mailhook' ) );
        }

        if ( ! isset( $this->settings['alerts_enabled'] ) || $this->settings['alerts_enabled'] !== '1' ) {
            wp_send_json_error( __( 'Please enable Email Alerts and save settings before testing.', 'mailhook' ) );
        }

        $emails = $this->get_alert_emails();
        if ( empty( $emails ) ) {
             wp_send_json_error( __( 'No valid alert emails configured. Please add an email address and save settings first.', 'mailhook' ) );
        }

        $site_name = get_bloginfo( 'name' );
        $subject = sprintf( '[%s] MailHook: Test Alert Notification', $site_name );

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;'>
                <div style='background-color: #0129ac; color: #ffffff; padding: 20px; text-align: center;'>
                    <h2 style='margin: 0;'>Test Alert Notification</h2>
                </div>
                <div style='padding: 24px;'>
                    <p>This is a test alert from your MailHook SMTP plugin on <strong>{$site_name}</strong>.</p>
                    <p>If you're reading this, your email alert system is configured correctly and you will receive notifications if an email fails to send from your site.</p>
                </div>
            </div>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Ensure we don't trigger our own failure handler if this test fails.
        self::$is_sending_alert = true;
        $result = wp_mail( $emails, $subject, $message, $headers );
        self::$is_sending_alert = false;

        if ( $result ) {
            wp_send_json_success( __( 'Test alert sent successfully!', 'mailhook' ) );
        } else {
            wp_send_json_error( __( 'Failed to send test alert. Check your SMTP settings on the General tab.', 'mailhook' ) );
        }
    }
}
