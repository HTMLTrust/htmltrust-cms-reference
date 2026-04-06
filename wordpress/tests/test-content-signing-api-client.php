<?php
/**
 * Tests for the ContentSigning_API_Client class.
 *
 * @package Content_Signing
 */

/**
 * API Client test class.
 */
class Test_Content_Signing_API_Client extends ContentSigning_API_Client_TestCase {

    /**
     * Test create_author method.
     */
    public function test_create_author() {
        $author_data = array(
            'id' => 'test-author-id',
            'name' => 'Test Author',
        );
        
        $result = $this->api_client->create_author($author_data);
        
        $this->assertNotWPError($result);
        $this->assertEquals('test-author-id', $result['id']);
        $this->assertEquals('Test Author', $result['name']);
        $this->assertEquals('mock-author-api-key', $result['apiKey']);
    }

    /**
     * Test get_author method.
     */
    public function test_get_author() {
        $author_id = 'test-author-id';
        
        $result = $this->api_client->get_author($author_id);
        
        $this->assertNotWPError($result);
        $this->assertEquals($author_id, $result['id']);
        $this->assertEquals('Mock Author', $result['name']);
        $this->assertArrayHasKey('publicKeys', $result);
        $this->assertCount(1, $result['publicKeys']);
        $this->assertEquals('mock-key-id', $result['publicKeys'][0]['id']);
    }

    /**
     * Test update_author method.
     */
    public function test_update_author() {
        $author_id = 'test-author-id';
        $author_data = array(
            'name' => 'Updated Author',
        );
        $author_api_key = 'mock-author-api-key';
        
        $result = $this->api_client->update_author($author_id, $author_data, $author_api_key);
        
        $this->assertNotWPError($result);
        $this->assertEquals($author_id, $result['id']);
        $this->assertEquals('Updated Author', $result['name']);
    }

    /**
     * Test get_author_public_key method.
     */
    public function test_get_author_public_key() {
        $author_id = 'test-author-id';
        
        $result = $this->api_client->get_author_public_key($author_id);
        
        $this->assertNotWPError($result);
        $this->assertEquals('mock-key-id', $result['id']);
        $this->assertEquals('HUMAN', $result['type']);
        $this->assertEquals('mock-public-key', $result['publicKey']);
    }

    /**
     * Test sign_content method.
     */
    public function test_sign_content() {
        $content_data = array(
            'contentHash' => 'sha256:test-content-hash',
            'domain' => 'test.example.com',
            'claims' => array(
                'ContentType' => 'Article',
                'AuthorType' => 'HUMAN',
            ),
        );
        $author_api_key = 'mock-author-api-key';
        
        $result = $this->api_client->sign_content($content_data, $author_api_key);
        
        $this->assertNotWPError($result);
        $this->assertEquals($content_data['contentHash'], $result['contentHash']);
        $this->assertEquals($content_data['domain'], $result['domain']);
        $this->assertEquals('mock-author-id', $result['authorId']);
        $this->assertEquals('mock-key-id', $result['keyId']);
        $this->assertEquals('mock-signature', $result['signature']);
        $this->assertEquals($content_data['claims'], $result['claims']);
    }

    /**
     * Test verify_content method.
     */
    public function test_verify_content() {
        $verification_data = array(
            'contentHash' => 'sha256:test-content-hash',
            'domain' => 'test.example.com',
            'authorId' => 'test-author-id',
            'signature' => 'test-signature',
        );
        
        $result = $this->api_client->verify_content($verification_data);
        
        $this->assertNotWPError($result);
        $this->assertTrue($result['valid']);
        $this->assertEquals($verification_data['contentHash'], $result['contentHash']);
        $this->assertEquals($verification_data['domain'], $result['domain']);
        $this->assertEquals($verification_data['authorId'], $result['authorId']);
        $this->assertEquals($verification_data['signature'], $result['signature']);
    }

    /**
     * Test get_claim_types method.
     */
    public function test_get_claim_types() {
        $result = $this->api_client->get_claim_types();
        
        $this->assertNotWPError($result);
        $this->assertArrayHasKey('claims', $result);
        $this->assertCount(2, $result['claims']);
        
        // Check ContentType claim
        $content_type = null;
        foreach ($result['claims'] as $claim) {
            if ($claim['id'] === 'ContentType') {
                $content_type = $claim;
                break;
            }
        }
        
        $this->assertNotNull($content_type);
        $this->assertEquals('Content Type', $content_type['name']);
        $this->assertCount(4, $content_type['values']);
    }

    /**
     * Test get_claim_type method.
     */
    public function test_get_claim_type() {
        // Test valid claim type
        $result = $this->api_client->get_claim_type('ContentType');
        
        $this->assertNotWPError($result);
        $this->assertEquals('ContentType', $result['id']);
        $this->assertEquals('Content Type', $result['name']);
        $this->assertCount(4, $result['values']);
        
        // Test invalid claim type
        $invalid_result = $this->api_client->get_claim_type('InvalidType');
        
        $this->assertWPError($invalid_result);
        $this->assertEquals('api_error', $invalid_result->get_error_code());
    }

    /**
     * Test search_public_keys method.
     */
    public function test_search_public_keys() {
        $result = $this->api_client->search_public_keys();
        
        $this->assertNotWPError($result);
        $this->assertArrayHasKey('keys', $result);
        $this->assertCount(2, $result['keys']);
        
        // Check first key
        $first_key = $result['keys'][0];
        $this->assertEquals('mock-key-id-1', $first_key['id']);
        $this->assertEquals('mock-author-id-1', $first_key['authorId']);
        $this->assertEquals('Mock Author 1', $first_key['authorName']);
    }

    /**
     * Test get_key_reputation method.
     */
    public function test_get_key_reputation() {
        $key_id = 'mock-key-id';
        
        $result = $this->api_client->get_key_reputation($key_id);
        
        $this->assertNotWPError($result);
        $this->assertEquals($key_id, $result['keyId']);
        $this->assertEquals('mock-author-id', $result['authorId']);
        $this->assertEquals('TRUSTED', $result['reputation']);
        $this->assertEquals(100, $result['signatureCount']);
        $this->assertEquals(95, $result['verificationCount']);
    }

    /**
     * Test search_signed_content method.
     */
    public function test_search_signed_content() {
        $result = $this->api_client->search_signed_content();
        
        $this->assertNotWPError($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertCount(2, $result['content']);
        
        // Check first content
        $first_content = $result['content'][0];
        $this->assertEquals('sha256:mock-content-hash-1', $first_content['contentHash']);
        $this->assertEquals('example.com', $first_content['domain']);
        $this->assertEquals('mock-author-id-1', $first_content['authorId']);
    }

    /**
     * Test find_content_occurrences method.
     */
    public function test_find_content_occurrences() {
        $content_hash = 'sha256:mock-content-hash';
        
        $result = $this->api_client->find_content_occurrences($content_hash);
        
        $this->assertNotWPError($result);
        $this->assertEquals($content_hash, $result['contentHash']);
        $this->assertArrayHasKey('occurrences', $result);
        $this->assertCount(2, $result['occurrences']);
        
        // Check first occurrence
        $first_occurrence = $result['occurrences'][0];
        $this->assertEquals('example.com', $first_occurrence['domain']);
        $this->assertEquals('https://example.com/post/1', $first_occurrence['url']);
        $this->assertEquals('mock-author-id-1', $first_occurrence['authorId']);
    }

    /**
     * Test API error handling.
     */
    public function test_api_error_handling() {
        // Temporarily modify the mock API key to trigger an error
        $original_key = $this->mock_api_key;
        $this->mock_api_key = 'invalid-key';
        
        // Create a new API client with the invalid key
        $api_client = new ContentSigning_API_Client(
            $this->mock_server_url,
            'invalid-key',
            $this->db
        );
        
        // Test API call
        $result = $api_client->get_author('test-author-id');
        
        // Verify error
        $this->assertWPError($result);
        $this->assertEquals('api_error', $result->get_error_code());
        $this->assertEquals('Invalid API key', $result->get_error_message());
        
        // Restore original key
        $this->mock_api_key = $original_key;
    }

    /**
     * Helper method to check if a value is not a WP_Error.
     *
     * @param mixed $value The value to check.
     */
    private function assertNotWPError($value) {
        $this->assertFalse(is_wp_error($value), 'Value is a WP_Error: ' . (is_wp_error($value) ? $value->get_error_message() : ''));
    }

    /**
     * Helper method to check if a value is a WP_Error.
     *
     * @param mixed $value The value to check.
     */
    private function assertWPError($value) {
        $this->assertTrue(is_wp_error($value), 'Value is not a WP_Error');
    }
}