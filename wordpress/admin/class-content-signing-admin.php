<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/admin
 */

class ContentSigning_Admin {

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
     * The settings page handler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Admin_Settings    $settings    The settings page handler.
     */
    private $settings;

    /**
     * The server profiles page handler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Admin_ServerProfiles    $server_profiles    The server profiles page handler.
     */
    private $server_profiles;

    /**
     * The author profiles page handler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Admin_AuthorProfiles    $author_profiles    The author profiles page handler.
     */
    private $author_profiles;

    /**
     * The post meta box handler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Admin_PostMetaBox    $post_meta_box    The post meta box handler.
     */
    private $post_meta_box;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    ContentSigning_DB             $db          The database handler.
     * @param    ContentSigning_API_Client     $api_client  The API client.
     */
    public function __construct($db, $api_client) {
        $this->db = $db;
        $this->api_client = $api_client;
        
        // Initialize admin components
        $this->init_components();
    }

    /**
     * Initialize the admin components.
     *
     * @since    1.0.0
     * @access   private
     * @return   void
     */
    private function init_components() {
        // Initialize settings page
        $this->settings = new ContentSigning_Admin_Settings($this->db, $this->api_client);
        
        // Initialize server profiles page
        $this->server_profiles = new ContentSigning_Admin_ServerProfiles($this->db, $this->api_client);
        
        // Initialize author profiles page
        $this->author_profiles = new ContentSigning_Admin_AuthorProfiles($this->db, $this->api_client);
        
        // Initialize post meta box
        $this->post_meta_box = new ContentSigning_Admin_PostMetaBox($this->db, $this->api_client);
    }

    /**
     * Register the admin menu items.
     *
     * @since    1.0.0
     * @return   void
     */
    public function add_admin_menu() {
        // Main menu item
        add_menu_page(
            __('Content Signing', 'content-signing'),
            __('Content Signing', 'content-signing'),
            'manage_options',
            'content-signing',
            array($this->settings, 'render_page'),
            'dashicons-shield',
            100
        );
        
        // Settings submenu
        add_submenu_page(
            'content-signing',
            __('Settings', 'content-signing'),
            __('Settings', 'content-signing'),
            'manage_options',
            'content-signing',
            array($this->settings, 'render_page')
        );
        
        // Server Profiles submenu
        add_submenu_page(
            'content-signing',
            __('Server Profiles', 'content-signing'),
            __('Server Profiles', 'content-signing'),
            'manage_options',
            'content-signing-servers',
            array($this->server_profiles, 'render_page')
        );
        
        // Author Profiles submenu
        add_submenu_page(
            'content-signing',
            __('Author Profiles', 'content-signing'),
            __('Author Profiles', 'content-signing'),
            'manage_options',
            'content-signing-authors',
            array($this->author_profiles, 'render_page')
        );
    }

    /**
     * Register the settings.
     *
     * @since    1.0.0
     * @return   void
     */
    public function register_settings() {
        $this->settings->register_settings();
    }

    /**
     * Add meta boxes.
     *
     * @since    1.0.0
     * @return   void
     */
    public function add_meta_boxes() {
        $this->post_meta_box->add_meta_boxes();
    }

    /**
     * Save post meta.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @param    WP_Post   $post       The post object.
     * @return   void
     */
    public function save_post_meta($post_id, $post) {
        $this->post_meta_box->save_post_meta($post_id, $post);
    }

    /**
     * Add user profile fields.
     *
     * @since    1.0.0
     * @param    WP_User   $user    The user object.
     * @return   void
     */
    public function add_user_profile_fields($user) {
        $this->author_profiles->add_user_profile_fields($user);
    }

    /**
     * Save user profile fields.
     *
     * @since    1.0.0
     * @param    int       $user_id    The user ID.
     * @return   void
     */
    public function save_user_profile_fields($user_id) {
        $this->author_profiles->save_user_profile_fields($user_id);
    }

    /**
     * Enqueue admin scripts and styles.
     *
     * @since    1.0.0
     * @param    string    $hook    The current admin page.
     * @return   void
     */
    public function enqueue_scripts($hook) {
        // Enqueue common admin styles
        wp_enqueue_style(
            'content-signing-admin',
            CONTENT_SIGNING_PLUGIN_URL . 'admin/css/content-signing-admin.css',
            array(),
            CONTENT_SIGNING_VERSION,
            'all'
        );
        
        // Enqueue common admin scripts
        wp_enqueue_script(
            'content-signing-admin',
            CONTENT_SIGNING_PLUGIN_URL . 'admin/js/content-signing-admin.js',
            array('jquery'),
            CONTENT_SIGNING_VERSION,
            false
        );
        
        // Localize script with nonce and ajax url
        wp_localize_script(
            'content-signing-admin',
            'content_signing_admin',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('content_signing_nonce'),
            )
        );
        
        // Enqueue page-specific scripts and styles
        if (strpos($hook, 'content-signing') !== false) {
            // Settings page
            if ($hook === 'toplevel_page_content-signing') {
                $this->settings->enqueue_scripts();
            }
            
            // Server profiles page
            if ($hook === 'content-signing_page_content-signing-servers') {
                $this->server_profiles->enqueue_scripts();
            }
            
            // Author profiles page
            if ($hook === 'content-signing_page_content-signing-authors') {
                $this->author_profiles->enqueue_scripts();
            }
        }
        
        // Post edit screen
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            $this->post_meta_box->enqueue_scripts();
        }
    }
}