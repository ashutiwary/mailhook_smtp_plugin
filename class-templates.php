<?php
/**
 * MailHook — Email Templates
 *
 * Provides pre-designed HTML wrappers for outgoing emails,
 * and an option to create a custom HTML template.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MailHook_Templates {

    const OPTION_KEY = 'mailhook_templates';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
    }

    /**
     * Add the Templates sub-page under the MailHook top-level menu.
     */
    public function add_submenu_page() {
        add_submenu_page(
            'mailhook',
            __( 'Email Templates', 'mailhook' ),
            __( 'Templates', 'mailhook' ),
            'manage_options',
            'mailhook-templates',
            array( $this, 'render_page' )
        );
    }

    /**
     * Get default settings.
     */
    public static function get_defaults() {
        return array(
            'layout'       => 'none', // none, modern, classic, custom
            'primary_color'=> '#0129ac',
            'header_text'  => get_bloginfo( 'name' ),
            'footer_text'  => sprintf( __( 'Sent from %s', 'mailhook' ), get_bloginfo( 'name' ) ),
            'custom_html'  => '{email_content}',
        );
    }

    /**
     * Get current settings merged with defaults.
     */
    public static function get_settings() {
        $saved    = get_option( self::OPTION_KEY, array() );
        $defaults = self::get_defaults();
        return wp_parse_args( $saved, $defaults );
    }

    /**
     * Handle form submission on the Templates page.
     */
    public function handle_save() {
        if ( ! isset( $_POST['mailhook_save_templates'] ) ) {
            return;
        }

        if ( ! isset( $_POST['mailhook_templates_nonce'] ) || ! wp_verify_nonce( $_POST['mailhook_templates_nonce'], 'mailhook_save_templates' ) ) {
            wp_die( __( 'Security check failed.', 'mailhook' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized access.', 'mailhook' ) );
        }

        $settings = array(
            'layout'       => sanitize_key( $_POST['layout'] ?? 'none' ),
            'primary_color'=> sanitize_hex_color( $_POST['primary_color'] ?? '#0129ac' ),
            'header_text'  => sanitize_text_field( $_POST['header_text'] ?? '' ),
            'footer_text'  => sanitize_text_field( $_POST['footer_text'] ?? '' ),
            'custom_html'  => wp_kses_post( wp_unslash( $_POST['custom_html'] ?? '' ) ),
        );

        update_option( self::OPTION_KEY, $settings );

        wp_redirect( add_query_arg( array(
            'page'    => 'mailhook-templates',
            'saved'   => '1',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Get the HTML structure for a predefined template.
     *
     * @param string $layout 'modern' or 'classic'
     * @param array $settings
     * @return string
     */
    public static function get_template_html( $layout, $settings ) {
        if ( $layout === 'custom' ) {
            return $settings['custom_html'];
        }

        if ( $layout === 'none' ) {
            return '{email_content}';
        }

        $color  = esc_attr( $settings['primary_color'] );
        $header = esc_html( $settings['header_text'] );
        $footer = esc_html( $settings['footer_text'] );

        if ( $layout === 'modern' ) {
            return '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
            </head>
            <body style="margin:0; padding:0; background-color:#f4f4f7; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
                <div style="max-width:560px; margin:40px auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,0.08);">
                    <div style="background:' . $color . '; padding:32px; text-align:center;">
                        <h1 style="color:#ffffff; margin:0; font-size:24px;">' . $header . '</h1>
                    </div>
                    <div style="padding:32px;">
                        {email_content}
                    </div>
                    <div style="background:#f8fafc; padding:16px 32px; text-align:center; border-top:1px solid #e2e8f0;">
                        <p style="color:#94a3b8; font-size:12px; margin:0;">' . $footer . '</p>
                    </div>
                </div>
            </body>
            </html>';
        }

        if ( $layout === 'classic' ) {
            return '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
            </head>
            <body style="margin:0; padding:0; background-color:#ffffff; font-family:Arial,sans-serif;">
                <div style="max-width:600px; margin:0 auto; padding:20px; border:1px solid #eaeaeb;">
                    <div style="border-bottom:2px solid ' . $color . '; padding-bottom:15px; margin-bottom:20px;">
                        <h2 style="color:#333333; margin:0;">' . $header . '</h2>
                    </div>
                    <div style="color:#555555; line-height:1.6; font-size:14px;">
                        {email_content}
                    </div>
                    <div style="margin-top:30px; padding-top:15px; border-top:1px solid #eaeaeb; font-size:11px; color:#888888; text-align:center;">
                        ' . $footer . '
                    </div>
                </div>
            </body>
            </html>';
        }

        return '{email_content}';
    }

    /**
     * Render the admin page.
     */
    public function render_page() {
        $settings = self::get_settings();
        $saved    = isset( $_GET['saved'] ) && $_GET['saved'] === '1';
        ?>
        <div class="wrap mailhook-wrap">
            <div class="mailhook-header" style="background: linear-gradient(135deg, #0129ac, #011e80);">
                <h1>
                    <?php _e( 'Email Templates', 'mailhook' ); ?>
                </h1>
                <p class="mailhook-tagline"><?php _e( 'Wrap your unstyled WordPress emails in beautiful, responsive HTML templates.', 'mailhook' ); ?></p>
            </div>

            <?php if ( $saved ) : ?>
                <div class="mailhook-notice-wrap">
                    <div class="notice notice-success is-dismissible mailhook-notice">
                        <p><strong><?php _e( 'Template settings saved successfully!', 'mailhook' ); ?></strong></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mailhook-container">
                <form method="post" action="" class="mailhook-form">
                    <?php wp_nonce_field( 'mailhook_save_templates', 'mailhook_templates_nonce' ); ?>

                    <!-- Template Selection Grid -->
                    <div class="mailhook-card">
                        <h2 class="mailhook-card-title">1. <?php _e( 'Choose a Layout', 'mailhook' ); ?></h2>
                        <p class="description" style="margin-bottom:20px;"><?php _e( 'Select how you want your outgoing emails to look.', 'mailhook' ); ?></p>

                        <div class="mailhook-templates-grid">
                            
                            <!-- None -->
                            <label class="mailhook-template-card <?php echo $settings['layout'] === 'none' ? 'active' : ''; ?>">
                                <input type="radio" name="layout" value="none" <?php checked( $settings['layout'], 'none' ); ?> />
                                <div class="mailhook-template-preview mh-preview-none">
                                    <div class="mh-preview-text-lines">
                                        <div class="mh-line mh-w-80"></div>
                                        <div class="mh-line mh-w-90"></div>
                                        <div class="mh-line mh-w-60"></div>
                                    </div>
                                </div>
                                <div class="mailhook-template-info">
                                    <strong><?php _e( 'None (Raw)', 'mailhook' ); ?></strong>
                                    <p><?php _e( 'Send emails exactly as generated by WordPress or plugins. No HTML wrapped.', 'mailhook' ); ?></p>
                                </div>
                            </label>

                            <!-- Modern -->
                            <label class="mailhook-template-card <?php echo $settings['layout'] === 'modern' ? 'active' : ''; ?>">
                                <input type="radio" name="layout" value="modern" <?php checked( $settings['layout'], 'modern' ); ?> />
                                <div class="mailhook-template-preview mh-preview-modern">
                                    <div class="mh-preview-modern-header"></div>
                                    <div class="mh-preview-modern-body">
                                        <div class="mh-line mh-w-70"></div>
                                        <div class="mh-line mh-w-90"></div>
                                        <div class="mh-line mh-w-50"></div>
                                    </div>
                                </div>
                                <div class="mailhook-template-info">
                                    <strong><?php _e( 'Modern', 'mailhook' ); ?></strong>
                                    <p><?php _e( 'A sleek, card-based design with a colorful header and clear typography.', 'mailhook' ); ?></p>
                                </div>
                            </label>

                            <!-- Classic -->
                            <label class="mailhook-template-card <?php echo $settings['layout'] === 'classic' ? 'active' : ''; ?>">
                                <input type="radio" name="layout" value="classic" <?php checked( $settings['layout'], 'classic' ); ?> />
                                <div class="mailhook-template-preview mh-preview-classic">
                                    <div class="mh-preview-classic-inner">
                                        <div class="mh-preview-classic-header">
                                            <div class="mh-line mh-w-40 mh-h-thick"></div>
                                        </div>
                                        <div class="mh-preview-classic-body">
                                            <div class="mh-line mh-w-100"></div>
                                            <div class="mh-line mh-w-90"></div>
                                            <div class="mh-line mh-w-60"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mailhook-template-info">
                                    <strong><?php _e( 'Classic', 'mailhook' ); ?></strong>
                                    <p><?php _e( 'A clean, professional, minimalistic corporate template.', 'mailhook' ); ?></p>
                                </div>
                            </label>

                            <!-- Custom -->
                            <label class="mailhook-template-card <?php echo $settings['layout'] === 'custom' ? 'active' : ''; ?>">
                                <input type="radio" name="layout" value="custom" <?php checked( $settings['layout'], 'custom' ); ?> />
                                <div class="mailhook-template-preview mh-preview-custom">
                                    <span class="mh-code-icon">&lt;/&gt;</span>
                                </div>
                                <div class="mailhook-template-info">
                                    <strong><?php _e( 'Custom HTML', 'mailhook' ); ?></strong>
                                    <p><?php _e( 'Provide your own HTML wrapper for complete control.', 'mailhook' ); ?></p>
                                </div>
                            </label>

                        </div>
                    </div>

                    <!-- Customization Options -->
                    <div id="mh-options-wrapper" class="mailhook-card" style="<?php echo $settings['layout'] === 'none' ? 'display:none;' : ''; ?>">
                        
                        <div id="mh-standard-options" style="<?php echo in_array( $settings['layout'], array( 'modern', 'classic' ) ) ? '' : 'display:none;'; ?>">
                            <h2 class="mailhook-card-title">2. <?php _e( 'Template Settings', 'mailhook' ); ?></h2>
                            <table class="form-table mailhook-table">
                                <tr>
                                    <th><label for="primary_color"><?php _e( 'Primary Color', 'mailhook' ); ?></label></th>
                                    <td>
                                        <input type="color" id="primary_color" name="primary_color" value="<?php echo esc_attr( $settings['primary_color'] ); ?>" style="padding:0; height:32px; width:60px; border-radius:4px; cursor:pointer;" />
                                        <p class="description"><?php _e( 'Used for headers or accents.', 'mailhook' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="header_text"><?php _e( 'Header Text', 'mailhook' ); ?></label></th>
                                    <td>
                                        <input type="text" id="header_text" name="header_text" value="<?php echo esc_attr( $settings['header_text'] ); ?>" class="regular-text" />
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="footer_text"><?php _e( 'Footer Text', 'mailhook' ); ?></label></th>
                                    <td>
                                        <input type="text" id="footer_text" name="footer_text" value="<?php echo esc_attr( $settings['footer_text'] ); ?>" class="regular-text" />
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div id="mh-custom-options" style="<?php echo $settings['layout'] === 'custom' ? '' : 'display:none;'; ?>">
                            <h2 class="mailhook-card-title">2. <?php _e( 'Custom HTML Wrapper', 'mailhook' ); ?></h2>
                            <p class="description" style="margin-bottom:12px;">
                                <?php _e( 'Use the <code>{email_content}</code> placeholder where you want the body of the email to appear.', 'mailhook' ); ?>
                            </p>
                            <textarea id="custom_html" name="custom_html" rows="15" style="width:100%; font-family:monospace; padding:12px; border:1px solid #d1d5db; border-radius:8px; line-height:1.4; background:#f8fafc;"><?php echo esc_textarea( $settings['custom_html'] ); ?></textarea>
                        </div>
                    </div>

                    <p class="submit">
                        <input type="submit" name="mailhook_save_templates" class="button button-primary button-hero mh-green-btn"
                               value="<?php _e( 'Save Templates', 'mailhook' ); ?>" />
                    </p>
                </form>
            </div>
            
            <div class="mailhook-footer">
                <p><?php printf( __( 'MailHook v%s — Lightweight SMTP for WordPress', 'mailhook' ), MAILHOOK_VERSION ); ?></p>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var radios         = document.querySelectorAll('input[name="layout"]');
            var optionsWrapper = document.getElementById('mh-options-wrapper');
            var stdOptions     = document.getElementById('mh-standard-options');
            var custOptions    = document.getElementById('mh-custom-options');

            radios.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    // Update active class on cards
                    document.querySelectorAll('.mailhook-template-card').forEach(function(card) {
                        card.classList.remove('active');
                    });
                    this.closest('.mailhook-template-card').classList.add('active');

                    // Show/Hide sections
                    var val = this.value;
                    if (val === 'none') {
                        optionsWrapper.style.display = 'none';
                    } else {
                        optionsWrapper.style.display = 'block';
                        if (val === 'custom') {
                            stdOptions.style.display = 'none';
                            custOptions.style.display = 'block';
                        } else {
                            stdOptions.style.display = 'block';
                            custOptions.style.display = 'none';
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }
}
