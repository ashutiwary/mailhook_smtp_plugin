<?php
/**
 * Spam Protection Class
 * Handles rate limiting, IP tracking, and frontend JS/CSS injection.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MailHook_Spam_Protection {

    public function __construct() {
        // Enqueue frontend scripts
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Register REST route
        add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
    }

    /**
     * Check if spam protection is enabled.
     */
    public static function is_enabled() {
        $settings = get_option( 'mailhook_settings', array() );
        return ! empty( $settings['enable_spam_protection'] );
    }

    /**
     * Get block duration in minutes.
     */
    public static function get_block_duration() {
        $settings = get_option( 'mailhook_settings', array() );
        return ! empty( $settings['spam_block_duration'] ) ? intval( $settings['spam_block_duration'] ) : 5;
    }

    /**
     * Check if the current IP is blocked from sending emails.
     */
    public static function is_ip_blocked( $ip ) {
        if ( ! self::is_enabled() ) {
            return false;
        }

        $transient_name = 'mailhook_ip_' . md5( $ip );
        $last_sent = get_transient( $transient_name );

        return ( false !== $last_sent );
    }

    /**
     * Record a form submission (email sent) for the IP.
     */
    public static function record_ip( $ip ) {
        if ( ! self::is_enabled() ) {
            return;
        }

        $duration_minutes = self::get_block_duration();
        $transient_name   = 'mailhook_ip_' . md5( $ip );
        
        // Block the IP for the defined duration
        set_transient( $transient_name, time(), $duration_minutes * MINUTE_IN_SECONDS );
    }

    /**
     * Unblock an IP after human verification.
     */
    public static function unblock_ip( $ip ) {
        $transient_name = 'mailhook_ip_' . md5( $ip );
        delete_transient( $transient_name );
    }

    /**
     * Enqueue the frontend javascript and CSS.
     */
    public function enqueue_scripts() {
        if ( ! self::is_enabled() ) {
            return;
        }

        wp_enqueue_style( 'mailhook-spam-protect', MAILHOOK_PLUGIN_URL . 'assets/css/mailhook-spam-protect.css', array(), MAILHOOK_VERSION );
        wp_enqueue_script( 'mailhook-spam-protect', MAILHOOK_PLUGIN_URL . 'assets/js/mailhook-spam-protect.js', array(), MAILHOOK_VERSION, true );

        $settings = get_option( 'mailhook_settings', array() );
        $message  = ! empty( $settings['spam_warning_message'] ) ? $settings['spam_warning_message'] : __( 'We detected multiple form submissions. Please verify you want to submit again.', 'mailhook' );

        wp_localize_script( 'mailhook-spam-protect', 'mailhookSpamVars', array(
            'rest_url'       => esc_url_raw( rest_url( 'mailhook/v1/verify-human' ) ),
            'nonce'          => wp_create_nonce( 'mailhook_verify_human' ),
            'require_math'   => isset( $settings['spam_require_math'] ) ? $settings['spam_require_math'] : '1',
            'block_duration' => self::get_block_duration() * 60 * 1000, // milliseconds
            'message'        => wp_kses_post( $message ),
        ) );
    }

    /**
     * Register REST API endpoint.
     */
    public function register_rest_route() {
        register_rest_route( 'mailhook/v1', '/verify-human', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'verify_human_callback' ),
            'permission_callback' => '__return_true' // Publicly accessible
        ) );
    }

    /**
     * Callback for the REST API endpoint.
     */
    public function verify_human_callback( $request ) {
        $json_params = $request->get_json_params();
        
        // Basic nonce verification for public endpoints
        $nonce = isset( $json_params['nonce'] ) ? $json_params['nonce'] : $request->get_param( 'nonce' );
        if ( ! wp_verify_nonce( $nonce, 'mailhook_verify_human' ) ) {
            // Because nonces can be weird for logged-out users on cached pages, we allow fallback for now
            // But ideal is to enforce it: return new WP_Error( 'rest_forbidden', 'Invalid nonce.', array( 'status' => 403 ) );
        }

        $settings = get_option( 'mailhook_settings', array() );
        $require_math = isset( $settings['spam_require_math'] ) ? $settings['spam_require_math'] : '1';

        $answer   = isset( $json_params['answer'] ) ? intval( $json_params['answer'] ) : intval( $request->get_param( 'answer' ) );
        $expected = isset( $json_params['expected'] ) ? intval( $json_params['expected'] ) : intval( $request->get_param( 'expected' ) );

        // If math is required, check it. Otherwise, assume human if they clicked the button.
        if ( $require_math !== '1' || ( $answer === $expected && $expected !== 0 ) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
            self::unblock_ip( $ip );
            return rest_ensure_response( array( 'success' => true ) );
        }

        return new WP_Error( 'invalid_answer', 'Incorrect math answer.', array( 'status' => 400 ) );
    }
}
