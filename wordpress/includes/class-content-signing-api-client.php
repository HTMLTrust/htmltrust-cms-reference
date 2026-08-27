<?php
/**
 * Content Signing API Client.
 *
 * This class handles all communication with the external Content Signing API.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/includes
 */

class ContentSigning_API_Client {

    /**
     * The API base URL.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $api_url    The API base URL.
     */
    private $api_url;

    /**
     * The general API key.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $general_api_key    The general API key.
     */
    private $general_api_key;

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
     * @param    string             $api_url          The API base URL.
     * @param    string             $general_api_key  The general API key.
     * @param    ContentSigning_DB  $db               The database handler.
     */
    public function __construct($api_url, $general_api_key, $db) {
        $this->api_url = rtrim($api_url, '/');
        $this->general_api_key = $general_api_key;
        $this->db = $db;
    }

    /**
     * Make an API request.
     *
     * @since    1.0.0
     * @param    string    $endpoint     The API endpoint.
     * @param    string    $method       The HTTP method.
     * @param    array     $data         The request data.
     * @param    string    $api_key      The API key to use.
     * @param    string    $api_key_type The API key type (general, author, admin).
     * @return   array|WP_Error          The API response or WP_Error on failure.
     */
    private function request($endpoint, $method = 'GET', $data = array(), $api_key = '', $api_key_type = 'general') {
        $url = $this->api_url . '/' . ltrim($endpoint, '/');
        
        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        );
        
        // Add API key to headers
        if (!empty($api_key)) {
            switch ($api_key_type) {
                case 'author':
                    $args['headers']['X-AUTHOR-API-KEY'] = $api_key;
                    break;
                case 'admin':
                    $args['headers']['X-ADMIN-API-KEY'] = $api_key;
                    break;
                case 'general':
                default:
                    $args['headers']['X-API-KEY'] = $api_key;
                    break;
            }
        } elseif (!empty($this->general_api_key)) {
            $args['headers']['X-API-KEY'] = $this->general_api_key;
        }
        
        // Add request body for POST, PUT methods
        if (!empty($data) && in_array($method, array('POST', 'PUT'))) {
            $args['body'] = wp_json_encode($data);
        }
        
        // Add query parameters for GET method
        if (!empty($data) && $method === 'GET') {
            $url = add_query_arg($data, $url);
        }
        
        // Make the request
        $response = wp_remote_request($url, $args);
        
        // Check for errors
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Parse response
        $body = wp_remote_retrieve_body($response);
        $code = wp_remote_retrieve_response_code($response);
        
        $result = json_decode($body, true);
        
        // Check for API errors
        if ($code >= 400) {
            return new WP_Error(
                'api_error',
                isset($result['message']) ? $result['message'] : 'API Error',
                array(
                    'status' => $code,
                    'response' => $result,
                )
            );
        }
        
        return $result;
    }

    /**
     * Identifier shape: opaque tokens (object ids, slugs, key ids).
     */
    const IDENTIFIER_PATTERN = '/^[A-Za-z0-9._~-]{1,128}$/';

    /**
     * Hash shape: an algorithm identifier, a colon, then unpadded Base64.
     */
    const HASH_PATTERN = '/^[A-Za-z0-9]{1,16}:[A-Za-z0-9+\/_-]{1,128}={0,2}$/';

    /**
     * Validate and encode a value for use as a single URL path segment.
     *
     * Identifiers reaching these methods come from the database and from API
     * responses, so they are not guaranteed to be path-safe. Interpolated raw,
     * a value containing "/", "..", "?" or "#" retargets the request at a
     * different endpoint on the signing server; rawurlencode() confines it to
     * one segment, and the pattern check rejects shapes that were never valid
     * identifiers in the first place.
     *
     * @since    1.0.0
     * @access   private
     * @param    string    $value    The path segment value.
     * @param    string    $pattern  The pattern the value must match.
     * @param    string    $label    Human-readable name, used in the error.
     * @return   string|WP_Error     The encoded segment, or WP_Error if unusable.
     */
    private function encode_path_segment($value, $pattern = self::IDENTIFIER_PATTERN, $label = 'identifier') {
        if (!is_scalar($value)) {
            return new WP_Error('invalid_identifier', sprintf('Invalid %s.', $label));
        }

        $value = trim((string) $value);
        if ($value === '' || !preg_match($pattern, $value)) {
            return new WP_Error('invalid_identifier', sprintf('Invalid %s.', $label));
        }

        return rawurlencode($value);
    }

    /**
     * Create a new author.
     *
     * @since    1.0.0
     * @param    array     $author_data  The author data.
     * @return   array|WP_Error          The API response or WP_Error on failure.
     */
    public function create_author($author_data) {
        return $this->request('authors', 'POST', $author_data, $this->general_api_key, 'general');
    }

    /**
     * Get an author by ID.
     *
     * @since    1.0.0
     * @param    string    $author_id    The author ID.
     * @return   array|WP_Error          The API response or WP_Error on failure.
     */
    public function get_author($author_id) {
        $author_id = $this->encode_path_segment($author_id, self::IDENTIFIER_PATTERN, 'author ID');
        if (is_wp_error($author_id)) {
            return $author_id;
        }

        return $this->request("authors/{$author_id}", 'GET');
    }

    /**
     * Update an author.
     *
     * @since    1.0.0
     * @param    string    $author_id    The author ID.
     * @param    array     $author_data  The author data.
     * @param    string    $author_api_key The author API key.
     * @return   array|WP_Error          The API response or WP_Error on failure.
     */
    public function update_author($author_id, $author_data, $author_api_key) {
        $author_id = $this->encode_path_segment($author_id, self::IDENTIFIER_PATTERN, 'author ID');
        if (is_wp_error($author_id)) {
            return $author_id;
        }

        return $this->request("authors/{$author_id}", 'PUT', $author_data, $author_api_key, 'author');
    }

    /**
     * Get an author's public key.
     *
     * @since    1.0.0
     * @param    string    $author_id    The author ID.
     * @return   array|WP_Error          The API response or WP_Error on failure.
     */
    public function get_author_public_key($author_id) {
        $author_id = $this->encode_path_segment($author_id, self::IDENTIFIER_PATTERN, 'author ID');
        if (is_wp_error($author_id)) {
            return $author_id;
        }

        return $this->request("authors/{$author_id}/public-key", 'GET');
    }

    /**
     * Sign content.
     *
     * @since    1.0.0
     * @param    array     $content_data   The content data.
     * @param    string    $author_api_key The author API key.
     * @return   array|WP_Error            The API response or WP_Error on failure.
     */
    public function sign_content($content_data, $author_api_key) {
        return $this->request('content/sign', 'POST', $content_data, $author_api_key, 'author');
    }

    /**
     * Verify content signature.
     *
     * @since    1.0.0
     * @param    array     $verification_data The verification data.
     * @return   array|WP_Error               The API response or WP_Error on failure.
     */
    public function verify_content($verification_data) {
        return $this->request('content/verify', 'POST', $verification_data);
    }

    /**
     * Get claim types.
     *
     * @since    1.0.0
     * @param    array     $params    Query parameters.
     * @return   array|WP_Error       The API response or WP_Error on failure.
     */
    public function get_claim_types($params = array()) {
        return $this->request('claims', 'GET', $params);
    }

    /**
     * Get a claim type by ID.
     *
     * @since    1.0.0
     * @param    string    $claim_id    The claim ID.
     * @return   array|WP_Error         The API response or WP_Error on failure.
     */
    public function get_claim_type($claim_id) {
        $claim_id = $this->encode_path_segment($claim_id, self::IDENTIFIER_PATTERN, 'claim ID');
        if (is_wp_error($claim_id)) {
            return $claim_id;
        }

        return $this->request("claims/{$claim_id}", 'GET');
    }

    /**
     * Search public keys in the directory.
     *
     * @since    1.0.0
     * @param    array     $params    Query parameters.
     * @return   array|WP_Error       The API response or WP_Error on failure.
     */
    public function search_public_keys($params = array()) {
        return $this->request('directory/keys', 'GET', $params);
    }

    /**
     * Get key reputation.
     *
     * @since    1.0.0
     * @param    string    $key_id    The key ID.
     * @return   array|WP_Error       The API response or WP_Error on failure.
     */
    public function get_key_reputation($key_id) {
        $key_id = $this->encode_path_segment($key_id, self::IDENTIFIER_PATTERN, 'key ID');
        if (is_wp_error($key_id)) {
            return $key_id;
        }

        return $this->request("directory/keys/{$key_id}/reputation", 'GET');
    }

    /**
     * Search signed content in the directory.
     *
     * @since    1.0.0
     * @param    array     $params    Query parameters.
     * @return   array|WP_Error       The API response or WP_Error on failure.
     */
    public function search_signed_content($params = array()) {
        return $this->request('directory/content', 'GET', $params);
    }

    /**
     * Find content occurrences.
     *
     * @since    1.0.0
     * @param    string    $content_hash    The content hash.
     * @param    array     $params          Query parameters.
     * @return   array|WP_Error             The API response or WP_Error on failure.
     */
    public function find_content_occurrences($content_hash, $params = array()) {
        // A content hash is "algorithm:base64", and standard-alphabet Base64
        // contains "/" -- rawurlencode() is what keeps it inside one segment.
        $content_hash = $this->encode_path_segment($content_hash, self::HASH_PATTERN, 'content hash');
        if (is_wp_error($content_hash)) {
            return $content_hash;
        }

        return $this->request("directory/content/{$content_hash}/occurrences", 'GET', $params);
    }
}