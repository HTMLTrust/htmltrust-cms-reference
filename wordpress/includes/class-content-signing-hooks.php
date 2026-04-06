<?php
/**
 * Content Signing Hooks.
 *
 * This class centralizes the registration and callback logic for WordPress actions and filters.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/includes
 */

class ContentSigning_Hooks {

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
     * The public handler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Public    $public    The public handler.
     */
    private $public;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    ContentSigning_Signing_Service    $signing_service    The signing service.
     * @param    ContentSigning_Admin              $admin              The admin handler.
     */
    public function __construct($signing_service, $admin) {
        $this->signing_service = $signing_service;
        $this->admin = $admin;
        
        // Get the plugin instance to access the public handler
        $plugin = ContentSigning_Plugin::get_instance();
        $this->public = $plugin->get_public();
    }

    /**
     * Register all hooks.
     *
     * @since    1.0.0
     * @return   void
     */
    public function register_hooks() {
        // Post hooks
        $this->register_post_hooks();
        
        // Admin hooks
        $this->register_admin_hooks();
        
        // Frontend hooks
        $this->register_frontend_hooks();
    }

    /**
     * Register post-related hooks.
     *
     * @since    1.0.0
     * @return   void
     */
    private function register_post_hooks() {
        // Get enabled post types
        $post_types = get_option('content_signing_post_types', array('post'));
        
        // Register hooks for each post type
        foreach ($post_types as $post_type) {
            // When a post is saved
            add_action("save_post_{$post_type}", array($this, 'on_save_post'), 10, 3);
            
            // When a post is published
            add_action("publish_{$post_type}", array($this, 'on_publish_post'), 10, 2);
            
            // When a post transitions from future to publish
            add_action('future_to_publish', array($this, 'on_future_to_publish'), 10, 1);
        }
        
        // When a post status changes
        add_action('transition_post_status', array($this, 'on_transition_post_status'), 10, 3);
    }

    /**
     * Register admin-related hooks.
     *
     * @since    1.0.0
     * @return   void
     */
    private function register_admin_hooks() {
        // Admin menu and settings
        add_action('admin_menu', array($this->admin, 'add_admin_menu'));
        add_action('admin_init', array($this->admin, 'register_settings'));
        
        // Meta boxes
        add_action('add_meta_boxes', array($this->admin, 'add_meta_boxes'));
        add_action('save_post', array($this->admin, 'save_post_meta'), 10, 2);
        
        // User profile
        add_action('show_user_profile', array($this->admin, 'add_user_profile_fields'));
        add_action('edit_user_profile', array($this->admin, 'add_user_profile_fields'));
        add_action('personal_options_update', array($this->admin, 'save_user_profile_fields'));
        add_action('edit_user_profile_update', array($this->admin, 'save_user_profile_fields'));
        
        // AJAX handlers
        add_action('wp_ajax_content_signing_sign_post', array($this, 'ajax_sign_post'));
        add_action('wp_ajax_content_signing_verify_signature', array($this, 'ajax_verify_signature'));
        add_action('wp_ajax_content_signing_get_claim_types', array($this, 'ajax_get_claim_types'));
    }

    /**
     * Register frontend-related hooks.
     *
     * @since    1.0.0
     * @return   void
     */
    private function register_frontend_hooks() {
        // We don't need to register hooks here anymore as they are now handled by the public class
        // The public class registers its own hooks in its register_hooks method
    }

    /**
     * Callback for the save_post_{$post_type} action.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @param    WP_Post   $post       The post object.
     * @param    bool      $update     Whether this is an existing post being updated.
     * @return   void
     */
    public function on_save_post($post_id, $post, $update) {
        $this->signing_service->process_post($post_id, $post, $update);
    }

    /**
     * Callback for the publish_{$post_type} action.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @param    WP_Post   $post       The post object.
     * @return   void
     */
    public function on_publish_post($post_id, $post) {
        // This hook is specifically for when a post is published
        if (get_option('content_signing_sign_on_publish', true)) {
            $this->signing_service->process_post($post_id, $post, false);
        }
    }

    /**
     * Callback for the future_to_publish action.
     *
     * @since    1.0.0
     * @param    WP_Post   $post    The post object.
     * @return   void
     */
    public function on_future_to_publish($post) {
        // This hook is specifically for when a scheduled post is published
        if (get_option('content_signing_sign_on_publish', true)) {
            $this->signing_service->process_post($post->ID, $post, false);
        }
    }

    /**
     * Callback for the transition_post_status action.
     *
     * @since    1.0.0
     * @param    string    $new_status    The new post status.
     * @param    string    $old_status    The old post status.
     * @param    WP_Post   $post          The post object.
     * @return   void
     */
    public function on_transition_post_status($new_status, $old_status, $post) {
        // Check if this post type is enabled for signing
        $post_types = get_option('content_signing_post_types', array('post'));
        if (!in_array($post->post_type, $post_types)) {
            return;
        }
        
        // Handle specific status transitions
        if ($new_status === 'publish' && $old_status !== 'publish') {
            // Post is being published
            if (get_option('content_signing_sign_on_publish', true)) {
                $this->signing_service->process_post($post->ID, $post, false);
            }
        } elseif ($new_status === 'publish' && $old_status === 'publish') {
            // Post is being updated while published
            if (get_option('content_signing_sign_on_update', false)) {
                $this->signing_service->process_post($post->ID, $post, true);
            }
        }
    }

    /**
     * AJAX handler for signing a post.
     *
     * @since    1.0.0
     * @return   void
     */
    public function ajax_sign_post() {
        // Check nonce
        check_ajax_referer('content_signing_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }
        
        // Get post ID
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(array('message' => 'Invalid post ID.'));
            return;
        }
        
        // Sign the post
        $result = $this->signing_service->sign_post($post_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler for verifying a signature.
     *
     * @since    1.0.0
     * @return   void
     */
    public function ajax_verify_signature() {
        // Check nonce
        check_ajax_referer('content_signing_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }
        
        // Get post ID and signature ID
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $signature_id = isset($_POST['signature_id']) ? intval($_POST['signature_id']) : 0;
        
        if (!$post_id || !$signature_id) {
            wp_send_json_error(array('message' => 'Invalid post ID or signature ID.'));
            return;
        }
        
        // Verify the signature
        $result = $this->signing_service->verify_post_signature($post_id, $signature_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler for getting claim types.
     *
     * @since    1.0.0
     * @return   void
     */
    public function ajax_get_claim_types() {
        // Check nonce
        check_ajax_referer('content_signing_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }
        
        // Get server ID
        $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
        if (!$server_id) {
            wp_send_json_error(array('message' => 'Invalid server ID.'));
            return;
        }
        
        // Get the plugin instance
        $plugin = ContentSigning_Plugin::get_instance();
        
        // Get the database handler
        $db = $plugin->get_db();
        
        // Get the server
        $server = $db->get_server($server_id);
        if (!$server) {
            wp_send_json_error(array('message' => 'Server not found.'));
            return;
        }
        
        // Create an API client for this server
        $api_client = new ContentSigning_API_Client(
            $server->api_url,
            $db->decrypt($server->api_key_encrypted),
            $db
        );
        
        // Get claim types
        $result = $api_client->get_claim_types();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'data' => $result->get_error_data(),
            ));
        } else {
            wp_send_json_success($result);
        }
    }

    // The embed_signature_in_content method has been moved to the ContentSigning_Display class
}