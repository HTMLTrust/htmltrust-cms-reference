<?php
/**
 * API Client test case for Content Signing tests.
 *
 * @package Content_Signing
 */

/**
 * API Client test case class.
 */
class ContentSigning_API_Client_TestCase extends ContentSigning_DB_TestCase {

    /**
     * The API client.
     *
     * @var ContentSigning_API_Client
     */
    protected $api_client;

    /**
     * Mock server URL.
     *
     * @var string
     */
    protected $mock_server_url = 'https://mock-api.example.com';

    /**
     * Mock API key.
     *
     * @var string
     */
    protected $mock_api_key = 'mock-api-key';

    /**
     * Set up before each test.
     */
    public function setUp(): void {
        parent::setUp();
        
        // Create a mock API client
        $this->api_client = new ContentSigning_API_Client(
            $this->mock_server_url,
            $this->mock_api_key,
            $this->db
        );

        update_option('content_signing_enable_signing', true);
        update_option('content_signing_sign_on_publish', true);
        update_option('content_signing_sign_on_update', false);
        update_option('content_signing_sign_days_before_publish', 0);
        update_option('content_signing_sign_days_after_publish', 0);
        update_option('content_signing_enable_endorsements', false);
        update_option('content_signing_endorser_profiles', array());
        wp_clear_scheduled_hook('content_signing_scheduled_signing');
        
        // Add filter to mock API responses
        add_filter('pre_http_request', array($this, 'mock_api_response'), 10, 3);
    }

    /**
     * Tear down after each test.
     */
    public function tearDown(): void {
        // Remove filter
        remove_filter('pre_http_request', array($this, 'mock_api_response'), 10);
        
        parent::tearDown();
    }

    protected function create_test_server($data = array()) {
        return parent::create_test_server(wp_parse_args($data, array(
            'api_url' => $this->mock_server_url,
            'api_key' => $this->mock_api_key,
        )));
    }

    protected function create_test_author($data = array()) {
        return parent::create_test_author(wp_parse_args($data, array(
            'author_api_key' => 'mock-author-api-key',
        )));
    }

    /**
     * Mock API response.
     *
     * @param false|array|WP_Error $response    The preemptive return value.
     * @param array               $args         The request arguments.
     * @param string              $url          The request URL.
     * @return array|WP_Error                   The mocked response.
     */
    public function mock_api_response($response, $args, $url) {
        // Check if this is a request to our mock server
        if (strpos($url, $this->mock_server_url) !== 0) {
            return $response;
        }
        
        // Get the endpoint from the URL
        $endpoint = str_replace($this->mock_server_url . '/', '', $url);
        
        // Get the method
        $method = $args['method'];
        
        // Get the request body
        $body = isset($args['body']) ? json_decode($args['body'], true) : array();
        
        // Get the API key from headers
        $api_key = '';
        if (isset($args['headers']['X-API-KEY'])) {
            $api_key = $args['headers']['X-API-KEY'];
        } elseif (isset($args['headers']['X-AUTHOR-API-KEY'])) {
            $api_key = $args['headers']['X-AUTHOR-API-KEY'];
        } elseif (isset($args['headers']['X-ADMIN-API-KEY'])) {
            $api_key = $args['headers']['X-ADMIN-API-KEY'];
        }
        
        // Check if API key is valid
        if ($api_key !== $this->mock_api_key && $api_key !== 'mock-author-api-key') {
            return array(
                'response' => array('code' => 401),
                'body' => json_encode(array(
                    'message' => 'Invalid API key',
                )),
            );
        }
        
        // Handle different endpoints. Path parameters are rawurldecode()d
        // because the client percent-encodes each interpolated segment, and a
        // real server (Express, in the reference directory) decodes them
        // before the route handler sees them.
        switch (true) {
            case preg_match('/^authors$/', $endpoint) && $method === 'POST':
                return $this->mock_create_author_response($body);
                
            case preg_match('/^authors\/([^\/]+)$/', $endpoint, $matches) && $method === 'GET':
                return $this->mock_get_author_response(rawurldecode($matches[1]));
                
            case preg_match('/^authors\/([^\/]+)$/', $endpoint, $matches) && $method === 'PUT':
                return $this->mock_update_author_response(rawurldecode($matches[1]), $body);
                
            case preg_match('/^authors\/([^\/]+)\/public-key$/', $endpoint, $matches) && $method === 'GET':
                return $this->mock_get_author_public_key_response(rawurldecode($matches[1]));
                
            case preg_match('/^content\/sign$/', $endpoint) && $method === 'POST':
                return $this->mock_sign_content_response($body);
                
            case preg_match('/^content\/verify$/', $endpoint) && $method === 'POST':
                return $this->mock_verify_content_response($body);
                
            case preg_match('/^claims$/', $endpoint) && $method === 'GET':
                return $this->mock_get_claim_types_response();
                
            case preg_match('/^claims\/([^\/]+)$/', $endpoint, $matches) && $method === 'GET':
                return $this->mock_get_claim_type_response(rawurldecode($matches[1]));
                
            case preg_match('/^directory\/keys$/', $endpoint) && $method === 'GET':
                return $this->mock_search_public_keys_response();
                
            case preg_match('/^directory\/keys\/([^\/]+)\/reputation$/', $endpoint, $matches) && $method === 'GET':
                return $this->mock_get_key_reputation_response(rawurldecode($matches[1]));
                
            case preg_match('/^directory\/content$/', $endpoint) && $method === 'GET':
                return $this->mock_search_signed_content_response();
                
            case preg_match('/^directory\/content\/([^\/]+)\/occurrences$/', $endpoint, $matches) && $method === 'GET':
                return $this->mock_find_content_occurrences_response(rawurldecode($matches[1]));
                
            default:
                return array(
                    'response' => array('code' => 404),
                    'body' => json_encode(array(
                        'message' => 'Endpoint not found',
                    )),
                );
        }
    }

    /**
     * Mock create author response.
     *
     * @param array $body Request body.
     * @return array      Response.
     */
    protected function mock_create_author_response($body) {
        return array(
            'response' => array('code' => 201),
            'body' => json_encode(array(
                'id' => isset($body['id']) ? $body['id'] : 'mock-author-id',
                'name' => isset($body['name']) ? $body['name'] : 'Mock Author',
                'apiKey' => 'mock-author-api-key',
            )),
        );
    }

    /**
     * Mock get author response.
     *
     * @param string $author_id Author ID.
     * @return array            Response.
     */
    protected function mock_get_author_response($author_id) {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'id' => $author_id,
                'name' => 'Mock Author',
                'publicKeys' => array(
                    array(
                        'id' => 'mock-key-id',
                        'type' => 'HUMAN',
                        'publicKey' => 'mock-public-key',
                    ),
                ),
            )),
        );
    }

    /**
     * Mock update author response.
     *
     * @param string $author_id Author ID.
     * @param array  $body      Request body.
     * @return array            Response.
     */
    protected function mock_update_author_response($author_id, $body) {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'id' => $author_id,
                'name' => isset($body['name']) ? $body['name'] : 'Mock Author',
            )),
        );
    }

    /**
     * Mock get author public key response.
     *
     * @param string $author_id Author ID.
     * @return array            Response.
     */
    protected function mock_get_author_public_key_response($author_id) {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'id' => 'mock-key-id',
                'type' => 'HUMAN',
                'publicKey' => 'mock-public-key',
                'algorithm' => 'ed25519',
            )),
        );
    }

    /**
     * Mock sign content response.
     *
     * @param array $body Request body.
     * @return array      Response.
     */
    protected function mock_sign_content_response($body) {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'contentHash' => isset($body['contentHash']) ? $body['contentHash'] : 'sha256:mock-content-hash',
                'domain' => isset($body['domain']) ? $body['domain'] : 'example.com',
                'authorId' => 'mock-author-id',
                'keyId' => 'mock-key-id',
                'keyid' => $this->mock_server_url . '/keys/mock-key-id',
                'algorithm' => 'ed25519',
                'signature' => 'mock-signature',
                'claims' => isset($body['claims']) ? $body['claims'] : array(),
                'timestamp' => time(),
            )),
        );
    }

    /**
     * Mock verify content response.
     *
     * @param array $body Request body.
     * @return array      Response.
     */
    protected function mock_verify_content_response($body) {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'valid' => true,
                'contentHash' => isset($body['contentHash']) ? $body['contentHash'] : 'sha256:mock-content-hash',
                'domain' => isset($body['domain']) ? $body['domain'] : 'example.com',
                'authorId' => isset($body['authorId']) ? $body['authorId'] : 'mock-author-id',
                'keyId' => 'mock-key-id',
                'signature' => isset($body['signature']) ? $body['signature'] : 'mock-signature',
                'claims' => array(),
                'timestamp' => time(),
            )),
        );
    }

    /**
     * Mock get claim types response.
     *
     * @return array Response.
     */
    protected function mock_get_claim_types_response() {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'claims' => array(
                    array(
                        'id' => 'ContentType',
                        'name' => 'Content Type',
                        'description' => 'The type of content being signed',
                        'values' => array('Article', 'Image', 'Video', 'Audio'),
                    ),
                    array(
                        'id' => 'AuthorType',
                        'name' => 'Author Type',
                        'description' => 'The type of author',
                        'values' => array('HUMAN', 'AI', 'HUMAN_ASSISTED_AI', 'AI_ASSISTED_HUMAN'),
                    ),
                ),
            )),
        );
    }

    /**
     * Mock get claim type response.
     *
     * @param string $claim_id Claim ID.
     * @return array           Response.
     */
    protected function mock_get_claim_type_response($claim_id) {
        $claims = array(
            'ContentType' => array(
                'id' => 'ContentType',
                'name' => 'Content Type',
                'description' => 'The type of content being signed',
                'values' => array('Article', 'Image', 'Video', 'Audio'),
            ),
            'AuthorType' => array(
                'id' => 'AuthorType',
                'name' => 'Author Type',
                'description' => 'The type of author',
                'values' => array('HUMAN', 'AI', 'HUMAN_ASSISTED_AI', 'AI_ASSISTED_HUMAN'),
            ),
        );
        
        if (isset($claims[$claim_id])) {
            return array(
                'response' => array('code' => 200),
                'body' => json_encode($claims[$claim_id]),
            );
        } else {
            return array(
                'response' => array('code' => 404),
                'body' => json_encode(array(
                    'message' => 'Claim type not found',
                )),
            );
        }
    }

    /**
     * Mock search public keys response.
     *
     * @return array Response.
     */
    protected function mock_search_public_keys_response() {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'keys' => array(
                    array(
                        'id' => 'mock-key-id-1',
                        'authorId' => 'mock-author-id-1',
                        'authorName' => 'Mock Author 1',
                        'type' => 'HUMAN',
                        'publicKey' => 'mock-public-key-1',
                    ),
                    array(
                        'id' => 'mock-key-id-2',
                        'authorId' => 'mock-author-id-2',
                        'authorName' => 'Mock Author 2',
                        'type' => 'AI',
                        'publicKey' => 'mock-public-key-2',
                    ),
                ),
            )),
        );
    }

    /**
     * Mock get key reputation response.
     *
     * @param string $key_id Key ID.
     * @return array         Response.
     */
    protected function mock_get_key_reputation_response($key_id) {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'keyId' => $key_id,
                'authorId' => 'mock-author-id',
                'authorName' => 'Mock Author',
                'reputation' => 'TRUSTED',
                'signatureCount' => 100,
                'verificationCount' => 95,
                'firstSeen' => '2023-01-01T00:00:00Z',
                'lastSeen' => '2023-12-31T23:59:59Z',
            )),
        );
    }

    /**
     * Mock search signed content response.
     *
     * @return array Response.
     */
    protected function mock_search_signed_content_response() {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'content' => array(
                    array(
                        'contentHash' => 'sha256:mock-content-hash-1',
                        'domain' => 'example.com',
                        'authorId' => 'mock-author-id-1',
                        'keyId' => 'mock-key-id-1',
                        'signature' => 'mock-signature-1',
                        'claims' => array('ContentType' => 'Article'),
                        'timestamp' => time() - 86400,
                    ),
                    array(
                        'contentHash' => 'sha256:mock-content-hash-2',
                        'domain' => 'example.org',
                        'authorId' => 'mock-author-id-2',
                        'keyId' => 'mock-key-id-2',
                        'signature' => 'mock-signature-2',
                        'claims' => array('ContentType' => 'Image'),
                        'timestamp' => time() - 43200,
                    ),
                ),
            )),
        );
    }

    /**
     * Mock find content occurrences response.
     *
     * @param string $content_hash Content hash.
     * @return array               Response.
     */
    protected function mock_find_content_occurrences_response($content_hash) {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'contentHash' => $content_hash,
                'occurrences' => array(
                    array(
                        'domain' => 'example.com',
                        'url' => 'https://example.com/post/1',
                        'authorId' => 'mock-author-id-1',
                        'keyId' => 'mock-key-id-1',
                        'signature' => 'mock-signature-1',
                        'timestamp' => time() - 86400,
                    ),
                    array(
                        'domain' => 'example.org',
                        'url' => 'https://example.org/post/1',
                        'authorId' => 'mock-author-id-2',
                        'keyId' => 'mock-key-id-2',
                        'signature' => 'mock-signature-2',
                        'timestamp' => time() - 43200,
                    ),
                ),
            )),
        );
    }
}
