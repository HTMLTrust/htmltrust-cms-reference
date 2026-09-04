<?php
/**
 * Content Signing Scheduler.
 *
 * This class handles scheduling of signing operations.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/includes
 */

class ContentSigning_Scheduler {

    /**
     * The database handler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_DB    $db    The database handler.
     */
    private $db;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    ContentSigning_DB    $db    The database handler.
     */
    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Register the scheduler hooks.
     *
     * @since    1.0.0
     * @return   void
     */
    public function register_hooks() {
        // Register the cron hook
        add_action('content_signing_scheduled_signing', array($this, 'process_scheduled_signing'), 10, 1);
    }

    /**
     * Schedule a signing operation.
     *
     * @since    1.0.0
     * @param    int       $post_id       The post ID.
     * @param    int       $timestamp     The timestamp when the signing should occur.
     * @return   bool                     True if scheduled successfully, false otherwise.
     */
    public function schedule_signing($post_id, $timestamp) {
        // Check if already scheduled
        if (wp_next_scheduled('content_signing_scheduled_signing', array($post_id))) {
            // Clear existing schedule
            wp_clear_scheduled_hook('content_signing_scheduled_signing', array($post_id));
        }
        
        // Schedule the signing
        return wp_schedule_single_event($timestamp, 'content_signing_scheduled_signing', array($post_id));
    }

    /**
     * Cancel a scheduled signing operation.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @return   bool                  True if canceled successfully, false otherwise.
     */
    public function cancel_scheduled_signing($post_id) {
        return wp_clear_scheduled_hook('content_signing_scheduled_signing', array($post_id)) > 0;
    }

    /**
     * Process a scheduled signing operation.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @return   void
     */
    public function process_scheduled_signing($post_id) {
        // Get the post
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        
        // Check if signing is still enabled globally
        if (!get_option('content_signing_enable_signing', true)) {
            return;
        }
        
        // Check if signing is disabled for this specific post
        $disable_signing = get_post_meta($post_id, '_content_signing_disable', true);
        if ($disable_signing) {
            return;
        }
        
        // Get the signing service
        $plugin = ContentSigning_Plugin::get_instance();
        $signing_service = $plugin->get_signing_service();
        
        // Cron has no access to the author's browser key. Leave a durable
        // queue entry for the next editor session instead of invoking the
        // legacy remote signer.
        $signing_service->queue_local_signature($post_id);
    }

    /**
     * Get the next scheduled signing time for a post.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @return   int|false             The timestamp of the next scheduled signing, or false if not scheduled.
     */
    public function get_next_scheduled_signing($post_id) {
        return wp_next_scheduled('content_signing_scheduled_signing', array($post_id));
    }

    /**
     * Check if a post has a scheduled signing operation.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @return   bool                  True if scheduled, false otherwise.
     */
    public function has_scheduled_signing($post_id) {
        return (bool) $this->get_next_scheduled_signing($post_id);
    }
}
