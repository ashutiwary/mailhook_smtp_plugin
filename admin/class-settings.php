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

        // Note: Email Reports submenu is registered by MailHook_Email_Report class.
    }


    /**
     * Enqueue admin CSS only on our settings page.
     */
    public function enqueue_assets( $hook ) {
        // Load CSS and JS only on our admin pages
        if ( ! in_array( $hook, array( 'toplevel_page_mailhook', 'mailhook_page_mailhook-logs', 'mailhook_page_mailhook-templates', 'mailhook_page_mailhook-email-report' ), true ) ) {
            return;
        }
        wp_enqueue_style(
            'mailhook-admin',
            MAILHOOK_ADMIN_URL . 'css/admin.css',
            array(),
            MAILHOOK_VERSION
        );
        wp_enqueue_script(
            'mailhook-admin',
            MAILHOOK_ADMIN_URL . 'js/admin.js',
            array(),
            MAILHOOK_VERSION,
            true
        );
        wp_localize_script(
            'mailhook-admin',
            'mailhookData',
            array(
                'ajaxurl'         => admin_url( 'admin-ajax.php' ),
                'testNonce'       => wp_create_nonce( 'mailhook_test' ),
                'deleteNonce'     => wp_create_nonce( 'mailhook_delete_logs' ),
                'viewNonce'       => wp_create_nonce( 'mailhook_view_log' ),
                'confirmDelete'   => __( 'Delete selected logs?', 'mailhook' ),
                'confirmDeleteAll'=> __( 'Delete ALL logs? This cannot be undone.', 'mailhook' ),
                'sending'         => __( 'Sending…', 'mailhook' ),
                'sendTest'        => __( 'Send Test Email', 'mailhook' ),
                'enterEmail'      => __( 'Please enter an email address.', 'mailhook' ),
                'sendingMsg'      => __( 'Sending test email…', 'mailhook' ),
                'requestFailed'   => __( 'Request failed: ', 'mailhook' ),
                'loading'         => __( 'Loading…', 'mailhook' ),
                'error'           => __( 'Error', 'mailhook' ),
                'logDeleted'      => __( 'Logs deleted.', 'mailhook' ),
                'confirmDeleteConn' => __( 'Are you sure you want to remove this connection?', 'mailhook' ),
                'confirmDeleteRule' => __( 'Are you sure you want to remove this routing rule?', 'mailhook' ),
                'on'              => __( 'ON', 'mailhook' ),
                'off'             => __( 'OFF', 'mailhook' ),
            )
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
            'smtp_host'       => sanitize_text_field( wp_unslash( $_POST['smtp_host'] ?? '' ) ),
            'smtp_port'       => min( 65535, max( 1, absint( $_POST['smtp_port'] ?? 587 ) ) ),
            'smtp_encryption' => in_array( $_POST['smtp_encryption'] ?? '', array( 'tls', 'ssl', 'none' ), true )
                                    ? sanitize_text_field( $_POST['smtp_encryption'] )
                                    : 'tls',
            'smtp_auth'       => sanitize_text_field( $_POST['smtp_auth'] ?? '0' ),
            'smtp_username'   => sanitize_text_field( wp_unslash( $_POST['smtp_username'] ?? '' ) ),
            'from_email'      => sanitize_email( $_POST['from_email'] ?? '' ),
            'from_name'       => sanitize_text_field( wp_unslash( $_POST['from_name'] ?? '' ) ),
        );

        // Encrypt password before saving
        $password = isset( $_POST['smtp_password'] ) ? $_POST['smtp_password'] : '';
        if ( ! empty( $password ) ) {
            $settings['smtp_password'] = $this->encrypt_password( $password );
        } else {
            // Keep existing password if field is left empty
            $existing = get_option( self::OPTION_KEY, array() );
            $settings['smtp_password'] = $existing['smtp_password'] ?? '';
        }

        // Alerts settings
        $settings['alerts_enabled'] = sanitize_text_field( $_POST['alerts_enabled'] ?? '0' );
        $alert_emails = array();
        if ( isset( $_POST['alert_emails'] ) && is_array( $_POST['alert_emails'] ) ) {
            foreach ( $_POST['alert_emails'] as $email ) {
                $email = sanitize_email( $email );
                if ( ! empty( $email ) ) {
                    $alert_emails[] = $email;
                }
            }
        }
        $settings['alert_emails'] = array_slice( $alert_emails, 0, 3 ); // Max 3 emails

        // Backup Connection & Additional Connections
        $settings['backup_enabled'] = sanitize_text_field( $_POST['backup_enabled'] ?? '0' );
        $settings['backup_connection_id'] = sanitize_text_field( $_POST['backup_connection_id'] ?? 'none' );
        
        $additional = array();
        if ( isset( $_POST['additional_connections'] ) && is_array( $_POST['additional_connections'] ) ) {
            $existing_settings = get_option( self::OPTION_KEY, array() );
            $existing_add = isset($existing_settings['additional_connections']) && is_array($existing_settings['additional_connections']) 
                            ? $existing_settings['additional_connections'] 
                            : array();
                            
            foreach ( $_POST['additional_connections'] as $idx => $conn ) {
                $id = sanitize_text_field( $conn['id'] ?? '' );
                if ( empty( $id ) ) continue;

                $sanitized = array(
                    'id'              => $id,
                    'name'            => sanitize_text_field( wp_unslash( $conn['name'] ?? 'New Connection' ) ),
                    'smtp_host'       => sanitize_text_field( wp_unslash( $conn['smtp_host'] ?? '' ) ),
                    'smtp_port'       => min( 65535, max( 1, absint( $conn['smtp_port'] ?? 587 ) ) ),
                    'smtp_encryption' => in_array( $conn['smtp_encryption'] ?? '', array( 'tls', 'ssl', 'none' ), true ) ? sanitize_text_field( $conn['smtp_encryption'] ) : 'tls',
                    'smtp_auth'       => sanitize_text_field( $conn['smtp_auth'] ?? '0' ),
                    'smtp_username'   => sanitize_text_field( wp_unslash( $conn['smtp_username'] ?? '' ) ),
                    'from_email'      => sanitize_email( $conn['from_email'] ?? '' ),
                    'from_name'       => sanitize_text_field( wp_unslash( $conn['from_name'] ?? '' ) ),
                    'is_collapsed'    => sanitize_text_field( $conn['is_collapsed'] ?? '0' ),
                );

                // Handle password for this connection
                $pass = $conn['smtp_password'] ?? '';
                if ( ! empty( $pass ) && $pass !== '********' ) {
                    $sanitized['smtp_password'] = $this->encrypt_password( $pass );
                } else {
                    // Try to find existing password
                    $existing_pass = '';
                    foreach($existing_add as $ex_conn) {
                        if ($ex_conn['id'] === $id) {
                            $existing_pass = $ex_conn['smtp_password'] ?? '';
                            break;
                        }
                    }
                    $sanitized['smtp_password'] = $existing_pass;
                }

                $additional[] = $sanitized;
            }
        }
        $settings['additional_connections'] = $additional;

        // Smart Routing
        $settings['routing_enabled'] = sanitize_text_field( $_POST['routing_enabled'] ?? '0' );
        $routing_rules = array();
        if ( isset( $_POST['routing_rules'] ) && is_array( $_POST['routing_rules'] ) ) {
            foreach ( $_POST['routing_rules'] as $rule ) {
                $conn_id = sanitize_text_field( $rule['connection_id'] ?? '' );
                if ( empty( $conn_id ) ) continue;

                $groups = array();
                if ( isset( $rule['groups'] ) && is_array( $rule['groups'] ) ) {
                    foreach ( $rule['groups'] as $group ) {
                        $conditions = array();
                        if ( isset( $group['conditions'] ) && is_array( $group['conditions'] ) ) {
                            foreach ( $group['conditions'] as $cond ) {
                                $conditions[] = array(
                                    'field'    => sanitize_text_field( $cond['field'] ?? 'subject' ),
                                    'operator' => sanitize_text_field( $cond['operator'] ?? 'contains' ),
                                    'value'    => sanitize_text_field( $cond['value'] ?? '' ),
                                );
                            }
                        }
                        if ( ! empty( $conditions ) ) {
                            $groups[] = array( 'conditions' => $conditions );
                        }
                    }
                }
                
                if ( ! empty( $groups ) ) {
                    $routing_rules[] = array(
                        'connection_id' => $conn_id,
                        'groups'        => $groups,
                    );
                }
            }
        }
        $settings['routing_rules'] = $routing_rules;
        
        // Email Controls
        $controls = array();
        if ( isset( $_POST['controls'] ) && is_array( $_POST['controls'] ) ) {
            foreach ( $_POST['controls'] as $key => $value ) {
                $controls[ sanitize_key( $key ) ] = sanitize_text_field( $value );
            }
        }
        $settings['controls'] = $controls;

        // Form Spam Protection
        $settings['enable_spam_protection'] = sanitize_text_field( $_POST['enable_spam_protection'] ?? '0' );
        $settings['spam_require_math']      = sanitize_text_field( $_POST['spam_require_math'] ?? '0' );
        $settings['spam_block_duration']    = max( 1, absint( $_POST['spam_block_duration'] ?? 5 ) );
        $settings['spam_warning_message']   = sanitize_textarea_field( wp_unslash( $_POST['spam_warning_message'] ?? '' ) );
        $settings['spam_blocked_ips']       = sanitize_textarea_field( wp_unslash( $_POST['spam_blocked_ips'] ?? '' ) );
        $settings['spam_block_ip_message']  = sanitize_text_field( wp_unslash( $_POST['spam_block_ip_message'] ?? '' ) );
        $settings['spam_blocked_keywords']  = sanitize_textarea_field( wp_unslash( $_POST['spam_blocked_keywords'] ?? '' ) );
        $settings['spam_block_keyword_message'] = sanitize_text_field( wp_unslash( $_POST['spam_block_keyword_message'] ?? '' ) );

        update_option( self::OPTION_KEY, $settings );

        $redirect_args = array(
            'page'  => 'mailhook',
            'saved' => '1',
        );

        if ( ! empty( $_POST['active_tab'] ) ) {
            $redirect_args['tab'] = sanitize_text_field( $_POST['active_tab'] );
        }

        // Redirect with success message
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
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
            'alerts_enabled'  => '0',
            'alert_emails'    => array( get_option( 'admin_email' ) ),
            'controls'        => array(), // Default: all emails enabled (empty array means no blocks)
            'enable_spam_protection' => '0',
            'spam_require_math'      => '1',
            'spam_block_duration'    => '5',
            'spam_warning_message'   => __( 'We detected multiple form submissions. Please verify you want to submit again.', 'mailhook' ),
            'spam_blocked_ips'       => '',
            'spam_block_ip_message'  => __( 'Your IP address is not allowed to submit forms on this site.', 'mailhook' ),
            'spam_blocked_keywords'  => '',
            'spam_block_keyword_message' => __( 'Your submission contains blocked content and cannot proceed.', 'mailhook' ),
        );
        $settings = wp_parse_args( $settings, $defaults );

        $has_password = ! empty( $settings['smtp_password'] );
        ?>
        <div class="wrap mailhook-wrap">

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible inline" style="margin-top: 0; margin-bottom: 20px;">
                    <p><strong><?php _e( 'Settings saved successfully!', 'mailhook' ); ?></strong></p>
                </div>
            <?php endif; ?>

            <div class="mailhook-header">
                <h1>
                    <?php _e( 'MailHook SMTP Settings', 'mailhook' ); ?>
                </h1>
                <p class="mailhook-tagline"><?php _e( 'Configure your SMTP server to send WordPress emails reliably.', 'mailhook' ); ?></p>
            </div>

            <div class="mailhook-container">
            
                <div class="mailhook-tabs-wrapper">
                    <nav class="nav-tab-wrapper mailhook-nav-tabs">
                        <a href="#tab-general" class="nav-tab nav-tab-active" data-tab="general"><?php _e( 'General', 'mailhook' ); ?></a>
                        <a href="#tab-alerts" class="nav-tab" data-tab="alerts"><?php _e( 'Alerts', 'mailhook' ); ?></a>
                        <a href="#tab-additional" class="nav-tab" data-tab="additional"><?php _e( 'Additional Connections', 'mailhook' ); ?></a>
                        <a href="#tab-routing" class="nav-tab" data-tab="routing"><?php _e( 'Smart Routing', 'mailhook' ); ?></a>
                        <a href="#tab-controls" class="nav-tab" data-tab="controls"><?php _e( 'Email Controls', 'mailhook' ); ?></a>
                        <a href="#tab-spam" class="nav-tab" data-tab="spam"><?php _e( 'Spam Protection', 'mailhook' ); ?></a>
                        <a href="#tab-block" class="nav-tab" data-tab="block"><?php _e( 'Block IP & Keyword', 'mailhook' ); ?></a>
                    </nav>
                </div>

                <!-- Settings Form -->
                <form method="post" action="" class="mailhook-form" id="mailhook-settings-form">
                    <?php wp_nonce_field( 'mailhook_save', 'mailhook_nonce' ); ?>
                    
                    <?php 
                    $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general'; 
                    ?>
                    <input type="hidden" name="active_tab" id="mailhook-active-tab" value="<?php echo esc_attr( $active_tab ); ?>">

                    <!-- Tab: General -->
                    <div id="tab-general" class="mailhook-tab-content mailhook-tab-active">
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
                                    <select id="smtp_encryption" name="smtp_encryption" style="margin-top: 5px;">
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
                    
                    </div><!-- /#tab-general -->

                    <!-- Tab: Alerts -->
                    <div id="tab-alerts" class="mailhook-tab-content" style="display:none;">
                        
                        <div class="mailhook-card">
                            <h2 class="mailhook-card-title"><?php _e( 'Email Delivery Alerts', 'mailhook' ); ?></h2>
                            <p><?php _e( 'Get notified when an email fails to send from your site. We\'ll send an alert with the error message and helpful links.', 'mailhook' ); ?></p>
                            
                            <table class="form-table mailhook-table">
                                <tr>
                                    <th><label for="alerts_enabled"><?php _e( 'Email Alerts', 'mailhook' ); ?></label></th>
                                    <td>
                                        <label class="mailhook-toggle">
                                            <input type="hidden" name="alerts_enabled" value="0" />
                                            <input type="checkbox" id="alerts_enabled" name="alerts_enabled" value="1"
                                                <?php checked( $settings['alerts_enabled'], '1' ); ?> />
                                            <span class="mailhook-toggle-slider"></span>
                                            <span class="mailhook-toggle-label"><?php _e( 'Enable alerts for email sending failures', 'mailhook' ); ?></span>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label><?php _e( 'Send To', 'mailhook' ); ?></label></th>
                                    <td>
                                        <div id="mailhook-alert-emails-container">
                                            <?php 
                                            $emails = is_array( $settings['alert_emails'] ) ? $settings['alert_emails'] : array();
                                            if ( empty( $emails ) ) {
                                                $emails = array( '' ); // Ensure at least one input
                                            }
                                            foreach ( $emails as $index => $email ) : 
                                            ?>
                                                <div class="mailhook-alert-email-row" style="margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                                                    <input type="email" name="alert_emails[]" value="<?php echo esc_attr( $email ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Enter email address', 'mailhook' ); ?>" />
                                                    <?php if ( count( $emails ) > 1 || $index > 0 ) : ?>
                                                        <button type="button" class="button mailhook-remove-email-btn" title="<?php esc_attr_e( 'Remove this email', 'mailhook' ); ?>">&times;</button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p>
                                            <button type="button" id="mailhook-add-email-btn" class="button" <?php echo count( $emails ) >= 3 ? 'style="display:none;"' : ''; ?>>
                                                <?php _e( 'Add Another Email Address', 'mailhook' ); ?>
                                            </button>
                                        </p>
                                        <p class="description"><?php _e( 'Enter the email addresses (3 max) you\'d like to use to receive alerts.', 'mailhook' ); ?></p>
                                    </td>
                                </tr>
                            </table>

                        </div>

                        <p class="submit" style="display: flex; gap: 10px; align-items: center;">
                            <input type="submit" name="mailhook_save_settings" class="button button-primary button-hero"
                                   value="<?php _e( 'Save Settings', 'mailhook' ); ?>" />
                            <button type="button" id="mailhook-test-alert" class="button button-secondary button-hero">
                                <?php _e( 'Test Alert', 'mailhook' ); ?>
                            </button>
                            <span class="mailhook-test-alert-result" style="display:inline-block; margin-left: 10px;"></span>
                        </p>

                    </div><!-- /#tab-alerts -->

                    <!-- Tab: Additional Connections -->
                    <div id="tab-additional" class="mailhook-tab-content" style="display:none;">
                        
                        <div class="mailhook-card">
                            <h2 class="mailhook-card-title"><?php _e( 'Backup Connection', 'mailhook' ); ?></h2>
                            <p class="description"><?php _e( 'Select an additional connection to use as a fallback if your Primary Connection fails.', 'mailhook' ); ?></p>
                            
                            <table class="form-table mailhook-table">
                                <tr>
                                    <th style="vertical-align: middle;"><label><?php _e( 'Backup Connection', 'mailhook' ); ?></label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 20px; min-height: 40px;">
                                            <label class="mailhook-toggle" style="margin: 0; line-height: 1;">
                                                <input type="hidden" name="backup_enabled" value="0" />
                                                <input type="checkbox" id="backup_enabled" name="backup_enabled" value="1"
                                                    <?php checked( isset($settings['backup_enabled']) ? $settings['backup_enabled'] : '0', '1' ); ?> />
                                                <span class="mailhook-toggle-slider"></span>
                                                <span class="mailhook-toggle-label" style="margin: 0; line-height: 1;"><?php _e( 'Enable backup connection', 'mailhook' ); ?></span>
                                            </label>
                                            
                                            <div id="backup-connection-selector-wrap" style="margin: 0; <?php echo (isset($settings['backup_enabled']) && $settings['backup_enabled'] === '1') ? 'display: flex; align-items: center;' : 'display:none;'; ?>">
                                                <select name="backup_connection_id" id="backup_connection_id" style="min-width: 250px; margin: 0; height: 32px; line-height: 1;">
                                                    <option value="none" <?php selected( isset($settings['backup_connection_id']) ? $settings['backup_connection_id'] : 'none', 'none' ); ?>><?php _e( 'Select Connection', 'mailhook' ); ?></option>
                                                    <?php 
                                                    if ( ! empty( $settings['additional_connections'] ) && is_array( $settings['additional_connections'] ) ) {
                                                        foreach ( $settings['additional_connections'] as $conn ) {
                                                            $conn_id = esc_attr( $conn['id'] );
                                                            $conn_name = esc_html( $conn['name'] );
                                                            echo '<option value="' . $conn_id . '" ' . selected( isset($settings['backup_connection_id']) ? $settings['backup_connection_id'] : 'none', $conn_id, false ) . '>' . $conn_name . '</option>';
                                                        }
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="mailhook-card">
                            <h2 class="mailhook-card-title" style="display: flex; justify-content: space-between; align-items: center;">
                                <?php _e( 'Additional Connections', 'mailhook' ); ?>
                                <button type="button" class="button" id="mailhook-add-connection-btn"><?php _e( 'Add New Connection', 'mailhook' ); ?></button>
                            </h2>
                            <p class="description"><?php _e( 'Create secondary email connections that can be used for backup routing.', 'mailhook' ); ?></p>
                            
                            <div id="mailhook-connections-container">
                                <?php 
                                $connections = isset( $settings['additional_connections'] ) && is_array( $settings['additional_connections'] ) ? $settings['additional_connections'] : array();
                                
                                if ( empty( $connections ) ) {
                                    echo '<p class="mailhook-no-connections description">' . __( 'No additional connections configured yet.', 'mailhook' ) . '</p>';
                                } else {
                                    foreach ( $connections as $index => $conn ) {
                                        $this->render_connection_row( $conn, $index );
                                    }
                                }
                                ?>
                            </div>

                        </div>

                        <p class="submit" style="display: flex; gap: 10px; align-items: center;">
                            <input type="submit" name="mailhook_save_settings" class="button button-primary button-hero"
                                   value="<?php _e( 'Save Settings', 'mailhook' ); ?>" />
                        </p>

                    </div><!-- /#tab-additional -->

                    <!-- Tab: Smart Routing -->
                    <div id="tab-routing" class="mailhook-tab-content" style="display:none;">
                        
                        <div class="mailhook-card">
                            <h2 class="mailhook-card-title"><?php _e( 'Smart Routing Configuration', 'mailhook' ); ?></h2>
                            <p><?php _e( 'Route emails through different additional connections based on configured conditions. Emails that do not match any of the conditions below will be sent via your Primary Connection.', 'mailhook' ); ?></p>
                            
                            <table class="form-table mailhook-table">
                                <tr>
                                    <th><label for="routing_enabled"><?php _e( 'Smart Routing', 'mailhook' ); ?></label></th>
                                    <td>
                                        <label class="mailhook-toggle">
                                            <input type="hidden" name="routing_enabled" value="0" />
                                            <input type="checkbox" id="routing_enabled" name="routing_enabled" value="1"
                                                <?php checked( $settings['routing_enabled'] ?? '0', '1' ); ?> />
                                            <span class="mailhook-toggle-slider"></span>
                                            <span class="mailhook-toggle-label"><?php _e( 'Enable Smart Routing', 'mailhook' ); ?></span>
                                        </label>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="mailhook-card" id="mailhook-no-additional-notice" style="<?php echo empty( $settings['additional_connections'] ) ? '' : 'display:none;'; ?>">
                            <div class="notice notice-info inline">
                                <p><?php printf( 
                                    __( 'You need to configure at least one %s before you can use Smart Routing.', 'mailhook' ),
                                    '<a href="#tab-additional" class="mailhook-switch-tab" data-tab="additional">' . __( 'additional connection', 'mailhook' ) . '</a>'
                                ); ?></p>
                            </div>
                        </div>

                        <div id="mailhook-routing-rules-container" style="<?php echo empty( $settings['additional_connections'] ) ? 'display:none;' : ''; ?>">
                            <div class="mailhook-rules-list">
                                <?php 
                                $rules = $settings['routing_rules'] ?? array();
                                foreach ( $rules as $rule_idx => $rule ) {
                                    $this->render_routing_rule_row( $rule, $rule_idx, $settings['additional_connections'] );
                                }
                                ?>
                            </div>
                            
                <p style="margin-top: 20px; text-align: center;">
                    <button type="button" id="mailhook-add-rule-btn" class="button">
                        <?php _e( 'Add New Rule', 'mailhook' ); ?>
                    </button>
                </p>
                        </div>

                        <p class="submit">
                            <input type="submit" name="mailhook_save_settings" class="button button-primary button-hero"
                                   value="<?php _e( 'Save Settings', 'mailhook' ); ?>" />
                        </p>

                    </div><!-- /#tab-routing -->

                    <!-- Tab: Email Controls -->
                    <div id="tab-controls" class="mailhook-tab-content" style="display:none;">
                        <?php $this->render_controls_tab( $settings['controls'] ); ?>
                        
                        <p class="submit">
                            <input type="submit" name="mailhook_save_settings" class="button button-primary button-hero"
                                   value="<?php _e( 'Save Settings', 'mailhook' ); ?>" />
                        </p>
                    </div><!-- /#tab-controls -->

                    <!-- Tab: Spam Protection -->
                    <div id="tab-spam" class="mailhook-tab-content" style="display:none;">
                        
                        <div class="mailhook-card">
                            <h2 class="mailhook-card-title"><?php _e( 'Form Spam & Rate Limiting', 'mailhook' ); ?></h2>
                            <p><?php _e( 'Prevent bot spam by blocking IP addresses that submit too many forms across your website. Works with any form plugin (Elementor, CF7, WPForms, etc.).', 'mailhook' ); ?></p>
                            
                            <table class="form-table mailhook-table">
                                <tr>
                                    <th><label for="enable_spam_protection"><?php _e( 'Enable Protection', 'mailhook' ); ?></label></th>
                                    <td>
                                        <div class="mailhook-control-item" style="display: flex; align-items: center; gap: 15px;">
                                            <label class="mailhook-toggle">
                                                <input type="hidden" name="enable_spam_protection" value="0" />
                                                <input type="checkbox" id="enable_spam_protection" name="enable_spam_protection" value="1"
                                                    <?php checked( $settings['enable_spam_protection'], '1' ); ?> />
                                                <span class="mailhook-toggle-slider"></span>
                                                <span class="mailhook-toggle-label"><?php echo $settings['enable_spam_protection'] === '1' ? __( 'ON', 'mailhook' ) : __( 'OFF', 'mailhook' ); ?></span>
                                            </label>
                                            <span class="mailhook-control-description"><?php _e( 'Enable Form Rate Limiting', 'mailhook' ); ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <tr class="mailhook-spam-dependent" style="<?php echo $settings['enable_spam_protection'] === '1' ? '' : 'display: none;'; ?>">
                                    <th><label for="spam_require_math"><?php _e( 'Require Math Challenge', 'mailhook' ); ?></label></th>
                                    <td>
                                        <div class="mailhook-control-item" style="display: flex; align-items: center; gap: 15px;">
                                            <label class="mailhook-toggle">
                                                <input type="hidden" name="spam_require_math" value="0" />
                                                <input type="checkbox" id="spam_require_math" name="spam_require_math" value="1"
                                                    <?php checked( $settings['spam_require_math'], '1' ); ?> />
                                                <span class="mailhook-toggle-slider"></span>
                                                <span class="mailhook-toggle-label"><?php echo $settings['spam_require_math'] === '1' ? __( 'ON', 'mailhook' ) : __( 'OFF', 'mailhook' ); ?></span>
                                            </label>
                                            <span class="mailhook-control-description"><?php _e( 'Show a simple math captcha (e.g. 5 + 3 = ?) to verify users.', 'mailhook' ); ?></span>
                                        </div>
                                    </td>
                                </tr>
                                <tr class="mailhook-spam-dependent" style="<?php echo $settings['enable_spam_protection'] === '1' ? '' : 'display: none;'; ?>">
                                    <th><label for="spam_block_duration"><?php _e( 'Block Duration', 'mailhook' ); ?></label></th>
                                    <td>
                                        <input type="number" id="spam_block_duration" name="spam_block_duration"
                                               value="<?php echo esc_attr( $settings['spam_block_duration'] ); ?>"
                                               class="small-text" min="1" max="1440" />
                                        <span class="description"><?php _e( 'minutes. (If a user submits a form, block them for this many minutes unless they verify they are human).', 'mailhook' ); ?></span>
                                    </td>
                                </tr>
                                <tr class="mailhook-spam-dependent" style="<?php echo $settings['enable_spam_protection'] === '1' ? '' : 'display: none;'; ?>">
                                    <th><label for="spam_warning_message"><?php _e( 'Popup Warning Message', 'mailhook' ); ?></label></th>
                                    <td>
                                        <textarea id="spam_warning_message" name="spam_warning_message" rows="3" class="large-text"><?php echo esc_textarea( $settings['spam_warning_message'] ); ?></textarea>
                                        <p class="description"><?php _e( 'This message is shown in the popup when a rate-limited user tries to submit another form.', 'mailhook' ); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <p class="submit">
                            <input type="submit" name="mailhook_save_settings" class="button button-primary button-hero"
                                   value="<?php _e( 'Save Settings', 'mailhook' ); ?>" />
                        </p>
                    </div><!-- /#tab-spam -->

                    <!-- Tab: Block IP & Keyword -->
                    <div id="tab-block" class="mailhook-tab-content" style="display:none;">
                        
                        <div class="mailhook-card">
                            <h2 class="mailhook-card-title"><?php _e( 'Block IP & Keyword', 'mailhook' ); ?></h2>
                            <p><?php _e( 'Globally restrict specific IPs or keywords from submitting forms.', 'mailhook' ); ?></p>
                            
                            <table class="form-table mailhook-table">
                                <tr>
                                    <th><label for="spam_blocked_ips"><?php _e( 'Blocked IPs', 'mailhook' ); ?></label></th>
                                    <td>
                                        <textarea id="spam_blocked_ips" name="spam_blocked_ips" rows="3" class="large-text" placeholder="192.168.1.1, 10.0.0.1"><?php echo esc_textarea( $settings['spam_blocked_ips'] ); ?></textarea>
                                        <p class="description"><?php _e( 'Enter IP addresses separated by commas. These IPs will be entirely blocked from submitting any form without a math challenge.', 'mailhook' ); ?></p>
                                        <p class="description" style="margin-top: 5px; color: #d63638;">
                                            <strong><?php _e( 'Your current IP address is:', 'mailhook' ); ?></strong>
                                            <code><?php echo esc_html( MailHook_Spam_Protection::get_user_ip() ); ?></code>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="spam_block_ip_message"><?php _e( 'Blocked IP Message', 'mailhook' ); ?></label></th>
                                    <td>
                                        <input type="text" id="spam_block_ip_message" name="spam_block_ip_message" value="<?php echo esc_attr( $settings['spam_block_ip_message'] ); ?>" class="large-text" />
                                        <p class="description"><?php _e( 'This message is shown when a permanently blocked IP tries to submit a form.', 'mailhook' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="spam_blocked_keywords"><?php _e( 'Blocked Keywords', 'mailhook' ); ?></label></th>
                                    <td>
                                        <textarea id="spam_blocked_keywords" name="spam_blocked_keywords" rows="3" class="large-text" placeholder="viagra, crypto, lottery"><?php echo esc_textarea( $settings['spam_blocked_keywords'] ); ?></textarea>
                                        <p class="description"><?php _e( 'Enter keywords separated by commas. If a user submits a form containing any of these words in the text fields, they will be blocked.', 'mailhook' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="spam_block_keyword_message"><?php _e( 'Blocked Keyword Message', 'mailhook' ); ?></label></th>
                                    <td>
                                        <input type="text" id="spam_block_keyword_message" name="spam_block_keyword_message" value="<?php echo esc_attr( $settings['spam_block_keyword_message'] ); ?>" class="large-text" />
                                        <p class="description"><?php _e( 'This message is shown when a user uses a blocked keyword.', 'mailhook' ); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <p class="submit">
                            <input type="submit" name="mailhook_save_settings" class="button button-primary button-hero"
                                   value="<?php _e( 'Save Settings', 'mailhook' ); ?>" />
                        </p>
                    </div><!-- /#tab-block -->

                </form>

                <!-- Test Email Section (General Tab Only) -->
                <div class="mailhook-card mailhook-test-card" id="mailhook-test-card-container">
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
                <p><?php printf( esc_html__( 'MailHook v%s &mdash; Lightweight SMTP for WordPress', 'mailhook' ), esc_html( MAILHOOK_VERSION ) ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render the HTML for a single additional connection row.
     *
     * @param array $conn  The connection data.
     * @param int   $index The index of the connection in the array.
     */
    public function render_connection_row( $conn = array(), $index = 0 ) {
        $defaults = array(
            'id'              => uniqid( 'mh_conn_' ),
            'name'            => __( 'New Connection', 'mailhook' ),
            'smtp_host'       => '',
            'smtp_port'       => '587',
            'smtp_encryption' => 'tls',
            'smtp_auth'       => '1',
            'smtp_username'   => '',
            'smtp_password'   => '',
            'from_email'      => '',
            'from_name'       => '',
            'is_collapsed'    => '0',
        );
        $conn = wp_parse_args( $conn, $defaults );
        $has_password = ! empty( $conn['smtp_password'] );
        $base_name = "additional_connections[{$index}]";
        $is_collapsed = isset($conn['is_collapsed']) && $conn['is_collapsed'] === '1';
        ?>
        <div class="mailhook-connection-row <?php echo $is_collapsed ? 'collapsed' : ''; ?>" data-index="<?php echo esc_attr( $index ); ?>" data-id="<?php echo esc_attr( $conn['id'] ); ?>">
            <input type="hidden" name="<?php echo esc_attr($base_name); ?>[is_collapsed]" class="mailhook-connection-collapsed-state" value="<?php echo $is_collapsed ? '1' : '0'; ?>">
            <div class="mailhook-connection-header">
                <div class="mailhook-connection-header-left">
                    <span class="mailhook-connection-toggle-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </span>
                    <h3 class="mailhook-connection-title"><?php echo esc_html( $conn['name'] ); ?></h3>
                </div>
                <div class="mailhook-connection-header-actions">
                    <button type="button" class="mailhook-remove-connection-btn" title="<?php esc_attr_e( 'Remove Connection', 'mailhook' ); ?>">
                        <?php _e( 'Remove', 'mailhook' ); ?>
                    </button>
                </div>
            </div> <!-- /.mailhook-connection-header -->

            <div class="mailhook-connection-body">
                <input type="hidden" name="<?php echo esc_attr($base_name); ?>[id]" value="<?php echo esc_attr( $conn['id'] ); ?>">
                
                <div class="mailhook-connection-grid">
                    <!-- Column 1 -->
                    <div class="mailhook-connection-column">
                        <div class="mailhook-field-group">
                            <label><?php _e( 'Connection Name', 'mailhook' ); ?></label>
                            <input type="text" name="<?php echo esc_attr($base_name); ?>[name]" value="<?php echo esc_attr( $conn['name'] ); ?>" class="mailhook-connection-name-input" required />
                            <p class="description"><?php _e( 'A friendly name to identify this connection.', 'mailhook' ); ?></p>
                        </div>

                        <div class="mailhook-field-group">
                            <label><?php _e( 'SMTP Host', 'mailhook' ); ?></label>
                            <input type="text" name="<?php echo esc_attr($base_name); ?>[smtp_host]" value="<?php echo esc_attr( $conn['smtp_host'] ); ?>" placeholder="smtp.example.com" />
                        </div>

                        <div class="mailhook-field-row">
                            <div class="mailhook-field-group">
                                <label><?php _e( 'SMTP Port', 'mailhook' ); ?></label>
                                <input type="number" name="<?php echo esc_attr($base_name); ?>[smtp_port]" value="<?php echo esc_attr( $conn['smtp_port'] ); ?>" />
                            </div>
                            <div class="mailhook-field-group">
                                <label><?php _e( 'Encryption', 'mailhook' ); ?></label>
                                <select name="<?php echo esc_attr($base_name); ?>[smtp_encryption]">
                                    <option value="none" <?php selected( $conn['smtp_encryption'], 'none' ); ?>><?php _e( 'None', 'mailhook' ); ?></option>
                                    <option value="ssl" <?php selected( $conn['smtp_encryption'], 'ssl' ); ?>><?php _e( 'SSL', 'mailhook' ); ?></option>
                                    <option value="tls" <?php selected( $conn['smtp_encryption'], 'tls' ); ?>><?php _e( 'TLS', 'mailhook' ); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="mailhook-field-group">
                            <label><?php _e( 'SMTP Authentication', 'mailhook' ); ?></label>
                            <div class="mailhook-radio-group">
                                <label>
                                    <input type="radio" name="<?php echo esc_attr($base_name); ?>[smtp_auth]" value="1" <?php checked( $conn['smtp_auth'], '1' ); ?> />
                                    <?php _e( 'Yes', 'mailhook' ); ?>
                                </label>
                                <label>
                                    <input type="radio" name="<?php echo esc_attr($base_name); ?>[smtp_auth]" value="0" <?php checked( $conn['smtp_auth'], '0' ); ?> />
                                    <?php _e( 'No', 'mailhook' ); ?>
                                </label>
                            </div>
                        </div>
                    </div> <!-- /.mailhook-connection-column-1 -->

                    <!-- Column 2 -->
                    <div class="mailhook-connection-column">
                        <div class="mailhook-field-group">
                            <label><?php _e( 'SMTP Username', 'mailhook' ); ?></label>
                            <input type="text" name="<?php echo esc_attr($base_name); ?>[smtp_username]" value="<?php echo esc_attr( $conn['smtp_username'] ); ?>" autocomplete="off" />
                        </div>

                        <div class="mailhook-field-group">
                            <label><?php _e( 'SMTP Password', 'mailhook' ); ?></label>
                            <input type="password" name="<?php echo esc_attr($base_name); ?>[smtp_password]" value="<?php echo $has_password ? '********' : ''; ?>" placeholder="<?php echo $has_password ? '********' : ''; ?>" autocomplete="new-password" />
                        </div>

                        <div class="mailhook-field-group">
                            <label><?php _e( 'From Email Override', 'mailhook' ); ?></label>
                            <input type="email" name="<?php echo esc_attr($base_name); ?>[from_email]" value="<?php echo esc_attr( $conn['from_email'] ); ?>" placeholder="noreply@example.com" />
                            <p class="description"><?php _e( 'Leave blank to use primary.', 'mailhook' ); ?></p>
                        </div>

                        <div class="mailhook-field-group">
                            <label><?php _e( 'From Name Override', 'mailhook' ); ?></label>
                            <input type="text" name="<?php echo esc_attr($base_name); ?>[from_name]" value="<?php echo esc_attr( $conn['from_name'] ); ?>" placeholder="My Site" />
                            <p class="description"><?php _e( 'Leave blank to use primary.', 'mailhook' ); ?></p>
                        </div>
                    </div> <!-- /.mailhook-connection-column-2 -->
                </div> <!-- /.mailhook-connection-grid -->
            </div> <!-- /.mailhook-connection-body -->
        </div> <!-- /.mailhook-connection-row -->
        <?php
    }

    /**
     * Render a single routing rule row.
     *
     * @param array $rule                   The rule data.
     * @param int   $rule_idx               The rule index.
     * @param array $additional_connections Available connections.
     */
    public function render_routing_rule_row( $rule, $rule_idx, $additional_connections ) {
        $connection_id = $rule['connection_id'] ?? '';
        $groups        = $rule['groups'] ?? array();
        $base_name     = "routing_rules[{$rule_idx}]";
        ?>
        <div class="mailhook-rule-row" data-index="<?php echo esc_attr( $rule_idx ); ?>">
            <div class="mailhook-rule-header">
                <div class="mailhook-rule-selector">
                    <span><?php _e( 'Send with', 'mailhook' ); ?></span>
                    <select name="<?php echo esc_attr( $base_name ); ?>[connection_id]" required>
                        <option value=""><?php _e( '-- Select a Connection --', 'mailhook' ); ?></option>
                        <?php foreach ( $additional_connections as $conn ) : ?>
                            <option value="<?php echo esc_attr( $conn['id'] ); ?>" <?php selected( $connection_id, $conn['id'] ); ?>>
                                <?php echo esc_html( $conn['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span><?php _e( 'if the following conditions are met...', 'mailhook' ); ?></span>
                </div>
                <div class="mailhook-rule-actions">
                    <button type="button" class="mailhook-remove-rule-btn" title="<?php esc_attr_e( 'Remove Rule', 'mailhook' ); ?>">&times;</button>
                </div>
            </div>

            <div class="mailhook-rule-body">
                <div class="mailhook-groups-container">
                    <?php 
                    foreach ( $groups as $group_idx => $group ) {
                        $this->render_routing_group_row( $group, $rule_idx, $group_idx );
                    }
                    if ( empty( $groups ) ) {
                        $this->render_routing_group_row( array(), $rule_idx, 0 );
                    }
                    ?>
                </div>
                <button type="button" class="button mailhook-add-group-btn"><?php _e( 'Add New Group', 'mailhook' ); ?></button>
            </div>
        </div>
        <?php
    }

    /**
     * Render a group of conditions within a rule.
     *
     * @param array $group     The group data.
     * @param int   $rule_idx  The rule index.
     * @param int   $group_idx The group index.
     */
    private function render_routing_group_row( $group, $rule_idx, $group_idx ) {
        $conditions = $group['conditions'] ?? array();
        $base_name  = "routing_rules[{$rule_idx}][groups][{$group_idx}]";
        ?>
        <div class="mailhook-group-row" data-index="<?php echo esc_attr( $group_idx ); ?>">
            <?php if ( $group_idx > 0 ) : ?>
                <div class="mailhook-group-separator"><span><?php _e( 'or', 'mailhook' ); ?></span></div>
            <?php endif; ?>
            
            <div class="mailhook-group-inner">
                <div class="mailhook-conditions-container">
                    <?php 
                    foreach ( $conditions as $cond_idx => $cond ) {
                        $this->render_routing_condition_row( $cond, $rule_idx, $group_idx, $cond_idx );
                    }
                    if ( empty( $conditions ) ) {
                        $this->render_routing_condition_row( array(), $rule_idx, $group_idx, 0 );
                    }
                    ?>
                </div>
                <div class="mailhook-group-actions">
                    <button type="button" class="mailhook-remove-group-btn" title="<?php esc_attr_e( 'Remove Group', 'mailhook' ); ?>">&times;</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single condition row.
     *
     * @param array $cond      The condition data.
     * @param int   $rule_idx  The rule index.
     * @param int   $group_idx The group index.
     * @param int   $cond_idx  The condition index.
     */
    private function render_routing_condition_row( $cond, $rule_idx, $group_idx, $cond_idx ) {
        $field    = $cond['field'] ?? 'subject';
        $operator = $cond['operator'] ?? 'contains';
        $value    = $cond['value'] ?? '';
        $base_name = "routing_rules[{$rule_idx}][groups][{$group_idx}][conditions][{$cond_idx}]";
        ?>
        <div class="mailhook-condition-row" data-index="<?php echo esc_attr( $cond_idx ); ?>">
            <select name="<?php echo esc_attr( $base_name ); ?>[field]">
                <option value="subject" <?php selected( $field, 'subject' ); ?>><?php _e( 'Subject', 'mailhook' ); ?></option>
                <option value="to" <?php selected( $field, 'to' ); ?>><?php _e( 'To', 'mailhook' ); ?></option>
                <option value="from" <?php selected( $field, 'from' ); ?>><?php _e( 'From', 'mailhook' ); ?></option>
                <option value="body" <?php selected( $field, 'body' ); ?>><?php _e( 'Body', 'mailhook' ); ?></option>
            </select>

            <select name="<?php echo esc_attr( $base_name ); ?>[operator]">
                <option value="contains" <?php selected( $operator, 'contains' ); ?>><?php _e( 'Contains', 'mailhook' ); ?></option>
                <option value="not_contains" <?php selected( $operator, 'not_contains' ); ?>><?php _e( 'Does not contain', 'mailhook' ); ?></option>
                <option value="equals" <?php selected( $operator, 'equals' ); ?>><?php _e( 'Is equal to', 'mailhook' ); ?></option>
                <option value="starts_with" <?php selected( $operator, 'starts_with' ); ?>><?php _e( 'Starts with', 'mailhook' ); ?></option>
                <option value="ends_with" <?php selected( $operator, 'ends_with' ); ?>><?php _e( 'Ends with', 'mailhook' ); ?></option>
            </select>

            <input type="text" name="<?php echo esc_attr( $base_name ); ?>[value]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php esc_attr_e( 'Value...', 'mailhook' ); ?>" />

            <div class="mailhook-condition-actions">
                <button type="button" class="button mailhook-add-condition-btn"><?php _e( 'And', 'mailhook' ); ?></button>
                <?php if ( $cond_idx > 0 ) : ?>
                    <button type="button" class="mailhook-remove-condition-btn" title="<?php esc_attr_e( 'Remove Condition', 'mailhook' ); ?>">&times;</button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Email Controls tab content.
     *
     * @param array $controls The saved control settings.
     */
    private function render_controls_tab( $controls ) {
        $sections = array(
            'comments' => array(
                'title' => __( 'Comments', 'mailhook' ),
                'fields' => array(
                    'comments_awaiting_moderation' => array(
                        'label' => __( 'Awaiting Moderation', 'mailhook' ),
                        'desc'  => __( 'Sent to site admin and post author when a comment needs approval.', 'mailhook' ),
                    ),
                    'comments_published' => array(
                        'label' => __( 'Published', 'mailhook' ),
                        'desc'  => __( 'Sent to the post author when a comment is approved and published.', 'mailhook' ),
                    ),
                ),
            ),
            'admin_email' => array(
                'title' => __( 'Change of Admin Email', 'mailhook' ),
                'fields' => array(
                    'admin_email_change_attempt' => array(
                        'label' => __( 'Site Admin Email Change Attempt', 'mailhook' ),
                        'desc'  => __( 'Change of site admin email address was attempted. Sent to the proposed new email address.', 'mailhook' ),
                    ),
                    'admin_email_changed' => array(
                        'label' => __( 'Site Admin Email Changed', 'mailhook' ),
                        'desc'  => __( 'Site admin email address was changed. Sent to the old site admin email address.', 'mailhook' ),
                    ),
                ),
            ),
            'user_email_pass' => array(
                'title' => __( 'Change of User Email or Password', 'mailhook' ),
                'fields' => array(
                    'user_reset_password_request' => array(
                        'label' => __( 'Reset Password Request', 'mailhook' ),
                        'desc'  => __( 'Sent to a user when they request to reset their account password.', 'mailhook' ),
                    ),
                    'user_reset_password_success' => array(
                        'label' => __( 'Password Reset Successfully', 'mailhook' ),
                        'desc'  => __( 'Sent to the site admin after a user successfully resets their password.', 'mailhook' ),
                    ),
                    'user_password_changed' => array(
                        'label' => __( 'Password Changed', 'mailhook' ),
                        'desc'  => __( 'Sent to a user after they have successfully changed their password.', 'mailhook' ),
                    ),
                    'user_email_change_attempt' => array(
                        'label' => __( 'Email Change Attempt', 'mailhook' ),
                        'desc'  => __( 'Sent to the new email address when a user attempts to change their email.', 'mailhook' ),
                    ),
                    'user_email_changed' => array(
                        'label' => __( 'Email Changed', 'mailhook' ),
                        'desc'  => __( 'Sent to a user after their email address has been successfully changed.', 'mailhook' ),
                    ),
                ),
            ),
            'personal_data' => array(
                'title' => __( 'Personal Data Requests', 'mailhook' ),
                'fields' => array(
                    'privacy_export_erasure_request' => array(
                        'label' => __( 'Export Request / Erasure Request', 'mailhook' ),
                        'desc'  => __( 'Sent to a user to confirm their request to export or erase personal data.', 'mailhook' ),
                    ),
                    'privacy_admin_erased_data' => array(
                        'label' => __( 'Admin Erased Data', 'mailhook' ),
                        'desc'  => __( 'Sent to a user when their personal data has been erased by the admin.', 'mailhook' ),
                    ),
                    'privacy_admin_sent_export_link' => array(
                        'label' => __( 'Admin Sent Link to Export Data', 'mailhook' ),
                        'desc'  => __( 'Sent to a user with a secure link to download their exported personal data.', 'mailhook' ),
                    ),
                ),
            ),
            'updates' => array(
                'title' => __( 'Automatic Updates', 'mailhook' ),
                'fields' => array(
                    'updates_plugin_status' => array(
                        'label' => __( 'Plugin Status', 'mailhook' ),
                        'desc'  => __( 'Sent after an automatic plugin update has completed or failed.', 'mailhook' ),
                    ),
                    'updates_theme_status' => array(
                        'label' => __( 'Theme Status', 'mailhook' ),
                        'desc'  => __( 'Sent after an automatic theme update has completed or failed.', 'mailhook' ),
                    ),
                    'updates_core_status' => array(
                        'label' => __( 'WordPress Core Status', 'mailhook' ),
                        'desc'  => __( 'Sent after an automatic WordPress core update has completed or failed.', 'mailhook' ),
                    ),
                    'updates_full_log' => array(
                        'label' => __( 'Full Log', 'mailhook' ),
                        'desc'  => __( 'Sent to provide a full report on all background update activities.', 'mailhook' ),
                    ),
                ),
            ),
            'new_user' => array(
                'title' => __( 'New User', 'mailhook' ),
                'fields' => array(
                    'new_user_admin_notification' => array(
                        'label' => __( 'New User (Admin Notification)', 'mailhook' ),
                        'desc'  => __( 'Sent to the site admin when a new user registers on the website.', 'mailhook' ),
                    ),
                    'new_user_user_notification' => array(
                        'label' => __( 'New User (User Notification)', 'mailhook' ),
                        'desc'  => __( 'Sent to a new user with their registration details and login link.', 'mailhook' ),
                    ),
                ),
            ),
        );

        foreach ( $sections as $section_id => $section ) : ?>
            <div class="mailhook-card mailhook-controls-section">
                <h2 class="mailhook-card-title"><?php echo esc_html( $section['title'] ); ?></h2>
                <table class="form-table mailhook-table">
                    <?php foreach ( $section['fields'] as $field_id => $field_data ) : 
                        $is_checked = isset( $controls[ $field_id ] ) ? $controls[ $field_id ] === '1' : true; // Default to checked (ON)
                        ?>
                        <tr>
                            <th><?php echo esc_html( $field_data['label'] ); ?></th>
                            <td>
                                <div class="mailhook-control-item">
                                    <label class="mailhook-toggle">
                                        <input type="hidden" name="controls[<?php echo esc_attr( $field_id ); ?>]" value="0" />
                                        <input type="checkbox" name="controls[<?php echo esc_attr( $field_id ); ?>]" value="1"
                                            <?php checked( $is_checked ); ?> />
                                        <span class="mailhook-toggle-slider"></span>
                                        <span class="mailhook-toggle-label"><?php echo $is_checked ? __( 'ON', 'mailhook' ) : __( 'OFF', 'mailhook' ); ?></span>
                                    </label>
                                    <span class="mailhook-control-description"><?php echo esc_html( $field_data['desc'] ); ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endforeach;
    }
}

