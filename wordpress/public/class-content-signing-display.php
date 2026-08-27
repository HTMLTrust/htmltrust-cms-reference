<?php
/**
 * The display functionality for content signatures.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/public
 */

class ContentSigning_Display {

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
     * Display signature information in the content.
     *
     * @since    1.0.0
     * @param    string    $content    The content.
     * @return   string                The content with signature information.
     */
    public function display_signature($content) {
        // Only display signature on single posts
        if (!is_singular()) {
            return $content;
        }

        // Get the post ID
        $post_id = get_the_ID();

        // Get signatures for this post
        $signatures = $this->db->get_signatures_by_post_id($post_id);

        // If no signatures, return the content as is
        if (empty($signatures)) {
            return $content;
        }

        $primary_signature = $this->get_primary_signature($signatures);
        if (!$primary_signature) {
            return $content;
        }

        if (stripos($content, '<signed-section') !== false) {
            return $content . $this->get_signature_html($post_id);
        }

        return $this->get_signed_section_html($primary_signature, $content) . $this->get_signature_html($post_id);
    }

    /**
     * Get the HTML for displaying signature information.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @param    array     $options    Display options.
     * @return   string                The signature HTML.
     */
    public function get_signature_html($post_id, $options = array()) {
        // Default options
        $defaults = array(
            'show_author' => true,
            'show_timestamp' => true,
            'show_claims' => true,
            'show_verification' => true,
            'layout' => 'default', // default, compact, detailed
        );

        // Merge options with defaults
        $options = wp_parse_args($options, $defaults);

        // Get signatures for this post
        $signatures = $this->db->get_signatures_by_post_id($post_id);

        // If no signatures, return empty string
        if (empty($signatures)) {
            return '';
        }

        // Get the primary signature (first one with status 'signed')
        $primary_signature = $this->get_primary_signature($signatures);
        $endorsement_signatures = array();

        foreach ($signatures as $signature) {
            if ($signature->status === 'signed' && $primary_signature && $signature->signature_id !== $primary_signature->signature_id) {
                $endorsement_signatures[] = $signature;
            }
        }

        // If no signed signature, return empty string
        if (!$primary_signature) {
            return '';
        }

        // Start building the HTML
        $html = '<div class="content-signing-container">';

        // Add the signature status indicator
        $html .= $this->get_signature_status_html($primary_signature);

        // Add the signature details
        $html .= '<div class="content-signing-details">';

        // Add the signature header
        $html .= '<div class="content-signing-header">';
        $html .= '<h3>' . __('Content Signature', 'content-signing') . '</h3>';
        $html .= '</div>';

        // Add the signature information
        $html .= '<div class="content-signing-info">';

        // Add author information if enabled
        if ($options['show_author']) {
            $html .= $this->get_author_html($primary_signature);
        }

        // Add timestamp information if enabled
        if ($options['show_timestamp']) {
            $html .= $this->get_timestamp_html($primary_signature);
        }

        // Add claims information if enabled
        if ($options['show_claims']) {
            $html .= $this->get_claims_html($primary_signature);
        }

        $html .= '</div>'; // .content-signing-info

        // Add verification button if enabled
        if ($options['show_verification']) {
            $html .= $this->get_verification_html($primary_signature);
        }

        // Add endorsements if any
        if (!empty($endorsement_signatures)) {
            $html .= $this->get_endorsements_html($endorsement_signatures, $options);
        }

        $html .= '</div>'; // .content-signing-details

        $html .= '</div>'; // .content-signing-container

        return $html;
    }

    /**
     * Get the primary signed signature.
     *
     * @since    1.0.0
     * @param    array $signatures The signatures.
     * @return   object|null       The primary signature.
     */
    private function get_primary_signature($signatures) {
        foreach ($signatures as $signature) {
            if ($signature->status === 'signed') {
                return $signature;
            }
        }

        return null;
    }

    /**
     * Get the HTML for displaying the signature status.
     *
     * @since    1.0.0
     * @param    object    $signature    The signature object.
     * @return   string                  The signature status HTML.
     */
    private function get_signature_status_html($signature) {
        $status_class = 'content-signing-status-' . $signature->status;
        $status_text = '';

        switch ($signature->status) {
            case 'signed':
                $status_text = __('Signed', 'content-signing');
                break;
            case 'pending':
                $status_text = __('Pending', 'content-signing');
                break;
            case 'error':
                $status_text = __('Error', 'content-signing');
                break;
            default:
                $status_text = __('Unknown', 'content-signing');
                break;
        }

        $html = '<div class="content-signing-status ' . esc_attr($status_class) . '">';
        $html .= '<span class="content-signing-status-icon"></span>';
        $html .= '<span class="content-signing-status-text">' . esc_html($status_text) . '</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get the HTML for displaying the author information.
     *
     * @since    1.0.0
     * @param    object    $signature    The signature object.
     * @return   string                  The author HTML.
     */
    private function get_author_html($signature) {
        $html = '<div class="content-signing-author">';
        $html .= '<strong>' . __('Author:', 'content-signing') . '</strong> ';

        // Get the author's display name
        $author_name = $signature->signing_author_id;
        $wp_user = get_user_by('ID', $signature->wp_user_id);

        if ($wp_user) {
            $author_name = $wp_user->display_name;
        }

        $html .= '<span>' . esc_html($author_name) . '</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get the HTML for displaying the timestamp information.
     *
     * @since    1.0.0
     * @param    object    $signature    The signature object.
     * @return   string                  The timestamp HTML.
     */
    private function get_timestamp_html($signature) {
        $html = '<div class="content-signing-timestamp">';
        $html .= '<strong>' . __('Signed on:', 'content-signing') . '</strong> ';

        // Format the timestamp
        $timestamp = $signature->signed_at ? strtotime($signature->signed_at) : strtotime($signature->created_at);
        $formatted_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);

        $html .= '<span>' . esc_html($formatted_date) . '</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get the HTML for displaying the claims information.
     *
     * @since    1.0.0
     * @param    object    $signature    The signature object.
     * @return   string                  The claims HTML.
     */
    private function get_claims_html($signature) {
        $html = '<div class="content-signing-claims">';
        $html .= '<strong>' . __('Claims:', 'content-signing') . '</strong>';

        // Parse the claims JSON
        $claims = json_decode($signature->claims_json, true);

        if (!empty($claims)) {
            $html .= '<ul>';

            foreach ($claims as $key => $value) {
                $html .= '<li>';
                $html .= '<span class="content-signing-claim-key">' . esc_html($key) . ':</span> ';

                if (is_array($value)) {
                    $html .= '<span class="content-signing-claim-value">' . esc_html(implode(', ', $value)) . '</span>';
                } else {
                    $html .= '<span class="content-signing-claim-value">' . esc_html($value) . '</span>';
                }

                $html .= '</li>';
            }

            $html .= '</ul>';
        } else {
            $html .= '<p>' . __('No claims available.', 'content-signing') . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Get the HTML for displaying the verification button.
     *
     * @since    1.0.0
     * @param    object    $signature    The signature object.
     * @return   string                  The verification HTML.
     */
    private function get_verification_html($signature) {
        $html = '<div class="content-signing-verification">';
        $html .= '<button class="content-signing-verify-button" data-post-id="' . esc_attr($signature->post_id) . '" data-signature-id="' . esc_attr($signature->signature_id) . '">';
        $html .= __('Verify Signature', 'content-signing');
        $html .= '</button>';
        $html .= '<div class="content-signing-verification-result"></div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get the HTML for displaying the endorsements.
     *
     * @since    1.0.0
     * @param    array     $endorsements    The endorsement signatures.
     * @param    array     $options         Display options.
     * @return   string                     The endorsements HTML.
     */
    private function get_endorsements_html($endorsements, $options) {
        $html = '<div class="content-signing-endorsements">';
        $html .= '<h4>' . __('Endorsements', 'content-signing') . '</h4>';

        $html .= '<div class="content-signing-endorsements-list">';

        foreach ($endorsements as $endorsement) {
            $html .= '<div class="content-signing-endorsement">';

            // Add author information if enabled
            if ($options['show_author']) {
                $html .= $this->get_author_html($endorsement);
            }

            // Add timestamp information if enabled
            if ($options['show_timestamp']) {
                $html .= $this->get_timestamp_html($endorsement);
            }

            // Add verification button if enabled
            if ($options['show_verification']) {
                $html .= $this->get_verification_html($endorsement);
            }

            $html .= '</div>'; // .content-signing-endorsement
        }

        $html .= '</div>'; // .content-signing-endorsements-list
        $html .= '</div>'; // .content-signing-endorsements

        return $html;
    }

    /**
     * Wrap signed post content in a spec-conformant signed-section element.
     *
     * @since    1.0.0
     * @param    object    $signature    The signature object.
     * @param    string    $content      The rendered post content.
     * @return   string                  The signed section HTML.
     */
    private function get_signed_section_html($signature, $content) {
        $key = $this->get_key_metadata($signature);
        if ($key['keyid'] === '' || $key['algorithm'] === '') {
            return $content;
        }

        $html = '<signed-section ';
        $html .= 'signature="' . esc_attr($signature->signature) . '" ';
        $html .= 'keyid="' . esc_attr($key['keyid']) . '" ';
        $html .= 'algorithm="' . esc_attr($key['algorithm']) . '" ';
        $html .= 'content-hash="' . esc_attr($signature->content_hash) . '">';
        $html .= $this->get_claim_meta_html($signature);
        $html .= $content;
        $html .= '</signed-section>';

        return $html;
    }

    /**
     * Get the key identifier and signature algorithm for a signature.
     *
     * The `algorithm` attribute has to name the algorithm the signing key
     * actually uses; a verifier that is handed the wrong identifier fails with
     * "algorithm-not-supported" or, worse, tries the wrong primitive. The
     * directory reports it from GET /authors/{id}/public-key.
     *
     * The remote lookup is cached because this runs on every rendered view of
     * a signed post.
     *
     * @since    1.0.0
     * @param    object $signature The signature object.
     * @return   array             Key metadata: 'keyid' and 'algorithm'.
     */
    private function get_key_metadata($signature) {
        $keyid = '';
        $algorithm = '';

        $api_response = json_decode($signature->api_response_json, true);
        if (is_array($api_response)) {
            foreach (array('keyid', 'keyId', 'publicKeyUrl') as $field) {
                if (!empty($api_response[$field])) {
                    $keyid = (string) $api_response[$field];
                    break;
                }
            }

            if (!empty($api_response['algorithm'])) {
                $algorithm = (string) $api_response['algorithm'];
            }
        }

        if ($keyid === '' || $algorithm === '') {
            $remote = $this->get_remote_key_metadata($signature);

            if ($keyid === '' && $remote['keyid'] !== '') {
                $keyid = $remote['keyid'];
            }

            if ($algorithm === '' && $remote['algorithm'] !== '') {
                $algorithm = $remote['algorithm'];
            }
        }

        return array(
            'keyid' => $keyid,
            'algorithm' => $algorithm,
        );
    }

    /**
     * Look up key metadata from the signing server, with a short cache.
     *
     * @since    1.0.0
     * @param    object $signature The signature object.
     * @return   array             Key metadata: 'keyid' and 'algorithm'.
     */
    private function get_remote_key_metadata($signature) {
        $empty = array('keyid' => '', 'algorithm' => '');

        $cache_key = 'content_signing_key_' . md5($signature->server_id . '|' . $signature->signing_author_id);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $server = $this->db->get_server($signature->server_id);
        if (!$server) {
            return $empty;
        }

        $api_client = new ContentSigning_API_Client(
            $server->api_url,
            $this->db->decrypt($server->api_key_encrypted),
            $this->db
        );

        $result = $api_client->get_author_public_key($signature->signing_author_id);
        if (is_wp_error($result) || !is_array($result)) {
            return $empty;
        }

        $metadata = $empty;
        if (!empty($result['id'])) {
            $metadata['keyid'] = rtrim($server->api_url, '/') . '/keys/' . rawurlencode((string) $result['id']);
        }
        if (!empty($result['algorithm'])) {
            $metadata['algorithm'] = (string) $result['algorithm'];
        }

        set_transient($cache_key, $metadata, 12 * HOUR_IN_SECONDS);

        return $metadata;
    }

    /**
     * Get direct child claim meta HTML for a signed-section.
     *
     * @since    1.0.0
     * @param    object $signature The signature object.
     * @return   string            The meta HTML.
     */
    private function get_claim_meta_html($signature) {
        $claims = $this->get_protocol_claims($signature);
        $html = '';

        foreach ($claims as $name => $value) {
            $html .= '<meta name="' . esc_attr($name) . '" content="' . esc_attr($value) . '">';
        }

        return $html;
    }

    /**
     * Get the protocol claim map for a signature.
     *
     * @since    1.0.0
     * @param    object $signature The signature object.
     * @return   array             Claims keyed by direct meta name.
     */
    private function get_protocol_claims($signature) {
        $claims = array();
        $stored_claims = json_decode($signature->claims_json, true);

        if (is_array($stored_claims)) {
            foreach ($stored_claims as $key => $value) {
                if (is_array($value) && isset($value['name'], $value['content'])) {
                    $claims[$value['name']] = $value['content'];
                    continue;
                }

                if (is_array($value)) {
                    $value = implode(', ', $value);
                }

                $name = (string) $key;
                if ($name !== 'author' && $name !== 'signed-at' && strpos($name, 'claim:') !== 0) {
                    $name = 'claim:' . $name;
                }

                $claims[$name] = (string) $value;
            }
        }

        $author_name = $signature->signing_author_id;
        $wp_user = get_user_by('ID', $signature->wp_user_id);
        if ($wp_user) {
            $author_name = $wp_user->display_name;
        }
        if ($author_name && empty($claims['author'])) {
            $claims['author'] = $author_name;
        }

        if (empty($claims['signed-at'])) {
            $claims['signed-at'] = $this->format_signed_at($signature->signed_at ? $signature->signed_at : $signature->created_at);
        }

        return $claims;
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
}
