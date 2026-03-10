<?php
/**
 * MailHook — Uninstall Handler
 *
 * Runs only when the plugin is deleted from WP Admin → Plugins → Delete.
 * Removes all plugin data: options and the custom database table.
 *
 * @package MailHook
 */

// Security: only run via WordPress uninstall process
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Remove plugin options
delete_option( 'mailhook_settings' );
delete_option( 'mailhook_templates' );
delete_option( 'mailhook_db_version' );

// Drop the custom email logs table
$table = $wpdb->prefix . 'mailhook_logs';

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
