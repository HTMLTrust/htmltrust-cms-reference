<?php
/**
 * Content Signing Service.
 *
 * This class orchestrates the content signing process.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/includes
 */

use HTMLTrust\Canonicalization\Canonicalize;

class ContentSigning_Signing_Service {

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
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    ContentSigning_DB             $db          The database handler.
     * @param    ContentSigning_API_Client     $api_client  The API client.
     * @param    ContentSigning_Scheduler      $scheduler   The scheduler.
     */
    public function __construct($db, $api_client, $scheduler) {
        $this->db = $db;
        $this->api_client = $api_client;
        $this->scheduler = $scheduler;
    }

    /**
     * Process a post for signing.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @param    WP_Post   $post       The post object.
     * @param    bool      $update     Whether this is an existing post being updated.
     * @return   void
     */
    public function process_post($post_id, $post, $update) {
        // Skip auto-saves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Check if this post type is enabled for signing
        $post_types = get_option('content_signing_post_types', array('post'));
        if (!in_array($post->post_type, $post_types)) {
            return;
        }

        // Check if signing is enabled globally
        if (!get_option('content_signing_enable_signing', true)) {
            return;
        }

        // Check if signing is disabled for this specific post
        $disable_signing = get_post_meta($post_id, '_content_signing_disable', true);
        if ($disable_signing) {
            return;
        }

        // Determine when to sign based on post status and plugin settings
        $sign_on_publish = get_option('content_signing_sign_on_publish', true);
        $sign_on_update = get_option('content_signing_sign_on_update', false);
        $sign_days_before_publish = get_option('content_signing_sign_days_before_publish', 0);
        $sign_days_after_publish = get_option('content_signing_sign_days_after_publish', 0);

        $should_sign_now = false;
        $should_schedule = false;
        $schedule_time = 0;

        // Check if we should sign now
        if ($post->post_status === 'publish') {
            if ($sign_on_publish && !$update) {
                // New post being published
                $should_sign_now = true;
            } elseif ($sign_on_update && $update) {
                // Existing post being updated
                $should_sign_now = true;
            }
        }

        // Check if we should schedule signing
        if ($post->post_status === 'future') {
            // Scheduled post
            $publish_time = strtotime($post->post_date_gmt);

            if ($sign_days_before_publish > 0) {
                // Schedule signing X days before publish
                $schedule_time = $publish_time - ($sign_days_before_publish * DAY_IN_SECONDS);
                $should_schedule = true;
            } elseif ($sign_on_publish) {
                // Schedule signing at publish time
                $schedule_time = $publish_time;
                $should_schedule = true;
            }
        } elseif ($post->post_status === 'publish' && $sign_days_after_publish > 0) {
            // Schedule signing X days after publish
            $publish_time = strtotime($post->post_date_gmt);
            $schedule_time = $publish_time + ($sign_days_after_publish * DAY_IN_SECONDS);
            $should_schedule = true;
        }

        // Sign now if needed
        if ($should_sign_now) {
            $this->sign_post($post_id);
        }

        // Schedule signing if needed
        if ($should_schedule && $schedule_time > time()) {
            $this->scheduler->schedule_signing($post_id, $schedule_time);
        }
    }

    /**
     * Sign a post.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @return   array                 The result of the signing operation.
     */
    public function sign_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return array(
                'success' => false,
                'message' => 'Post not found.',
            );
        }

        // Get the post author's signing profile
        $author_profile = $this->db->get_author_by_wp_user_id($post->post_author);
        if (!$author_profile) {
            return array(
                'success' => false,
                'message' => 'Author does not have a signing profile.',
            );
        }

        // Get the server for this author
        $server = $this->db->get_server($author_profile->server_id);
        if (!$server) {
            return array(
                'success' => false,
                'message' => 'Server not found for this author.',
            );
        }

        // Prepare the content for signing
        $content_data = $this->prepare_content_data($post);

        // Get the author's API key
        $author_api_key = $this->db->decrypt($author_profile->author_api_key_encrypted);

        // Create a new API client for this server
        $api_client = new ContentSigning_API_Client(
            $server->api_url,
            $this->db->decrypt($server->api_key_encrypted),
            $this->db
        );

        // Record the signature attempt
        $signature_id = $this->db->insert_signature(array(
            'post_id' => $post_id,
            'server_id' => $server->server_id,
            'signing_author_id' => $author_profile->signing_author_id,
            'wp_user_id' => get_current_user_id(),
            'content_hash' => $content_data['contentHash'],
            'domain' => $content_data['domain'],
            'status' => 'pending',
        ));

        if (!$signature_id) {
            return array(
                'success' => false,
                'message' => 'Failed to record signature attempt.',
            );
        }

        // Sign the content
        $result = $api_client->sign_content($content_data, $author_api_key);

        // Update the signature record
        if (is_wp_error($result)) {
            $this->db->update_signature($signature_id, array(
                'status' => 'error',
                'api_response' => array(
                    'error' => $result->get_error_message(),
                    'data' => $result->get_error_data(),
                ),
            ));

            return array(
                'success' => false,
                'message' => $result->get_error_message(),
                'data' => $result->get_error_data(),
            );
        } else {
            $this->db->update_signature($signature_id, array(
                'signature' => $result['signature'],
                'claims_json' => wp_json_encode($result['claims']),
                'status' => 'signed',
                'signed_at' => current_time('mysql'),
                'api_response' => $result,
            ));

            // Process endorsements if enabled
            if (get_option('content_signing_enable_endorsements', false)) {
                $this->process_endorsements($post_id, $content_data);
            }

            return array(
                'success' => true,
                'message' => 'Content signed successfully.',
                'data' => $result,
            );
        }
    }

    /**
     * Process endorsements for a post.
     *
     * @since    1.0.0
     * @param    int       $post_id        The post ID.
     * @param    array     $content_data   The content data.
     * @return   void
     */
    private function process_endorsements($post_id, $content_data) {
        // Get selected endorser profiles
        $endorser_profile_ids = get_option('content_signing_endorser_profiles', array());
        if (empty($endorser_profile_ids)) {
            return;
        }

        // Get all site endorsers
        $endorsers = $this->db->get_site_endorsers();
        if (empty($endorsers)) {
            return;
        }

        // Filter to only selected endorsers
        $selected_endorsers = array();
        foreach ($endorsers as $endorser) {
            if (in_array($endorser->author_profile_id, $endorser_profile_ids)) {
                $selected_endorsers[] = $endorser;
            }
        }

        // Process each endorser
        foreach ($selected_endorsers as $endorser) {
            // Get the server for this endorser
            $server = $this->db->get_server($endorser->server_id);
            if (!$server) {
                continue;
            }

            // Get the endorser's API key
            $endorser_api_key = $this->db->decrypt($endorser->author_api_key_encrypted);

            // Create a new API client for this server
            $api_client = new ContentSigning_API_Client(
                $server->api_url,
                $this->db->decrypt($server->api_key_encrypted),
                $this->db
            );

            // Record the endorsement attempt
            $signature_id = $this->db->insert_signature(array(
                'post_id' => $post_id,
                'server_id' => $server->server_id,
                'signing_author_id' => $endorser->signing_author_id,
                'wp_user_id' => get_current_user_id(),
                'content_hash' => $content_data['contentHash'],
                'domain' => $content_data['domain'],
                'status' => 'pending',
            ));

            if (!$signature_id) {
                continue;
            }

            // Sign the content with the endorser's key
            $result = $api_client->sign_content($content_data, $endorser_api_key);

            // Update the signature record
            if (is_wp_error($result)) {
                $this->db->update_signature($signature_id, array(
                    'status' => 'error',
                    'api_response' => array(
                        'error' => $result->get_error_message(),
                        'data' => $result->get_error_data(),
                    ),
                ));
            } else {
                $this->db->update_signature($signature_id, array(
                    'signature' => $result['signature'],
                    'claims_json' => wp_json_encode($result['claims']),
                    'status' => 'signed',
                    'signed_at' => current_time('mysql'),
                    'api_response' => $result,
                ));
            }
        }
    }

    /**
     * Prepare content data for signing.
     *
     * @since    1.0.0
     * @param    WP_Post   $post    The post object.
     * @return   array              The content data.
     */
    private function prepare_content_data($post) {
        // Get the post content
        $content = $post->post_content;

        // Normalize the content (strip whitespace, etc.)
        $normalized_content = $this->normalize_content($content);

        // Calculate the content hash
        $content_hash = $this->calculate_content_hash($normalized_content);

        // Determine the domain
        $domain = parse_url(get_site_url(), PHP_URL_HOST);

        // Get default claims from post author's profile
        $author_profile = $this->db->get_author_by_wp_user_id($post->post_author);
        $default_claims = array();
        if ($author_profile && !empty($author_profile->default_claims_json)) {
            $default_claims = json_decode($author_profile->default_claims_json, true);
        }

        // Get post-specific claims
        $post_claims = get_post_meta($post->ID, '_content_signing_claims', true);
        if (empty($post_claims)) {
            $post_claims = array();
        }

        // Merge claims, with post-specific claims taking precedence
        $claims = array_merge($default_claims, $post_claims);

        // Add standard claims if not already set
        if (!isset($claims['ContentType'])) {
            $claims['ContentType'] = 'Article';
        }

        if (!isset($claims['AuthorType'])) {
            $claims['AuthorType'] = $author_profile ? $author_profile->default_key_type : 'HUMAN';
        }

        return array(
            'contentHash' => $content_hash,
            'domain' => $domain,
            'claims' => $claims,
        );
    }

    /**
     * Normalize content for consistent hashing.
     *
     * @since    1.0.0
     * @param    string    $content    The content to normalize.
     * @return   string                The normalized content.
     */
    private function normalize_content($content) {
        // Strip HTML tags
        $content = wp_strip_all_tags($content);

        // Apply canonical text normalization
        return Canonicalize::normalize($content);
    }

    /**
     * Calculate a hash of the content.
     *
     * @since    1.0.0
     * @param    string    $content    The content to hash.
     * @return   string                The content hash.
     */
    private function calculate_content_hash($content) {
        // Use SHA-256 for hashing
        return 'sha256:' . hash('sha256', $content);
    }

    /**
     * Verify a post's signature.
     *
     * @since    1.0.0
     * @param    int       $post_id       The post ID.
     * @param    int       $signature_id  The signature ID.
     * @return   array                    The verification result.
     */
    public function verify_post_signature($post_id, $signature_id) {
        $post = get_post($post_id);
        if (!$post) {
            return array(
                'success' => false,
                'message' => 'Post not found.',
            );
        }

        $signature = $this->db->get_signature($signature_id);
        if (!$signature) {
            return array(
                'success' => false,
                'message' => 'Signature not found.',
            );
        }

        // Get the server for this signature
        $server = $this->db->get_server($signature->server_id);
        if (!$server) {
            return array(
                'success' => false,
                'message' => 'Server not found for this signature.',
            );
        }

        // Create a new API client for this server
        $api_client = new ContentSigning_API_Client(
            $server->api_url,
            $this->db->decrypt($server->api_key_encrypted),
            $this->db
        );

        // Prepare verification data
        $verification_data = array(
            'contentHash' => $signature->content_hash,
            'domain' => $signature->domain,
            'authorId' => $signature->signing_author_id,
            'signature' => $signature->signature,
        );

        // Verify the signature
        $result = $api_client->verify_content($verification_data);

        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message(),
                'data' => $result->get_error_data(),
            );
        } else {
            return array(
                'success' => true,
                'message' => 'Signature verified successfully.',
                'data' => $result,
            );
        }
    }
}
