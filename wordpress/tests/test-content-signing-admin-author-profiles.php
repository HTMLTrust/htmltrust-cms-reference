<?php
/**
 * Tests for browser-local author profile administration.
 *
 * @package Content_Signing
 */

class Test_Content_Signing_Admin_Author_Profiles extends ContentSigning_API_Client_TestCase {

    public function test_add_form_exposes_local_mode_without_remote_requirements() {
        $admin = new ContentSigning_Admin_AuthorProfiles($this->db, $this->api_client);
        $method = new ReflectionMethod($admin, 'render_author_form');

        ob_start();
        $method->invoke($admin, null);
        $html = ob_get_clean();

        $this->assertStringContainsString('Browser-local only', $html);
        $this->assertStringContainsString('local-wp-user-{ID}', $html);
        $this->assertDoesNotMatchRegularExpression('/name="signing_author_id"[^>]*required/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="author_api_key"[^>]*required/', $html);
    }

    public function test_local_profile_has_stable_identity_and_is_not_an_endorser() {
        $user_id = $this->create_test_user();
        $admin = new ContentSigning_Admin_AuthorProfiles($this->db, $this->api_client);
        $method = new ReflectionMethod($admin, 'local_signing_author_id');

        $this->assertSame('local-wp-user-' . $user_id, $method->invoke($admin, $user_id));

        $profile_id = $this->db->insert_author(array(
            'wp_user_id' => $user_id,
            'signing_author_id' => 'local-wp-user-' . $user_id,
            'server_id' => 0,
            'author_api_key' => '',
            'is_site_endorser' => 0,
        ));
        $this->assertNotFalse($profile_id);
        $this->assertCount(0, $this->db->get_site_endorsers());

        $profile = $this->db->get_author($profile_id);
        $list_method = new ReflectionMethod($admin, 'render_authors_list');
        ob_start();
        $list_method->invoke($admin, array($profile));
        $html = ob_get_clean();
        $this->assertStringContainsString('Browser-local only', $html);
    }

    public function test_edit_form_disables_local_endorser_control() {
        $user_id = $this->create_test_user();
        $profile_id = $this->db->insert_author(array(
            'wp_user_id' => $user_id,
            'signing_author_id' => 'local-wp-user-' . $user_id,
            'server_id' => 0,
            'is_site_endorser' => 0,
        ));
        $admin = new ContentSigning_Admin_AuthorProfiles($this->db, $this->api_client);
        $method = new ReflectionMethod($admin, 'render_author_form');
        ob_start();
        $method->invoke($admin, $this->db->get_author($profile_id));
        $html = ob_get_clean();

        $this->assertStringContainsString('Browser-local only', $html);
        $this->assertMatchesRegularExpression('/name="is_site_endorser"[^>]*disabled/', $html);
    }

    public function test_local_endorser_submission_is_rejected() {
        $user_id = $this->create_test_user();
        wp_set_current_user(1);

        $admin = new ContentSigning_Admin_AuthorProfiles($this->db, $this->api_client);
        $_POST = array(
            'wp_user_id' => $user_id,
            'server_id' => 0,
            'signing_author_id' => '',
            'author_api_key' => '',
            'is_site_endorser' => 1,
        );

        $method = new ReflectionMethod($admin, 'handle_add_author');
        $method->invoke($admin);

        $this->assertNull($this->db->get_author_by_wp_user_id($user_id));
        $_POST = array();
    }

    public function test_post_meta_box_limits_sign_now_to_local_profiles() {
        $local_user = $this->create_test_user();
        $this->db->insert_author(array(
            'wp_user_id' => $local_user,
            'signing_author_id' => 'local-wp-user-' . $local_user,
            'server_id' => 0,
        ));
        $local_post = $this->create_test_post(array('post_author' => $local_user, 'post_status' => 'publish'));
        wp_set_current_user($local_user);
        $meta_box = new ContentSigning_Admin_PostMetaBox($this->db, $this->api_client);
        ob_start();
        $meta_box->render_meta_box(get_post($local_post));
        $local_html = ob_get_clean();
        $this->assertStringContainsString('Sign Now', $local_html);

        $remote_server = $this->create_test_server();
        $remote_user = $this->create_test_user();
        $this->db->insert_author(array(
            'wp_user_id' => $remote_user,
            'signing_author_id' => 'remote-author-' . $remote_user,
            'server_id' => $remote_server,
            'author_api_key' => 'mock-author-api-key',
        ));
        $remote_post = $this->create_test_post(array('post_author' => $remote_user, 'post_status' => 'publish'));
        wp_set_current_user($remote_user);
        ob_start();
        $meta_box->render_meta_box(get_post($remote_post));
        $remote_html = ob_get_clean();
        $this->assertStringNotContainsString('class="button sign-post"', $remote_html);
        $this->assertStringContainsString('remote author profile', strtolower($remote_html));
    }
}
