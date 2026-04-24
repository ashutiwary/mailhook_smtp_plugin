<?php
/**
 * MailHook — Backup Connection Logic
 *
 * Intercepts failed emails and attempts to resend them using
 * a configured backup connection.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MailHook_Backup {

    /**
     * Stored plugin settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Prevents infinite loops and multiple alerts.
     *
     * @var bool
     */
    private static $is_retrying = false;

    public function __construct() {
        $this->settings = get_option( 'mailhook_settings', array() );

        // Only register if backup is enabled and a connection is selected
        if ( isset( $this->settings['backup_enabled'] ) && $this->settings['backup_enabled'] === '1' &&
             ! empty( $this->settings['backup_connection_id'] ) && $this->settings['backup_connection_id'] !== 'none' ) {
            // Hook in early to attempt retry before alerts fire
            add_action( 'wp_mail_failed', array( $this, 'handle_failure' ), 5 );
            // Register the reset hook once here so is_retrying is cleared at the end
            // of each wp_mail_failed iteration (priority 100 runs after alerts at 10).
            add_action( 'wp_mail_failed', array( $this, 'reset_retry_flag' ), 100 );
        }
    }

    /**
     * Handle email sending failure.
     *
     * @param WP_Error $wp_error The error object.
     */
    public function handle_failure( $wp_error ) {
        // If we're already in a retry attempt, don't trigger another one
        if ( self::$is_retrying ) {
            $this->handle_nested_failure( $wp_error );
            return;
        }

        $backup_id = $this->settings['backup_connection_id'] ?? 'none';
        if ( $backup_id === 'none' ) {
            return;
        }

        // Find the backup connection details
        $backup_conn = null;
        if ( ! empty( $this->settings['additional_connections'] ) ) {
            foreach ( $this->settings['additional_connections'] as $conn ) {
                if ( $conn['id'] === $backup_id ) {
                    $backup_conn = $conn;
                    break;
                }
            }
        }

        if ( ! $backup_conn ) {
            return;
        }

        $error_data = $wp_error->get_error_data();
        if ( empty( $error_data ) || ! isset( $error_data['to'] ) ) {
            return;
        }

        // Prepare retry data
        $to          = $error_data['to'];
        $subject     = $error_data['subject'] ?? '';
        $message     = $error_data['message'] ?? '';
        $headers     = $error_data['headers'] ?? array();
        $attachments = $error_data['attachments'] ?? array();

        // Log the failure and retry attempt
        $this->log_backup_attempt( $wp_error );

        // Set the override and retry
        self::$is_retrying = true;

        if ( class_exists( 'MailHook_Mailer' ) ) {
            MailHook_Mailer::set_connection_override( $backup_conn );
        }

        // Re-send the email
        $success = wp_mail( $to, $subject, $message, $headers, $attachments );

        // Clear the override
        if ( class_exists( 'MailHook_Mailer' ) ) {
            MailHook_Mailer::set_connection_override( null );
        }

        // $is_retrying stays true until priority 100 on the outer wp_mail_failed
        // iteration clears it — this ensures the Alert handler at priority 10 skips
        // the alert when the backup retry succeeded.
    }

    /**
     * Handle nested failure.
     *
     * @param WP_Error $wp_error The error object.
     */
    public function handle_nested_failure( $wp_error ) {
        // Tag this error as a backup failure so the Alert system knows to fire
        $wp_error->add( 'mailhook_is_backup_failure', true );
    }

    /**
     * Reset the retry flag so future failures can trigger a backup attempt again.
     */
    public function reset_retry_flag() {
        self::$is_retrying = false;
    }

    /**
     * Check if a retry is currently in progress.
     *
     * @return bool
     */
    public static function is_retrying() {
        return self::$is_retrying;
    }

    /**
     * Log the backup attempt to the database if the logger exists.
     *
     * @param WP_Error $wp_error
     */
    private function log_backup_attempt( $wp_error ) {
        if ( ! class_exists( 'MailHook_Logger' ) ) {
            return;
        }

        // Custom logging logic could go here, or we just let the standard logger handle the re-send.
        // For now, we'll let the user know via the UI/logs that a backup was triggered.
        // (Implementation of specific "Backup Attempted" log entry can be added later if needed).
    }
}
