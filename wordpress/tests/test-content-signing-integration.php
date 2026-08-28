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
            'server_id' => 0,
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
        $this->assertEquals('awaiting-local-signature', $signatures[0]->status);
        
        $this->assertEquals('local-browser', $signatures[0]->signing_mode);
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
            'server_id' => 0,
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

        // Key resolution must be registered independently of frontend asset
        // enqueueing because verifiers call it from REST requests.
        $this->assertTrue(has_action('rest_api_init'));
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
            'server_id' => 0,
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
        $this->assertEquals('awaiting-local-signature', $signatures[0]->status);
        
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
            'server_id' => 0,
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
        
        // Browser signing creates one queue entry. Endorsements cannot use a
        // private key held by the remote trust server anymore.
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertCount(1, $signatures);
        $this->assertEquals('awaiting-local-signature', $signatures[0]->status);
        
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
        $this->assertSame(
            '<p>Signed body</p>',
            $method->invoke($display, $this->db->get_signature($signature_id), '<p>Signed body</p>')
        );
    }

    /**
     * Local signatures emit the frozen v1 profile, scope, and location.
     */
    public function test_public_rendering_emits_v1_local_attributes() {
        $post_id = $this->create_test_post(array('post_content' => '<p>Signed body</p>'));
        $signature_id = $this->create_test_signature(array(
            'post_id' => $post_id,
            'signing_mode' => 'local-browser',
            'server_id' => 0,
            'keyid' => 'https://example.org/wp-json/htmltrust/v1/keys/test-key',
            'public_key' => str_repeat('A', 43),
            'signature' => str_repeat('B', 86),
            'api_response' => array(
                'keyid' => 'https://example.org/wp-json/htmltrust/v1/keys/test-key',
                'algorithm' => 'ed25519',
                'profile' => 'htmltrust-signature-v1',
                'scope' => 'url',
                'location' => 'https://example.org/test-post',
                'sourceURL' => 'https://example.org/test-post',
                'payload' => '{}',
            ),
        ));
        $display = new ContentSigning_Display($this->db, $this->api_client);
        $method = new ReflectionMethod($display, 'get_signed_section_html');
        $html = $method->invoke($display, $this->db->get_signature($signature_id), '<p>Signed body</p>');

        $this->assertStringContainsString('profile="htmltrust-signature-v1"', $html);
        $this->assertStringContainsString('signature-scope="url"', $html);
        $this->assertStringNotContainsString(' scope="url"', $html);
        $this->assertStringContainsString('location="https://example.org/test-post"', $html);
    }

    public function test_public_rendering_withholds_corrupt_local_v1_metadata() {
        $post_id = $this->create_test_post(array('post_content' => '<p>Signed body</p>'));
        foreach (array(
            array('profile' => 'wrong-profile', 'scope' => 'url', 'location' => 'https://example.org/test-post', 'sourceURL' => 'https://example.org/test-post'),
            array('profile' => 'htmltrust-signature-v1', 'scope' => 'origin', 'location' => 'https://example.org/test-post', 'sourceURL' => 'https://example.org/test-post'),
            array('profile' => 'htmltrust-signature-v1', 'scope' => 'url', 'location' => '', 'sourceURL' => 'https://example.org/test-post'),
            array('profile' => 'htmltrust-signature-v1', 'scope' => 'url', 'location' => 'https://example.org/test-post', 'sourceURL' => ''),
        ) as $metadata) {
            $signature_id = $this->create_test_signature(array(
                'post_id' => $post_id,
                'signing_mode' => 'local-browser',
                'server_id' => 0,
                'keyid' => 'https://example.org/wp-json/htmltrust/v1/keys/corrupt',
                'public_key' => str_repeat('A', 43),
                'signature' => str_repeat('B', 86),
                'api_response' => array_merge($metadata, array(
                    'keyid' => 'https://example.org/wp-json/htmltrust/v1/keys/corrupt',
                    'algorithm' => 'ed25519',
                    'payload' => '{}',
                )),
            ));
            $display = new ContentSigning_Display($this->db, $this->api_client);
            $method = new ReflectionMethod($display, 'get_signed_section_html');
            $html = $method->invoke($display, $this->db->get_signature($signature_id), '<p>Signed body</p>');
            $this->assertStringNotContainsString('<signed-section', $html);
            $this->db->delete_signature($signature_id);
        }
    }

    /**
     * A late content filter cannot leave a stale local wrapper on the page.
     */
    public function test_public_rendering_withholds_signature_after_late_mutation() {
        update_option('siteurl', 'https://example.org');
        update_option('home', 'https://example.org');
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0));
        $post_id = $this->create_test_post(array(
            'post_author' => $user_id,
            'post_content' => '<p>Signed body</p>',
        ));
        wp_set_current_user($user_id);
        $keyid = 'https://example.org/wp-json/htmltrust/v1/keys/late-filter';
        $prepared = $this->signing_service->prepare_local_signing($post_id, $keyid);
        $keypair = sodium_crypto_sign_keypair();
        $public_key = sodium_crypto_sign_publickey($keypair);
        $signature = sodium_crypto_sign_detached($prepared['data']['payload'], sodium_crypto_sign_secretkey($keypair));
        $stored = $this->signing_service->complete_local_signing($post_id, array(
            'prepareToken' => $prepared['data']['prepareToken'],
            'keyid' => $keyid,
            'publicKey' => rtrim(strtr(base64_encode($public_key), '+/', '-_'), '='),
            'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
            'contentHash' => $prepared['data']['contentHash'],
            'claimsHash' => $prepared['data']['claimsHash'],
            'domain' => $prepared['data']['domain'],
            'signedAt' => $prepared['data']['signedAt'],
            'payload' => $prepared['data']['payload'],
            'profile' => $prepared['data']['profile'],
            'algorithm' => $prepared['data']['algorithm'],
            'scope' => $prepared['data']['scope'],
            'location' => $prepared['data']['location'],
            'sourceURL' => $prepared['data']['sourceURL'],
        ));
        $this->assertTrue($stored['success']);

        $display = $this->plugin->get_public()->get_display();
        $late_filter = function ($content) {
            return $content . '<p>Late filter mutation</p>';
        };
        add_filter('the_content', $late_filter, PHP_INT_MAX - 1);
        add_filter('the_content', array($display, 'display_signature'), PHP_INT_MAX);
        $this->go_to(get_permalink($post_id));
        $rendered = apply_filters('the_content', '<p>Signed body</p>');
        remove_filter('the_content', array($display, 'display_signature'), PHP_INT_MAX);
        remove_filter('the_content', $late_filter, PHP_INT_MAX - 1);

        $this->assertStringContainsString('Late filter mutation', $rendered);
        $this->assertStringNotContainsString('<signed-section', $rendered);
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
        
        // The legacy remote endpoint is intentionally disabled. The AJAX
        // route remains for clients that need a deterministic error response.
        $this->assertNotFalse(has_action('wp_ajax_content_signing_sign_post'));
        $result = $this->signing_service->sign_post($post_id);

        $this->assertFalse($result['success']);
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertCount(0, $signatures);
    }
}
