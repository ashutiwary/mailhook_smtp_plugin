<?php
/**
 * MailHook — Email Controls
 *
 * Intercepts and blocks specific WordPress core emails based on settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MailHook_Controls {

    /**
     * The plugin settings.
     */
    private $settings;

    /**
     * The controls configuration.
     */
    private $controls;

    public function __construct() {
        $this->settings = get_option( 'mailhook_settings', array() );
        $this->controls = $this->settings['controls'] ?? array();

        if ( empty( $this->controls ) ) {
            return;
        }

        $this->init_hooks();
    }

    /**
     * Initialize hooks based on enabled controls.
     */
    private function init_hooks() {
        // Comments
        if ( ! $this->is_enabled( 'comments_awaiting_moderation' ) ) {
            add_filter( 'comment_moderation_recipients', '__return_empty_array', 999 );
        }
        if ( ! $this->is_enabled( 'comments_published' ) ) {
            add_filter( 'comment_notification_recipients', '__return_empty_array', 999 );
        }

        // Change of Admin Email
        if ( ! $this->is_enabled( 'admin_email_change_attempt' ) ) {
            add_filter( 'send_site_admin_email_change_notification', '__return_false', 999 );
        }
        if ( ! $this->is_enabled( 'admin_email_changed' ) ) {
            add_filter( 'send_network_admin_email_change_notification', '__return_false', 999 );
        }

        // Change of User Email or Password
        if ( ! $this->is_enabled( 'user_reset_password_request' ) ) {
            add_filter( 'retrieve_password_message', '__return_false', 999 );
        }
        if ( ! $this->is_enabled( 'user_reset_password_success' ) ) {
            add_filter( 'wp_password_change_notification_email', '__return_false', 999 );
        }
        if ( ! $this->is_enabled( 'user_password_changed' ) ) {
            add_filter( 'send_password_change_email', '__return_false', 999 );
        }
        if ( ! $this->is_enabled( 'user_email_change_attempt' ) ) {
            add_filter( 'send_email_change_email', '__return_false', 999 );
        }
        if ( ! $this->is_enabled( 'user_email_changed' ) ) {
            add_filter( 'email_change_email', '__return_false', 999 );
        }

        // Personal Data Requests
        if ( ! $this->is_enabled( 'privacy_export_erasure_request' ) ) {
            add_filter( 'user_request_action_confirmed', '__return_false', 999 );
        }
        if ( ! $this->is_enabled( 'privacy_admin_erased_data' ) ) {
            add_filter( 'wp_privacy_personal_data_erased', '__return_false', 999 );
        }
        if ( ! $this->is_enabled( 'privacy_admin_sent_export_link' ) ) {
            add_filter( 'wp_privacy_personal_data_export_file_created', '__return_false', 999 );
        }

        // Automatic Updates
        if ( ! $this->is_enabled( 'updates_plugin_status' ) ) {
            add_filter( 'auto_plugin_update_send_email', '__return_false', 999 );
        }
        if ( ! $this->is_enabled( 'updates_theme_status' ) ) {
            add_filter( 'auto_theme_update_send_email', '__return_false', 999 );
        }
        if ( ! $this->is_enabled( 'updates_core_status' ) ) {
            add_filter( 'auto_core_update_send_email', '__return_false', 999 );
        }
        if ( ! $this->is_enabled( 'updates_full_log' ) ) {
            add_filter( 'automatic_updates_send_debug_email', '__return_false', 999 );
        }

        // New User
        if ( ! $this->is_enabled( 'new_user_admin_notification' ) ) {
            add_filter( 'wp_new_user_notification_email_admin', '__return_false', 999 );
        }
        if ( ! $this->is_enabled( 'new_user_user_notification' ) ) {
            add_filter( 'wp_new_user_notification_email', '__return_false', 999 );
        }
    }

    /**
     * Check if a specific email control is enabled (ON).
     *
     * @param string $key The control key.
     * @return bool True if enabled (ON), false otherwise.
     */
    private function is_enabled( $key ) {
        // Default is ON (1) if not set.
        return ( ! isset( $this->controls[ $key ] ) || $this->controls[ $key ] === '1' );
    }
}
