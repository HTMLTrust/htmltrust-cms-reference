<?php
/**
 * Tests for the browser-local signing boundary.
 *
 * @package Content_Signing
 */

/**
 * Browser asset security contract tests.
 */
class Test_Content_Signing_Browser_Assets extends WP_UnitTestCase {

    /**
     * The private key is imported as non-extractable and is not an AJAX field.
     */
    public function test_private_key_is_local_only() {
        $script = file_get_contents(dirname(__DIR__) . '/admin/js/content-signing-post-meta-box.js');

        $this->assertNotFalse($script);
        $this->assertStringContainsString("generateKey({name: 'Ed25519'}, false, ['sign', 'verify'])", $script);
        $this->assertStringContainsString("exportKey('raw', key)", $script);
        $this->assertStringNotContainsString("exportKey('pkcs8'", $script);
        $this->assertStringNotContainsString("importKey('pkcs8'", $script);
        $this->assertDoesNotMatchRegularExpression('/privateKey\s*:\s*stored/', $script);
        $this->assertStringContainsString('publicKey: publicKey', $script);
        $this->assertStringContainsString('signature: toBase64Url', $script);
    }
}
