<?php
/**
 * MailHook — SMTP Mailer Configuration
 *
 * Hooks into phpmailer_init to override WordPress's default
 * PHP mail() with authenticated SMTP.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MailHook_Mailer {

    /**
     * Stored plugin settings.
     *
     * @var array
     */
    private $settings;

    public function __construct() {
        $this->settings = get_option( 'mailhook_settings', array() );

        // Only hook if SMTP host is configured
        if ( ! empty( $this->settings['smtp_host'] ) ) {
            add_action( 'phpmailer_init', array( $this, 'configure_smtp' ), 99 );
        }

        // Override From email and name
        if ( ! empty( $this->settings['from_email'] ) ) {
            add_filter( 'wp_mail_from', array( $this, 'set_from_email' ), 99 );
        }
        if ( ! empty( $this->settings['from_name'] ) ) {
            add_filter( 'wp_mail_from_name', array( $this, 'set_from_name' ), 99 );
        }

        // Apply HTML Templates (run early but after logger)
        add_filter( 'wp_mail', array( $this, 'apply_template' ), 10 );
    }

    /**
     * Configure PHPMailer to use SMTP.
     *
     * This fires every time wp_mail() is called.
     *
     * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
     */
    public function configure_smtp( $phpmailer ) {
        $phpmailer->isSMTP();
        $phpmailer->Host = $this->settings['smtp_host'];

        // Clamp port to valid range
        $port = intval( $this->settings['smtp_port'] );
        $phpmailer->Port = ( $port >= 1 && $port <= 65535 ) ? $port : 587;

        // Validate encryption against allowlist before assigning
        $phpmailer->SMTPSecure = $this->get_encryption();

        // Authentication
        if ( ! empty( $this->settings['smtp_auth'] ) && $this->settings['smtp_auth'] === '1' ) {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $this->settings['smtp_username'];
            $phpmailer->Password = $this->decrypt_password( $this->settings['smtp_password'] );
        } else {
            $phpmailer->SMTPAuth = false;
        }

        // Enable debug output in error log (not on screen)
        // $phpmailer->SMTPDebug = 2;
        // $phpmailer->Debugoutput = 'error_log';
    }

    /**
     * Apply the selected HTML template to the email content.
     *
     * @param array $args wp_mail() arguments
     * @return array
     */
    public function apply_template( $args ) {
        if ( ! class_exists( 'MailHook_Templates' ) ) {
            return $args;
        }

        $template_settings = MailHook_Templates::get_settings();
        $layout = $template_settings['layout'];

        if ( $layout === 'none' ) {
            return $args;
        }

        $message = $args['message'];

        // If message is plain text, convert to basic HTML
        if ( strpos( $message, '<' ) === false || strpos( $message, 'html>' ) === false ) {
            // Convert plain text links to clickable links and newlines to <br>
            $message = make_clickable( $message );
            $message = nl2br( $message );
        }

        // Get template HTML
        $template_html = MailHook_Templates::get_template_html( $layout, $template_settings );

        // Replace placeholder
        $message = str_replace( '{email_content}', $message, $template_html );
        $args['message'] = $message;

        // Force content type to HTML
        $headers = $args['headers'];
        if ( ! is_array( $headers ) ) {
            $headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
        }
        
        // Remove existing Content-Type headers
        $headers = array_filter( $headers, function( $h ) {
            return stripos( $h, 'Content-Type:' ) === false;
        } );
        
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $args['headers'] = $headers;

        return $args;
    }
    /**
     * Get encryption type.
     *
     * @return string
     */
    private function get_encryption() {
        $allowed    = array( 'tls', 'ssl', 'none' );
        $encryption = isset( $this->settings['smtp_encryption'] ) ? $this->settings['smtp_encryption'] : 'tls';

        // Only allow known values; fall back to TLS for anything unexpected
        if ( ! in_array( $encryption, $allowed, true ) ) {
            $encryption = 'tls';
        }

        return ( $encryption === 'none' ) ? '' : $encryption;
    }

    /**
     * Override the From email address.
     *
     * @param string $email
     * @return string
     */
    public function set_from_email( $email ) {
        if ( ! empty( $this->settings['from_email'] ) ) {
            return sanitize_email( $this->settings['from_email'] );
        }
        return $email;
    }

    /**
     * Override the From name.
     *
     * @param string $name
     * @return string
     */
    public function set_from_name( $name ) {
        if ( ! empty( $this->settings['from_name'] ) ) {
            return sanitize_text_field( $this->settings['from_name'] );
        }
        return $name;
    }

    /**
     * Decrypt the stored SMTP password.
     *
     * Uses WordPress AUTH_KEY as the encryption key.
     *
     * @param string $encrypted_password
     * @return string
     */
    private function decrypt_password( $encrypted_password ) {
        if ( empty( $encrypted_password ) ) {
            return '';
        }

        // Guard: OpenSSL extension must be available
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            // Return as-is; cannot decrypt without OpenSSL
            return $encrypted_password;
        }

        $key  = $this->get_encryption_key();
        $data = base64_decode( $encrypted_password );

        if ( $data === false || strlen( $data ) < 17 ) {
            // Not encrypted or corrupted — return as-is (backward compat)
            return $encrypted_password;
        }

        $iv_length = openssl_cipher_iv_length( 'AES-256-CBC' );
        $iv        = substr( $data, 0, $iv_length );
        $encrypted = substr( $data, $iv_length );

        $decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

        return $decrypted !== false ? $decrypted : $encrypted_password;
    }

    /**
     * Get the encryption key derived from WordPress AUTH_KEY.
     *
     * @return string
     */
    private function get_encryption_key() {
        return hash( 'sha256', AUTH_KEY, true );
    }
}
