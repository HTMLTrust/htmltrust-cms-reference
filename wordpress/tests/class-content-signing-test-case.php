<?php
/**
 * Base test case for Content Signing tests.
 *
 * @package Content_Signing
 */

/**
 * Base test case class.
 */
class ContentSigning_TestCase extends WP_UnitTestCase {

    /** @var ContentSigning_Plugin */
    protected $plugin;

    /**
     * Set up before each test.
     */
    public function setUp(): void {
        parent::setUp();
        
        // Get plugin instance
        $this->plugin = ContentSigning_Plugin::get_instance();
    }

    /**
     * Tear down after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
    }

    /**
     * Create a test post.
     *
     * @param array $args Post arguments.
     * @return int Post ID.
     */
    protected function create_test_post($args = array()) {
        $defaults = array(
            'post_title' => 'Test Post',
            'post_content' => 'This is test content for signing.',
            'post_status' => 'draft',
            'post_author' => 1,
            'post_type' => 'post',
        );

        $args = wp_parse_args($args, $defaults);
        
        return wp_insert_post($args);
    }

    /**
     * Create a test user.
     *
     * @param array $args User arguments.
     * @return int User ID.
     */
    protected function create_test_user($args = array()) {
        $defaults = array(
            'user_login' => 'testuser' . rand(1000, 9999),
            'user_pass' => 'password',
            'user_email' => 'testuser' . rand(1000, 9999) . '@example.com',
            'role' => 'author',
        );

        $args = wp_parse_args($args, $defaults);
        
        return wp_insert_user($args);
    }
}
