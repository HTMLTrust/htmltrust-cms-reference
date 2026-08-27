<?php
/**
 * Integration tests for Content Signing.
 *
 * @package Content_Signing
 */

/**
 * Integration test class.
 */
class Test_Content_Signing_Integration extends ContentSigning_API_Client_TestCase {

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
        
        // Get components
        $this->signing_service = $this->plugin->get_signing_service();
        $this->scheduler = $this->plugin->get_scheduler();
        
        // Set up default options
        update_option('content_signing_enable_signing', true);
        update_option('content_signing_sign_on_publish', true);
        update_option('content_signing_post_types', array('post'));
    }

    /**
     * Test the complete signing workflow.
     */
    public function test_complete_signing_workflow() {
        // Create a server
        $server_id = $this->create_test_server();
        
        // Create an author profile
        $user_id = $this->create_test_user();
        $author_id = $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
        ));
        
        // Create a post
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
            'post_status' => 'draft',
        ));
        
        // Verify no signatures yet
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertEmpty($signatures);
        
        // Publish the post
        wp_publish_post($post_id);
        $post = get_post($post_id);
        
        // Verify a signature was created
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertCount(1, $signatures);
        $this->assertEquals('signed', $signatures[0]->status);
        
        // Verify the signature
        $signature_id = $signatures[0]->signature_id;
        $result = $this->signing_service->verify_post_signature($post_id, $signature_id);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('Signature verified successfully.', $result['message']);
    }

    /**
     * Test WordPress hooks integration.
     */
    public function test_wordpress_hooks_integration() {
        // Create a server
        $server_id = $this->create_test_server();
        
        // Create an author profile
        $user_id = $this->create_test_user();
        $author_id = $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
        ));
        
        // Create a post
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
            'post_status' => 'draft',
        ));
        
        // Verify the save_post hook is registered
        $this->assertTrue(has_action('save_post'));
        
        // Verify the transition_post_status hook is registered
        $this->assertTrue(has_action('transition_post_status'));
        
        // Verify the content_signing_scheduled_signing hook is registered
        $this->assertTrue(has_action('content_signing_scheduled_signing'));
    }

    /**
     * Test admin settings saving and retrieval.
     */
    public function test_admin_settings_saving_and_retrieval() {
        // Set various plugin settings
        $settings = array(
            'content_signing_enable_signing' => true,
            'content_signing_sign_on_publish' => true,
            'content_signing_sign_on_update' => false,
            'content_signing_sign_days_before_publish' => 1,
            'content_signing_sign_days_after_publish' => 2,
            'content_signing_post_types' => array('post', 'page'),
            'content_signing_enable_endorsements' => true,
        );
        
        foreach ($settings as $option => $value) {
            update_option($option, $value);
        }
        
        // Verify settings are saved correctly
        foreach ($settings as $option => $value) {
            $this->assertEquals($value, get_option($option));
        }
    }

    /**
     * Test scheduled signing workflow.
     */
    public function test_scheduled_signing_workflow() {
        // Create a server
        $server_id = $this->create_test_server();
        
        // Create an author profile
        $user_id = $this->create_test_user();
        $author_id = $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
        ));
        
        // Configure for scheduled signing
        update_option('content_signing_sign_on_publish', false);
        update_option('content_signing_sign_days_after_publish', 1);
        
        // Create a post
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
            'post_status' => 'publish',
        ));
        wp_publish_post($post_id);
        
        // Verify no signatures yet
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertEmpty($signatures);
        
        // Verify a signing is scheduled
        $scheduled = $this->scheduler->has_scheduled_signing($post_id);
        $this->assertTrue($scheduled);
        
        // Simulate the scheduled event
        do_action('content_signing_scheduled_signing', $post_id);
        
        // Verify a signature was created
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertCount(1, $signatures);
        $this->assertEquals('signed', $signatures[0]->status);
        
        // Reset options
        update_option('content_signing_sign_on_publish', true);
        update_option('content_signing_sign_days_after_publish', 0);
    }

    /**
     * Test endorsement workflow.
     */
    public function test_endorsement_workflow() {
        // Create a server
        $server_id = $this->create_test_server();
        
        // Create a regular author profile
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
        
        // Create a post
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
        ));
        wp_publish_post($post_id);
        
        // Verify signatures were created (1 primary + 2 endorsements)
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertCount(3, $signatures);
        
        // Verify all signatures are valid
        foreach ($signatures as $signature) {
            $result = $this->signing_service->verify_post_signature($post_id, $signature->signature_id);
            $this->assertTrue($result['success']);
        }
        
        // Reset options
        update_option('content_signing_enable_endorsements', false);
    }

    /**
     * Test post meta box integration.
     */
    public function test_post_meta_box_integration() {
        // Verify the add_meta_boxes hook is registered
        $this->assertTrue(has_action('add_meta_boxes'));
        
        // Create a post
        $post_id = $this->create_test_post();
        
        // Set post meta
        update_post_meta($post_id, '_content_signing_disable', true);
        update_post_meta($post_id, '_content_signing_claims', array('ContentType' => 'Article'));
        
        // Verify post meta is saved correctly
        $this->assertTrue((bool) get_post_meta($post_id, '_content_signing_disable', true));
        $claims = get_post_meta($post_id, '_content_signing_claims', true);
        $this->assertEquals('Article', $claims['ContentType']);
    }

    /**
     * Test public rendering wraps the actual post content in signed-section.
     */
    public function test_public_rendering_wraps_actual_signed_content() {
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user(array(
            'display_name' => 'Alice Example',
        ));
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
            'post_content' => '<p>Signed body</p>',
        ));

        $signature_id = $this->create_test_signature(array(
            'post_id' => $post_id,
            'server_id' => $server_id,
            'wp_user_id' => $user_id,
            'content_hash' => 'sha256:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU',
            'domain' => 'https://example.org',
            'signature' => 'qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq',
            'claims' => array(
                'author' => 'Alice Example',
                'signed-at' => '2026-05-01T10:30:00Z',
                'claim:ContentType' => 'Article',
            ),
            'api_response' => array(
                'keyId' => 'did:web:author.example',
                'algorithm' => 'ed25519',
            ),
        ));

        $display = new ContentSigning_Display($this->db, $this->api_client);
        $method = new ReflectionMethod($display, 'get_signed_section_html');
        $method->setAccessible(true);
        $html = $method->invoke($display, $this->db->get_signature($signature_id), '<p>Signed body</p>');

        $this->assertStringStartsWith('<signed-section ', $html);
        $this->assertStringContainsString('keyid="did:web:author.example"', $html);
        $this->assertStringContainsString('<meta name="author" content="Alice Example">', $html);
        $this->assertStringContainsString('<meta name="signed-at" content="2026-05-01T10:30:00Z">', $html);
        $this->assertStringContainsString('<meta name="claim:ContentType" content="Article">', $html);
        $this->assertStringContainsString('<p>Signed body</p></signed-section>', $html);
    }

    public function test_public_rendering_fails_closed_without_algorithm_metadata() {
        $post_id = $this->create_test_post(array('post_content' => '<p>Signed body</p>'));
        $signature_id = $this->create_test_signature(array(
            'post_id' => $post_id,
            'server_id' => 999999,
            'signature' => 'qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq',
            'api_response' => array('keyId' => 'did:web:author.example'),
        ));
        $display = new ContentSigning_Display($this->db, $this->api_client);
        $method = new ReflectionMethod($display, 'get_signed_section_html');
        $method->setAccessible(true);

        $this->assertSame(
            '<p>Signed body</p>',
            $method->invoke($display, $this->db->get_signature($signature_id), '<p>Signed body</p>')
        );
    }

    /**
     * Test error handling in the signing process.
     */
    public function test_error_handling() {
        // Create a server with invalid API key
        $server_id = $this->create_test_server(array(
            'api_key' => 'invalid-key',
        ));
        
        // Create an author profile
        $user_id = $this->create_test_user();
        $author_id = $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
            'author_api_key' => 'invalid-key',
        ));
        
        // Create a post
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
        ));
        
        // Temporarily modify the mock API key to trigger an error
        $original_key = $this->mock_api_key;
        $this->mock_api_key = 'valid-key-but-not-matching';
        
        // Sign the post
        $result = $this->signing_service->sign_post($post_id);
        
        // Verify error handling
        $this->assertFalse($result['success']);
        
        // Verify signature record with error status
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertCount(1, $signatures);
        $this->assertEquals('error', $signatures[0]->status);
        
        // Restore original key
        $this->mock_api_key = $original_key;
    }
}
