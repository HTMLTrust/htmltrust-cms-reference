<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks,
 * and public-facing site hooks.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/includes
 */

class ContentSigning_Plugin {

    /**
     * The unique instance of the plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Plugin    $instance    The single instance of the class.
     */
    private static $instance = null;

    /**
     * The database handler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_DB    $db    The database handler.
     */
    private $db;

    /**
     * The API client.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_API_Client    $api_client    The API client.
     */
    private $api_client;

    /**
     * The scheduler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Scheduler    $scheduler    The scheduler.
     */
    private $scheduler;

    /**
     * The signing service.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Signing_Service    $signing_service    The signing service.
     */
    private $signing_service;

    /**
     * The admin handler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Admin    $admin    The admin handler.
     */
    private $admin;

    /**
     * The hooks handler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Hooks    $hooks    The hooks handler.
     */
    private $hooks;

    /**
     * The public handler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Public    $public    The public handler.
     */
    private $public;

    /**
     * Get the unique instance of the plugin.
     *
     * @since    1.0.0
     * @return   ContentSigning_Plugin    The single instance of the class.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    /**
     * Define the core functionality of the plugin.
     *
     * @since    1.0.0
     */
    private function __construct() {
        $this->load_dependencies();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @return   void
     */
    private function load_dependencies() {
        // Database
        require_once CONTENT_SIGNING_PLUGIN_DIR . 'includes/db/class-content-signing-db.php';
        
        // API Client
        require_once CONTENT_SIGNING_PLUGIN_DIR . 'includes/class-content-signing-api-client.php';
        
        // Scheduler
        require_once CONTENT_SIGNING_PLUGIN_DIR . 'includes/class-content-signing-scheduler.php';
        
        // Signing Service
        require_once CONTENT_SIGNING_PLUGIN_DIR . 'includes/class-content-signing-signing-service.php';
        
        // Admin
        require_once CONTENT_SIGNING_PLUGIN_DIR . 'admin/class-content-signing-admin.php';
        require_once CONTENT_SIGNING_PLUGIN_DIR . 'admin/class-content-signing-admin-settings.php';
        require_once CONTENT_SIGNING_PLUGIN_DIR . 'admin/class-content-signing-admin-server-profiles.php';
        require_once CONTENT_SIGNING_PLUGIN_DIR . 'admin/class-content-signing-admin-author-profiles.php';
        require_once CONTENT_SIGNING_PLUGIN_DIR . 'admin/class-content-signing-admin-post-meta-box.php';
        
        // Public
        require_once CONTENT_SIGNING_PLUGIN_DIR . 'public/class-content-signing-public.php';
        
        // Hooks
        require_once CONTENT_SIGNING_PLUGIN_DIR . 'includes/class-content-signing-hooks.php';
    }

    /**
     * Run the plugin.
     *
     * @since    1.0.0
     * @return   void
     */
    public function run() {
        // Initialize components
        $this->init_components();
        
        // Register hooks
        $this->hooks->register_hooks();
        $this->scheduler->register_hooks();
        $this->public->register_hooks();
    }

    /**
     * Initialize the plugin components.
     *
     * @since    1.0.0
     * @access   private
     * @return   void
     */
    private function init_components() {
        // Initialize database
        $this->db = new ContentSigning_DB();
        
        // Initialize scheduler
        $this->scheduler = new ContentSigning_Scheduler($this->db);
        
        // Get default server
        $default_server = $this->db->get_default_server();
        $api_url = '';
        $general_api_key = '';
        
        if ($default_server) {
            $api_url = $default_server->api_url;
            $general_api_key = $this->db->decrypt($default_server->api_key_encrypted);
        }
        
        // Initialize API client
        $this->api_client = new ContentSigning_API_Client($api_url, $general_api_key, $this->db);
        
        // Initialize signing service
        $this->signing_service = new ContentSigning_Signing_Service($this->db, $this->api_client, $this->scheduler);
        
        // Initialize admin
        $this->admin = new ContentSigning_Admin($this->db, $this->api_client);
        
        // Initialize public
        $this->public = new ContentSigning_Public($this->db, $this->api_client);
        
        // Initialize hooks
        $this->hooks = new ContentSigning_Hooks($this->signing_service, $this->admin);
    }

    /**
     * Get the database handler.
     *
     * @since    1.0.0
     * @return   ContentSigning_DB    The database handler.
     */
    public function get_db() {
        return $this->db;
    }

    /**
     * Get the API client.
     *
     * @since    1.0.0
     * @return   ContentSigning_API_Client    The API client.
     */
    public function get_api_client() {
        return $this->api_client;
    }

    /**
     * Get the scheduler.
     *
     * @since    1.0.0
     * @return   ContentSigning_Scheduler    The scheduler.
     */
    public function get_scheduler() {
        return $this->scheduler;
    }

    /**
     * Get the signing service.
     *
     * @since    1.0.0
     * @return   ContentSigning_Signing_Service    The signing service.
     */
    public function get_signing_service() {
        return $this->signing_service;
    }

    /**
     * Get the admin handler.
     *
     * @since    1.0.0
     * @return   ContentSigning_Admin    The admin handler.
     */
    public function get_admin() {
        return $this->admin;
    }

    /**
     * Get the hooks handler.
     *
     * @since    1.0.0
     * @return   ContentSigning_Hooks    The hooks handler.
     */
    public function get_hooks() {
        return $this->hooks;
    }

    /**
     * Get the public handler.
     *
     * @since    1.0.0
     * @return   ContentSigning_Public    The public handler.
     */
    public function get_public() {
        return $this->public;
    }
}