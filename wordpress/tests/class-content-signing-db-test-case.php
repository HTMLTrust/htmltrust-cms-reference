<?php
/**
 * Database test case for Content Signing tests.
 *
 * @package Content_Signing
 */

/**
 * Database test case class.
 */
class ContentSigning_DB_TestCase extends ContentSigning_TestCase {

    /**
     * The database handler.
     *
     * @var ContentSigning_DB
     */
    protected $db;

    /**
     * Set up before each test.
     */
    public function setUp(): void {
        parent::setUp();
        
        // Get DB instance
        $this->db = $this->plugin->get_db();
        
        // Ensure tables exist
        $this->create_test_tables();
    }

    /**
     * Tear down after each test.
     */
    public function tearDown(): void {
        // Clean up test data
        $this->clean_test_data();
        
        parent::tearDown();
    }

    /**
     * Create test tables.
     */
    protected function create_test_tables() {
        global $wpdb;
        
        // Get table names
        $servers_table = $this->db->get_table_name('servers');
        $authors_table = $this->db->get_table_name('authors');
        $signatures_table = $this->db->get_table_name('signatures');
        
        // Check if tables exist
        $servers_exists = $wpdb->get_var("SHOW TABLES LIKE '$servers_table'") === $servers_table;
        $authors_exists = $wpdb->get_var("SHOW TABLES LIKE '$authors_table'") === $authors_table;
        $signatures_exists = $wpdb->get_var("SHOW TABLES LIKE '$signatures_table'") === $signatures_table;
        
        // If tables don't exist, create them
        if (!$servers_exists || !$authors_exists || !$signatures_exists) {
            // Get activator class
            require_once CONTENT_SIGNING_PLUGIN_DIR . 'includes/class-content-signing-activator.php';
            
            // Create tables
            ContentSigning_Activator::create_tables();
        }
    }

    /**
     * Clean test data.
     */
    protected function clean_test_data() {
        global $wpdb;
        
        // Get table names
        $servers_table = $this->db->get_table_name('servers');
        $authors_table = $this->db->get_table_name('authors');
        $signatures_table = $this->db->get_table_name('signatures');
        
        // Clean tables
        $wpdb->query("TRUNCATE TABLE $signatures_table");
        $wpdb->query("TRUNCATE TABLE $authors_table");
        $wpdb->query("TRUNCATE TABLE $servers_table");
    }

    /**
     * Create a test server.
     *
     * @param array $data Server data.
     * @return int Server ID.
     */
    protected function create_test_server($data = array()) {
        $defaults = array(
            'name' => 'Test Server',
            'api_url' => 'https://test-api.example.com',
            'api_key' => 'test-api-key',
            'is_default_server' => 1,
        );

        $data = wp_parse_args($data, $defaults);
        
        return $this->db->insert_server($data);
    }

    /**
     * Create a test author.
     *
     * @param array $data Author data.
     * @return int Author profile ID.
     */
    protected function create_test_author($data = array()) {
        // Create a server if none provided
        if (empty($data['server_id'])) {
            $data['server_id'] = $this->create_test_server();
        }
        
        // Create a user if none provided
        if (empty($data['wp_user_id'])) {
            $data['wp_user_id'] = $this->create_test_user();
        }
        
        $defaults = array(
            'signing_author_id' => 'test-author-' . rand(1000, 9999),
            'author_api_key' => 'test-author-api-key',
            'default_key_type' => 'HUMAN',
            'default_claims' => array('ContentType' => 'Article'),
            'is_site_endorser' => 0,
        );

        $data = wp_parse_args($data, $defaults);
        
        return $this->db->insert_author($data);
    }

    /**
     * Create a test signature.
     *
     * @param array $data Signature data.
     * @return int Signature ID.
     */
    protected function create_test_signature($data = array()) {
        // Create a post if none provided
        if (empty($data['post_id'])) {
            $data['post_id'] = $this->create_test_post();
        }
        
        // Create a server if none provided
        if (empty($data['server_id'])) {
            $data['server_id'] = $this->create_test_server();
        }
        
        $defaults = array(
            'signing_author_id' => 'test-author-' . rand(1000, 9999),
            'wp_user_id' => 1,
            'content_hash' => 'sha256:' . md5('test-content'),
            'domain' => 'example.com',
            'signature' => 'test-signature-' . rand(1000, 9999),
            'claims' => array('ContentType' => 'Article'),
            'status' => 'signed',
            'signed_at' => current_time('mysql'),
        );

        $data = wp_parse_args($data, $defaults);
        
        return $this->db->insert_signature($data);
    }
}
