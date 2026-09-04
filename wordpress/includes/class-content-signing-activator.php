<?php
/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/includes
 */

class ContentSigning_Activator {

    /**
     * Activate the plugin.
     *
     * Creates the necessary database tables for the plugin.
     *
     * Idempotent by construction:
     *   - create_database_tables() uses dbDelta(), which diffs and ALTERs to
     *     match the desired schema rather than failing on existing tables.
     *   - set_default_options() uses add_option(), which is a no-op when the
     *     option already exists.
     *
     * Safe to call repeatedly. Also called lazily from
     * ContentSigning_Plugin::maybe_install_schema() to recover from edge
     * cases where the activation hook never fired (manual installs, site
     * clones, etc.).
     *
     * @since    1.0.0
     */
    public static function activate() {
        self::create_database_tables();
        self::set_default_options();
        update_option('content_signing_db_version', CONTENT_SIGNING_VERSION);
    }

    /**
     * Create the necessary database tables.
     *
     * @since    1.0.0
     */
    private static function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Table names
        $servers_table = $wpdb->prefix . 'content_signing_servers';
        $authors_table = $wpdb->prefix . 'content_signing_authors';
        $signatures_table = $wpdb->prefix . 'content_signing_signatures';
        
        // SQL for creating servers table
        $servers_sql = "CREATE TABLE $servers_table (
            server_id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            api_url varchar(255) NOT NULL,
            api_key_encrypted text NOT NULL,
            is_default_server tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (server_id)
        ) $charset_collate;";
        
        // SQL for creating authors table
        $authors_sql = "CREATE TABLE $authors_table (
            author_profile_id bigint(20) NOT NULL AUTO_INCREMENT,
            wp_user_id bigint(20) NOT NULL,
            signing_author_id varchar(255) NOT NULL,
            server_id bigint(20) NOT NULL,
            author_api_key_encrypted text NOT NULL,
            default_key_type varchar(50) NOT NULL,
            default_claims_json text,
            is_site_endorser tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (author_profile_id),
            KEY wp_user_id (wp_user_id),
            KEY server_id (server_id)
        ) $charset_collate;";
        
        // SQL for creating signatures table
        $signatures_sql = "CREATE TABLE $signatures_table (
            signature_id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            server_id bigint(20) NOT NULL,
            signing_author_id varchar(255) NOT NULL,
            wp_user_id bigint(20) NOT NULL,
            content_hash varchar(255) NOT NULL,
            domain varchar(255) NOT NULL,
            signature text NOT NULL,
            keyid varchar(255) DEFAULT NULL,
            public_key text,
            signing_mode varchar(32) NOT NULL DEFAULT 'remote',
            claims_json text,
            status varchar(50) NOT NULL,
            api_response_json text,
            signed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (signature_id),
            KEY post_id (post_id),
            KEY server_id (server_id),
            KEY wp_user_id (wp_user_id),
            KEY local_keyid (keyid(191), signing_mode)
        ) $charset_collate;";
        
        // Include WordPress database upgrade functions
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Create the tables
        dbDelta($servers_sql);
        dbDelta($authors_sql);
        dbDelta($signatures_sql);
    }

    /**
     * Set default options for the plugin.
     *
     * @since    1.0.0
     */
    private static function set_default_options() {
        // Default options
        $default_options = array(
            'enable_signing' => true,
            'enable_endorsements' => false,
            'sign_on_publish' => true,
            'sign_on_update' => false,
            'sign_days_before_publish' => 0,
            'sign_days_after_publish' => 0,
            'post_types' => array('post'),
            'endorser_profiles' => array(),
        );
        
        // Add options if they don't exist
        foreach ($default_options as $option_name => $option_value) {
            add_option('content_signing_' . $option_name, $option_value);
        }
    }
}
