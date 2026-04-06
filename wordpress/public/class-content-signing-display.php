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

        // Build the signature HTML
        $signature_html = $this->get_signature_html($post_id);

        // Append the signature HTML to the content
        $content .= $signature_html;

        return $content;
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
        $primary_signature = null;
        $endorsement_signatures = array();

        foreach ($signatures as $signature) {
            if ($signature->status === 'signed') {
                if (!$primary_signature) {
                    $primary_signature = $signature;
                } else {
                    $endorsement_signatures[] = $signature;
                }
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

        // Add the signature HTML attributes for verification
        $html .= $this->get_signature_attributes_html($primary_signature);

        $html .= '</div>'; // .content-signing-container

        return $html;
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
     * Get the HTML attributes for the signature container.
     *
     * @since    1.0.0
     * @param    object    $signature    The signature object.
     * @return   string                  The signature attributes HTML.
     */
    private function get_signature_attributes_html($signature) {
        // Get the author's public key
        $author_public_key = '';

        // Create an API client for the server
        $server = $this->db->get_server($signature->server_id);
        if ($server) {
            $api_client = new ContentSigning_API_Client(
                $server->api_url,
                $this->db->decrypt($server->api_key_encrypted),
                $this->db
            );

            // Get the author's public key
            $result = $api_client->get_author_public_key($signature->signing_author_id);
            if (!is_wp_error($result) && isset($result['key'])) {
                $author_public_key = $result['key'];
            }
        }

        // Build the signed-section element with signature attributes
        $html = '<signed-section ';
        $html .= 'signature="' . esc_attr($signature->signature) . '" ';
        $html .= 'keyid="' . esc_attr($author_public_key) . '" ';
        $html .= 'algorithm="ed25519" ';
        $html .= 'content-hash="' . esc_attr($signature->content_hash) . '" ';
        $html .= 'style="display: block;">';

        // Inner metadata: timestamp
        $signed_at = $signature->signed_at ? $signature->signed_at : $signature->created_at;
        if ($signed_at) {
            $html .= '<meta name="signed-at" content="' . esc_attr($signed_at) . '">';
        }

        // Inner metadata: author name
        $author_name = $signature->signing_author_id;
        $wp_user = get_user_by('ID', $signature->wp_user_id);
        if ($wp_user) {
            $author_name = $wp_user->display_name;
        }
        if ($author_name) {
            $html .= '<meta name="author" content="' . esc_attr($author_name) . '">';
        }

        // Inner metadata: claims from JSON
        $claims = json_decode($signature->claims_json, true);
        if (!empty($claims)) {
            foreach ($claims as $key => $value) {
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $html .= '<meta name="claim:' . esc_attr($key) . '" content="' . esc_attr($value) . '">';
            }
        }

        $html .= '</signed-section>';

        return $html;
    }
}
