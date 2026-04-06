/**
 * JavaScript for the post meta box functionality of the plugin.
 *
 * @package    Content_Signing
 * @subpackage Content_Signing/admin/js
 */

(function($) {
    'use strict';

    /**
     * Initialize the post meta box scripts.
     */
    function init() {
        // Initialize sign post button
        initSignPostButton();

        // Initialize verify signature button
        initVerifySignatureButton();
    }

    /**
     * Initialize the sign post button.
     */
    function initSignPostButton() {
        $('.content-signing-meta-box .sign-post').on('click', function(e) {
            e.preventDefault();

            const $button = $(this);
            const $spinner = $button.siblings('.spinner');
            const postId = $button.data('post-id');

            // Confirm before signing
            if (!confirm(content_signing_post_meta_box.sign_post_confirm)) {
                return;
            }

            // Disable button and show spinner
            $button.prop('disabled', true);
            $button.text(content_signing_post_meta_box.signing_text);
            $spinner.addClass('is-active');

            // Send AJAX request
            $.ajax({
                url: content_signing_post_meta_box.ajax_url,
                type: 'POST',
                data: {
                    action: 'content_signing_sign_post',
                    nonce: content_signing_post_meta_box.nonce,
                    post_id: postId
                },
                success: function(response) {
                    if (response.success) {
                        // Reload the page to show the updated signature
                        location.reload();
                    } else {
                        // Show error message
                        const errorMessage = response.data && response.data.message ? response.data.message : content_signing_post_meta_box.error_text;
                        alert(content_signing_post_meta_box.error_text + ' ' + errorMessage);
                        
                        // Reset button
                        $button.prop('disabled', false);
                        $button.text(content_signing_post_meta_box.sign_post_text);
                        $spinner.removeClass('is-active');
                    }
                },
                error: function() {
                    // Show error message
                    alert(content_signing_post_meta_box.error_text + ' ' + content_signing_post_meta_box.ajax_error);
                    
                    // Reset button
                    $button.prop('disabled', false);
                    $button.text(content_signing_post_meta_box.sign_post_text);
                    $spinner.removeClass('is-active');
                }
            });
        });
    }

    /**
     * Initialize the verify signature button.
     */
    function initVerifySignatureButton() {
        $('.content-signing-meta-box .verify-signature').on('click', function(e) {
            e.preventDefault();

            const $button = $(this);
            const $listItem = $button.closest('li');
            const signatureId = $button.data('signature-id');
            const postId = $button.data('post-id');

            // Remove any existing verification result
            $listItem.find('.verify-result').remove();

            // Disable button and show text
            $button.prop('disabled', true);
            $button.text(content_signing_post_meta_box.verifying_text);

            // Send AJAX request
            $.ajax({
                url: content_signing_post_meta_box.ajax_url,
                type: 'POST',
                data: {
                    action: 'content_signing_verify_signature',
                    nonce: content_signing_post_meta_box.nonce,
                    signature_id: signatureId,
                    post_id: postId
                },
                success: function(response) {
                    // Reset button
                    $button.prop('disabled', false);
                    $button.text(content_signing_post_meta_box.verify_text);

                    if (response.success) {
                        // Show success message
                        const resultClass = response.data.valid ? 'valid' : 'invalid';
                        const resultText = response.data.valid ? 
                            content_signing_post_meta_box.valid_text : 
                            content_signing_post_meta_box.invalid_text;
                        
                        const $result = $('<div class="verify-result ' + resultClass + '">' + resultText + '</div>');
                        $listItem.append($result);
                    } else {
                        // Show error message
                        const errorMessage = response.data && response.data.message ? response.data.message : content_signing_post_meta_box.error_text;
                        const $result = $('<div class="verify-result invalid">' + content_signing_post_meta_box.error_text + ' ' + errorMessage + '</div>');
                        $listItem.append($result);
                    }
                },
                error: function() {
                    // Reset button
                    $button.prop('disabled', false);
                    $button.text(content_signing_post_meta_box.verify_text);

                    // Show error message
                    const $result = $('<div class="verify-result invalid">' + content_signing_post_meta_box.error_text + ' ' + content_signing_post_meta_box.ajax_error + '</div>');
                    $listItem.append($result);
                }
            });
        });
    }

    // Initialize when the DOM is ready
    $(document).ready(init);

})(jQuery);