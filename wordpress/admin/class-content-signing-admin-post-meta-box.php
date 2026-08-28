<?php
/**
 * The post meta box functionality of the plugin.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/admin
 */

class ContentSigning_Admin_PostMetaBox {

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
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    ContentSigning_DB             $db          The database handler.
     * @param    ContentSigning_API_Client     $api_client  The API client.
     */
    public function __construct($db, $api_client) {
        $this->db = $db;
        $this->api_client = $api_client;
    }

    /**
     * Add meta boxes.
     *
     * @since    1.0.0
     * @return   void
     */
    public function add_meta_boxes() {
        // Get enabled post types
        $post_types = get_option('content_signing_post_types', array('post'));
        
        // Add meta box to each enabled post type
        foreach ($post_types as $post_type) {
            add_meta_box(
                'content_signing_meta_box',
                __('Content Signing', 'content-signing'),
                array($this, 'render_meta_box'),
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the meta box.
     *
     * @since    1.0.0
     * @param    WP_Post    $post    The post object.
     * @return   void
     */
    public function render_meta_box($post) {
        // Add nonce for security
        wp_nonce_field('content_signing_meta_box', 'content_signing_meta_box_nonce');
        
        // Get post meta
        $disable_signing = get_post_meta($post->ID, '_content_signing_disable', true);
        $custom_claims = get_post_meta($post->ID, '_content_signing_claims', true);
        
        // Get signatures for this post
        $signatures = $this->db->get_signatures_by_post_id($post->ID);
        
        // Check if the post author has a signing profile
        $author_profile = $this->db->get_author_by_wp_user_id($post->post_author);
        $has_author_profile = $author_profile !== null;
        $is_local_profile = $has_author_profile && (int) $author_profile->server_id === 0;
        $is_post_author = get_current_user_id() > 0 && (int) get_current_user_id() === (int) $post->post_author;
        
        // Get global signing settings
        $enable_signing = get_option('content_signing_enable_signing', true);
        
        ?>
        <div class="content-signing-meta-box">
            <?php if (!$enable_signing) : ?>
                <p class="notice notice-warning"><?php _e('Content signing is globally disabled.', 'content-signing'); ?></p>
            <?php elseif (!$has_author_profile) : ?>
                <p class="notice notice-warning"><?php _e('The post author does not have a signing profile.', 'content-signing'); ?></p>
            <?php endif; ?>
            
            <p>
                <label for="content_signing_disable">
                    <input type="checkbox" id="content_signing_disable" name="content_signing_disable" value="1" <?php checked($disable_signing); ?>>
                    <?php _e('Disable signing for this post', 'content-signing'); ?>
                </label>
            </p>
            
            <p>
                <label for="content_signing_claims"><?php _e('Custom Claims (JSON):', 'content-signing'); ?></label>
                <textarea id="content_signing_claims" name="content_signing_claims" class="widefat" rows="5"><?php echo esc_textarea(wp_json_encode($custom_claims, JSON_PRETTY_PRINT)); ?></textarea>
                <span class="description"><?php _e('Custom claims to include in the signature for this post.', 'content-signing'); ?></span>
            </p>
            
            <?php if (!empty($signatures)) : ?>
                <div class="content-signing-signatures">
                    <h4><?php _e('Signatures:', 'content-signing'); ?></h4>
                    <ul>
                        <?php foreach ($signatures as $signature) : 
                            $user = get_userdata($signature->wp_user_id);
                            $user_name = $user ? $user->display_name : __('Unknown User', 'content-signing');
                            $server = $this->db->get_server($signature->server_id);
                            $server_name = (int) $signature->server_id === 0 ? __('Browser-local only', 'content-signing') : ($server ? $server->name : __('Unknown Server', 'content-signing'));
                            $status_class = $signature->status === 'signed' ? 'signature-status-signed' : 'signature-status-' . $signature->status;
                        ?>
                            <li class="<?php echo esc_attr($status_class); ?>">
                                <strong><?php echo esc_html($user_name); ?></strong> (<?php echo esc_html($server_name); ?>)<br>
                                <?php _e('Status:', 'content-signing'); ?> <span class="signature-status"><?php echo esc_html(ucfirst($signature->status)); ?></span><br>
                                <?php if ($signature->status === 'signed' && !empty($signature->signed_at)) : ?>
                                    <?php _e('Signed at:', 'content-signing'); ?> <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($signature->signed_at))); ?><br>
                                <?php endif; ?>
                                <?php if ($signature->status === 'signed') : ?>
                                    <button type="button" class="button verify-signature" data-signature-id="<?php echo esc_attr($signature->signature_id); ?>" data-post-id="<?php echo esc_attr($post->ID); ?>"><?php _e('Verify', 'content-signing'); ?></button>
                                <?php elseif ($signature->status === 'error' && !empty($signature->api_response_json)) : 
                                    $api_response = json_decode($signature->api_response_json, true);
                                    $error_message = isset($api_response['error']) ? $api_response['error'] : __('Unknown error', 'content-signing');
                                ?>
                                    <div class="signature-error"><?php echo esc_html($error_message); ?></div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if ($enable_signing && $is_local_profile && $is_post_author && in_array($post->post_status, array('publish', 'future'), true)) : ?>
                <p>
                    <button type="button" class="button sign-post" data-post-id="<?php echo esc_attr($post->ID); ?>"><?php _e('Sign Now', 'content-signing'); ?></button>
                    <span class="spinner" style="float: none; margin-top: 0;"></span>
                </p>
                <p class="description">
                    <?php _e('The browser signs the exact filtered HTMLTrust payload with a local non-exportable key. The private key never goes to WordPress.', 'content-signing'); ?>
                </p>
                <p>
                    <button type="button" class="button-link rotate-local-key" data-author-id="<?php echo esc_attr($post->post_author); ?>"><?php _e('Rotate local key', 'content-signing'); ?></button>
                </p>
            <?php elseif ($enable_signing && $is_local_profile && !$is_post_author && in_array($post->post_status, array('publish', 'future'), true)) : ?>
                <p class="description"><?php _e('Only the post author can use browser-local signing for this post.', 'content-signing'); ?></p>
            <?php elseif ($enable_signing && $has_author_profile && !$is_local_profile && in_array($post->post_status, array('publish', 'future'), true)) : ?>
                <p class="description"><?php _e('This remote author profile cannot use browser-local signing.', 'content-signing'); ?></p>
            <?php endif; ?>
        </div>
        <?php
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
        // Check if our nonce is set
        if (!isset($_POST['content_signing_meta_box_nonce'])) {
            return;
        }
        
        // Verify that the nonce is valid
        if (!wp_verify_nonce($_POST['content_signing_meta_box_nonce'], 'content_signing_meta_box')) {
            return;
        }
        
        // If this is an autosave, our form has not been submitted, so we don't want to do anything
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check the user's permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save disable signing setting
        $disable_signing = isset($_POST['content_signing_disable']) ? 1 : 0;
        update_post_meta($post_id, '_content_signing_disable', $disable_signing);
        
        // Save custom claims
        $custom_claims = isset($_POST['content_signing_claims']) ? $this->sanitize_claims($_POST['content_signing_claims']) : array();
        update_post_meta($post_id, '_content_signing_claims', $custom_claims);
    }

    /**
     * Sanitize claims.
     *
     * @since    1.0.0
     * @param    string    $claims_json    The claims JSON string.
     * @return   array                     The sanitized claims array.
     */
    private function sanitize_claims($claims_json) {
        if (empty($claims_json)) {
            return array();
        }
        
        $claims = json_decode($claims_json, true);
        if (!is_array($claims)) {
            return array();
        }
        
        // Sanitize each claim
        $sanitized = array();
        foreach ($claims as $key => $value) {
            $sanitized_key = sanitize_text_field($key);
            
            if (is_array($value)) {
                $sanitized_value = array_map('sanitize_text_field', $value);
            } else {
                $sanitized_value = sanitize_text_field($value);
            }
            
            $sanitized[$sanitized_key] = $sanitized_value;
        }
        
        return $sanitized;
    }

    /**
     * Enqueue scripts and styles for the post meta box.
     *
     * @since    1.0.0
     * @return   void
     */
    public function enqueue_scripts() {
        // Get current screen
        $screen = get_current_screen();
        
        // Only enqueue on post edit screens
        if (!$screen || !in_array($screen->base, array('post'))) {
            return;
        }
        
        // Get enabled post types
        $post_types = get_option('content_signing_post_types', array('post'));
        
        // Only enqueue for enabled post types
        if (!in_array($screen->post_type, $post_types)) {
            return;
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'content-signing-post-meta-box',
            CONTENT_SIGNING_PLUGIN_URL . 'admin/css/content-signing-post-meta-box.css',
            array(),
            CONTENT_SIGNING_VERSION,
            'all'
        );
        
        // Enqueue scripts
        wp_enqueue_script(
            'content-signing-post-meta-box',
            CONTENT_SIGNING_PLUGIN_URL . 'admin/js/content-signing-post-meta-box.js',
            array('jquery'),
            CONTENT_SIGNING_VERSION,
            false
        );
        
        // The sign and verify AJAX handlers verify a post-scoped nonce, so the
        // one handed to the script has to be minted for the post being edited.
        $post = get_post();
        $post_id = $post ? $post->ID : 0;

        // Localize script
        wp_localize_script(
            'content-signing-post-meta-box',
            'content_signing_post_meta_box',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('content_signing_post_' . $post_id),
                'sign_post_confirm' => __('Are you sure you want to sign this post?', 'content-signing'),
                'signing_text' => __('Signing...', 'content-signing'),
                'sign_post_text' => __('Sign Now', 'content-signing'),
                'verifying_text' => __('Verifying...', 'content-signing'),
                'verify_text' => __('Verify', 'content-signing'),
                'valid_text' => __('Valid', 'content-signing'),
                'invalid_text' => __('Invalid', 'content-signing'),
                'error_text' => __('Error:', 'content-signing'),
                'ajax_error' => __('The request failed.', 'content-signing'),
                'prepare_error' => __('Could not prepare the server-rendered payload.', 'content-signing'),
                'local_signing_error' => __('Local signing failed:', 'content-signing'),
                'rotate_confirm' => __('Rotate the local signing key? Existing signatures remain valid, but this browser will need the new key for future posts.', 'content-signing'),
                'author_id' => $post ? (int) $post->post_author : 0,
                'key_base_url' => trailingslashit(get_rest_url(null, 'htmltrust/v1/keys')),
            )
        );
    }
}
