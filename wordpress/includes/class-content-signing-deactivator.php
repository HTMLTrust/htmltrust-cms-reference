<?php
/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/includes
 */

class ContentSigning_Deactivator {

    /**
     * Deactivate the plugin.
     *
     * Performs cleanup operations when the plugin is deactivated.
     * Note: We don't drop tables on deactivation to prevent data loss.
     *
     * @since    1.0.0
     */
    public static function deactivate() {
        // Clear any scheduled events
        self::clear_scheduled_events();
    }

    /**
     * Clear any scheduled events created by this plugin.
     *
     * @since    1.0.0
     */
    private static function clear_scheduled_events() {
        // Clear any scheduled cron events
        wp_clear_scheduled_hook('content_signing_scheduled_signing');
    }

    /**
     * Drop plugin database tables.
     * 
     * This method is not called during normal deactivation to prevent data loss.
     * It could be used during uninstallation if desired.
     *
     * @since    1.0.0
     */
    public static function drop_database_tables() {
        global $wpdb;
        
        // Table names
        $servers_table = $wpdb->prefix . 'content_signing_servers';
        $authors_table = $wpdb->prefix . 'content_signing_authors';
        $signatures_table = $wpdb->prefix . 'content_signing_signatures';
        
        // Drop tables
        $wpdb->query("DROP TABLE IF EXISTS $signatures_table");
        $wpdb->query("DROP TABLE IF EXISTS $authors_table");
        $wpdb->query("DROP TABLE IF EXISTS $servers_table");
    }
}