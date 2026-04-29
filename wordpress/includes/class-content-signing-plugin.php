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
     * Track whether components have been initialized to avoid double-init.
     *
     * @since    1.0.1
     * @access   private
     * @var      bool
     */
    private $initialized = false;

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
     * Defers all DB-touching component initialization to the WordPress 'init'
     * action. This is important because the plugin file is loaded on every
     * request -- including the activation request -- and at plugin-load time
     * our custom tables (wp_content_signing_servers et al.) may not exist yet.
     *
     * Historical bug: init_components() previously ran synchronously here and
     * called $db->get_default_server(), which executes a SELECT against
     * wp_content_signing_servers. On a freshly-installed site, the activator
     * has not yet created that table when this code path is reached, causing
     * a fatal "table doesn't exist" error and breaking `wp plugin activate`.
     *
     * Deferring to 'init' means components are built only after WordPress is
     * fully bootstrapped and after register_activation_hook has had a chance
     * to run dbDelta. Hook *registration* (which is metadata-only and does
     * not query the DB) happens immediately so that we don't miss the early
     * action ordering that some hooks depend on -- but the registration
     * itself is just attaching a closure to 'init', not building anything.
     *
     * @since    1.0.0
     * @return   void
     */
    public function run() {
        // Defer real component construction until WP is ready and our tables
        // are guaranteed to exist (post-activation). Priority 5 so we are
        // ready before most other 'init' callers, but after WP core init.
        add_action('init', array($this, 'init_components'), 5);
    }

    /**
     * Initialize the plugin components.
     *
     * Public so it can be wired as an 'init' action callback. Idempotent:
     * safe to call multiple times -- subsequent calls are no-ops.
     *
     * @since    1.0.0
     * @return   void
     */
    public function init_components() {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        // Defensive: make sure tables exist before we ever touch them. This
        // protects against edge cases where the plugin file loads in a
        // request context that bypassed normal activation (e.g. a manual
        // 'must-use' install, or a site clone where the activation hook
        // never fired). Cheap because we gate on a version option.
        $this->maybe_install_schema();

        // Initialize database (constructor only stores wpdb refs / table names;
        // does not query).
        $this->db = new ContentSigning_DB();

        // Initialize scheduler (constructor stores db ref only).
        $this->scheduler = new ContentSigning_Scheduler($this->db);

        // Get default server -- this DOES query the DB. Now safe because
        // we are inside 'init' and tables exist.
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

        // Register the per-component hooks now that components exist.
        $this->hooks->register_hooks();
        $this->scheduler->register_hooks();
        $this->public->register_hooks();
    }

    /**
     * Ensure the plugin's schema is present, idempotently.
     *
     * Gated on the 'content_signing_db_version' option so we only re-run
     * dbDelta when the bundled version differs from the installed version.
     * dbDelta itself is idempotent (it diffs and ALTERs to match), so
     * calling this on every load would be safe but wasteful.
     *
     * @since    1.0.1
     * @access   private
     * @return   void
     */
    private function maybe_install_schema() {
        $installed = get_option('content_signing_db_version');
        if ($installed === CONTENT_SIGNING_VERSION) {
            return;
        }

        require_once CONTENT_SIGNING_PLUGIN_DIR . 'includes/class-content-signing-activator.php';
        ContentSigning_Activator::activate();
        update_option('content_signing_db_version', CONTENT_SIGNING_VERSION);
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