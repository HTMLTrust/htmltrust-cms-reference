/**
 * All of the JavaScript for the admin-specific functionality of the plugin.
 *
 * @package    Content_Signing
 * @subpackage Content_Signing/admin/js
 */

(function($) {
    'use strict';

    /**
     * Initialize the admin scripts.
     */
    function init() {
        // Initialize tabs if they exist
        if ($('.content-signing-tabs').length) {
            initTabs();
        }

        // Initialize any dismissible notices
        initDismissibleNotices();
    }

    /**
     * Initialize tabs.
     */
    function initTabs() {
        const $tabs = $('.content-signing-tabs');
        const $tabLinks = $tabs.find('.content-signing-tab-link');
        const $tabContents = $('.content-signing-tab-content');

        // Hide all tab contents except the first one
        $tabContents.not(':first').hide();

        // Add active class to the first tab link
        $tabLinks.first().addClass('active');

        // Handle tab clicks
        $tabLinks.on('click', function(e) {
            e.preventDefault();

            const tabId = $(this).attr('href');

            // Remove active class from all tab links
            $tabLinks.removeClass('active');

            // Add active class to the clicked tab link
            $(this).addClass('active');

            // Hide all tab contents
            $tabContents.hide();

            // Show the selected tab content
            $(tabId).show();
        });
    }

    /**
     * Initialize dismissible notices.
     */
    function initDismissibleNotices() {
        $('.content-signing-notice.is-dismissible').each(function() {
            const $notice = $(this);
            const $dismissButton = $('<button type="button" class="notice-dismiss"></button>');

            // Add the dismiss button to the notice
            $notice.append($dismissButton);

            // Handle dismiss button click
            $dismissButton.on('click', function() {
                $notice.fadeOut(300, function() {
                    $notice.remove();
                });
            });
        });
    }

    // Initialize when the DOM is ready
    $(document).ready(init);

})(jQuery);