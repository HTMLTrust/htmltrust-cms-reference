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
     * Get the display handler.
     *
     * The signing service needs it to detach the `the_content` wrapper while
     * rendering the bytes it hashes.
     *
     * @since    1.0.0
     * @return   ContentSigning_Display    The display handler.
     */
    public function get_display() {
        return $this->display;
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

        // Local-browser key resolution is a public, read-only endpoint. It
        // exposes only the public key needed by verifiers, never claims or
        // private material.
        add_action('rest_api_init', array($this, 'register_key_route'));
        
        // Register content filters
        if (get_option('content_signing_embed_signature', false)) {
            // Run after the normal content filters so the signed bytes are
            // the same bytes visitors receive. A filter registered later at
            // this exact priority remains outside this boundary.
            add_filter('the_content', array($this->display, 'display_signature'), PHP_INT_MAX);
        }
    }

    /**
     * Register the local key resolution endpoint.
     *
     * @return void
     */
    public function register_key_route() {
        register_rest_route('htmltrust/v1', '/keys/(?P<keyid>[A-Za-z0-9._~-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'resolve_local_key'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Resolve a locally stored public key.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error Response.
     */
    public function resolve_local_key($request) {
        $keyid = (string) $request['keyid'];
        $base = trailingslashit(get_rest_url(null, 'htmltrust/v1/keys'));
        $full_keyid = $base . rawurlencode($keyid);
        $signature = $this->db->get_local_signature_by_keyid($full_keyid);
        if (!$signature || empty($signature->public_key)) {
            return new WP_Error('key_not_found', __('Public key not found.', 'content-signing'), array('status' => 404));
        }

        return rest_ensure_response(array(
            'id' => $keyid,
            'keyid' => $full_keyid,
            'algorithm' => 'ed25519',
            'publicKey' => $signature->public_key,
            'publicKeyEncoding' => 'spki-der',
            'type' => 'HUMAN',
        ));
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
