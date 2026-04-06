/**
 * Content Signing Public JavaScript
 *
 * This file contains the JavaScript for the public-facing aspects of the plugin.
 */

(function ($) {
  "use strict";

  /**
   * Initialize the Content Signing public functionality.
   */
  function initContentSigning() {
    // Set up verification button click handlers
    $(".content-signing-verify-button").on("click", handleVerifyClick);
  }

  /**
   * Handle the verification button click.
   *
   * @param {Event} e - The click event.
   */
  function handleVerifyClick(e) {
    e.preventDefault();

    const $button = $(this);
    const $resultContainer = $button.siblings(
      ".content-signing-verification-result",
    );
    const postId = $button.data("post-id");
    const signatureId = $button.data("signature-id");

    // Show loading state
    $resultContainer
      .removeClass(
        "content-signing-verification-success content-signing-verification-error",
      )
      .addClass("content-signing-verification-loading")
      .text(content_signing_public.i18n.loading)
      .show();

    // Disable the button during verification
    $button.prop("disabled", true);

    // Send AJAX request to verify the signature
    $.ajax({
      url: content_signing_public.ajax_url,
      type: "POST",
      data: {
        action: "content_signing_verify",
        nonce: content_signing_public.nonce,
        post_id: postId,
        signature_id: signatureId,
      },
      success: function (response) {
        handleVerificationResponse(response, $resultContainer);
      },
      error: function () {
        // Show error message
        $resultContainer
          .removeClass(
            "content-signing-verification-loading content-signing-verification-success",
          )
          .addClass("content-signing-verification-error")
          .text(content_signing_public.i18n.error);
      },
      complete: function () {
        // Re-enable the button
        $button.prop("disabled", false);
      },
    });
  }

  /**
   * Handle the verification response.
   *
   * @param {Object} response - The AJAX response.
   * @param {jQuery} $resultContainer - The result container element.
   */
  function handleVerificationResponse(response, $resultContainer) {
    if (response.success) {
      // Show success message
      $resultContainer
        .removeClass(
          "content-signing-verification-loading content-signing-verification-error",
        )
        .addClass("content-signing-verification-success")
        .html(buildVerificationResultHtml(response.data, true));
    } else {
      // Show error message
      $resultContainer
        .removeClass(
          "content-signing-verification-loading content-signing-verification-success",
        )
        .addClass("content-signing-verification-error")
        .html(buildVerificationResultHtml(response.data, false));
    }
  }

  /**
   * Build the verification result HTML.
   *
   * @param {Object} data - The verification data.
   * @param {boolean} success - Whether verification was successful.
   * @return {string} The HTML for the verification result.
   */
  function buildVerificationResultHtml(data, success) {
    let html = "";

    if (success) {
      html +=
        "<p><strong>" + content_signing_public.i18n.verified + "</strong></p>";

      // Add verification details if available
      if (data.verification_details) {
        html += '<ul class="content-signing-verification-details">';

        // Add each verification detail
        for (const key in data.verification_details) {
          if (data.verification_details.hasOwnProperty(key)) {
            const value = data.verification_details[key];
            html +=
              '<li><span class="content-signing-verification-key">' +
              key +
              ":</span> ";
            html +=
              '<span class="content-signing-verification-value">' +
              value +
              "</span></li>";
          }
        }

        html += "</ul>";
      }
    } else {
      html +=
        "<p><strong>" +
        content_signing_public.i18n.not_verified +
        "</strong></p>";

      // Add error message if available
      if (data.message) {
        html += "<p>" + data.message + "</p>";
      }
    }

    return html;
  }

  /**
   * Verify signatures using the browser's native verification capabilities.
   * This is an alternative to server-side verification and can be used
   * when the signature attributes are embedded in the HTML.
   */
  function verifySignaturesInBrowser() {
    // Find all elements with signature attributes
    const $signedElements = $("signed-section[signature]");

    // If no signed elements, return
    if ($signedElements.length === 0) {
      return;
    }

    // For each signed element, verify the signature
    $signedElements.each(function () {
      const $element = $(this);
      const signature = $element.attr("signature");
      const authorPubKeys = $element.attr("keyid");
      const contentHash = $element.attr("content-hash");

      // If any attribute is missing, skip this element
      if (!signature || !authorPubKeys || !contentHash) {
        return;
      }

      // TODO: Implement browser-based verification using Web Crypto API
      // This would require additional implementation and is beyond the scope
      // of this initial version. For now, we rely on server-side verification.
    });
  }

  /**
   * Initialize when the DOM is ready.
   */
  $(function () {
    initContentSigning();

    // Optionally enable browser-based verification
    // verifySignaturesInBrowser();
  });
})(jQuery);
