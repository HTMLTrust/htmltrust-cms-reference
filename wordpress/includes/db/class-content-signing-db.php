<?php
/**
 * Database operations for the Content Signing plugin.
 *
 * This class handles all database interactions for the plugin.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/includes/db
 */

class ContentSigning_DB {

    /**
     * The WordPress database object.
     *
     * @since    1.0.0
     * @access   private
     * @var      wpdb    $wpdb    The WordPress database object.
     */
    private $wpdb;

    /**
     * Table names.
     *
     * @since    1.0.0
     * @access   private
     * @var      array    $tables    The plugin's table names.
     */
    private $tables;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        
        // Define table names
        $this->tables = array(
            'servers' => $wpdb->prefix . 'content_signing_servers',
            'authors' => $wpdb->prefix . 'content_signing_authors',
            'signatures' => $wpdb->prefix . 'content_signing_signatures',
        );
    }

    /**
     * Get a table name.
     *
     * @since    1.0.0
     * @param    string    $table    The table identifier.
     * @return   string              The full table name.
     */
    public function get_table_name($table) {
        return isset($this->tables[$table]) ? $this->tables[$table] : '';
    }

    /**
     * Encrypt sensitive data.
     *
     * @since    1.0.0
     * @param    string    $data    The data to encrypt.
     * @return   string             The encrypted data.
     */
    public function encrypt($data) {
        // For simplicity, we're using base64 encoding here
        // In a production environment, use a more secure encryption method
        // Consider using WordPress's Sodium compatibility layer if available
        return base64_encode($data);
    }

    /**
     * Decrypt sensitive data.
     *
     * @since    1.0.0
     * @param    string    $data    The data to decrypt.
     * @return   string             The decrypted data.
     */
    public function decrypt($data) {
        // For simplicity, we're using base64 decoding here
        // In a production environment, use a more secure decryption method
        // Consider using WordPress's Sodium compatibility layer if available
        return base64_decode($data);
    }

    /**
     * Insert a server profile.
     *
     * @since    1.0.0
     * @param    array    $data    The server data.
     * @return   int|false         The server ID on success, false on failure.
     */
    public function insert_server($data) {
        $now = current_time('mysql');
        
        $defaults = array(
            'name' => '',
            'api_url' => '',
            'api_key_encrypted' => '',
            'is_default_server' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Encrypt the API key
        if (!empty($data['api_key'])) {
            $data['api_key_encrypted'] = $this->encrypt($data['api_key']);
            unset($data['api_key']);
        }
        
        // If this is set as default, unset any existing defaults
        if ($data['is_default_server']) {
            $this->wpdb->update(
                $this->tables['servers'],
                array('is_default_server' => 0),
                array('is_default_server' => 1)
            );
        }
        
        // Insert the server
        $result = $this->wpdb->insert($this->tables['servers'], $data);
        
        return $result ? $this->wpdb->insert_id : false;
    }

    /**
     * Update a server profile.
     *
     * @since    1.0.0
     * @param    int      $server_id    The server ID.
     * @param    array    $data         The server data.
     * @return   bool                   True on success, false on failure.
     */
    public function update_server($server_id, $data) {
        $data['updated_at'] = current_time('mysql');
        
        // Encrypt the API key if provided
        if (!empty($data['api_key'])) {
            $data['api_key_encrypted'] = $this->encrypt($data['api_key']);
            unset($data['api_key']);
        }
        
        // If this is set as default, unset any existing defaults
        if (isset($data['is_default_server']) && $data['is_default_server']) {
            $this->wpdb->update(
                $this->tables['servers'],
                array('is_default_server' => 0),
                array('is_default_server' => 1)
            );
        }
        
        // Update the server
        $result = $this->wpdb->update(
            $this->tables['servers'],
            $data,
            array('server_id' => $server_id)
        );
        
        return $result !== false;
    }

    /**
     * Delete a server profile.
     *
     * @since    1.0.0
     * @param    int      $server_id    The server ID.
     * @return   bool                   True on success, false on failure.
     */
    public function delete_server($server_id) {
        return $this->wpdb->delete(
            $this->tables['servers'],
            array('server_id' => $server_id)
        ) !== false;
    }

    /**
     * Get a server profile.
     *
     * @since    1.0.0
     * @param    int      $server_id    The server ID.
     * @return   object|null            The server data or null if not found.
     */
    public function get_server($server_id) {
        $server = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['servers']} WHERE server_id = %d",
                $server_id
            )
        );
        
        return $server;
    }

    /**
     * Get the default server profile.
     *
     * @since    1.0.0
     * @return   object|null    The default server data or null if not found.
     */
    public function get_default_server() {
        return $this->wpdb->get_row(
            "SELECT * FROM {$this->tables['servers']} WHERE is_default_server = 1"
        );
    }

    /**
     * Get all server profiles.
     *
     * @since    1.0.0
     * @return   array    The server profiles.
     */
    public function get_servers() {
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->tables['servers']} ORDER BY name ASC"
        );
    }

    /**
     * Insert an author profile.
     *
     * @since    1.0.0
     * @param    array    $data    The author data.
     * @return   int|false         The author profile ID on success, false on failure.
     */
    public function insert_author($data) {
        $now = current_time('mysql');
        
        $defaults = array(
            'wp_user_id' => 0,
            'signing_author_id' => '',
            'server_id' => 0,
            'author_api_key_encrypted' => '',
            'default_key_type' => 'HUMAN',
            'default_claims_json' => '{}',
            'is_site_endorser' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Encrypt the API key
        if (!empty($data['author_api_key'])) {
            $data['author_api_key_encrypted'] = $this->encrypt($data['author_api_key']);
            unset($data['author_api_key']);
        }
        
        // Encode default claims as JSON if it's an array
        if (isset($data['default_claims']) && is_array($data['default_claims'])) {
            $data['default_claims_json'] = wp_json_encode($data['default_claims']);
            unset($data['default_claims']);
        }
        
        // Insert the author
        $result = $this->wpdb->insert($this->tables['authors'], $data);
        
        return $result ? $this->wpdb->insert_id : false;
    }

    /**
     * Update an author profile.
     *
     * @since    1.0.0
     * @param    int      $author_profile_id    The author profile ID.
     * @param    array    $data                 The author data.
     * @return   bool                           True on success, false on failure.
     */
    public function update_author($author_profile_id, $data) {
        $data['updated_at'] = current_time('mysql');
        
        // Encrypt the API key if provided
        if (!empty($data['author_api_key'])) {
            $data['author_api_key_encrypted'] = $this->encrypt($data['author_api_key']);
            unset($data['author_api_key']);
        }
        
        // Encode default claims as JSON if it's an array
        if (isset($data['default_claims']) && is_array($data['default_claims'])) {
            $data['default_claims_json'] = wp_json_encode($data['default_claims']);
            unset($data['default_claims']);
        }
        
        // Update the author
        $result = $this->wpdb->update(
            $this->tables['authors'],
            $data,
            array('author_profile_id' => $author_profile_id)
        );
        
        return $result !== false;
    }

    /**
     * Delete an author profile.
     *
     * @since    1.0.0
     * @param    int      $author_profile_id    The author profile ID.
     * @return   bool                           True on success, false on failure.
     */
    public function delete_author($author_profile_id) {
        return $this->wpdb->delete(
            $this->tables['authors'],
            array('author_profile_id' => $author_profile_id)
        ) !== false;
    }

    /**
     * Get an author profile.
     *
     * @since    1.0.0
     * @param    int      $author_profile_id    The author profile ID.
     * @return   object|null                    The author data or null if not found.
     */
    public function get_author($author_profile_id) {
        $author = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['authors']} WHERE author_profile_id = %d",
                $author_profile_id
            )
        );
        
        return $author;
    }

    /**
     * Get an author profile by WordPress user ID.
     *
     * @since    1.0.0
     * @param    int      $wp_user_id    The WordPress user ID.
     * @return   object|null             The author data or null if not found.
     */
    public function get_author_by_wp_user_id($wp_user_id) {
        $author = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['authors']} WHERE wp_user_id = %d",
                $wp_user_id
            )
        );
        
        return $author;
    }

    /**
     * Get all author profiles.
     *
     * @since    1.0.0
     * @param    array    $args    Query arguments.
     * @return   array             The author profiles.
     */
    public function get_authors($args = array()) {
        $defaults = array(
            'is_site_endorser' => null,
            'server_id' => null,
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $query = "SELECT a.*, u.display_name as wp_user_display_name 
                 FROM {$this->tables['authors']} a
                 LEFT JOIN {$this->wpdb->users} u ON a.wp_user_id = u.ID";
        
        $where = array();
        $values = array();
        
        if (isset($args['is_site_endorser'])) {
            $where[] = "a.is_site_endorser = %d";
            $values[] = $args['is_site_endorser'];
        }
        
        if (isset($args['server_id'])) {
            $where[] = "a.server_id = %d";
            $values[] = $args['server_id'];
        }
        
        if (!empty($where)) {
            $query .= " WHERE " . implode(" AND ", $where);
            $query = $this->wpdb->prepare($query, $values);
        }
        
        $query .= " ORDER BY u.display_name ASC";
        
        return $this->wpdb->get_results($query);
    }

    /**
     * Get all site endorser profiles.
     *
     * @since    1.0.0
     * @return   array    The site endorser profiles.
     */
    public function get_site_endorsers() {
        return $this->get_authors(array('is_site_endorser' => 1));
    }

    /**
     * Insert a signature.
     *
     * @since    1.0.0
     * @param    array    $data    The signature data.
     * @return   int|false         The signature ID on success, false on failure.
     */
    public function insert_signature($data) {
        $now = current_time('mysql');
        
        $defaults = array(
            'post_id' => 0,
            'server_id' => 0,
            'signing_author_id' => '',
            'wp_user_id' => get_current_user_id(),
            'content_hash' => '',
            'domain' => '',
            'signature' => '',
            'claims_json' => '{}',
            'status' => 'pending',
            'api_response_json' => null,
            'signed_at' => null,
            'created_at' => $now,
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Encode claims as JSON if it's an array
        if (isset($data['claims']) && is_array($data['claims'])) {
            $data['claims_json'] = wp_json_encode($data['claims']);
            unset($data['claims']);
        }
        
        // Encode API response as JSON if it's an array
        if (isset($data['api_response']) && is_array($data['api_response'])) {
            $data['api_response_json'] = wp_json_encode($data['api_response']);
            unset($data['api_response']);
        }
        
        // Insert the signature
        $result = $this->wpdb->insert($this->tables['signatures'], $data);
        
        return $result ? $this->wpdb->insert_id : false;
    }

    /**
     * Update a signature.
     *
     * @since    1.0.0
     * @param    int      $signature_id    The signature ID.
     * @param    array    $data            The signature data.
     * @return   bool                      True on success, false on failure.
     */
    public function update_signature($signature_id, $data) {
        // Encode claims as JSON if it's an array
        if (isset($data['claims']) && is_array($data['claims'])) {
            $data['claims_json'] = wp_json_encode($data['claims']);
            unset($data['claims']);
        }
        
        // Encode API response as JSON if it's an array
        if (isset($data['api_response']) && is_array($data['api_response'])) {
            $data['api_response_json'] = wp_json_encode($data['api_response']);
            unset($data['api_response']);
        }
        
        // Update the signature
        $result = $this->wpdb->update(
            $this->tables['signatures'],
            $data,
            array('signature_id' => $signature_id)
        );
        
        return $result !== false;
    }

    /**
     * Delete a signature.
     *
     * @since    1.0.0
     * @param    int      $signature_id    The signature ID.
     * @return   bool                      True on success, false on failure.
     */
    public function delete_signature($signature_id) {
        return $this->wpdb->delete(
            $this->tables['signatures'],
            array('signature_id' => $signature_id)
        ) !== false;
    }

    /**
     * Get a signature.
     *
     * @since    1.0.0
     * @param    int      $signature_id    The signature ID.
     * @return   object|null               The signature data or null if not found.
     */
    public function get_signature($signature_id) {
        $signature = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['signatures']} WHERE signature_id = %d",
                $signature_id
            )
        );
        
        return $signature;
    }

    /**
     * Get signatures for a post.
     *
     * @since    1.0.0
     * @param    int      $post_id    The post ID.
     * @return   array                The signatures.
     */
    public function get_signatures_by_post_id($post_id) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT s.*, a.wp_user_id, a.default_key_type, srv.name as server_name
                 FROM {$this->tables['signatures']} s
                 LEFT JOIN {$this->tables['authors']} a ON s.signing_author_id = a.signing_author_id
                 LEFT JOIN {$this->tables['servers']} srv ON s.server_id = srv.server_id
                 WHERE s.post_id = %d
                 ORDER BY s.created_at DESC",
                $post_id
            )
        );
    }

    /**
     * Get pending signatures.
     *
     * @since    1.0.0
     * @param    int      $limit    The maximum number of signatures to return.
     * @return   array              The pending signatures.
     */
    public function get_pending_signatures($limit = 50) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['signatures']} 
                 WHERE status = 'pending' 
                 ORDER BY created_at ASC 
                 LIMIT %d",
                $limit
            )
        );
    }
}