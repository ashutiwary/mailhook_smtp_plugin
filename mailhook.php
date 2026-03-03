<?php
/**
 * Plugin Name: MailHook
 * Plugin URI:  https://github.com/your-username/mailhook
 * Description: A lightweight WordPress SMTP plugin that reconfigures wp_mail() to send emails through any SMTP server — reliable, secure, and easy to set up.
 * Version:     1.0.0
 * Author:      Ashu Tiwary
 * Author URI:  https://yourwebsite.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mailhook
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'MAILHOOK_VERSION', '1.0.0' );
define( 'MAILHOOK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAILHOOK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MAILHOOK_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Include class files
require_once MAILHOOK_PLUGIN_DIR . 'class-settings.php';
require_once MAILHOOK_PLUGIN_DIR . 'class-templates.php';
require_once MAILHOOK_PLUGIN_DIR . 'class-mailer.php';
require_once MAILHOOK_PLUGIN_DIR . 'class-test-mail.php';
require_once MAILHOOK_PLUGIN_DIR . 'class-logger.php';

/**
 * Initialize the plugin on plugins_loaded.
 */
function mailhook_init() {
    // Initialize settings page (admin only)
    if ( is_admin() ) {
        new MailHook_Settings();
        new MailHook_Templates();
        new MailHook_Test_Mail();
    }

    // Initialize mailer (always — so all wp_mail calls go through SMTP)
    new MailHook_Mailer();

    // Initialize email logger (always — captures all wp_mail events)
    new MailHook_Logger();
}
add_action( 'plugins_loaded', 'mailhook_init' );

/**
 * Add "Settings" link on the Plugins page.
 */
function mailhook_action_links( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=mailhook' ) . '">' . __( 'Settings', 'mailhook' ) . '</a>';
    $logs_link     = '<a href="' . admin_url( 'admin.php?page=mailhook-logs' ) . '">' . __( 'Logs', 'mailhook' ) . '</a>';
    array_unshift( $links, $logs_link );
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . MAILHOOK_PLUGIN_BASENAME, 'mailhook_action_links' );

/**
 * Set default options on activation.
 */
function mailhook_activate() {
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

    // Only set defaults if options don't already exist
    if ( ! get_option( 'mailhook_settings' ) ) {
        update_option( 'mailhook_settings', $defaults );
    }

    // Default template settings
    if ( ! get_option( 'mailhook_templates' ) ) {
        require_once MAILHOOK_PLUGIN_DIR . 'class-templates.php';
        update_option( 'mailhook_templates', MailHook_Templates::get_defaults() );
    }

    // Create the email logs database table
    require_once MAILHOOK_PLUGIN_DIR . 'class-logger.php';
    MailHook_Logger::install_table();
}
register_activation_hook( __FILE__, 'mailhook_activate' );
