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
     * Check if form rate limiting is enabled.
     */
    public static function is_rate_limit_enabled() {
        $settings = get_option( 'mailhook_settings', array() );
        return ! empty( $settings['enable_spam_protection'] );
    }

    /**
     * Check if ANY form protection (rate limit, IP block, Keyword block) is active.
     */
    public static function is_any_protection_enabled() {
        $settings = get_option( 'mailhook_settings', array() );
        return ( 
            ! empty( $settings['enable_spam_protection'] ) || 
            ! empty( $settings['spam_blocked_ips'] ) || 
            ! empty( $settings['spam_blocked_keywords'] )
        );
    }

    /**
     * Get block duration in minutes.
     */
    public static function get_block_duration() {
        $settings = get_option( 'mailhook_settings', array() );
        return ! empty( $settings['spam_block_duration'] ) ? intval( $settings['spam_block_duration'] ) : 5;
    }

    /**
     * Get array of permanently blocked IPs.
     */
    public static function get_blocked_ips() {
        $settings = get_option( 'mailhook_settings', array() );
        if ( empty( $settings['spam_blocked_ips'] ) ) {
            return array();
        }
        $ips_raw = explode( ',', $settings['spam_blocked_ips'] );
        return array_filter( array_map( 'trim', $ips_raw ) );
    }

    /**
     * Get array of blocked keywords.
     */
    public static function get_blocked_keywords() {
        $settings = get_option( 'mailhook_settings', array() );
        if ( empty( $settings['spam_blocked_keywords'] ) ) {
            return array();
        }
        $keywords_raw = explode( ',', $settings['spam_blocked_keywords'] );
        return array_filter( array_map( 'strtolower', array_map( 'trim', $keywords_raw ) ) );
    }

    /**
     * Get the user's IP address.
     *
     * Only REMOTE_ADDR is trusted by default. Forwarded-IP headers like
     * X-Forwarded-For and Client-IP are user-supplied and can be spoofed
     * to bypass IP blocks and rate limits. Sites that sit behind a trusted
     * reverse proxy can opt in to the forwarded header via the
     * `mailhook_trust_forwarded_ip` filter.
     */
    public static function get_user_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';

        if ( apply_filters( 'mailhook_trust_forwarded_ip', false ) ) {
            if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $proxies     = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
                $candidate   = trim( current( $proxies ) );
                if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                    $ip = $candidate;
                }
            } elseif ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) && filter_var( $_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP ) ) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            }
        }

        return $ip;
    }

    /**
     * Check if the current IP is permanently blocked.
     */
    public static function is_permanently_blocked_ip( $ip ) {
        $blocked_ips = self::get_blocked_ips();
        return in_array( trim( $ip ), $blocked_ips, true );
    }

    /**
     * Maximum number of emails a single IP may trigger within the block window
     * before it is rate-limited. Defaults to 5 so that ordinary requests which
     * legitimately send more than one email (e.g. user registration fires an
     * admin notice AND a welcome email) are not blocked. Filterable.
     */
    public static function get_rate_limit_max() {
        $settings = get_option( 'mailhook_settings', array() );
        $max      = ! empty( $settings['spam_rate_limit_max'] ) ? intval( $settings['spam_rate_limit_max'] ) : 5;
        return max( 1, (int) apply_filters( 'mailhook_rate_limit_max', $max ) );
    }

    /**
     * Read the current send count for an IP within the active window.
     *
     * @return int
     */
    private static function get_ip_count( $ip ) {
        $data = get_transient( 'mailhook_ip_' . md5( $ip ) );
        if ( is_array( $data ) ) {
            return (int) ( $data['count'] ?? 0 );
        }
        // Back-compat: an old-format transient (bare timestamp) counts as 1 send.
        return $data ? 1 : 0;
    }

    /**
     * Check if the current IP has reached the rate limit for sending emails.
     */
    public static function is_ip_blocked( $ip ) {
        if ( ! self::is_rate_limit_enabled() ) {
            return false;
        }

        return self::get_ip_count( $ip ) >= self::get_rate_limit_max();
    }

    /**
     * Record an email send for the IP, incrementing its count within a fixed
     * window. The window starts on the first send and is preserved on each
     * increment, so the block is "N emails per X minutes" rather than a hard
     * block after the very first email.
     */
    public static function record_ip( $ip ) {
        if ( ! self::is_rate_limit_enabled() ) {
            return;
        }

        $transient_name = 'mailhook_ip_' . md5( $ip );
        $now            = time();
        $window         = self::get_block_duration() * MINUTE_IN_SECONDS;
        $data           = get_transient( $transient_name );

        if ( is_array( $data ) && isset( $data['expires'] ) && $data['expires'] > $now ) {
            $data['count'] = (int) ( $data['count'] ?? 0 ) + 1;
            $ttl           = $data['expires'] - $now;
        } else {
            $data = array( 'count' => 1, 'expires' => $now + $window );
            $ttl  = $window;
        }

        set_transient( $transient_name, $data, $ttl );
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
        if ( ! self::is_any_protection_enabled() ) {
            return;
        }

        wp_enqueue_style( 'mailhook-spam-protect', MAILHOOK_PLUGIN_URL . 'assets/css/mailhook-spam-protect.css', array(), MAILHOOK_VERSION );
        wp_enqueue_script( 'mailhook-spam-protect', MAILHOOK_PLUGIN_URL . 'assets/js/mailhook-spam-protect.js', array(), MAILHOOK_VERSION, true );

        $settings = get_option( 'mailhook_settings', array() );
        $message  = ! empty( $settings['spam_warning_message'] ) ? $settings['spam_warning_message'] : __( 'We detected multiple form submissions. Please verify you want to submit again.', 'mailhook' );
        
        $ip_message = ! empty( $settings['spam_block_ip_message'] ) ? $settings['spam_block_ip_message'] : __( 'Your IP address is not allowed to submit forms on this site.', 'mailhook' );
        $kw_message = ! empty( $settings['spam_block_keyword_message'] ) ? $settings['spam_block_keyword_message'] : __( 'Your submission contains blocked content and cannot proceed.', 'mailhook' );

        wp_localize_script( 'mailhook-spam-protect', 'mailhookSpamVars', array(
            'rest_url'       => esc_url_raw( rest_url( 'mailhook/v1/verify-human' ) ),
            'kw_check_url'   => esc_url_raw( rest_url( 'mailhook/v1/check-keywords' ) ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'require_math'   => isset( $settings['spam_require_math'] ) ? $settings['spam_require_math'] : '1',
            'block_duration' => self::get_block_duration() * 60 * 1000,
            'message'        => wp_kses_post( $message ),

            // Blocking fields. NOTE: the raw blocked-keyword list and the
            // visitor's IP are intentionally NOT sent to the browser — exposing
            // the keyword blocklist in page source lets spammers route around it.
            // Instead the browser only learns WHETHER keyword protection is on,
            // and submits field values to /check-keywords for a server-side
            // verdict. IP blocking and the keyword list itself stay enforced
            // server-side in MailHook_Mailer::pre_flight_spam_check() as a
            // second layer (in case JS is disabled or the request is forged).
            'is_permanently_blocked' => self::is_permanently_blocked_ip( self::get_user_ip() ) ? '1' : '0',
            'has_keyword_protection' => ! empty( $settings['spam_blocked_keywords'] ) ? '1' : '0',
            'ip_message'             => wp_kses_post( $ip_message ),
            'kw_message'             => wp_kses_post( $kw_message ),
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

        register_rest_route( 'mailhook/v1', '/check-keywords', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'check_keywords_callback' ),
            'permission_callback' => '__return_true' // Publicly accessible
        ) );
    }

    /**
     * Callback for the keyword-check REST endpoint.
     *
     * Runs the blocked-keyword scan server-side so the keyword list itself
     * never has to be sent to the browser.
     */
    public function check_keywords_callback( $request ) {
        $json_params = $request->get_json_params();
        $fields      = isset( $json_params['fields'] ) && is_array( $json_params['fields'] ) ? $json_params['fields'] : array();

        $blocked_keywords = self::get_blocked_keywords();
        $blocked          = false;

        if ( ! empty( $blocked_keywords ) ) {
            $full_text = '';
            foreach ( $fields as $field ) {
                if ( is_string( $field ) ) {
                    $full_text .= ' ' . $field;
                }
            }
            $full_text = strtolower( wp_strip_all_tags( $full_text ) );

            foreach ( $blocked_keywords as $keyword ) {
                if ( '' !== $keyword && false !== strpos( $full_text, $keyword ) ) {
                    $blocked = true;
                    break;
                }
            }
        }

        return rest_ensure_response( array( 'blocked' => $blocked ) );
    }

    /**
     * Callback for the REST API endpoint.
     */
    public function verify_human_callback( $request ) {
        $json_params = $request->get_json_params();

        $settings = get_option( 'mailhook_settings', array() );
        $require_math = isset( $settings['spam_require_math'] ) ? $settings['spam_require_math'] : '1';

        $answer   = isset( $json_params['answer'] ) ? intval( $json_params['answer'] ) : intval( $request->get_param( 'answer' ) );
        $expected = isset( $json_params['expected'] ) ? intval( $json_params['expected'] ) : intval( $request->get_param( 'expected' ) );

        // If math is required, check it. Otherwise, assume human if they clicked the button.
        if ( $require_math !== '1' || ( $answer === $expected && $expected !== 0 ) ) {
            $ip = self::get_user_ip();
            self::unblock_ip( $ip );
            return rest_ensure_response( array( 'success' => true ) );
        }

        return new WP_Error( 'invalid_answer', 'Incorrect math answer.', array( 'status' => 400 ) );
    }
}
