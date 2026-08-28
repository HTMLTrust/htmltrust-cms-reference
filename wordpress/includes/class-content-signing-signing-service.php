<?php
/**
 * Content Signing Service.
 *
 * This class orchestrates the content signing process.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/includes
 */

use HTMLTrust\Canonicalization\Canonicalize;
use HTMLTrust\Canonicalization\Signature;

class ContentSigning_Signing_Service {

    /** @var array<string, bool> Signing or scheduling work completed in this request. */
    private $processed_actions = array();

    /**
     * The database handler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_DB    $db    The database handler.
     */
    private $db;

    /**
     * The API client.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_API_Client    $api_client    The API client.
     */
    private $api_client;

    /**
     * The scheduler.
     *
     * @since    1.0.0
     * @access   private
     * @var      ContentSigning_Scheduler    $scheduler    The scheduler.
     */
    private $scheduler;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    ContentSigning_DB             $db          The database handler.
     * @param    ContentSigning_API_Client     $api_client  The API client.
     * @param    ContentSigning_Scheduler      $scheduler   The scheduler.
     */
    public function __construct($db, $api_client, $scheduler) {
        $this->db = $db;
        $this->api_client = $api_client;
        $this->scheduler = $scheduler;
    }

    /**
     * Process a post for signing.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @param    WP_Post   $post       The post object.
     * @param    bool      $update     Whether this is an existing post being updated.
     * @return   void
     */
    public function process_post($post_id, $post, $update) {
        // Skip auto-saves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Check if this post type is enabled for signing
        $post_types = get_option('content_signing_post_types', array('post'));
        if (!in_array($post->post_type, $post_types)) {
            return;
        }

        // Check if signing is enabled globally
        if (!get_option('content_signing_enable_signing', true)) {
            return;
        }

        // Check if signing is disabled for this specific post
        $disable_signing = get_post_meta($post_id, '_content_signing_disable', true);
        if ($disable_signing) {
            return;
        }

        // Determine when to sign based on post status and plugin settings
        $sign_on_publish = get_option('content_signing_sign_on_publish', true);
        $sign_on_update = get_option('content_signing_sign_on_update', false);
        $sign_days_before_publish = get_option('content_signing_sign_days_before_publish', 0);
        $sign_days_after_publish = get_option('content_signing_sign_days_after_publish', 0);

        $should_sign_now = false;
        $should_schedule = false;
        $schedule_time = 0;

        // Check if we should sign now
        if ($post->post_status === 'publish') {
            if ($sign_on_publish && !$update) {
                // New post being published
                $should_sign_now = true;
            } elseif ($sign_on_update && $update) {
                // Existing post being updated
                $should_sign_now = true;
            }
        }

        // Check if we should schedule signing
        if ($post->post_status === 'future') {
            // Scheduled post
            $publish_time = strtotime($post->post_date_gmt);

            if ($sign_days_before_publish > 0) {
                // Schedule signing X days before publish
                $schedule_time = $publish_time - ($sign_days_before_publish * DAY_IN_SECONDS);
                $should_schedule = true;
            } elseif ($sign_on_publish) {
                // Schedule signing at publish time
                $schedule_time = $publish_time;
                $should_schedule = true;
            }
        } elseif ($post->post_status === 'publish' && $sign_days_after_publish > 0) {
            // Schedule signing X days after publish
            $publish_time = strtotime($post->post_date_gmt);
            $schedule_time = $publish_time + ($sign_days_after_publish * DAY_IN_SECONDS);
            $should_schedule = true;
        }

        // Sign now if needed
        if ($should_sign_now) {
            $action_key = 'sign:' . $post_id;
            if (empty($this->processed_actions[$action_key])) {
                $this->processed_actions[$action_key] = true;
                // A save/publish hook has no browser context. Queue the
                // exact bytes for an author-side signer instead of sending
                // them to a trust server that owns the signing key.
                $this->queue_local_signature($post_id);
            }
        }

        // Schedule signing if needed
        if ($should_schedule && $schedule_time > time()) {
            $action_key = 'schedule:' . $post_id;
            if (empty($this->processed_actions[$action_key])) {
                $this->processed_actions[$action_key] = true;
                $this->scheduler->schedule_signing($post_id, $schedule_time);
            }
        }
    }

    /**
     * Prepare the authoritative payload for browser-local signing.
     *
     * PHP runs the complete WordPress filter chain before returning this
     * value. The browser signs the returned payload verbatim; it never signs
     * a preview or a client-reconstructed claims object.
     *
     * @param int    $post_id Post ID.
     * @param string $keyid   Key identifier that is bound into v1 payload.
     * @return array Result containing the payload, or an error result.
     */
    public function prepare_local_signing($post_id, $keyid) {
        $post = get_post($post_id);
        if (!$post) {
            return array('success' => false, 'message' => 'Post not found.');
        }

        if (!$this->current_user_is_post_author($post)) {
            return array('success' => false, 'message' => 'Only the post author may sign locally.');
        }

        $author_profile = $this->db->get_author_by_wp_user_id($post->post_author);
        if (!$author_profile) {
            return array('success' => false, 'message' => 'Author does not have a signing profile.');
        }

        if ((int) $author_profile->server_id !== 0) {
            return array('success' => false, 'message' => 'Remote author profiles must use the legacy remote workflow; browser-local signing requires server ID 0.');
        }

        // Completion leaves a short-lived consume option while it persists a
        // row. A bounded scan here reclaims options left by a crashed PHP
        // request after their five-minute prepare state has expired.
        $this->cleanup_orphaned_consume_locks();

        if (!$this->is_valid_keyid($keyid)) {
            return array('success' => false, 'message' => 'Invalid key ID.');
        }

        try {
            $content_data = $this->prepare_content_data($post);
            $signing_data = $this->local_signing_data($content_data, $post_id, $keyid);
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Local signing payload preparation failed: ' . $e->getMessage(),
            );
        }

        // Keep the exact server-rendered signing state short-lived and bound
        // to this post, author, key, and payload. The token carries no key
        // material and is consumed by a successful completion.
        $prepare_token = wp_generate_password(64, false, false);
        set_transient(
            $this->prepare_transient_key($prepare_token),
            array(
                'post_id' => (int) $post_id,
                'user_id' => (int) get_current_user_id(),
                'keyid' => $keyid,
                'payload' => $signing_data['payload'],
                'contentHash' => $signing_data['contentHash'],
                'claimsHash' => $signing_data['claimsHash'],
                'domain' => $signing_data['domain'],
                'signedAt' => $signing_data['signedAt'],
                'profile' => $signing_data['profile'],
                'algorithm' => $signing_data['algorithm'],
                'scope' => $signing_data['scope'],
                'location' => $signing_data['location'],
                'sourceURL' => $signing_data['sourceURL'],
            ),
            5 * MINUTE_IN_SECONDS
        );
        $signing_data['prepareToken'] = $prepare_token;

        return array(
            'success' => true,
            'data' => $signing_data,
        );
    }

    /**
     * Verify and persist a browser-generated Ed25519 signature.
     *
     * The content hash, claims hash, domain, and signed timestamp are
     * recomputed on the server. Client-provided values are accepted only when
     * they match that authoritative result, preventing a browser from signing
     * bytes for content different from the published post.
     *
     * @param int   $post_id Post ID.
     * @param array $submitted Browser submission.
     * @return array Result of persistence.
     */
    public function complete_local_signing($post_id, $submitted) {
        $post = get_post($post_id);
        if (!$post) {
            return array('success' => false, 'message' => 'Post not found.');
        }

        if (!$this->current_user_is_post_author($post)) {
            return array('success' => false, 'message' => 'Only the post author may sign locally.');
        }

        $author_profile = $this->db->get_author_by_wp_user_id($post->post_author);
        if (!$author_profile) {
            return array('success' => false, 'message' => 'Author does not have a signing profile.');
        }

        if ((int) $author_profile->server_id !== 0) {
            return array('success' => false, 'message' => 'Remote author profiles cannot enter the browser-local signing path.');
        }

        $keyid = isset($submitted['keyid']) ? trim((string) $submitted['keyid']) : '';
        $prepare_token = isset($submitted['prepareToken']) ? trim((string) $submitted['prepareToken']) : '';
        $public_key = isset($submitted['publicKey']) ? (string) $submitted['publicKey'] : '';
        $signature = isset($submitted['signature']) ? (string) $submitted['signature'] : '';
        $signed_at = isset($submitted['signedAt']) ? trim((string) $submitted['signedAt']) : '';

        if ($keyid === '' || strlen($keyid) > 255 || $public_key === '' || $signature === '' || $signed_at === '' || $prepare_token === '') {
            return array('success' => false, 'message' => 'A prepare token, key ID, public key, signature, and signed timestamp are required.');
        }

        if (!$this->is_valid_keyid($keyid)) {
            return array('success' => false, 'message' => 'Invalid key ID.');
        }

        $prepared = get_transient($this->prepare_transient_key($prepare_token));
        if (!is_array($prepared)
            || (int) $prepared['post_id'] !== (int) $post_id
            || (int) $prepared['user_id'] !== (int) get_current_user_id()
            || !hash_equals((string) $prepared['keyid'], $keyid)) {
            return array('success' => false, 'message' => 'The signing preparation is absent, expired, or bound to a different post or key. Prepare a new signature.');
        }

        if (!$this->is_rfc3339_utc($signed_at) || !hash_equals((string) $prepared['signedAt'], $signed_at)) {
            return array('success' => false, 'message' => 'signedAt must be an RFC3339 UTC timestamp.');
        }

        try {
            $content_data = $this->prepare_content_data($post, $prepared['signedAt']);
            $expected = $this->local_signing_data($content_data, $post_id, $keyid);
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Local signing payload preparation failed: ' . $e->getMessage(),
            );
        }

        foreach (array('payload', 'contentHash', 'claimsHash', 'domain', 'signedAt', 'profile', 'algorithm', 'scope', 'location', 'sourceURL') as $prepared_field) {
            if (!isset($prepared[$prepared_field]) || !hash_equals((string) $prepared[$prepared_field], (string) $expected[$prepared_field])) {
                return array('success' => false, 'message' => 'The signing preparation no longer matches the post. Prepare a new signature.');
            }
        }

        foreach (array('contentHash', 'claimsHash', 'domain', 'signedAt', 'payload', 'profile', 'algorithm', 'scope', 'location', 'sourceURL') as $field) {
            if (!array_key_exists($field, $submitted)) {
                return array('success' => false, 'message' => 'The complete v1 signing payload is required. Prepare a new signature.');
            }
            if (isset($submitted[$field]) && (string) $submitted[$field] !== (string) $expected[$field]) {
                return array('success' => false, 'message' => 'The post changed while it was being signed. Prepare a new signature.');
            }
        }

        $public_key_bytes = $this->decode_base64url($public_key);
        $signature_bytes = $this->decode_base64url($signature);
        if (false === $public_key_bytes || false === $signature_bytes || strlen($public_key_bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature_bytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return array('success' => false, 'message' => 'The public key or signature is malformed.');
        }

        $public_key_spki = $this->ed25519_raw_to_spki_base64($public_key_bytes);
        if (false === $public_key_spki) {
            return array('success' => false, 'message' => 'The public key is malformed.');
        }

        if (!function_exists('sodium_crypto_sign_verify_detached') || !sodium_crypto_sign_verify_detached($signature_bytes, $expected['payload'], $public_key_bytes)) {
            return array('success' => false, 'message' => 'The submitted signature did not verify.');
        }

        // Only a verified proof of possession may establish the immutable
        // key ID binding. A malformed first submission must not reserve the
        // identifier for a public key that did not sign this payload.
        if (!$this->local_keyid_matches_public_key($keyid, $public_key_spki)) {
            return array('success' => false, 'message' => 'This key ID is already bound to a different public key. Rotate with a new key ID.');
        }

        // add_option is a single-row insert and therefore gives us a
        // persistent compare-and-set guard across concurrent PHP requests.
        // Re-read the transient after acquiring it so a second request that
        // observed the same state cannot proceed after the first consumes it.
        $consume_lock = $this->prepare_consume_lock_key($prepare_token);
        $existing_lock = get_option($consume_lock, false);
        if ($existing_lock && strtotime((string) $existing_lock) < (time() - (10 * MINUTE_IN_SECONDS))) {
            // A process can die after persistence and before cleanup. The
            // prepare transient expires after five minutes, so a ten-minute
            // lock is safe to reclaim and cannot authorize a late retry.
            delete_option($consume_lock);
        }
        if (!add_option($consume_lock, gmdate('c'), '', 'no')) {
            return array('success' => false, 'message' => 'This signing preparation has already been consumed. Prepare a new signature.');
        }
        if (false === get_transient($this->prepare_transient_key($prepare_token))) {
            delete_option($consume_lock);
            return array('success' => false, 'message' => 'The signing preparation is absent or expired. Prepare a new signature.');
        }

        $signature_data = array(
            'post_id' => $post_id,
            'server_id' => 0,
            'signing_author_id' => $author_profile->signing_author_id,
            'wp_user_id' => get_current_user_id() ? get_current_user_id() : $post->post_author,
            'content_hash' => $content_data['contentHash'],
            'domain' => $content_data['domain'],
            'signature' => $signature,
            'keyid' => $keyid,
            // Store resolver-compatible SPKI DER, encoded with canonical
            // unpadded standard Base64. The browser may submit raw bytes.
            'public_key' => $public_key_spki,
            'signing_mode' => 'local-browser',
            'claims_json' => wp_json_encode($content_data['claims']),
            'status' => 'signed',
            'signed_at' => $content_data['signedAtMysql'],
            'api_response' => array(
                'algorithm' => 'ed25519',
                'keyid' => $keyid,
                'payload' => $expected['payload'],
                'profile' => $expected['profile'],
                'scope' => $expected['scope'],
                'location' => $expected['location'],
                'sourceURL' => $expected['sourceURL'],
                'mode' => 'local-browser',
            ),
        );

        if (!apply_filters('content_signing_local_signature_persistence', true, $post_id, $signature_data)) {
            delete_option($consume_lock);
            return array('success' => false, 'message' => 'Local signature persistence was unavailable. Retry with the same preparation.');
        }

        $pending = $this->db->get_pending_local_signature($post_id);
        if ($pending) {
            $signature_id = (int) $pending->signature_id;
            $stored = $this->db->update_signature($signature_id, $signature_data);
            if (!$stored) {
                $signature_id = false;
            }
        } else {
            $signature_id = $this->db->insert_signature($signature_data);
        }

        if (!$signature_id) {
            // Keep the prepare state available for a retry when persistence
            // fails, while releasing the compare-and-set guard.
            delete_option($consume_lock);
            return array('success' => false, 'message' => 'Failed to store the local signature.');
        }

        delete_transient($this->prepare_transient_key($prepare_token));
        // Keep the persistent replay guard until bounded cleanup removes it.
        // If a transient backend fails to delete the prepare state, releasing
        // this option here would make the successfully used token valid again.

        return array(
            'success' => true,
            'message' => 'Content signed locally and verified before persistence.',
            'data' => array_merge($expected, array('signatureId' => $signature_id, 'keyid' => $keyid, 'algorithm' => 'ed25519')),
        );
    }

    /**
     * Queue a publication for author-side signing.
     *
     * Headless and scheduled publication cannot access a browser key. They
     * remain unsigned and receive a durable queue record for a later editor
     * session rather than falling back to remote signing.
     *
     * @param int $post_id Post ID.
     * @return int|false Signature ID or false.
     */
    public function queue_local_signature($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $author_profile = $this->db->get_author_by_wp_user_id($post->post_author);
        if (!$author_profile) {
            return false;
        }

        if ((int) $author_profile->server_id !== 0) {
            return false;
        }

        try {
            $content_data = $this->prepare_content_data($post);
        } catch (Exception $e) {
            return false;
        }

        $pending = $this->db->get_pending_local_signature($post_id);
        if ($pending) {
            $this->db->update_signature($pending->signature_id, array(
                'signing_author_id' => $author_profile->signing_author_id,
                'wp_user_id' => $post->post_author,
                'content_hash' => $content_data['contentHash'],
                'domain' => $content_data['domain'],
                'claims_json' => wp_json_encode($content_data['claims']),
                'api_response' => array('mode' => 'local-browser', 'reason' => 'browser-required', 'refreshed_at' => current_time('mysql', true)),
            ));
            return (int) $pending->signature_id;
        }

        return $this->db->insert_signature(array(
            'post_id' => $post_id,
            'server_id' => 0,
            'signing_author_id' => $author_profile->signing_author_id,
            'wp_user_id' => $post->post_author,
            'content_hash' => $content_data['contentHash'],
            'domain' => $content_data['domain'],
            'claims_json' => wp_json_encode($content_data['claims']),
            'status' => 'awaiting-local-signature',
            'signing_mode' => 'local-browser',
            'api_response' => array('mode' => 'local-browser', 'reason' => 'browser-required'),
        ));
    }

    /**
     * Build the exact frozen v1 RFC 8785 payload signed by this plugin.
     *
     * @param array $content_data Prepared content data.
     * @param int   $post_id Post ID.
     * @return array Payload fields.
     */
    private function local_signing_data($content_data, $post_id, $keyid) {
        $scope = 'url';
        $algorithm = 'ed25519';
        $payload = Signature::buildSigningPayloadV1(array(
            'contentHash' => $content_data['contentHash'],
            'claimsHash' => $content_data['claimsHash'],
            'documentURL' => $content_data['sourceURL'],
            'scope' => $scope,
            'keyid' => $keyid,
            'algorithm' => $algorithm,
            'signedAt' => $content_data['signedAt'],
        ));

        return array(
            'postId' => (int) $post_id,
            'contentHash' => $content_data['contentHash'],
            'claimsHash' => $content_data['claimsHash'],
            'domain' => $content_data['domain'],
            'claims' => $content_data['claims'],
            'signedAt' => $content_data['signedAt'],
            'sourceURL' => $content_data['sourceURL'],
            'profile' => Signature::SIGNING_PROFILE_V1,
            'algorithm' => $algorithm,
            'scope' => $scope,
            'location' => Signature::deriveSigningLocationV1($content_data['sourceURL'], $scope),
            'keyid' => $keyid,
            'payload' => $payload,
        );
    }

    /**
     * Validate a key identifier before it is included in a v1 signing object.
     *
     * @param string $keyid Key identifier.
     * @return bool Whether the identifier is non-empty and path-safe.
     */
    private function is_valid_keyid($keyid) {
        return strlen($keyid) <= 255 && preg_match('~^[A-Za-z0-9._:/#?=-]{1,255}$~', $keyid) === 1;
    }

    /**
     * Build the transient key for a one-time signing preparation.
     *
     * @param string $token Preparation token.
     * @return string Transient key.
     */
    private function prepare_transient_key($token) {
        return 'content_signing_prepare_' . hash('sha256', (string) $token);
    }

    /**
     * Build the persistent compare-and-set key for token consumption.
     *
     * @param string $token Preparation token.
     * @return string Option key.
     */
    private function prepare_consume_lock_key($token) {
        return 'content_signing_consumed_' . hash('sha256', (string) $token);
    }

    /**
     * Build the persistent key binding option for a key identifier.
     *
     * @param string $keyid Key identifier.
     * @return string Option name.
     */
    private function local_key_binding_option_key($keyid) {
        return 'content_signing_key_binding_' . hash('sha256', (string) $keyid);
    }

    /**
     * Atomically bind a local key identifier to its first SPKI public key.
     *
     * add_option performs a single-row insert, so two first-use requests with
     * different keys have one winner. The winner remains authoritative across
     * all later signatures and is seeded from the earliest historical signed
     * row when upgrading an installation that predates this binding option.
     *
     * @param string $keyid Key identifier.
     * @param string $public_key_spki Canonical SPKI public key.
     * @return bool Whether the submitted key matches the immutable binding.
     */
    private function local_keyid_matches_public_key($keyid, $public_key_spki) {
        $option_name = $this->local_key_binding_option_key($keyid);
        $bound_key = get_option($option_name, null);

        if ($bound_key === null) {
            $legacy_key = $this->db->get_local_public_key_by_keyid($keyid);
            $initial_key = $legacy_key ? (string) $legacy_key : (string) $public_key_spki;
            add_option($option_name, $initial_key, '', 'no');
            // If another request won the insert, this read observes its key.
            $bound_key = get_option($option_name, null);
        }

        return is_string($bound_key)
            && hash_equals((string) $bound_key, (string) $public_key_spki);
    }

    /**
     * Reclaim a bounded number of consume locks left by crashed requests.
     *
     * The prepare transient expires after five minutes. Locks older than ten
     * minutes cannot authorize a retry and are safe to remove. The bounded
     * query keeps normal prepare requests from scanning an unbounded options
     * table.
     *
     * @return void
     */
    private function cleanup_orphaned_consume_locks() {
        global $wpdb;

        if (!isset($wpdb->options) || !method_exists($wpdb, 'esc_like')) {
            return;
        }

        $prefix = 'content_signing_consumed_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 100",
            $wpdb->esc_like($prefix) . '%'
        ));

        if (!is_array($rows)) {
            return;
        }

        $cutoff = time() - (10 * MINUTE_IN_SECONDS);
        foreach ($rows as $row) {
            $created_at = strtotime((string) $row->option_value);
            if ($created_at && $created_at < $cutoff) {
                delete_option($row->option_name);
            }
        }
    }

    /**
     * Require the WordPress post author for browser-held signing keys.
     *
     * @param WP_Post $post Post being signed.
     * @return bool Whether the current session is the post author.
     */
    private function current_user_is_post_author($post) {
        $current_user_id = get_current_user_id();
        return $current_user_id > 0 && (int) $current_user_id === (int) $post->post_author;
    }

    /**
     * Validate the timestamp grammar used by the protocol.
     *
     * @param string $value Timestamp.
     * @return bool Whether valid UTC RFC3339.
     */
    private function is_rfc3339_utc($value) {
        $date = DateTime::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d\\TH:i:s\\Z') === $value;
    }

    /**
     * Decode unpadded base64url input strictly.
     *
     * @param string $value Base64url value.
     * @return string|false Decoded bytes.
     */
    private function decode_base64url($value) {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return false;
        }

        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    /**
     * Wrap a raw Ed25519 public key as canonical SPKI DER Base64.
     *
     * @param string $raw_key Raw 32-byte Ed25519 public key.
     * @return string|false Unpadded standard Base64 or false when malformed.
     */
    private function ed25519_raw_to_spki_base64($raw_key) {
        if (strlen($raw_key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        // SubjectPublicKeyInfo for id-Ed25519 with a 32-byte BIT STRING.
        $prefix = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";
        return rtrim(base64_encode($prefix . $raw_key), '=');
    }

    /**
     * Decode the resolver's canonical SPKI DER Ed25519 public key.
     *
     * @param string $value Unpadded standard Base64 SPKI DER.
     * @return string|false Raw 32-byte Ed25519 public key or false.
     */
    private function decode_ed25519_spki_base64($value) {
        if ($value === '' || preg_match('/^[A-Za-z0-9+\/]+$/', $value) !== 1 || strlen($value) % 4 === 1) {
            return false;
        }

        $encoded = $value;

        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $der = base64_decode($value, true);
        $prefix = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";
        if ($der === false
            || rtrim(base64_encode($der), '=') !== $encoded
            || strlen($der) !== strlen($prefix) + SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || substr($der, 0, strlen($prefix)) !== $prefix) {
            return false;
        }

        return substr($der, strlen($prefix));
    }

    /**
     * Sign a post.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @return   array                 The result of the signing operation.
     */
    public function sign_post($post_id) {
        return array(
            'success' => false,
            'message' => 'Server-side content signing is disabled. Use browser-local signing.',
            'code' => 'local_signing_required',
        );
    }

    /**
     * Prepare content data for signing.
     *
     * @since    1.0.0
     * @param    WP_Post   $post    The post object.
     * @return   array              The content data.
     */
    private function prepare_content_data($post, $signed_at = null) {
        $base_url = get_permalink($post);
        $signed_at = $signed_at ? (string) $signed_at : gmdate('Y-m-d\TH:i:s\Z');
        $author_name = $this->get_post_author_name($post);

        // Hash the rendered content, not the raw post_content. The
        // <signed-section> element emitted by ContentSigning_Display wraps the
        // output of the `the_content` filter chain (wpautop, shortcodes, ...),
        // so hashing the raw editor markup would produce a content hash that
        // no verifier can reproduce from the published page.
        $rendered_content = $this->get_rendered_content($post);

        // Normalize the content using the signed content extraction rules.
        $normalized_content = $this->normalize_content($rendered_content, $base_url);

        // Calculate the content hash
        $content_hash = $this->calculate_content_hash($normalized_content);

        // Determine the serialized Web origin for the legacy-named domain field.
        $domain = $this->serialize_origin(get_site_url());

        // Get default claims from post author's profile
        $author_profile = $this->db->get_author_by_wp_user_id($post->post_author);
        $default_claims = array();
        if ($author_profile && !empty($author_profile->default_claims_json)) {
            $default_claims = json_decode($author_profile->default_claims_json, true);
        }

        // Get post-specific claims
        $post_claims = get_post_meta($post->ID, '_content_signing_claims', true);
        if (empty($post_claims)) {
            $post_claims = array();
        }

        // Merge claims, with post-specific claims taking precedence.
        $custom_claims = array_merge($default_claims, $post_claims);

        // Add standard claims if not already set
        if (!isset($custom_claims['ContentType'])) {
            $custom_claims['ContentType'] = 'Article';
        }

        if (!isset($custom_claims['AuthorType'])) {
            $custom_claims['AuthorType'] = $author_profile ? $author_profile->default_key_type : 'HUMAN';
        }

        $claims = $this->build_claims($author_name, $signed_at, $custom_claims);

        return array(
            'contentHash' => $content_hash,
            'claimsHash' => $this->calculate_claims_hash($claims),
            'domain' => $domain,
            'claims' => $claims,
            'signedAt' => $signed_at,
            'signedAtMysql' => gmdate('Y-m-d H:i:s', strtotime($signed_at)),
            'sourceURL' => $base_url,
        );
    }

    /**
     * Render post content through the same filters the front end applies.
     *
     * ContentSigning_Display wraps the value it receives from the
     * `the_content` filter, so the signer has to hash that same value. Our own
     * wrapper callback is detached for the duration of the call: it is
     * registered on `the_content` itself, so leaving it attached would recurse
     * and fold a <signed-section> element into the hashed bytes.
     *
     * Caveat: a filter registered later at the same maximum priority runs
     * after the wrapper and is outside the signed bytes. WordPress has no
     * ordering signal for that same-priority case, and block context can also
     * differ between this pass and the frontend request.
     *
     * @since    1.0.0
     * @param    WP_Post   $post    The post object.
     * @return   string             The rendered post content.
     */
    private function get_rendered_content($post) {
        $display = $this->get_display();
        $callback = $display ? array($display, 'display_signature') : null;

        // Read the registered priority back rather than assuming it: detaching
        // at the wrong priority is a silent no-op, and the recursion it would
        // leave in place is not obvious from the resulting hash.
        $priority = $callback ? has_filter('the_content', $callback) : false;

        if (false !== $priority) {
            remove_filter('the_content', $callback, $priority);
        }

        $content = apply_filters('the_content', $post->post_content);

        if (false !== $priority) {
            add_filter('the_content', $callback, $priority);
        }

        return $content;
    }

    /**
     * Get the plugin's display handler, if the plugin is fully booted.
     *
     * @since    1.0.0
     * @return   ContentSigning_Display|null    The display handler.
     */
    private function get_display() {
        if (!class_exists('ContentSigning_Plugin')) {
            return null;
        }

        $plugin = ContentSigning_Plugin::get_instance();
        if (!$plugin) {
            return null;
        }

        $public = $plugin->get_public();
        if (!$public || !method_exists($public, 'get_display')) {
            return null;
        }

        return $public->get_display();
    }

    /**
     * Normalize content for consistent hashing.
     *
     * Delegates to the shared htmltrust/canonicalization package. This plugin
     * deliberately keeps no canonicalizer of its own: the bytes hashed here
     * have to match, byte for byte, what the JavaScript, Go, Python and Rust
     * verifiers derive from the published page.
     *
     * @since    1.0.0
     * @param    string      $content    The content to normalize.
     * @param    string|null $base_url   The signed document URL, used to
     *                                   resolve relative href/src values.
     * @return   string                  The normalized content.
     * @throws   RuntimeException         If the canonicalization package is missing.
     * @throws   InvalidArgumentException If a signed attribute cannot be canonicalized.
     */
    private function normalize_content($content, $base_url = null) {
        $this->require_canonicalization_library();

        return Canonicalize::extractCanonicalText($content, false, $base_url === '' ? null : $base_url);
    }

    /**
     * Calculate a hash of the content.
     *
     * @since    1.0.0
     * @param    string    $content    The content to hash.
     * @return   string                The content hash.
     */
    private function calculate_content_hash($content) {
        // Use SHA-256 for hashing
        return $this->hash_canonical_bytes($content);
    }

    /**
     * Calculate the claims hash for a direct-child claim map.
     *
     * The claims hash is the second field of the signing payload binding
     * (draft §5) and is computed over the canonical claims byte string
     * defined in draft §4.6.
     *
     * @since    1.0.0
     * @param    array     $claims    Claims keyed by direct meta name.
     * @return   string               The claims hash.
     * @throws   RuntimeException         If the canonicalization package is missing.
     * @throws   InvalidArgumentException If a claim is malformed or duplicated.
     */
    private function calculate_claims_hash($claims) {
        $this->require_canonicalization_library();

        return $this->hash_canonical_bytes(Canonicalize::canonicalizeClaims($claims));
    }

    /**
     * Hash a canonical byte string into the spec's prefixed hash format.
     *
     * Base64 with the standard alphabet and padding removed, prefixed with the
     * hash algorithm identifier and a colon (draft §6.2).
     *
     * @since    1.0.0
     * @param    string    $bytes    The canonical bytes to hash.
     * @return   string              The prefixed hash.
     */
    private function hash_canonical_bytes($bytes) {
        return 'sha256:' . rtrim(base64_encode(hash('sha256', $bytes, true)), '=');
    }

    /**
     * Assert that the shared canonicalization package is loadable.
     *
     * The package is a composer dependency (htmltrust/canonicalization); a
     * missing autoloader is a deployment error, not a content error, so it is
     * reported distinctly rather than surfacing as a class-not-found fatal.
     *
     * @since    1.0.0
     * @return   void
     * @throws   RuntimeException    If the package is not installed.
     */
    private function require_canonicalization_library() {
        if (!class_exists(Canonicalize::class)) {
            throw new RuntimeException(
                'The htmltrust/canonicalization package is not installed. Run "composer install" in the plugin directory.'
            );
        }
    }

    /**
     * Serialize a URL as a Web origin.
     *
     * @since    1.0.0
     * @param    string $url The URL to serialize.
     * @return   string      The serialized origin.
     */
    private function serialize_origin($url) {
        $parts = wp_parse_url($url);
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';

        if (function_exists('idn_to_ascii') && $host !== '') {
            $ascii_host = idn_to_ascii($host, 0, defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0);
            if ($ascii_host) {
                $host = strtolower($ascii_host);
            }
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port']) && !$this->is_default_port($scheme, intval($parts['port']))) {
            $origin .= ':' . intval($parts['port']);
        }

        return $origin;
    }

    /**
     * Check whether a port is the default for a scheme.
     *
     * @since    1.0.0
     * @param    string $scheme The URL scheme.
     * @param    int    $port   The port.
     * @return   bool           Whether the port is default.
     */
    private function is_default_port($scheme, $port) {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }

    /**
     * Build direct child meta claims.
     *
     * @since    1.0.0
     * @param    string $author_name   The author display name.
     * @param    string $signed_at     RFC3339 UTC signing timestamp.
     * @param    array  $custom_claims Custom claim values.
     * @return   array                 Direct meta claims keyed by meta name.
     */
    private function build_claims($author_name, $signed_at, $custom_claims) {
        $claims = array(
            'author' => $author_name,
            'signed-at' => $signed_at,
        );

        foreach ($custom_claims as $name => $value) {
            $claim_name = strpos($name, 'claim:') === 0 ? $name : 'claim:' . $name;
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $claims[$claim_name] = (string) $value;
        }

        return $claims;
    }

    /**
     * Get the post author's display name.
     *
     * @since    1.0.0
     * @param    WP_Post $post The post object.
     * @return   string        The author display name.
     */
    private function get_post_author_name($post) {
        $user = get_user_by('ID', $post->post_author);
        if ($user && !empty($user->display_name)) {
            return $user->display_name;
        }

        return (string) $post->post_author;
    }

    /**
     * Format a stored datetime as RFC3339 UTC.
     *
     * @since    1.0.0
     * @param    string $datetime The stored datetime.
     * @return   string           The RFC3339 UTC datetime.
     */
    private function format_signed_at($datetime) {
        $timestamp = strtotime($datetime);
        if (!$timestamp) {
            $timestamp = time();
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * Verify a post's signature.
     *
     * @since    1.0.0
     * @param    int       $post_id       The post ID.
     * @param    int       $signature_id  The signature ID.
     * @return   array                    The verification result.
     */
    public function verify_post_signature($post_id, $signature_id) {
        $post = get_post($post_id);
        if (!$post) {
            return array(
                'success' => false,
                'message' => 'Post not found.',
            );
        }

        $signature = $this->db->get_signature($signature_id);
        if (!$signature) {
            return array(
                'success' => false,
                'message' => 'Signature not found.',
            );
        }

        // The caller was authorized against $post_id, so the signature has to
        // belong to that post; otherwise the post ID is just decoration and any
        // signature row is reachable.
        if (intval($signature->post_id) !== intval($post_id)) {
            return array(
                'success' => false,
                'message' => 'Signature does not belong to this post.',
            );
        }

        if (isset($signature->signing_mode) && $signature->signing_mode === 'local-browser') {
            return $this->verify_local_signature($post, $signature);
        }

        // Get the server for this signature
        $server = $this->db->get_server($signature->server_id);
        if (!$server) {
            return array(
                'success' => false,
                'message' => 'Server not found for this signature.',
            );
        }

        // Create a new API client for this server
        $api_client = new ContentSigning_API_Client(
            $server->api_url,
            $this->db->decrypt($server->api_key_encrypted),
            $this->db
        );

        // Rebuild the signing payload binding. The verifier needs all four
        // bound fields (draft §5); sending only contentHash and domain makes
        // the request fail validation before any crypto runs.
        $claims = json_decode((string) $signature->claims_json, true);
        if (!is_array($claims) || empty($claims)) {
            return array(
                'success' => false,
                'message' => 'Stored claims are missing; cannot rebuild the signing payload.',
            );
        }

        try {
            $claims_hash = $this->calculate_claims_hash($claims);
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Claims canonicalization failed: ' . $e->getMessage(),
            );
        }

        // Prefer the signed-at claim: it is the exact string that was bound
        // into the signature. The signed_at column is a MySQL datetime and
        // only reconstructs the RFC3339 form.
        $signed_at = isset($claims['signed-at']) && $claims['signed-at'] !== ''
            ? (string) $claims['signed-at']
            : $this->format_signed_at($signature->signed_at ? $signature->signed_at : $signature->created_at);

        // Prepare verification data
        $verification_data = array(
            'contentHash' => $signature->content_hash,
            'claimsHash' => $claims_hash,
            'domain' => $signature->domain,
            'signedAt' => $signed_at,
            'authorId' => $signature->signing_author_id,
            'signature' => $signature->signature,
        );

        // Verify the signature
        $result = $api_client->verify_content($verification_data);

        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message(),
                'data' => $result->get_error_data(),
            );
        } else {
            return array(
                'success' => true,
                'message' => 'Signature verified successfully.',
                'data' => $result,
            );
        }
    }

    /**
     * Verify a locally-created signature without a trust-server request.
     *
     * @param WP_Post $post      Post object.
     * @param object  $signature Signature row.
     * @return array Verification result.
     */
    private function verify_local_signature($post, $signature) {
        if (empty($signature->public_key) || empty($signature->signature)) {
            return array('success' => false, 'message' => 'Local public key or signature is missing.');
        }

        $claims = json_decode((string) $signature->claims_json, true);
        if (!is_array($claims) || empty($claims['signed-at'])) {
            return array('success' => false, 'message' => 'Stored local claims are missing.');
        }

        try {
            $current = $this->prepare_content_data($post, (string) $claims['signed-at']);
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Content canonicalization failed: ' . $e->getMessage());
        }

        if ($current['contentHash'] !== $signature->content_hash || $current['domain'] !== $signature->domain || wp_json_encode($current['claims']) !== wp_json_encode($claims)) {
            return array('success' => true, 'message' => 'Signature is cryptographically valid, but the published content no longer matches.', 'data' => array('valid' => false, 'reason' => 'content-changed'));
        }

        $metadata = json_decode((string) $signature->api_response_json, true);
        $keyid = isset($signature->keyid) ? (string) $signature->keyid : '';
        try {
            $expected = $this->local_signing_data($current, (int) $post->ID, $keyid);
        } catch (Exception $e) {
            return array('success' => true, 'message' => 'Local signature payload cannot be rebuilt: ' . $e->getMessage(), 'data' => array('valid' => false));
        }
        if (!is_array($metadata)
            || !isset($metadata['profile'], $metadata['algorithm'], $metadata['scope'], $metadata['location'], $metadata['sourceURL'], $metadata['payload'])
            || $metadata['profile'] !== $expected['profile']
            || $metadata['algorithm'] !== $expected['algorithm']
            || $metadata['scope'] !== $expected['scope']
            || $metadata['location'] !== $expected['location']
            || $metadata['sourceURL'] !== $expected['sourceURL']
            || $metadata['payload'] !== $expected['payload']) {
            return array('success' => true, 'message' => 'Local signature metadata does not match the v1 signing profile.', 'data' => array('valid' => false));
        }

        // Rebuild the RFC 8785 payload from current content and stored v1
        // attributes. The persisted payload is diagnostic metadata only.
        $payload = $expected['payload'];
        $public_key = $this->decode_ed25519_spki_base64((string) $signature->public_key);
        $signature_bytes = $this->decode_base64url((string) $signature->signature);

        $valid = $public_key !== false && $signature_bytes !== false && strlen($public_key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && strlen($signature_bytes) === SODIUM_CRYPTO_SIGN_BYTES && function_exists('sodium_crypto_sign_verify_detached') && sodium_crypto_sign_verify_detached($signature_bytes, $payload, $public_key);

        return array(
            'success' => true,
            'message' => $valid ? 'Local signature verified successfully.' : 'Local signature verification failed.',
            'data' => array('valid' => $valid, 'keyid' => isset($signature->keyid) ? $signature->keyid : ''),
        );
    }
}
