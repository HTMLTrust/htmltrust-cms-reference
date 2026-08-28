<?php
/**
 * Tests for the ContentSigning_DB class.
 *
 * @package Content_Signing
 */

/**
 * DB test class.
 */
class Test_Content_Signing_DB extends ContentSigning_DB_TestCase {

    /**
     * Test get_table_name method.
     */
    public function test_get_table_name() {
        global $wpdb;
        
        // Test valid table names
        $this->assertEquals($wpdb->prefix . 'content_signing_servers', $this->db->get_table_name('servers'));
        $this->assertEquals($wpdb->prefix . 'content_signing_authors', $this->db->get_table_name('authors'));
        $this->assertEquals($wpdb->prefix . 'content_signing_signatures', $this->db->get_table_name('signatures'));
        
        // Test invalid table name
        $this->assertEquals('', $this->db->get_table_name('invalid'));
    }

    /**
     * Test encrypt and decrypt methods.
     */
    public function test_encrypt_decrypt() {
        $original = 'test-api-key';

        // Encrypt
        $encrypted = $this->db->encrypt($original);

        // Verify encrypted is different from original
        $this->assertNotEquals($original, $encrypted);

        // Verify the stored value is ciphertext, not reversible encoding
        $this->assertNotEquals($original, base64_decode($encrypted, true));
        $this->assertStringNotContainsString($original, (string) base64_decode($encrypted, true));

        // Decrypt
        $decrypted = $this->db->decrypt($encrypted);

        // Verify decrypted matches original
        $this->assertEquals($original, $decrypted);
    }

    /**
     * Test that encryption uses a fresh nonce per call.
     */
    public function test_encrypt_is_nondeterministic() {
        $original = 'test-api-key';

        $this->assertNotEquals($this->db->encrypt($original), $this->db->encrypt($original));
    }

    /**
     * Test that tampered ciphertext is rejected rather than silently decoded.
     */
    public function test_decrypt_rejects_tampered_ciphertext() {
        $encrypted = $this->db->encrypt('test-api-key');
        $raw = base64_decode($encrypted, true);

        // Flip a bit in the last ciphertext byte.
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);

        $this->assertNull($this->db->decrypt(base64_encode($raw)));
    }

    /**
     * Test that legacy base64 values from the previous release do not decrypt.
     */
    public function test_decrypt_rejects_legacy_base64_values() {
        $this->assertNull($this->db->decrypt(base64_encode('test-api-key')));
    }

    /**
     * Test server CRUD operations.
     */
    public function test_server_crud() {
        // Test insert_server
        $server_id = $this->create_test_server(array(
            'name' => 'Test Server 1',
            'api_url' => 'https://test1.example.com',
            'api_key' => 'test-api-key-1',
            'is_default_server' => 1,
        ));
        
        $this->assertNotFalse($server_id);
        
        // Test get_server
        $server = $this->db->get_server($server_id);
        
        $this->assertNotNull($server);
        $this->assertEquals('Test Server 1', $server->name);
        $this->assertEquals('https://test1.example.com', $server->api_url);
        $this->assertEquals(1, $server->is_default_server);
        
        // Test update_server
        $result = $this->db->update_server($server_id, array(
            'name' => 'Updated Server 1',
            'api_url' => 'https://updated1.example.com',
        ));
        
        $this->assertTrue($result);
        
        // Verify update
        $updated_server = $this->db->get_server($server_id);
        
        $this->assertEquals('Updated Server 1', $updated_server->name);
        $this->assertEquals('https://updated1.example.com', $updated_server->api_url);
        
        // Test get_default_server
        $default_server = $this->db->get_default_server();
        
        $this->assertNotNull($default_server);
        $this->assertEquals($server_id, $default_server->server_id);
        
        // Test setting a new default server
        $server_id2 = $this->create_test_server(array(
            'name' => 'Test Server 2',
            'api_url' => 'https://test2.example.com',
            'api_key' => 'test-api-key-2',
            'is_default_server' => 1,
        ));
        
        // Verify new default
        $new_default = $this->db->get_default_server();
        
        $this->assertEquals($server_id2, $new_default->server_id);
        
        // Verify old default is no longer default
        $old_default = $this->db->get_server($server_id);
        
        $this->assertEquals(0, $old_default->is_default_server);
        
        // Test get_servers
        $servers = $this->db->get_servers();
        
        $this->assertCount(2, $servers);
        
        // Test delete_server
        $result = $this->db->delete_server($server_id);
        
        $this->assertTrue($result);
        
        // Verify deletion
        $deleted_server = $this->db->get_server($server_id);
        
        $this->assertNull($deleted_server);
        
        // Verify remaining servers
        $remaining_servers = $this->db->get_servers();
        
        $this->assertCount(1, $remaining_servers);
    }

    /**
     * Test author CRUD operations.
     */
    public function test_author_crud() {
        // Create a server for testing
        $server_id = $this->create_test_server();
        
        // Create a user for testing
        $user_id = $this->create_test_user();
        
        // Test insert_author
        $author_id = $this->create_test_author(array(
            'wp_user_id' => $user_id,
            'server_id' => $server_id,
            'signing_author_id' => 'test-author-1',
            'author_api_key' => 'test-author-api-key-1',
            'default_key_type' => 'HUMAN',
            'default_claims' => array('ContentType' => 'Article'),
            'is_site_endorser' => 1,
        ));
        
        $this->assertNotFalse($author_id);
        
        // Test get_author
        $author = $this->db->get_author($author_id);
        
        $this->assertNotNull($author);
        $this->assertEquals($user_id, $author->wp_user_id);
        $this->assertEquals($server_id, $author->server_id);
        $this->assertEquals('test-author-1', $author->signing_author_id);
        $this->assertEquals('HUMAN', $author->default_key_type);
        $this->assertEquals(1, $author->is_site_endorser);
        
        // Verify claims JSON
        $claims = json_decode($author->default_claims_json, true);
        $this->assertEquals('Article', $claims['ContentType']);
        
        // Test update_author
        $result = $this->db->update_author($author_id, array(
            'default_key_type' => 'AI_ASSISTED_HUMAN',
            'default_claims' => array('ContentType' => 'Video'),
        ));
        
        $this->assertTrue($result);
        
        // Verify update
        $updated_author = $this->db->get_author($author_id);
        
        $this->assertEquals('AI_ASSISTED_HUMAN', $updated_author->default_key_type);
        
        $updated_claims = json_decode($updated_author->default_claims_json, true);
        $this->assertEquals('Video', $updated_claims['ContentType']);
        
        // Test get_author_by_wp_user_id
        $author_by_user = $this->db->get_author_by_wp_user_id($user_id);
        
        $this->assertNotNull($author_by_user);
        $this->assertEquals($author_id, $author_by_user->author_profile_id);
        
        // Test get_authors
        $authors = $this->db->get_authors();
        
        $this->assertCount(1, $authors);
        
        // Test get_authors with filters
        $endorsers = $this->db->get_authors(array('is_site_endorser' => 1));
        
        $this->assertCount(1, $endorsers);
        
        $server_authors = $this->db->get_authors(array('server_id' => $server_id));
        
        $this->assertCount(1, $server_authors);
        
        // Test get_site_endorsers
        $site_endorsers = $this->db->get_site_endorsers();
        
        $this->assertCount(1, $site_endorsers);
        
        // Test delete_author
        $result = $this->db->delete_author($author_id);
        
        $this->assertTrue($result);
        
        // Verify deletion
        $deleted_author = $this->db->get_author($author_id);
        
        $this->assertNull($deleted_author);
    }

    /**
     * Test signature CRUD operations.
     */
    public function test_signature_crud() {
        // Create a post for testing
        $post_id = $this->create_test_post();
        
        // Create a server for testing
        $server_id = $this->create_test_server();
        
        // Test insert_signature
        $signature_id = $this->create_test_signature(array(
            'post_id' => $post_id,
            'server_id' => $server_id,
            'signing_author_id' => 'test-author-1',
            'content_hash' => 'sha256:test-hash-1',
            'domain' => 'example.com',
            'signature' => 'test-signature-1',
            'claims' => array('ContentType' => 'Article'),
            'status' => 'signed',
        ));
        
        $this->assertNotFalse($signature_id);
        
        // Test get_signature
        $signature = $this->db->get_signature($signature_id);
        
        $this->assertNotNull($signature);
        $this->assertEquals($post_id, $signature->post_id);
        $this->assertEquals($server_id, $signature->server_id);
        $this->assertEquals('test-author-1', $signature->signing_author_id);
        $this->assertEquals('sha256:test-hash-1', $signature->content_hash);
        $this->assertEquals('example.com', $signature->domain);
        $this->assertEquals('test-signature-1', $signature->signature);
        $this->assertEquals('signed', $signature->status);
        
        // Verify claims JSON
        $claims = json_decode($signature->claims_json, true);
        $this->assertEquals('Article', $claims['ContentType']);
        
        // Test update_signature
        $result = $this->db->update_signature($signature_id, array(
            'status' => 'verified',
            'claims' => array('ContentType' => 'Article', 'AuthorType' => 'HUMAN'),
        ));
        
        $this->assertTrue($result);
        
        // Verify update
        $updated_signature = $this->db->get_signature($signature_id);
        
        $this->assertEquals('verified', $updated_signature->status);
        
        $updated_claims = json_decode($updated_signature->claims_json, true);
        $this->assertEquals('Article', $updated_claims['ContentType']);
        $this->assertEquals('HUMAN', $updated_claims['AuthorType']);
        
        // Test get_signatures_by_post_id
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        
        $this->assertCount(1, $signatures);
        $this->assertEquals($signature_id, $signatures[0]->signature_id);
        
        // Create a pending signature
        $pending_id = $this->create_test_signature(array(
            'post_id' => $post_id,
            'server_id' => $server_id,
            'signing_author_id' => 'test-author-2',
            'content_hash' => 'sha256:test-hash-2',
            'domain' => 'example.com',
            'status' => 'pending',
        ));
        
        // Test get_pending_signatures
        $pending = $this->db->get_pending_signatures();
        
        $this->assertCount(1, $pending);
        $this->assertEquals($pending_id, $pending[0]->signature_id);
        
        // Test delete_signature
        $result = $this->db->delete_signature($signature_id);
        
        $this->assertTrue($result);
        
        // Verify deletion
        $deleted_signature = $this->db->get_signature($signature_id);
        
        $this->assertNull($deleted_signature);
        
        // Verify remaining signatures
        $remaining = $this->db->get_signatures_by_post_id($post_id);
        
        $this->assertCount(1, $remaining);
    }

    public function test_local_public_key_lookup_ignores_pending_rows() {
        $keyid = 'https://example.org/key/pending';
        $signature_id = $this->create_test_signature(array(
            'server_id' => 0,
            'keyid' => $keyid,
            'public_key' => 'pending-public-key',
            'signing_mode' => 'local-browser',
            'status' => 'awaiting-local-signature',
        ));

        $this->assertNotFalse($signature_id);
        $this->assertNull($this->db->get_local_public_key_by_keyid($keyid));
    }
}
