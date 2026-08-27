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

    /** @var array<string, bool> Signing or scheduling work completed in this request. */
    private $processed_actions = array();

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
            $action_key = 'sign:' . $post_id;
            if (empty($this->processed_actions[$action_key])) {
                $this->processed_actions[$action_key] = true;
                $this->sign_post($post_id);
            }
        }

        // Schedule signing if needed
        if ($should_schedule && $schedule_time > time()) {
            $action_key = 'schedule:' . $post_id;
            if (empty($this->processed_actions[$action_key])) {
                $this->processed_actions[$action_key] = true;
                $this->scheduler->schedule_signing($post_id, $schedule_time);
            }
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

        // Prepare the content for signing. Canonicalization is allowed to
        // fail hard (draft §4.3.2 requires MUST-fail on unresolvable signed
        // attribute values); surface that as a signing error rather than a
        // PHP fatal inside a save_post hook.
        try {
            $content_data = $this->prepare_content_data($post);
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Content canonicalization failed: ' . $e->getMessage(),
            );
        }

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
        $result = $api_client->sign_content($this->prepare_api_content_data($content_data), $author_api_key);

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
                'claims_json' => wp_json_encode($content_data['claims']),
                'status' => 'signed',
                'signed_at' => $content_data['signedAtMysql'],
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
            $result = $api_client->sign_content($this->prepare_api_content_data($content_data), $endorser_api_key);

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
                    'claims_json' => wp_json_encode($content_data['claims']),
                    'status' => 'signed',
                    'signed_at' => $content_data['signedAtMysql'],
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
        $base_url = get_permalink($post);
        $signed_at = gmdate('Y-m-d\TH:i:s\Z');
        $author_name = $this->get_post_author_name($post);

        // Hash the rendered content, not the raw post_content. The
        // <signed-section> element emitted by ContentSigning_Display wraps the
        // output of the `the_content` filter chain (wpautop, shortcodes, ...),
        // so hashing the raw editor markup would produce a content hash that
        // no verifier can reproduce from the published page.
        $rendered_content = $this->get_rendered_content($post);

        // Normalize the content using the signed content extraction rules.
        $normalized_content = $this->normalize_content($rendered_content, $base_url);

        // Calculate the content hash
        $content_hash = $this->calculate_content_hash($normalized_content);

        // Determine the serialized Web origin for the legacy-named domain field.
        $domain = $this->serialize_origin(get_site_url());

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

        // Merge claims, with post-specific claims taking precedence.
        $custom_claims = array_merge($default_claims, $post_claims);

        // Add standard claims if not already set
        if (!isset($custom_claims['ContentType'])) {
            $custom_claims['ContentType'] = 'Article';
        }

        if (!isset($custom_claims['AuthorType'])) {
            $custom_claims['AuthorType'] = $author_profile ? $author_profile->default_key_type : 'HUMAN';
        }

        $claims = $this->build_claims($author_name, $signed_at, $custom_claims);

        return array(
            'contentHash' => $content_hash,
            'claimsHash' => $this->calculate_claims_hash($claims),
            'domain' => $domain,
            'claims' => $claims,
            'signedAt' => $signed_at,
            'signedAtMysql' => gmdate('Y-m-d H:i:s', strtotime($signed_at)),
            'sourceURL' => $base_url,
        );
    }

    /**
     * Render post content through the same filters the front end applies.
     *
     * ContentSigning_Display wraps the value it receives from the
     * `the_content` filter, so the signer has to hash that same value. Our own
     * wrapper callback is detached for the duration of the call: it is
     * registered on `the_content` itself, so leaving it attached would recurse
     * and fold a <signed-section> element into the hashed bytes.
     *
     * Caveat: filters registered on `the_content` at a priority later than the
     * display callback (20) run after the wrapper and are therefore outside the
     * signed bytes on the published page. Themes that mutate content that late
     * will break reproducibility.
     *
     * @since    1.0.0
     * @param    WP_Post   $post    The post object.
     * @return   string             The rendered post content.
     */
    private function get_rendered_content($post) {
        $display = $this->get_display();
        $callback = $display ? array($display, 'display_signature') : null;

        // Read the registered priority back rather than assuming it: detaching
        // at the wrong priority is a silent no-op, and the recursion it would
        // leave in place is not obvious from the resulting hash.
        $priority = $callback ? has_filter('the_content', $callback) : false;

        if (false !== $priority) {
            remove_filter('the_content', $callback, $priority);
        }

        $content = apply_filters('the_content', $post->post_content);

        if (false !== $priority) {
            add_filter('the_content', $callback, $priority);
        }

        return $content;
    }

    /**
     * Get the plugin's display handler, if the plugin is fully booted.
     *
     * @since    1.0.0
     * @return   ContentSigning_Display|null    The display handler.
     */
    private function get_display() {
        if (!class_exists('ContentSigning_Plugin')) {
            return null;
        }

        $plugin = ContentSigning_Plugin::get_instance();
        if (!$plugin) {
            return null;
        }

        $public = $plugin->get_public();
        if (!$public || !method_exists($public, 'get_display')) {
            return null;
        }

        return $public->get_display();
    }

    /**
     * Prepare the API-facing signing payload.
     *
     * @since    1.0.0
     * @param    array $content_data Internal content data.
     * @return   array               API content data.
     */
    private function prepare_api_content_data($content_data) {
        // claimsHash is not optional: the signing payload binding is
        // "content-hash:claims-hash:domain:signed-at" (draft §5), and the
        // reference server rejects the request outright when it is absent.
        return array(
            'contentHash' => $content_data['contentHash'],
            'claimsHash' => $content_data['claimsHash'],
            'domain' => $content_data['domain'],
            'claims' => $content_data['claims'],
            'signedAt' => $content_data['signedAt'],
            'sourceURL' => $content_data['sourceURL'],
        );
    }

    /**
     * Normalize content for consistent hashing.
     *
     * Delegates to the shared htmltrust/canonicalization package. This plugin
     * deliberately keeps no canonicalizer of its own: the bytes hashed here
     * have to match, byte for byte, what the JavaScript, Go, Python and Rust
     * verifiers derive from the published page.
     *
     * @since    1.0.0
     * @param    string      $content    The content to normalize.
     * @param    string|null $base_url   The signed document URL, used to
     *                                   resolve relative href/src values.
     * @return   string                  The normalized content.
     * @throws   RuntimeException         If the canonicalization package is missing.
     * @throws   InvalidArgumentException If a signed attribute cannot be canonicalized.
     */
    private function normalize_content($content, $base_url = null) {
        $this->require_canonicalization_library();

        return Canonicalize::extractCanonicalText($content, false, $base_url === '' ? null : $base_url);
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
        return $this->hash_canonical_bytes($content);
    }

    /**
     * Calculate the claims hash for a direct-child claim map.
     *
     * The claims hash is the second field of the signing payload binding
     * (draft §5) and is computed over the canonical claims byte string
     * defined in draft §4.6.
     *
     * @since    1.0.0
     * @param    array     $claims    Claims keyed by direct meta name.
     * @return   string               The claims hash.
     * @throws   RuntimeException         If the canonicalization package is missing.
     * @throws   InvalidArgumentException If a claim is malformed or duplicated.
     */
    private function calculate_claims_hash($claims) {
        $this->require_canonicalization_library();

        return $this->hash_canonical_bytes(Canonicalize::canonicalizeClaims($claims));
    }

    /**
     * Hash a canonical byte string into the spec's prefixed hash format.
     *
     * Base64 with the standard alphabet and padding removed, prefixed with the
     * hash algorithm identifier and a colon (draft §6.2).
     *
     * @since    1.0.0
     * @param    string    $bytes    The canonical bytes to hash.
     * @return   string              The prefixed hash.
     */
    private function hash_canonical_bytes($bytes) {
        return 'sha256:' . rtrim(base64_encode(hash('sha256', $bytes, true)), '=');
    }

    /**
     * Assert that the shared canonicalization package is loadable.
     *
     * The package is a composer dependency (htmltrust/canonicalization); a
     * missing autoloader is a deployment error, not a content error, so it is
     * reported distinctly rather than surfacing as a class-not-found fatal.
     *
     * @since    1.0.0
     * @return   void
     * @throws   RuntimeException    If the package is not installed.
     */
    private function require_canonicalization_library() {
        if (!class_exists(Canonicalize::class)) {
            throw new RuntimeException(
                'The htmltrust/canonicalization package is not installed. Run "composer install" in the plugin directory.'
            );
        }
    }

    /**
     * Serialize a URL as a Web origin.
     *
     * @since    1.0.0
     * @param    string $url The URL to serialize.
     * @return   string      The serialized origin.
     */
    private function serialize_origin($url) {
        $parts = wp_parse_url($url);
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';

        if (function_exists('idn_to_ascii') && $host !== '') {
            $ascii_host = idn_to_ascii($host, 0, defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0);
            if ($ascii_host) {
                $host = strtolower($ascii_host);
            }
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port']) && !$this->is_default_port($scheme, intval($parts['port']))) {
            $origin .= ':' . intval($parts['port']);
        }

        return $origin;
    }

    /**
     * Check whether a port is the default for a scheme.
     *
     * @since    1.0.0
     * @param    string $scheme The URL scheme.
     * @param    int    $port   The port.
     * @return   bool           Whether the port is default.
     */
    private function is_default_port($scheme, $port) {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }

    /**
     * Build direct child meta claims.
     *
     * @since    1.0.0
     * @param    string $author_name   The author display name.
     * @param    string $signed_at     RFC3339 UTC signing timestamp.
     * @param    array  $custom_claims Custom claim values.
     * @return   array                 Direct meta claims keyed by meta name.
     */
    private function build_claims($author_name, $signed_at, $custom_claims) {
        $claims = array(
            'author' => $author_name,
            'signed-at' => $signed_at,
        );

        foreach ($custom_claims as $name => $value) {
            $claim_name = strpos($name, 'claim:') === 0 ? $name : 'claim:' . $name;
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $claims[$claim_name] = (string) $value;
        }

        return $claims;
    }

    /**
     * Get the post author's display name.
     *
     * @since    1.0.0
     * @param    WP_Post $post The post object.
     * @return   string        The author display name.
     */
    private function get_post_author_name($post) {
        $user = get_user_by('ID', $post->post_author);
        if ($user && !empty($user->display_name)) {
            return $user->display_name;
        }

        return (string) $post->post_author;
    }

    /**
     * Format a stored datetime as RFC3339 UTC.
     *
     * @since    1.0.0
     * @param    string $datetime The stored datetime.
     * @return   string           The RFC3339 UTC datetime.
     */
    private function format_signed_at($datetime) {
        $timestamp = strtotime($datetime);
        if (!$timestamp) {
            $timestamp = time();
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
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

        // The caller was authorized against $post_id, so the signature has to
        // belong to that post; otherwise the post ID is just decoration and any
        // signature row is reachable.
        if (intval($signature->post_id) !== intval($post_id)) {
            return array(
                'success' => false,
                'message' => 'Signature does not belong to this post.',
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

        // Rebuild the signing payload binding. The verifier needs all four
        // bound fields (draft §5); sending only contentHash and domain makes
        // the request fail validation before any crypto runs.
        $claims = json_decode((string) $signature->claims_json, true);
        if (!is_array($claims) || empty($claims)) {
            return array(
                'success' => false,
                'message' => 'Stored claims are missing; cannot rebuild the signing payload.',
            );
        }

        try {
            $claims_hash = $this->calculate_claims_hash($claims);
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Claims canonicalization failed: ' . $e->getMessage(),
            );
        }

        // Prefer the signed-at claim: it is the exact string that was bound
        // into the signature. The signed_at column is a MySQL datetime and
        // only reconstructs the RFC3339 form.
        $signed_at = isset($claims['signed-at']) && $claims['signed-at'] !== ''
            ? (string) $claims['signed-at']
            : $this->format_signed_at($signature->signed_at ? $signature->signed_at : $signature->created_at);

        // Prepare verification data
        $verification_data = array(
            'contentHash' => $signature->content_hash,
            'claimsHash' => $claims_hash,
            'domain' => $signature->domain,
            'signedAt' => $signed_at,
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
