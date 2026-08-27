<?php
/**
 * Tests for the ContentSigning_Signing_Service class.
 *
 * @package Content_Signing
 */

/**
 * Signing Service test class.
 */
class Test_Content_Signing_Signing_Service extends ContentSigning_API_Client_TestCase {

    /**
     * The signing service.
     *
     * @var ContentSigning_Signing_Service
     */
    protected $signing_service;

    /**
     * The scheduler.
     *
     * @var ContentSigning_Scheduler
     */
    protected $scheduler;

    /**
     * Set up before each test.
     */
    public function setUp(): void {
        parent::setUp();
        
        // Get scheduler instance
        $this->scheduler = $this->plugin->get_scheduler();
        
        // Create signing service
        $this->signing_service = new ContentSigning_Signing_Service(
            $this->db,
            $this->api_client,
            $this->scheduler
        );
    }

    /**
     * Test process_post method with signing disabled.
     */
    public function test_process_post_signing_disabled() {
        // Create a test post
        $post_id = $this->create_test_post();
        $post = get_post($post_id);
        
        // Disable signing globally
        update_option('content_signing_enable_signing', false);
        
        // Process the post
        $this->signing_service->process_post($post_id, $post, false);
        
        // Verify no signatures were created
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertEmpty($signatures);
        
        // Re-enable signing for other tests
        update_option('content_signing_enable_signing', true);
    }

    /**
     * Test process_post method with signing disabled for specific post.
     */
    public function test_process_post_signing_disabled_for_post() {
        // Create a test post
        $post_id = $this->create_test_post();
        $post = get_post($post_id);
        
        // Disable signing for this post
        update_post_meta($post_id, '_content_signing_disable', true);
        
        // Process the post
        $this->signing_service->process_post($post_id, $post, false);
        
        // Verify no signatures were created
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertEmpty($signatures);
    }

    /**
     * Test process_post method with sign on publish.
     */
    public function test_process_post_sign_on_publish() {
        // Create a server and author profile
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $author_id = $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
        ));
        
        // Enable sign on publish
        update_option('content_signing_sign_on_publish', true);
        update_option('content_signing_sign_on_update', false);
        
        // Create a test post with the author
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
        ));
        $post = get_post($post_id);
        $post->post_status = 'publish';
        
        // Process the post as a new publish
        $this->signing_service->process_post($post_id, $post, false);
        
        // Verify a signature was created
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertCount(1, $signatures);
        $this->assertEquals('signed', $signatures[0]->status);
    }

    /**
     * Test process_post method with sign on update.
     */
    public function test_process_post_sign_on_update() {
        // Create a server and author profile
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $author_id = $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
        ));
        
        // Enable sign on update, disable sign on publish
        update_option('content_signing_sign_on_publish', false);
        update_option('content_signing_sign_on_update', true);
        
        // Create a test post with the author
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
        ));
        $post = get_post($post_id);
        $post->post_status = 'publish';
        
        // Process the post as an update
        $this->signing_service->process_post($post_id, $post, true);
        
        // Verify a signature was created
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertCount(1, $signatures);
        $this->assertEquals('signed', $signatures[0]->status);
    }

    /**
     * Test process_post method with scheduled signing.
     */
    public function test_process_post_scheduled_signing() {
        // Create a server and author profile
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $author_id = $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
        ));
        
        // Set up scheduled signing
        update_option('content_signing_sign_days_after_publish', 1);
        
        // Create a test post with the author
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
        ));
        $post = get_post($post_id);
        $post->post_status = 'publish';
        $post->post_date_gmt = gmdate('Y-m-d H:i:s');
        
        // Process the post
        $this->signing_service->process_post($post_id, $post, false);
        
        // Verify a signature was scheduled
        $scheduled = $this->scheduler->has_scheduled_signing($post_id);
        $this->assertTrue($scheduled);
        
        // Reset option for other tests
        update_option('content_signing_sign_days_after_publish', 0);
    }

    /**
     * Test sign_post method.
     */
    public function test_sign_post() {
        // Create a server and author profile
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $author_id = $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
        ));
        
        // Create a test post with the author
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
        ));
        
        // Sign the post
        $result = $this->signing_service->sign_post($post_id);
        
        // Verify result
        $this->assertTrue($result['success']);
        $this->assertEquals('Content signed successfully.', $result['message']);
        
        // Verify a signature was created
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertCount(1, $signatures);
        $this->assertEquals('signed', $signatures[0]->status);
        $this->assertEquals('mock-signature', $signatures[0]->signature);
    }

    /**
     * Test prepared signing data follows the current HTMLTrust wire format.
     */
    public function test_prepare_content_data_uses_origin_base64_and_direct_claims() {
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user(array(
            'display_name' => 'Alice Example',
        ));
        $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
            'default_claims' => array(
                'License' => 'CC-BY-4.0',
            ),
        ));

        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
            'post_content' => '<p>Read <a href="/about" aria-label="About HTMLTrust">about us</a>.</p>',
        ));
        update_post_meta($post_id, '_content_signing_claims', array(
            'ContentType' => 'Article',
        ));

        $content_data = $this->invoke_prepare_content_data(get_post($post_id));

        $this->assertMatchesRegularExpression('/^sha256:[A-Za-z0-9+\/]{43}$/', $content_data['contentHash']);
        $this->assertStringNotContainsString('=', $content_data['contentHash']);
        $this->assertEquals($this->expected_site_origin(), $content_data['domain']);
        $this->assertEquals('Alice Example', $content_data['claims']['author']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $content_data['claims']['signed-at']);
        $this->assertEquals('Article', $content_data['claims']['claim:ContentType']);
        $this->assertEquals('CC-BY-4.0', $content_data['claims']['claim:License']);
    }

    /**
     * Test signed semantic attributes affect the content hash.
     */
    public function test_prepare_content_data_covers_signed_semantic_attributes() {
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
        ));

        $first_post_id = $this->create_test_post(array(
            'post_author' => $user_id,
            'post_content' => '<p><a href="/one" aria-label="Read more">Read more</a></p>',
        ));
        $second_post_id = $this->create_test_post(array(
            'post_author' => $user_id,
            'post_content' => '<p><a href="/two" aria-label="Read more">Read more</a></p>',
        ));

        $first = $this->invoke_prepare_content_data(get_post($first_post_id));
        $second = $this->invoke_prepare_content_data(get_post($second_post_id));

        $this->assertNotEquals($first['contentHash'], $second['contentHash']);
    }

    /**
     * Test sign_post method with missing post.
     */
    public function test_sign_post_missing_post() {
        // Sign a non-existent post
        $result = $this->signing_service->sign_post(999999);
        
        // Verify result
        $this->assertFalse($result['success']);
        $this->assertEquals('Post not found.', $result['message']);
    }

    /**
     * Test sign_post method with missing author profile.
     */
    public function test_sign_post_missing_author_profile() {
        // Create a test post with no author profile
        $post_id = $this->create_test_post();
        
        // Sign the post
        $result = $this->signing_service->sign_post($post_id);
        
        // Verify result
        $this->assertFalse($result['success']);
        $this->assertEquals('Author does not have a signing profile.', $result['message']);
    }

    /**
     * Invoke private prepare_content_data for focused format assertions.
     *
     * @param WP_Post $post The post.
     * @return array        Prepared content data.
     */
    private function invoke_prepare_content_data($post) {
        $method = new ReflectionMethod($this->signing_service, 'prepare_content_data');
        $method->setAccessible(true);

        return $method->invoke($this->signing_service, $post);
    }

    /**
     * Get the expected serialized origin for the test site.
     *
     * @return string The serialized origin.
     */
    private function expected_site_origin() {
        $parts = wp_parse_url(get_site_url());
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $origin = $scheme . '://' . $host;

        if (isset($parts['port']) && !(($scheme === 'http' && intval($parts['port']) === 80) || ($scheme === 'https' && intval($parts['port']) === 443))) {
            $origin .= ':' . intval($parts['port']);
        }

        return $origin;
    }

    /**
     * Test process_endorsements method.
     */
    public function test_process_endorsements() {
        // Create a server
        $server_id = $this->create_test_server();
        
        // Create a regular author
        $user_id = $this->create_test_user();
        $author_id = $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
        ));
        
        // Create endorser profiles
        $endorser1_id = $this->create_test_author(array(
            'server_id' => $server_id,
            'is_site_endorser' => 1,
        ));
        
        $endorser2_id = $this->create_test_author(array(
            'server_id' => $server_id,
            'is_site_endorser' => 1,
        ));
        
        // Enable endorsements and select endorsers
        update_option('content_signing_enable_endorsements', true);
        update_option('content_signing_endorser_profiles', array($endorser1_id, $endorser2_id));
        
        // Create a test post
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
        ));
        
        // Sign the post
        $result = $this->signing_service->sign_post($post_id);
        
        // Verify result
        $this->assertTrue($result['success']);
        
        // Verify signatures were created (1 primary + 2 endorsements)
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertCount(3, $signatures);
        
        // Reset options for other tests
        update_option('content_signing_enable_endorsements', false);
    }

    /**
     * Test verify_post_signature method.
     */
    public function test_verify_post_signature() {
        // Create a server and author profile
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $author_id = $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
        ));
        
        // Create a test post with the author
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
        ));
        
        // Create a signature
        $signature_id = $this->create_test_signature(array(
            'post_id' => $post_id,
            'server_id' => $server_id,
        ));
        
        // Verify the signature
        $result = $this->signing_service->verify_post_signature($post_id, $signature_id);
        
        // Verify result
        $this->assertTrue($result['success']);
        $this->assertEquals('Signature verified successfully.', $result['message']);
    }

    /**
     * Test verify_post_signature method with missing post.
     */
    public function test_verify_post_signature_missing_post() {
        // Create a signature
        $signature_id = $this->create_test_signature();
        
        // Verify with non-existent post
        $result = $this->signing_service->verify_post_signature(999999, $signature_id);
        
        // Verify result
        $this->assertFalse($result['success']);
        $this->assertEquals('Post not found.', $result['message']);
    }

    /**
     * Test verify_post_signature method with missing signature.
     */
    public function test_verify_post_signature_missing_signature() {
        // Create a test post
        $post_id = $this->create_test_post();
        
        // Verify with non-existent signature
        $result = $this->signing_service->verify_post_signature($post_id, 999999);
        
        // Verify result
        $this->assertFalse($result['success']);
        $this->assertEquals('Signature not found.', $result['message']);
    }

    /**
     * Test normalize_content method.
     */
    public function test_normalize_content() {
        // Create a test post with HTML content
        $post_id = $this->create_test_post(array(
            'post_content' => '<p>This is <strong>formatted</strong> content with   extra   spaces.</p>',
        ));
        $post = get_post($post_id);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($this->signing_service);
        $method = $reflection->getMethod('normalize_content');
        $method->setAccessible(true);
        
        // Call the method
        $normalized = $method->invoke($this->signing_service, $post->post_content);
        
        // Verify normalization
        $this->assertEquals('This is formatted content with extra spaces.', $normalized);
    }

    /**
     * Test calculate_content_hash method.
     */
    public function test_calculate_content_hash() {
        $content = 'Test content for hashing';
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($this->signing_service);
        $method = $reflection->getMethod('calculate_content_hash');
        $method->setAccessible(true);
        
        // Call the method
        $hash = $method->invoke($this->signing_service, $content);
        
        // Verify hash format
        $this->assertStringStartsWith('sha256:', $hash);
        $this->assertMatchesRegularExpression('/^sha256:[A-Za-z0-9+\/]{43}$/', $hash);
        $this->assertStringNotContainsString('=', $hash);
    }
}
