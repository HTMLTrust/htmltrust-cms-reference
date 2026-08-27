<?php
/**
 * Tests for the ContentSigning_Scheduler class.
 *
 * @package Content_Signing
 */

/**
 * Scheduler test class.
 */
class Test_Content_Signing_Scheduler extends ContentSigning_API_Client_TestCase {

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
    }

    /**
     * Tear down after each test.
     */
    public function tearDown(): void {
        // Clear any scheduled events
        $this->clear_scheduled_hooks();
        
        parent::tearDown();
    }

    /**
     * Test register_hooks method.
     */
    public function test_register_hooks() {
        // Verify the hook is registered
        $this->assertEquals(
            10,
            has_action('content_signing_scheduled_signing', array($this->scheduler, 'process_scheduled_signing'))
        );
    }

    /**
     * Test schedule_signing method.
     */
    public function test_schedule_signing() {
        // Create a test post
        $post_id = $this->create_test_post();
        
        // Schedule signing
        $timestamp = time() + 3600; // 1 hour from now
        $result = $this->scheduler->schedule_signing($post_id, $timestamp);
        
        // Verify result
        $this->assertTrue($result);
        
        // Verify event is scheduled
        $scheduled = wp_next_scheduled('content_signing_scheduled_signing', array($post_id));
        $this->assertEquals($timestamp, $scheduled);
    }

    /**
     * Test schedule_signing method with existing schedule.
     */
    public function test_schedule_signing_with_existing_schedule() {
        // Create a test post
        $post_id = $this->create_test_post();
        
        // Schedule signing
        $timestamp1 = time() + 3600; // 1 hour from now
        $this->scheduler->schedule_signing($post_id, $timestamp1);
        
        // Schedule again with different timestamp
        $timestamp2 = time() + 7200; // 2 hours from now
        $result = $this->scheduler->schedule_signing($post_id, $timestamp2);
        
        // Verify result
        $this->assertTrue($result);
        
        // Verify event is scheduled with new timestamp
        $scheduled = wp_next_scheduled('content_signing_scheduled_signing', array($post_id));
        $this->assertEquals($timestamp2, $scheduled);
        $this->assertNotEquals($timestamp1, $scheduled);
    }

    /**
     * Test cancel_scheduled_signing method.
     */
    public function test_cancel_scheduled_signing() {
        // Create a test post
        $post_id = $this->create_test_post();
        
        // Schedule signing
        $timestamp = time() + 3600; // 1 hour from now
        $this->scheduler->schedule_signing($post_id, $timestamp);
        
        // Verify event is scheduled
        $scheduled = wp_next_scheduled('content_signing_scheduled_signing', array($post_id));
        $this->assertEquals($timestamp, $scheduled);
        
        // Cancel scheduling
        $result = $this->scheduler->cancel_scheduled_signing($post_id);
        
        // Verify result
        $this->assertTrue($result);
        
        // Verify event is no longer scheduled
        $scheduled = wp_next_scheduled('content_signing_scheduled_signing', array($post_id));
        $this->assertFalse($scheduled);
    }

    /**
     * Test process_scheduled_signing method.
     */
    public function test_process_scheduled_signing() {
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
        
        // Enable signing
        update_option('content_signing_enable_signing', true);
        
        // Process scheduled signing
        $this->scheduler->process_scheduled_signing($post_id);
        
        // Verify a signature was created
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertCount(1, $signatures);
        $this->assertEquals('signed', $signatures[0]->status);
    }

    /**
     * Test process_scheduled_signing method with signing disabled.
     */
    public function test_process_scheduled_signing_with_signing_disabled() {
        // Create a test post
        $post_id = $this->create_test_post();
        
        // Disable signing globally
        update_option('content_signing_enable_signing', false);
        
        // Process scheduled signing
        $this->scheduler->process_scheduled_signing($post_id);
        
        // Verify no signatures were created
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertEmpty($signatures);
        
        // Re-enable signing for other tests
        update_option('content_signing_enable_signing', true);
    }

    /**
     * Test process_scheduled_signing method with signing disabled for post.
     */
    public function test_process_scheduled_signing_with_signing_disabled_for_post() {
        // Create a test post
        $post_id = $this->create_test_post();
        
        // Disable signing for this post
        update_post_meta($post_id, '_content_signing_disable', true);
        
        // Process scheduled signing
        $this->scheduler->process_scheduled_signing($post_id);
        
        // Verify no signatures were created
        $signatures = $this->db->get_signatures_by_post_id($post_id);
        $this->assertEmpty($signatures);
    }

    /**
     * Test get_next_scheduled_signing method.
     */
    public function test_get_next_scheduled_signing() {
        // Create a test post
        $post_id = $this->create_test_post();
        
        // Initially, no scheduled signing
        $scheduled = $this->scheduler->get_next_scheduled_signing($post_id);
        $this->assertFalse($scheduled);
        
        // Schedule signing
        $timestamp = time() + 3600; // 1 hour from now
        $this->scheduler->schedule_signing($post_id, $timestamp);
        
        // Verify scheduled time
        $scheduled = $this->scheduler->get_next_scheduled_signing($post_id);
        $this->assertEquals($timestamp, $scheduled);
    }

    /**
     * Test has_scheduled_signing method.
     */
    public function test_has_scheduled_signing() {
        // Create a test post
        $post_id = $this->create_test_post();
        
        // Initially, no scheduled signing
        $has_scheduled = $this->scheduler->has_scheduled_signing($post_id);
        $this->assertFalse($has_scheduled);
        
        // Schedule signing
        $timestamp = time() + 3600; // 1 hour from now
        $this->scheduler->schedule_signing($post_id, $timestamp);
        
        // Verify has scheduled
        $has_scheduled = $this->scheduler->has_scheduled_signing($post_id);
        $this->assertTrue($has_scheduled);
    }

    /**
     * Clear all scheduled hooks.
     */
    private function clear_scheduled_hooks() {
        // Get all posts
        $posts = get_posts(array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ));
        
        // Clear scheduled hooks for each post
        foreach ($posts as $post_id) {
            wp_clear_scheduled_hook('content_signing_scheduled_signing', array($post_id));
        }
    }
}
