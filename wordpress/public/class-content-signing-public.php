<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/public
 */

class ContentSigning_Public {

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
     * The display handler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Display    $display    The display handler.
     */
    private $display;

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
        
        // Initialize the display handler
        require_once plugin_dir_path(dirname(__FILE__)) . 'public/class-content-signing-display.php';
        $this->display = new ContentSigning_Display($db, $api_client);
    }

    /**
     * Register the stylesheets for the public-facing side of the site.
     *
     * @since    1.0.0
     * @return   void
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            'content-signing-public',
            plugin_dir_url(dirname(__FILE__)) . 'public/css/content-signing-public.css',
            array(),
            CONTENT_SIGNING_VERSION,
            'all'
        );
    }

    /**
     * Register the JavaScript for the public-facing side of the site.
     *
     * @since    1.0.0
     * @return   void
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            'content-signing-public',
            plugin_dir_url(dirname(__FILE__)) . 'public/js/content-signing-public.js',
            array('jquery'),
            CONTENT_SIGNING_VERSION,
            true
        );
        
        // Localize the script with necessary data
        wp_localize_script(
            'content-signing-public',
            'content_signing_public',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('content_signing_public_nonce'),
                'i18n' => array(
                    'verified' => __('Signature verified', 'content-signing'),
                    'not_verified' => __('Signature could not be verified', 'content-signing'),
                    'loading' => __('Verifying signature...', 'content-signing'),
                    'error' => __('Error verifying signature', 'content-signing'),
                ),
            )
        );
    }

    /**
     * Register all hooks related to the public-facing functionality.
     *
     * @since    1.0.0
     * @return   void
     */
    public function register_hooks() {
        // Enqueue styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Register shortcodes
        add_shortcode('content_signature', array($this, 'signature_shortcode'));
        
        // Register AJAX handlers
        add_action('wp_ajax_nopriv_content_signing_verify', array($this, 'ajax_verify_signature'));
        add_action('wp_ajax_content_signing_verify', array($this, 'ajax_verify_signature'));
        
        // Register content filters
        if (get_option('content_signing_embed_signature', false)) {
            add_filter('the_content', array($this->display, 'display_signature'), 20);
        }
    }

    /**
     * Shortcode for displaying signature information.
     *
     * @since    1.0.0
     * @param    array     $atts    Shortcode attributes.
     * @return   string             The shortcode output.
     */
    public function signature_shortcode($atts) {
        $atts = shortcode_atts(
            array(
                'post_id' => get_the_ID(),
                'show_author' => 'true',
                'show_timestamp' => 'true',
                'show_claims' => 'true',
                'show_verification' => 'true',
                'layout' => 'default', // default, compact, detailed
            ),
            $atts,
            'content_signature'
        );
        
        // Convert string booleans to actual booleans
        $atts['show_author'] = filter_var($atts['show_author'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_timestamp'] = filter_var($atts['show_timestamp'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_claims'] = filter_var($atts['show_claims'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_verification'] = filter_var($atts['show_verification'], FILTER_VALIDATE_BOOLEAN);
        
        return $this->display->get_signature_html($atts['post_id'], $atts);
    }

    /**
     * AJAX handler for verifying a signature.
     *
     * @since    1.0.0
     * @return   void
     */
    public function ajax_verify_signature() {
        // Check nonce
        check_ajax_referer('content_signing_public_nonce', 'nonce');
        
        // Get post ID and signature ID
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $signature_id = isset($_POST['signature_id']) ? intval($_POST['signature_id']) : 0;
        
        if (!$post_id || !$signature_id) {
            wp_send_json_error(array('message' => __('Invalid post ID or signature ID.', 'content-signing')));
            return;
        }
        
        // Get the plugin instance
        $plugin = ContentSigning_Plugin::get_instance();
        
        // Get the signing service
        $signing_service = $plugin->get_signing_service();
        
        // Verify the signature
        $result = $signing_service->verify_post_signature($post_id, $signature_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}