<?php
/**
 * MailHook — Admin Settings Page
 *
 * Renders the settings form under Settings → MailHook
 * and handles saving SMTP configuration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MailHook_Settings {

    /**
     * Option key in wp_options table.
     */
    const OPTION_KEY = 'mailhook_settings';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Register top-level MailHook menu and sub-pages.
     */
    public function add_menu_page() {
        // Top-level menu
        add_menu_page(
            __( 'MailHook', 'mailhook' ),
            __( 'MailHook', 'mailhook' ),
            'manage_options',
            'mailhook',
            array( $this, 'render_page' ),
            'dashicons-email-alt',
            26
        );

        // Settings sub-page (replaces auto-generated parent duplicate)
        add_submenu_page(
            'mailhook',
            __( 'Settings', 'mailhook' ),
            __( 'Settings', 'mailhook' ),
            'manage_options',
            'mailhook',
            array( $this, 'render_page' )
        );

        // Email Logs sub-page
        add_submenu_page(
            'mailhook',
            __( 'Email Logs', 'mailhook' ),
            __( 'Email Logs', 'mailhook' ),
            'manage_options',
            'mailhook-logs',
            array( 'MailHook_Logger', 'render_page' )
        );
    }

    /**
     * Enqueue admin CSS only on our settings page.
     */
    public function enqueue_assets( $hook ) {
        // Load CSS on both MailHook admin pages
        if ( ! in_array( $hook, array( 'toplevel_page_mailhook', 'mailhook_page_mailhook-logs', 'mailhook_page_mailhook-templates' ), true ) ) {
            return;
        }
        wp_enqueue_style(
            'mailhook-admin',
            MAILHOOK_PLUGIN_URL . 'admin.css',
            array(),
            MAILHOOK_VERSION
        );
    }

    /**
     * Handle form submission.
     */
    public function handle_save() {
        if ( ! isset( $_POST['mailhook_save_settings'] ) ) {
            return;
        }

        // Verify nonce
        if ( ! isset( $_POST['mailhook_nonce'] ) || ! wp_verify_nonce( $_POST['mailhook_nonce'], 'mailhook_save' ) ) {
            wp_die( __( 'Security check failed.', 'mailhook' ) );
        }

        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized access.', 'mailhook' ) );
        }

        $settings = array(
            'smtp_host'       => sanitize_text_field( $_POST['smtp_host'] ?? '' ),
            'smtp_port'       => absint( $_POST['smtp_port'] ?? 587 ),
            'smtp_encryption' => sanitize_text_field( $_POST['smtp_encryption'] ?? 'tls' ),
            'smtp_auth'       => sanitize_text_field( $_POST['smtp_auth'] ?? '0' ),
            'smtp_username'   => sanitize_text_field( $_POST['smtp_username'] ?? '' ),
            'from_email'      => sanitize_email( $_POST['from_email'] ?? '' ),
            'from_name'       => sanitize_text_field( $_POST['from_name'] ?? '' ),
        );

        // Encrypt password before saving
        $password = $_POST['smtp_password'] ?? '';
        if ( ! empty( $password ) ) {
            $settings['smtp_password'] = $this->encrypt_password( $password );
        } else {
            // Keep existing password if field is left empty
            $existing = get_option( self::OPTION_KEY, array() );
            $settings['smtp_password'] = $existing['smtp_password'] ?? '';
        }

        update_option( self::OPTION_KEY, $settings );

        // Redirect with success message
        wp_redirect( add_query_arg( array(
            'page'    => 'mailhook',
            'saved'   => '1',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Encrypt password using AES-256-CBC with WordPress AUTH_KEY.
     *
     * @param string $password
     * @return string
     */
    private function encrypt_password( $password ) {
        $key       = hash( 'sha256', AUTH_KEY, true );
        $iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'AES-256-CBC' ) );
        $encrypted = openssl_encrypt( $password, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

        return base64_encode( $iv . $encrypted );
    }

    /**
     * Render the settings page HTML.
     */
    public function render_page() {
        $settings = get_option( self::OPTION_KEY, array() );
        $saved    = isset( $_GET['saved'] ) && $_GET['saved'] === '1';

        $defaults = array(
            'smtp_host'       => '',
            'smtp_port'       => '587',
            'smtp_encryption' => 'tls',
            'smtp_auth'       => '1',
            'smtp_username'   => '',
            'smtp_password'   => '',
            'from_email'      => get_option( 'admin_email' ),
            'from_name'       => get_bloginfo( 'name' ),
        );
        $settings = wp_parse_args( $settings, $defaults );

        $has_password = ! empty( $settings['smtp_password'] );
        ?>
        <div class="wrap mailhook-wrap">
            <div class="mailhook-header">
                <h1>
                    <?php _e( 'MailHook SMTP Settings', 'mailhook' ); ?>
                </h1>
                <p class="mailhook-tagline"><?php _e( 'Configure your SMTP server to send WordPress emails reliably.', 'mailhook' ); ?></p>
            </div>

            <?php if ( $saved ) : ?>
                <div class="mailhook-notice-wrap">
                    <div class="notice notice-success is-dismissible mailhook-notice">
                        <p><strong><?php _e( 'Settings saved successfully!', 'mailhook' ); ?></strong></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mailhook-container">
                <!-- Settings Form -->
                <form method="post" action="" class="mailhook-form">
                    <?php wp_nonce_field( 'mailhook_save', 'mailhook_nonce' ); ?>

                    <!-- SMTP Server Section -->
                    <div class="mailhook-card">
                        <h2 class="mailhook-card-title"><?php _e( 'SMTP Server Configuration', 'mailhook' ); ?></h2>

                        <table class="form-table mailhook-table">
                            <tr>
                                <th><label for="smtp_host"><?php _e( 'SMTP Host', 'mailhook' ); ?></label></th>
                                <td>
                                    <input type="text" id="smtp_host" name="smtp_host"
                                           value="<?php echo esc_attr( $settings['smtp_host'] ); ?>"
                                           class="regular-text" placeholder="e.g. smtp.gmail.com" />
                                    <p class="description"><?php _e( 'The SMTP server address from your email provider.', 'mailhook' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="smtp_port"><?php _e( 'SMTP Port', 'mailhook' ); ?></label></th>
                                <td>
                                    <input type="number" id="smtp_port" name="smtp_port"
                                           value="<?php echo esc_attr( $settings['smtp_port'] ); ?>"
                                           class="small-text" min="1" max="65535" />
                                    <p class="description"><?php _e( '587 (TLS) recommended. Use 465 for SSL, 25 for no encryption.', 'mailhook' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="smtp_encryption"><?php _e( 'Encryption', 'mailhook' ); ?></label></th>
                                <td>
                                    <select id="smtp_encryption" name="smtp_encryption">
                                        <option value="tls" <?php selected( $settings['smtp_encryption'], 'tls' ); ?>><?php _e( 'TLS (Recommended)', 'mailhook' ); ?></option>
                                        <option value="ssl" <?php selected( $settings['smtp_encryption'], 'ssl' ); ?>><?php _e( 'SSL', 'mailhook' ); ?></option>
                                        <option value="none" <?php selected( $settings['smtp_encryption'], 'none' ); ?>><?php _e( 'None', 'mailhook' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Authentication Section -->
                    <div class="mailhook-card">
                        <h2 class="mailhook-card-title"><?php _e( 'Authentication', 'mailhook' ); ?></h2>

                        <table class="form-table mailhook-table">
                            <tr>
                                <th><label for="smtp_auth"><?php _e( 'SMTP Authentication', 'mailhook' ); ?></label></th>
                                <td>
                                    <label class="mailhook-toggle">
                                        <input type="hidden" name="smtp_auth" value="0" />
                                        <input type="checkbox" id="smtp_auth" name="smtp_auth" value="1"
                                            <?php checked( $settings['smtp_auth'], '1' ); ?> />
                                        <span class="mailhook-toggle-slider"></span>
                                        <span class="mailhook-toggle-label"><?php _e( 'Enable authentication (required by most providers)', 'mailhook' ); ?></span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="smtp_username"><?php _e( 'Username', 'mailhook' ); ?></label></th>
                                <td>
                                    <input type="text" id="smtp_username" name="smtp_username"
                                           value="<?php echo esc_attr( $settings['smtp_username'] ); ?>"
                                           class="regular-text" placeholder="your_email@gmail.com"
                                           autocomplete="off" />
                                </td>
                            </tr>
                            <tr>
                                <th><label for="smtp_password"><?php _e( 'Password', 'mailhook' ); ?></label></th>
                                <td>
                                    <input type="password" id="smtp_password" name="smtp_password"
                                           value="" class="regular-text"
                                           placeholder="<?php echo $has_password ? '••••••••••••' : ''; ?>"
                                           autocomplete="new-password" />
                                    <?php if ( $has_password ) : ?>
                                        <p class="description"><?php _e( 'Password is saved and encrypted. Leave blank to keep the current password.', 'mailhook' ); ?></p>
                                    <?php else : ?>
                                        <p class="description"><?php _e( 'For Gmail, use an App Password (not your regular password).', 'mailhook' ); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- From Address Section -->
                    <div class="mailhook-card">
                        <h2 class="mailhook-card-title"><?php _e( 'From Address', 'mailhook' ); ?></h2>

                        <table class="form-table mailhook-table">
                            <tr>
                                <th><label for="from_email"><?php _e( 'From Email', 'mailhook' ); ?></label></th>
                                <td>
                                    <input type="email" id="from_email" name="from_email"
                                           value="<?php echo esc_attr( $settings['from_email'] ); ?>"
                                           class="regular-text" placeholder="noreply@yourdomain.com" />
                                    <p class="description"><?php _e( 'This email appears as the sender for all WordPress emails.', 'mailhook' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="from_name"><?php _e( 'From Name', 'mailhook' ); ?></label></th>
                                <td>
                                    <input type="text" id="from_name" name="from_name"
                                           value="<?php echo esc_attr( $settings['from_name'] ); ?>"
                                           class="regular-text" placeholder="My Website" />
                                </td>
                            </tr>
                        </table>
                    </div>

                    <p class="submit">
                        <input type="submit" name="mailhook_save_settings" class="button button-primary button-hero"
                               value="<?php _e( 'Save Settings', 'mailhook' ); ?>" />
                    </p>
                </form>

                <!-- Test Email Section -->
                <div class="mailhook-card mailhook-test-card">
                    <h2 class="mailhook-card-title"><?php _e( 'Send Test Email', 'mailhook' ); ?></h2>
                    <p><?php _e( 'Send a test email to verify your SMTP configuration is working correctly.', 'mailhook' ); ?></p>

                    <div class="mailhook-test-form">
                        <input type="email" id="mailhook-test-email"
                               value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
                               class="regular-text" placeholder="test@example.com" />
                        <button type="button" id="mailhook-send-test" class="button button-secondary">
                            <?php _e( 'Send Test Email', 'mailhook' ); ?>
                        </button>
                    </div>
                    <div id="mailhook-test-result" class="mailhook-test-result" style="display:none;"></div>
                </div>
            </div>

            <!-- Footer -->
            <div class="mailhook-footer">
                <p><?php printf( __( 'MailHook v%s — Lightweight SMTP for WordPress', 'mailhook' ), MAILHOOK_VERSION ); ?></p>
            </div>
        </div>

        <script>
        (function() {
            var btn     = document.getElementById('mailhook-send-test');
            var input   = document.getElementById('mailhook-test-email');
            var result  = document.getElementById('mailhook-test-result');

            if (!btn) return;

            btn.addEventListener('click', function() {
                var email = input.value.trim();
                if (!email) {
                    result.style.display = 'block';
                    result.className = 'mailhook-test-result error';
                    result.innerHTML = 'Please enter an email address.';
                    return;
                }

                btn.disabled = true;
                btn.textContent = 'Sending...';
                result.style.display = 'block';
                result.className = 'mailhook-test-result info';
                result.innerHTML = 'Sending test email...';

                var formData = new FormData();
                formData.append('action', 'mailhook_send_test');
                formData.append('email', email);
                formData.append('nonce', '<?php echo wp_create_nonce( "mailhook_test" ); ?>');

                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        result.className = 'mailhook-test-result success';
                        result.innerHTML = '' + data.data;
                    } else {
                        result.className = 'mailhook-test-result error';
                        result.innerHTML = '' + data.data;
                    }
                })
                .catch(function(err) {
                    result.className = 'mailhook-test-result error';
                    result.innerHTML = 'Request failed: ' + err.message;
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.textContent = 'Send Test Email';
                });
            });
        })();
        </script>
        <?php
    }
}
