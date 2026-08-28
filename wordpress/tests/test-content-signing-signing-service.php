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
            'server_id' => 0,
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
        $this->assertEquals('awaiting-local-signature', $signatures[0]->status);
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
            'server_id' => 0,
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
        $this->assertEquals('awaiting-local-signature', $signatures[0]->status);
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
    public function test_sign_post_is_disabled() {
        $result = $this->signing_service->sign_post(123);

        $this->assertFalse($result['success']);
        $this->assertSame('local_signing_required', $result['code']);
        $this->assertStringContainsString('disabled', strtolower($result['message']));
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
        $this->assertSame('local_signing_required', $result['code']);
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
        $this->assertSame('local_signing_required', $result['code']);
    }

    /**
     * Browser signatures are verified by PHP and stored without an API call.
     */
    public function test_complete_local_signing_verifies_and_persists() {
        update_option('siteurl', 'https://example.org');
        update_option('home', 'https://example.org');
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0));
        $post_id = $this->create_test_post(array('post_author' => $user_id, 'post_status' => 'draft'));
        wp_set_current_user($user_id);

        $keyid = 'https://example.org/wp-json/htmltrust/v1/keys/test-key';
        $prepared = $this->signing_service->prepare_local_signing($post_id, $keyid);
        $this->assertTrue($prepared['success']);
        $this->assertSame('htmltrust-signature-v1', $prepared['data']['profile']);
        $this->assertSame('url', $prepared['data']['scope']);
        $this->assertSame($prepared['data']['sourceURL'], $prepared['data']['location']);
        $this->assertStringStartsWith('{', $prepared['data']['payload']);
        $payload_object = json_decode($prepared['data']['payload'], true);
        $this->assertSame('htmltrust-signature-v1', $payload_object['profile']);
        $this->assertSame($keyid, $payload_object['keyid']);
        $this->assertSame('url', $payload_object['scope']);
        $this->assertSame($prepared['data']['location'], $payload_object['location']);
        $this->assertSame($prepared['data']['signedAt'], $payload_object['signedAt']);
        $this->assertSame(
            HTMLTrust\Canonicalization\Signature::buildSigningPayloadV1(array(
                'contentHash' => $prepared['data']['contentHash'],
                'claimsHash' => $prepared['data']['claimsHash'],
                'documentURL' => $prepared['data']['sourceURL'],
                'scope' => 'url',
                'keyid' => $keyid,
                'algorithm' => 'ed25519',
                'signedAt' => $prepared['data']['signedAt'],
            )),
            $prepared['data']['payload']
        );

        $keypair = sodium_crypto_sign_keypair();
        $public_key = sodium_crypto_sign_publickey($keypair);
        $private_key = sodium_crypto_sign_secretkey($keypair);
        $signature = sodium_crypto_sign_detached($prepared['data']['payload'], $private_key);

        $result = $this->signing_service->complete_local_signing($post_id, array(
            'prepareToken' => $prepared['data']['prepareToken'],
            'keyid' => $keyid,
            'publicKey' => $this->base64url($public_key),
            'signature' => $this->base64url($signature),
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

        $this->assertTrue($result['success']);
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertCount(1, $signatures);
        $this->assertEquals('local-browser', $signatures[0]->signing_mode);
        $this->assertEquals($keyid, $signatures[0]->keyid);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/]+$/', $signatures[0]->public_key);
        $this->assertStringNotContainsString('=', $signatures[0]->public_key);
        $this->assertTrue($this->signing_service->verify_post_signature($post_id, $signatures[0]->signature_id)['data']['valid']);
    }

    /**
     * The public key endpoint returns the resolver's canonical SPKI document.
     */
    public function test_local_key_resolver_document_and_mutation() {
        update_option('siteurl', 'https://example.org');
        update_option('home', 'https://example.org');
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0));
        $post_id = $this->create_test_post(array('post_author' => $user_id));
        wp_set_current_user($user_id);
        $keyid = trailingslashit(get_rest_url(null, 'htmltrust/v1/keys')) . 'resolver';
        $prepared = $this->signing_service->prepare_local_signing($post_id, $keyid);
        $keypair = sodium_crypto_sign_keypair();
        $public_key = sodium_crypto_sign_publickey($keypair);
        $signature = sodium_crypto_sign_detached($prepared['data']['payload'], sodium_crypto_sign_secretkey($keypair));
        $result = $this->signing_service->complete_local_signing($post_id, array(
            'prepareToken' => $prepared['data']['prepareToken'],
            'keyid' => $keyid,
            'publicKey' => $this->base64url($public_key),
            'signature' => $this->base64url($signature),
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
        $this->assertTrue($result['success']);

        $request = new WP_REST_Request('GET', '/htmltrust/v1/keys/resolver');
        $request->set_param('keyid', 'resolver');
        $response = $this->plugin->get_public()->resolve_local_key($request);
        $this->assertNotWPError($response, $response instanceof WP_Error ? $response->get_error_message() : 'unexpected response type');
        $document = $response->get_data();
        $this->assertSame('resolver', $document['id']);
        $this->assertSame($keyid, $document['keyid']);
        $this->assertSame('ed25519', $document['algorithm']);
        $this->assertSame('spki-der', $document['publicKeyEncoding']);
        $this->assertSame('HUMAN', $document['type']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/]+$/', $document['publicKey']);
        $this->assertStringNotContainsString('=', $document['publicKey']);
        $encoded_key = $document['publicKey'] . str_repeat('=', (4 - strlen($document['publicKey']) % 4) % 4);
        $der = base64_decode($encoded_key, true);
        $this->assertSame("\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00", substr($der, 0, 12));
        $this->assertSame(44, strlen($der));

        $stored = $this->db->get_signature($result['data']['signatureId']);
        $mutated_key = substr($stored->public_key, 0, -1) . ($stored->public_key[-1] === 'A' ? 'B' : 'A');
        $this->assertTrue($this->db->update_signature($stored->signature_id, array('public_key' => $mutated_key)));
        $this->assertFalse($this->signing_service->verify_post_signature($post_id, $stored->signature_id)['data']['valid']);
    }

    /**
     * A signature prepared for old content cannot be attached to new content.
     */
    public function test_complete_local_signing_rejects_content_mutation() {
        update_option('siteurl', 'https://example.org');
        update_option('home', 'https://example.org');
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0));
        $post_id = $this->create_test_post(array('post_author' => $user_id));
        wp_set_current_user($user_id);
        $keyid = 'https://example.org/wp-json/htmltrust/v1/keys/mutation';
        $prepared = $this->signing_service->prepare_local_signing($post_id, $keyid);
        wp_update_post(array('ID' => $post_id, 'post_content' => 'Changed after prepare.'));

        $keypair = sodium_crypto_sign_keypair();
        $public_key = sodium_crypto_sign_publickey($keypair);
        $private_key = sodium_crypto_sign_secretkey($keypair);
        $signature = sodium_crypto_sign_detached($prepared['data']['payload'], $private_key);
        $result = $this->signing_service->complete_local_signing($post_id, array(
            'prepareToken' => $prepared['data']['prepareToken'],
            'keyid' => $keyid,
            'publicKey' => $this->base64url($public_key),
            'signature' => $this->base64url($signature),
            'signedAt' => $prepared['data']['signedAt'],
            'payload' => $prepared['data']['payload'],
            'contentHash' => $prepared['data']['contentHash'],
            'claimsHash' => $prepared['data']['claimsHash'],
            'domain' => $prepared['data']['domain'],
            'profile' => $prepared['data']['profile'],
            'algorithm' => $prepared['data']['algorithm'],
            'scope' => $prepared['data']['scope'],
            'location' => $prepared['data']['location'],
            'sourceURL' => $prepared['data']['sourceURL'],
        ));

        $this->assertFalse($result['success']);
        $this->assertMatchesRegularExpression('/changed|preparation/', strtolower($result['message']));
    }

    /**
     * v1 metadata mutations cannot be smuggled past independent rebuilding.
     */
    public function test_complete_local_signing_rejects_v1_binding_mutations() {
        update_option('siteurl', 'https://example.org');
        update_option('home', 'https://example.org');
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0));
        $post_id = $this->create_test_post(array('post_author' => $user_id));
        wp_set_current_user($user_id);
        $keyid = 'https://example.org/wp-json/htmltrust/v1/keys/negative';
        $prepared = $this->signing_service->prepare_local_signing($post_id, $keyid);
        $keypair = sodium_crypto_sign_keypair();
        $public_key = sodium_crypto_sign_publickey($keypair);
        $private_key = sodium_crypto_sign_secretkey($keypair);
        $signature = sodium_crypto_sign_detached($prepared['data']['payload'], $private_key);
        $submission = array(
            'prepareToken' => $prepared['data']['prepareToken'],
            'keyid' => $keyid,
            'publicKey' => $this->base64url($public_key),
            'signature' => $this->base64url($signature),
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
        );

        foreach (array(
            'keyid' => 'https://example.org/wp-json/htmltrust/v1/keys/other',
            'scope' => 'origin',
            'location' => 'https://example.org',
            'algorithm' => 'rsa-pss-sha256',
            'signedAt' => '2026-01-01T00:00:00Z',
        ) as $field => $value) {
            $mutated = $submission;
            $mutated[$field] = $value;
            $result = $this->signing_service->complete_local_signing($post_id, $mutated);
            $this->assertFalse($result['success'], 'Mutation should be rejected: ' . $field);
        }
    }

    /**
     * Save/publish hooks queue work for a browser instead of remote signing.
     */
    public function test_queue_local_signature_does_not_call_remote_api() {
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0));
        $post_id = $this->create_test_post(array('post_author' => $user_id));

        $signature_id = $this->signing_service->queue_local_signature($post_id);
        $this->assertNotFalse($signature_id);
        $signature = $this->db->get_signature($signature_id);
        $this->assertEquals('awaiting-local-signature', $signature->status);
        $this->assertEquals('local-browser', $signature->signing_mode);
        $this->assertSame(0, (int) $signature->server_id);
    }

    /**
     * Browser-held signing keys cannot be used by another editor.
     */
    public function test_local_signing_requires_post_author() {
        update_option('siteurl', 'https://example.org');
        update_option('home', 'https://example.org');
        $server_id = $this->create_test_server();
        $author_id = $this->create_test_user();
        $other_user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $author_id, 'server_id' => $server_id));
        $post_id = $this->create_test_post(array('post_author' => $author_id));
        wp_set_current_user($other_user_id);

        $prepared = $this->signing_service->prepare_local_signing($post_id, 'https://example.org/wp-json/htmltrust/v1/keys/unauthorized');
        $this->assertFalse($prepared['success']);
        $this->assertStringContainsString('post author', strtolower($prepared['message']));

        $completed = $this->signing_service->complete_local_signing($post_id, array('keyid' => 'https://example.org/wp-json/htmltrust/v1/keys/unauthorized'));
        $this->assertFalse($completed['success']);
        $this->assertStringContainsString('post author', strtolower($completed['message']));
    }

    public function test_remote_profile_cannot_enter_local_signing_path() {
        $server_id = $this->create_test_server();
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => $server_id));
        $post_id = $this->create_test_post(array('post_author' => $user_id));
        wp_set_current_user($user_id);

        $prepared = $this->signing_service->prepare_local_signing($post_id, 'https://example.org/key/remote');
        $this->assertFalse($prepared['success']);
        $this->assertStringContainsString('server ID 0', $prepared['message']);
        $this->assertFalse($this->signing_service->queue_local_signature($post_id));
        $completed = $this->signing_service->complete_local_signing($post_id, array());
        $this->assertFalse($completed['success']);
        $this->assertStringContainsString('remote author', strtolower($completed['message']));
    }

    /**
     * A valid prepare token is consumed and cannot be replayed.
     */
    public function test_local_prepare_token_is_one_time() {
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0, 'author_api_key' => ''));
        $post_id = $this->create_test_post(array('post_author' => $user_id));
        wp_set_current_user($user_id);
        $prepared = $this->signing_service->prepare_local_signing($post_id, 'https://example.org/key/one-time');
        $keypair = sodium_crypto_sign_keypair();
        $submission = $this->local_submission($prepared['data'], 'https://example.org/key/one-time', $keypair);
        $transient_key = 'content_signing_prepare_' . hash('sha256', $prepared['data']['prepareToken']);
        $consume_lock = 'content_signing_consumed_' . hash('sha256', $prepared['data']['prepareToken']);
        $prepared_state = get_transient($transient_key);

        $this->assertTrue($this->signing_service->complete_local_signing($post_id, $submission)['success']);
        $this->assertNotFalse(get_option($consume_lock, false));

        // Model a transient backend that acknowledged deletion without
        // removing the value. The durable consume guard still rejects replay.
        set_transient($transient_key, $prepared_state, 5 * MINUTE_IN_SECONDS);
        $replay = $this->signing_service->complete_local_signing($post_id, $submission);
        $this->assertFalse($replay['success']);
        $this->assertStringContainsString('consumed', strtolower($replay['message']));
        delete_transient($transient_key);
        delete_option($consume_lock);
    }

    public function test_local_prepare_is_reusable_after_persistence_failure() {
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0, 'author_api_key' => ''));
        $post_id = $this->create_test_post(array('post_author' => $user_id));
        wp_set_current_user($user_id);
        $keyid = 'https://example.org/key/retry';
        $prepared = $this->signing_service->prepare_local_signing($post_id, $keyid);
        $keypair = sodium_crypto_sign_keypair();
        $submission = $this->local_submission($prepared['data'], $keyid, $keypair);

        add_filter('content_signing_local_signature_persistence', '__return_false');
        $failed = $this->signing_service->complete_local_signing($post_id, $submission);
        remove_filter('content_signing_local_signature_persistence', '__return_false');
        $this->assertFalse($failed['success']);
        $this->assertStringContainsString('retry', strtolower($failed['message']));

        $retried = $this->signing_service->complete_local_signing($post_id, $submission);
        $this->assertTrue($retried['success']);
    }

    /**
     * Expired or deleted prepare state cannot be completed.
     */
    public function test_local_prepare_token_expiry_is_rejected() {
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0, 'author_api_key' => ''));
        $post_id = $this->create_test_post(array('post_author' => $user_id));
        wp_set_current_user($user_id);
        $prepared = $this->signing_service->prepare_local_signing($post_id, 'https://example.org/key/expired');
        delete_transient('content_signing_prepare_' . hash('sha256', $prepared['data']['prepareToken']));
        $rejected = $this->signing_service->complete_local_signing($post_id, array(
            'prepareToken' => $prepared['data']['prepareToken'],
            'keyid' => 'https://example.org/key/expired',
            'publicKey' => 'invalid',
            'signature' => 'invalid',
            'signedAt' => $prepared['data']['signedAt'],
        ));
        $this->assertFalse($rejected['success']);
        $this->assertStringContainsString('expired', strtolower($rejected['message']));
    }

    /**
     * A key identifier remains bound to its first public key.
     */
    public function test_local_keyid_rejects_public_key_rotation() {
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0, 'author_api_key' => ''));
        $post_id = $this->create_test_post(array('post_author' => $user_id));
        wp_set_current_user($user_id);
        $keyid = 'https://example.org/key/immutable';
        $first = $this->signing_service->prepare_local_signing($post_id, $keyid);
        $first_pair = sodium_crypto_sign_keypair();
        $this->assertTrue($this->signing_service->complete_local_signing($post_id, $this->local_submission($first['data'], $keyid, $first_pair))['success']);

        $second = $this->signing_service->prepare_local_signing($post_id, $keyid);
        $second_pair = sodium_crypto_sign_keypair();
        $rejected = $this->signing_service->complete_local_signing($post_id, $this->local_submission($second['data'], $keyid, $second_pair));
        $this->assertFalse($rejected['success']);
        $this->assertStringContainsString('different public key', strtolower($rejected['message']));
    }

    /**
     * Competing first-use requests serialize on the persistent key binding.
     */
    public function test_local_keyid_binding_rejects_competing_first_use_key() {
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0, 'author_api_key' => ''));
        $post_id = $this->create_test_post(array('post_author' => $user_id));
        wp_set_current_user($user_id);
        $keyid = 'https://example.org/key/competing-first-use';

        // Prepare both requests before either signature is stored. Both see
        // an unused key ID, then add_option elects the first completion.
        $first = $this->signing_service->prepare_local_signing($post_id, $keyid);
        $second = $this->signing_service->prepare_local_signing($post_id, $keyid);
        $first_pair = sodium_crypto_sign_keypair();
        $second_pair = sodium_crypto_sign_keypair();

        $this->assertTrue($this->signing_service->complete_local_signing(
            $post_id,
            $this->local_submission($first['data'], $keyid, $first_pair)
        )['success']);

        $rejected = $this->signing_service->complete_local_signing(
            $post_id,
            $this->local_submission($second['data'], $keyid, $second_pair)
        );
        $this->assertFalse($rejected['success']);
        $this->assertStringContainsString('different public key', strtolower($rejected['message']));
    }

    /**
     * A signature that fails verification cannot reserve a new key ID.
     */
    public function test_invalid_signature_does_not_bind_keyid() {
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0, 'author_api_key' => ''));
        $post_id = $this->create_test_post(array('post_author' => $user_id));
        wp_set_current_user($user_id);
        $keyid = 'https://example.org/key/verify-before-binding';
        $prepared = $this->signing_service->prepare_local_signing($post_id, $keyid);
        $unproven_pair = sodium_crypto_sign_keypair();
        $valid_pair = sodium_crypto_sign_keypair();

        $invalid = $this->local_submission($prepared['data'], $keyid, $unproven_pair);
        $invalid['signature'] = $this->base64url(sodium_crypto_sign_detached(
            $prepared['data']['payload'],
            sodium_crypto_sign_secretkey($valid_pair)
        ));
        $rejected = $this->signing_service->complete_local_signing($post_id, $invalid);
        $this->assertFalse($rejected['success']);
        $this->assertStringContainsString('did not verify', strtolower($rejected['message']));

        $accepted = $this->signing_service->complete_local_signing(
            $post_id,
            $this->local_submission($prepared['data'], $keyid, $valid_pair)
        );
        $this->assertTrue($accepted['success']);
    }

    /**
     * Prepare requests reclaim old consume locks left by crashed PHP.
     */
    public function test_prepare_reclaims_bounded_orphaned_consume_lock() {
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0, 'author_api_key' => ''));
        $post_id = $this->create_test_post(array('post_author' => $user_id));
        wp_set_current_user($user_id);
        $token = 'orphaned-consume-lock-for-prepare';
        $lock_name = 'content_signing_consumed_' . hash('sha256', $token);
        update_option($lock_name, gmdate('c', time() - (11 * MINUTE_IN_SECONDS)), false);

        $prepared = $this->signing_service->prepare_local_signing($post_id, 'https://example.org/key/reclaim');

        $this->assertTrue($prepared['success']);
        $this->assertFalse(get_option($lock_name, false));
    }

    /**
     * Re-queuing after an edit refreshes the durable pending hashes.
     */
    public function test_queue_local_signature_refreshes_existing_row() {
        $user_id = $this->create_test_user();
        $this->create_test_author(array('wp_user_id' => $user_id, 'server_id' => 0, 'author_api_key' => ''));
        $post_id = $this->create_test_post(array('post_author' => $user_id, 'post_content' => 'First version.'));
        $first_id = $this->signing_service->queue_local_signature($post_id);
        $first = $this->db->get_signature($first_id);
        wp_update_post(array('ID' => $post_id, 'post_content' => 'Second version.'));
        $second_id = $this->signing_service->queue_local_signature($post_id);
        $second = $this->db->get_signature($second_id);
        $this->assertSame($first_id, $second_id);
        $this->assertNotSame($first->content_hash, $second->content_hash);
    }

    /**
     * Invoke private prepare_content_data for focused format assertions.
     *
     * @param WP_Post $post The post.
     * @return array        Prepared content data.
     */
    private function invoke_prepare_content_data($post) {
        $method = new ReflectionMethod($this->signing_service, 'prepare_content_data');
        return $method->invoke($this->signing_service, $post);
    }

    /**
     * Encode binary test data as unpadded base64url.
     *
     * @param string $bytes Binary data.
     * @return string Encoded value.
     */
    private function base64url($bytes) {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function local_submission($prepared, $keyid, $keypair) {
        $public_key = sodium_crypto_sign_publickey($keypair);
        $signature = sodium_crypto_sign_detached($prepared['payload'], sodium_crypto_sign_secretkey($keypair));
        return array(
            'prepareToken' => $prepared['prepareToken'],
            'keyid' => $keyid,
            'publicKey' => $this->base64url($public_key),
            'signature' => $this->base64url($signature),
            'contentHash' => $prepared['contentHash'],
            'claimsHash' => $prepared['claimsHash'],
            'domain' => $prepared['domain'],
            'signedAt' => $prepared['signedAt'],
            'payload' => $prepared['payload'],
            'profile' => $prepared['profile'],
            'algorithm' => $prepared['algorithm'],
            'scope' => $prepared['scope'],
            'location' => $prepared['location'],
            'sourceURL' => $prepared['sourceURL'],
        );
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
     * Legacy endorsement authority is absent from the publication service.
     */
    public function test_remote_endorsement_authority_is_disabled() {
        $this->assertFalse(method_exists($this->signing_service, 'process_endorsements'));
        $result = $this->signing_service->sign_post(456);
        $this->assertFalse($result['success']);
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
        // Call the method
        $hash = $method->invoke($this->signing_service, $content);
        
        // Verify hash format
        $this->assertStringStartsWith('sha256:', $hash);
        $this->assertMatchesRegularExpression('/^sha256:[A-Za-z0-9+\/]{43}$/', $hash);
        $this->assertStringNotContainsString('=', $hash);
    }
}
