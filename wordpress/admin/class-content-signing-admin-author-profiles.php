<?php
/**
 * The author profiles admin page functionality of the plugin.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/admin
 */

class ContentSigning_Admin_AuthorProfiles {

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
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    ContentSigning_DB             $db          The database handler.
     * @param    ContentSigning_API_Client     $api_client  The API client.
     */
    public function __construct($db, $api_client) {
        $this->db = $db;
        $this->api_client = $api_client;
    }

    /**
     * Render the author profiles page.
     *
     * @since    1.0.0
     * @return   void
     */
    public function render_page() {
        // Handle form submissions
        $this->handle_form_submissions();
        
        // Get all authors
        $authors = $this->db->get_authors();
        
        // Get the author being edited (if any)
        $author_profile_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $author = $author_profile_id ? $this->db->get_author($author_profile_id) : null;
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Author Profiles', 'content-signing'); ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=content-signing-authors&action=add')); ?>" class="page-title-action"><?php _e('Add New', 'content-signing'); ?></a>
            <hr class="wp-header-end">
            
            <?php
            // Show messages
            $this->show_admin_notices();
            
            // Show the add/edit form if adding or editing
            if (isset($_GET['action']) && ($_GET['action'] === 'add' || $_GET['action'] === 'edit')) {
                $this->render_author_form($author);
            } else {
                // Show the authors list
                $this->render_authors_list($authors);
            }
            ?>
        </div>
        <?php
    }

    /**
     * Handle form submissions.
     *
     * @since    1.0.0
     * @return   void
     */
    private function handle_form_submissions() {
        // Check if a form was submitted
        if (!isset($_POST['content_signing_author_nonce'])) {
            return;
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['content_signing_author_nonce'], 'content_signing_author')) {
            add_settings_error(
                'content_signing_author',
                'nonce_error',
                __('Security check failed.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            add_settings_error(
                'content_signing_author',
                'capability_error',
                __('You do not have permission to perform this action.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Get the action
        $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';
        
        // Handle different actions
        switch ($action) {
            case 'add_author':
                $this->handle_add_author();
                break;
            case 'edit_author':
                $this->handle_edit_author();
                break;
            case 'delete_author':
                $this->handle_delete_author();
                break;
            case 'create_api_author':
                $this->handle_create_api_author();
                break;
        }
    }

    /**
     * Handle adding a new author profile.
     *
     * @since    1.0.0
     * @return   void
     */
    private function handle_add_author() {
        // Get and sanitize form data
        $wp_user_id = isset($_POST['wp_user_id']) ? intval($_POST['wp_user_id']) : 0;
        $signing_author_id = isset($_POST['signing_author_id']) ? sanitize_text_field($_POST['signing_author_id']) : '';
        $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : -1;
        $author_api_key = isset($_POST['author_api_key']) ? sanitize_text_field($_POST['author_api_key']) : '';
        $default_key_type = isset($_POST['default_key_type']) ? sanitize_text_field($_POST['default_key_type']) : 'HUMAN';
        $default_claims = isset($_POST['default_claims']) ? $this->sanitize_claims($_POST['default_claims']) : array();
        $is_site_endorser = isset($_POST['is_site_endorser']) ? 1 : 0;
        
        if ($server_id === 0 && empty($signing_author_id) && $wp_user_id) {
            $signing_author_id = $this->local_signing_author_id($wp_user_id);
        }

        // Remote profiles keep their existing required API credentials. A
        // browser-local profile has no server credential and uses a concrete
        // local identity derived from the linked WordPress user.
        if (!$wp_user_id || $server_id < 0 || empty($signing_author_id) || ($server_id !== 0 && empty($author_api_key))) {
            add_settings_error(
                'content_signing_author',
                'required_fields',
                __('WordPress user, author identity, and a remote server API key are required for remote profiles.', 'content-signing'),
                'error'
            );
            return;
        }

        if ($server_id === 0 && $is_site_endorser) {
            add_settings_error(
                'content_signing_author',
                'local_endorser',
                __('Browser-local profiles cannot be site endorsers because endorsements require a remote server.', 'content-signing'),
                'error'
            );
            return;
        }

        if ($server_id === 0) {
            // Local profiles never persist remote credentials.
            $author_api_key = '';
            $is_site_endorser = 0;
        }
        
        // Check if user already has an author profile
        $existing_author = $this->db->get_author_by_wp_user_id($wp_user_id);
        if ($existing_author) {
            add_settings_error(
                'content_signing_author',
                'duplicate_user',
                __('This WordPress user already has an author profile.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Insert the author profile
        $author_profile_id = $this->db->insert_author(array(
            'wp_user_id' => $wp_user_id,
            'signing_author_id' => $signing_author_id,
            'server_id' => $server_id,
            'author_api_key' => $author_api_key,
            'default_key_type' => $default_key_type,
            'default_claims' => $default_claims,
            'is_site_endorser' => $is_site_endorser,
        ));
        
        if ($author_profile_id) {
            // Redirect to the authors list with a success message
            wp_redirect(add_query_arg(array(
                'page' => 'content-signing-authors',
                'message' => 'added',
            ), admin_url('admin.php')));
            exit;
        } else {
            add_settings_error(
                'content_signing_author',
                'insert_error',
                __('Failed to add author profile.', 'content-signing'),
                'error'
            );
        }
    }

    /**
     * Handle editing an author profile.
     *
     * @since    1.0.0
     * @return   void
     */
    private function handle_edit_author() {
        // Get and sanitize form data
        $author_profile_id = isset($_POST['author_profile_id']) ? intval($_POST['author_profile_id']) : 0;
        $signing_author_id = isset($_POST['signing_author_id']) ? sanitize_text_field($_POST['signing_author_id']) : '';
        $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : -1;
        $author_api_key = isset($_POST['author_api_key']) ? sanitize_text_field($_POST['author_api_key']) : '';
        $default_key_type = isset($_POST['default_key_type']) ? sanitize_text_field($_POST['default_key_type']) : 'HUMAN';
        $default_claims = isset($_POST['default_claims']) ? $this->sanitize_claims($_POST['default_claims']) : array();
        $is_site_endorser = isset($_POST['is_site_endorser']) ? 1 : 0;
        
        $existing_author = $author_profile_id ? $this->db->get_author($author_profile_id) : null;
        if ($existing_author && $server_id === 0 && empty($signing_author_id)) {
            $signing_author_id = $this->local_signing_author_id($existing_author->wp_user_id);
        }

        // A remote profile may retain its existing key. Switching a local
        // profile to a remote server requires a new remote credential.
        $switching_to_remote_without_key = $existing_author
            && (int) $existing_author->server_id === 0
            && $server_id !== 0
            && empty($author_api_key);
        if (!$existing_author || $server_id < 0 || empty($signing_author_id) || $switching_to_remote_without_key) {
            add_settings_error(
                'content_signing_author',
                'required_fields',
                __('Author profile, author identity, and a remote server API key are required when using a remote profile.', 'content-signing'),
                'error'
            );
            return;
        }

        if ($server_id === 0 && $is_site_endorser) {
            add_settings_error(
                'content_signing_author',
                'local_endorser',
                __('Browser-local profiles cannot be site endorsers because endorsements require a remote server.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Prepare update data
        $data = array(
            'signing_author_id' => $signing_author_id,
            'server_id' => $server_id,
            'default_key_type' => $default_key_type,
            'default_claims' => $default_claims,
            'is_site_endorser' => $is_site_endorser,
        );

        if ($server_id === 0) {
            $data['author_api_key_encrypted'] = '';
            $data['is_site_endorser'] = 0;
        }
        
        // Only update API key if provided
        if (!empty($author_api_key)) {
            $data['author_api_key'] = $author_api_key;
        }
        
        // Update the author profile
        $result = $this->db->update_author($author_profile_id, $data);
        
        if ($result) {
            // Redirect to the authors list with a success message
            wp_redirect(add_query_arg(array(
                'page' => 'content-signing-authors',
                'message' => 'updated',
            ), admin_url('admin.php')));
            exit;
        } else {
            add_settings_error(
                'content_signing_author',
                'update_error',
                __('Failed to update author profile.', 'content-signing'),
                'error'
            );
        }
    }

    /**
     * Handle deleting an author profile.
     *
     * @since    1.0.0
     * @return   void
     */
    private function handle_delete_author() {
        // Get and sanitize form data
        $author_profile_id = isset($_POST['author_profile_id']) ? intval($_POST['author_profile_id']) : 0;
        
        // Validate author profile ID
        if (!$author_profile_id) {
            add_settings_error(
                'content_signing_author',
                'invalid_id',
                __('Invalid author profile ID.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Delete the author profile
        $result = $this->db->delete_author($author_profile_id);
        
        if ($result) {
            // Redirect to the authors list with a success message
            wp_redirect(add_query_arg(array(
                'page' => 'content-signing-authors',
                'message' => 'deleted',
            ), admin_url('admin.php')));
            exit;
        } else {
            add_settings_error(
                'content_signing_author',
                'delete_error',
                __('Failed to delete author profile.', 'content-signing'),
                'error'
            );
        }
    }

    /**
     * Handle creating a new author in the API.
     *
     * @since    1.0.0
     * @return   void
     */
    private function handle_create_api_author() {
        // Get and sanitize form data
        $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
        $wp_user_id = isset($_POST['wp_user_id']) ? intval($_POST['wp_user_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $key_type = isset($_POST['key_type']) ? sanitize_text_field($_POST['key_type']) : 'HUMAN';
        $key_algorithm = isset($_POST['key_algorithm']) ? sanitize_text_field($_POST['key_algorithm']) : 'RSA';
        
        // Validate required fields
        if (!$server_id || !$wp_user_id || empty($name) || empty($key_type)) {
            add_settings_error(
                'content_signing_author',
                'required_fields',
                __('Server ID, WordPress user, name, and key type are required.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Get the server
        $server = $this->db->get_server($server_id);
        if (!$server) {
            add_settings_error(
                'content_signing_author',
                'invalid_server',
                __('Invalid server ID.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Create an API client for this server
        $api_client = new ContentSigning_API_Client(
            $server->api_url,
            $this->db->decrypt($server->api_key_encrypted),
            $this->db
        );
        
        // Prepare author data
        $author_data = array(
            'name' => $name,
            'keyType' => $key_type,
            'keyAlgorithm' => $key_algorithm,
        );
        
        if (!empty($description)) {
            $author_data['description'] = $description;
        }
        
        if (!empty($url)) {
            $author_data['url'] = $url;
        }
        
        // Create the author in the API
        $result = $api_client->create_author($author_data);
        
        if (is_wp_error($result)) {
            add_settings_error(
                'content_signing_author',
                'api_error',
                sprintf(__('API Error: %s', 'content-signing'), $result->get_error_message()),
                'error'
            );
            return;
        }
        
        // Extract author ID and API key from the response
        $signing_author_id = isset($result['author']['id']) ? $result['author']['id'] : '';
        $author_api_key = isset($result['authorApiKey']) ? $result['authorApiKey'] : '';
        
        if (empty($signing_author_id) || empty($author_api_key)) {
            add_settings_error(
                'content_signing_author',
                'invalid_response',
                __('Invalid API response. Missing author ID or API key.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Check if user already has an author profile
        $existing_author = $this->db->get_author_by_wp_user_id($wp_user_id);
        if ($existing_author) {
            add_settings_error(
                'content_signing_author',
                'duplicate_user',
                __('This WordPress user already has an author profile.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Insert the author profile
        $author_profile_id = $this->db->insert_author(array(
            'wp_user_id' => $wp_user_id,
            'signing_author_id' => $signing_author_id,
            'server_id' => $server_id,
            'author_api_key' => $author_api_key,
            'default_key_type' => $key_type,
            'is_site_endorser' => 0,
        ));
        
        if ($author_profile_id) {
            // Redirect to the edit page for the new author profile
            wp_redirect(add_query_arg(array(
                'page' => 'content-signing-authors',
                'action' => 'edit',
                'edit' => $author_profile_id,
                'message' => 'created',
            ), admin_url('admin.php')));
            exit;
        } else {
            add_settings_error(
                'content_signing_author',
                'insert_error',
                __('Failed to add author profile.', 'content-signing'),
                'error'
            );
        }
    }

    /**
     * Show admin notices.
     *
     * @since    1.0.0
     * @return   void
     */
    private function show_admin_notices() {
        // Show settings errors
        settings_errors('content_signing_author');
        
        // Show success messages
        if (isset($_GET['message'])) {
            $message = sanitize_text_field($_GET['message']);
            $class = 'notice notice-success is-dismissible';
            $message_text = '';
            
            switch ($message) {
                case 'added':
                    $message_text = __('Author profile added successfully.', 'content-signing');
                    break;
                case 'updated':
                    $message_text = __('Author profile updated successfully.', 'content-signing');
                    break;
                case 'deleted':
                    $message_text = __('Author profile deleted successfully.', 'content-signing');
                    break;
                case 'created':
                    $message_text = __('Author created in API and profile added successfully.', 'content-signing');
                    break;
            }
            
            if ($message_text) {
                printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message_text));
            }
        }
    }

    /**
     * Render the author form.
     *
     * @since    1.0.0
     * @param    object    $author    The author object.
     * @return   void
     */
    private function render_author_form($author) {
        $is_edit = $author !== null;
        $form_title = $is_edit ? __('Edit Author Profile', 'content-signing') : __('Add Author Profile', 'content-signing');
        $submit_text = $is_edit ? __('Update Author Profile', 'content-signing') : __('Add Author Profile', 'content-signing');
        $action = $is_edit ? 'edit_author' : 'add_author';
        
        // Get all servers
        $servers = $this->db->get_servers();
        
        // Get all WordPress users
        $wp_users = get_users(array(
            'orderby' => 'display_name',
            'order' => 'ASC',
        ));
        
        // Get default claims if editing
        $default_claims = array();
        if ($is_edit && !empty($author->default_claims_json)) {
            $default_claims = json_decode($author->default_claims_json, true);
        }
        
        ?>
        <h2><?php echo esc_html($form_title); ?></h2>
        
        <?php if (!$is_edit) : ?>
            <div class="notice notice-info">
                <p><?php _e('Choose Browser-local only for editor signing without a trust directory. Remote profiles remain available for legacy API identities.', 'content-signing'); ?></p>
                <p><a href="#create-api-author" class="button"><?php _e('Create New API Author', 'content-signing'); ?></a></p>
            </div>
        <?php endif; ?>
        
        <form method="post" action="">
            <?php wp_nonce_field('content_signing_author', 'content_signing_author_nonce'); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <?php if ($is_edit) : ?>
                <input type="hidden" name="author_profile_id" value="<?php echo esc_attr($author->author_profile_id); ?>">
                <input type="hidden" name="wp_user_id" value="<?php echo esc_attr($author->wp_user_id); ?>">
            <?php endif; ?>
            
            <table class="form-table" role="presentation">
                <tbody>
                    <?php if (!$is_edit) : ?>
                        <tr>
                            <th scope="row">
                                <label for="wp_user_id"><?php _e('WordPress User', 'content-signing'); ?></label>
                            </th>
                            <td>
                                <select name="wp_user_id" id="wp_user_id" class="regular-text" required>
                                    <option value=""><?php _e('Select a user', 'content-signing'); ?></option>
                                    <?php foreach ($wp_users as $user) : ?>
                                        <option value="<?php echo esc_attr($user->ID); ?>"><?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_login); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php _e('The WordPress user to link to this author profile.', 'content-signing'); ?></p>
                            </td>
                        </tr>
                    <?php else : ?>
                        <tr>
                            <th scope="row"><?php _e('WordPress User', 'content-signing'); ?></th>
                            <td>
                                <?php 
                                $user = get_userdata($author->wp_user_id);
                                echo $user ? esc_html($user->display_name) : __('Unknown User', 'content-signing');
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row">
                            <label for="server_id"><?php _e('Server', 'content-signing'); ?></label>
                        </th>
                        <td>
                            <select name="server_id" id="server_id" class="regular-text" required>
                                <option value="0" <?php selected(!$is_edit || (int) $author->server_id === 0); ?>><?php _e('Browser-local only', 'content-signing'); ?></option>
                                <?php foreach ($servers as $server) : ?>
                                    <option value="<?php echo esc_attr($server->server_id); ?>" <?php selected($is_edit && $author->server_id == $server->server_id); ?>><?php echo esc_html($server->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Browser-local only keeps the signing key in the author browser and requires no API key. Select a remote server for a legacy API profile.', 'content-signing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="signing_author_id"><?php _e('Signing Author ID', 'content-signing'); ?></label>
                        </th>
                        <td>
                            <input name="signing_author_id" type="text" id="signing_author_id" value="<?php echo $is_edit ? esc_attr($author->signing_author_id) : ''; ?>" class="regular-text">
                            <p class="description"><?php _e('Remote profiles use the author ID from the Content Signing API. Browser-local profiles may leave this blank and use local-wp-user-{ID}.', 'content-signing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="author_api_key"><?php _e('Author API Key', 'content-signing'); ?></label>
                        </th>
                        <td>
                            <input name="author_api_key" type="password" id="author_api_key" value="" class="regular-text">
                            <p class="description">
                                <?php 
                                if ($is_edit) {
                                    _e('Leave blank to keep the current remote API key or when using Browser-local only.', 'content-signing');
                                } else {
                                    _e('Required for a remote server. Leave blank for Browser-local only.', 'content-signing');
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="default_key_type"><?php _e('Default Key Type', 'content-signing'); ?></label>
                        </th>
                        <td>
                            <select name="default_key_type" id="default_key_type" class="regular-text">
                                <option value="HUMAN" <?php selected($is_edit && $author->default_key_type === 'HUMAN'); ?>><?php _e('Human', 'content-signing'); ?></option>
                                <option value="AI" <?php selected($is_edit && $author->default_key_type === 'AI'); ?>><?php _e('AI', 'content-signing'); ?></option>
                                <option value="HUMAN_AI_MIX" <?php selected($is_edit && $author->default_key_type === 'HUMAN_AI_MIX'); ?>><?php _e('Human + AI', 'content-signing'); ?></option>
                                <option value="ORGANIZATION" <?php selected($is_edit && $author->default_key_type === 'ORGANIZATION'); ?>><?php _e('Organization', 'content-signing'); ?></option>
                            </select>
                            <p class="description"><?php _e('The default key type to use for this author.', 'content-signing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="default_claims"><?php _e('Default Claims', 'content-signing'); ?></label>
                        </th>
                        <td>
                            <textarea name="default_claims" id="default_claims" class="large-text code" rows="10"><?php echo esc_textarea(wp_json_encode($default_claims, JSON_PRETTY_PRINT)); ?></textarea>
                            <p class="description"><?php _e('Default claims to include in signatures for this author. JSON format.', 'content-signing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Site Endorser', 'content-signing'); ?>
                        </th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php _e('Site Endorser', 'content-signing'); ?></legend>
                                <label for="is_site_endorser">
                                    <input name="is_site_endorser" type="checkbox" id="is_site_endorser" value="1" <?php checked($is_edit && $author->is_site_endorser); ?> <?php disabled($is_edit && (int) $author->server_id === 0); ?>>
                                    <?php _e('Mark as site endorser', 'content-signing'); ?>
                                </label>
                                <p class="description"><?php _e('Remote profiles can be used for site-wide endorsements. Browser-local profiles cannot endorse because endorsement signing is disabled.', 'content-signing'); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <?php submit_button($submit_text); ?>
            
            <a href="<?php echo esc_url(admin_url('admin.php?page=content-signing-authors')); ?>" class="button"><?php _e('Cancel', 'content-signing'); ?></a>
            
            <?php if ($is_edit) : ?>
                <div class="delete-author-container" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <h3><?php _e('Delete Author Profile', 'content-signing'); ?></h3>
                    <p><?php _e('Warning: This action cannot be undone.', 'content-signing'); ?></p>
                    <form method="post" action="" onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete this author profile?', 'content-signing'); ?>');">
                        <?php wp_nonce_field('content_signing_author', 'content_signing_author_nonce'); ?>
                        <input type="hidden" name="action" value="delete_author">
                        <input type="hidden" name="author_profile_id" value="<?php echo esc_attr($author->author_profile_id); ?>">
                        <?php submit_button(__('Delete Author Profile', 'content-signing'), 'delete', 'submit', false); ?>
                    </form>
                </div>
            <?php endif; ?>
        </form>
        
        <?php if (!$is_edit) : ?>
            <div id="create-api-author" style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd;">
                <h2><?php _e('Create New API Author', 'content-signing'); ?></h2>
                <p><?php _e('Use this form to create a new author in the Content Signing API and automatically add it as an author profile.', 'content-signing'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('content_signing_author', 'content_signing_author_nonce'); ?>
                    <input type="hidden" name="action" value="create_api_author">
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="create_server_id"><?php _e('Server', 'content-signing'); ?></label>
                                </th>
                                <td>
                                    <select name="server_id" id="create_server_id" class="regular-text" required>
                                        <option value=""><?php _e('Select a server', 'content-signing'); ?></option>
                                        <?php foreach ($servers as $server) : ?>
                                            <option value="<?php echo esc_attr($server->server_id); ?>"><?php echo esc_html($server->name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php _e('The server to create the author on.', 'content-signing'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="create_wp_user_id"><?php _e('WordPress User', 'content-signing'); ?></label>
                                </th>
                                <td>
                                    <select name="wp_user_id" id="create_wp_user_id" class="regular-text" required>
                                        <option value=""><?php _e('Select a user', 'content-signing'); ?></option>
                                        <?php foreach ($wp_users as $user) : ?>
                                            <option value="<?php echo esc_attr($user->ID); ?>"><?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_login); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php _e('The WordPress user to link to this author profile.', 'content-signing'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="name"><?php _e('Name', 'content-signing'); ?></label>
                                </th>
                                <td>
                                    <input name="name" type="text" id="name" value="" class="regular-text" required>
                                    <p class="description"><?php _e('The name of the author.', 'content-signing'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="description"><?php _e('Description', 'content-signing'); ?></label>
                                </th>
                                <td>
                                    <textarea name="description" id="description" class="large-text" rows="3"></textarea>
                                    <p class="description"><?php _e('A description of the author.', 'content-signing'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="url"><?php _e('URL', 'content-signing'); ?></label>
                                </th>
                                <td>
                                    <input name="url" type="url" id="url" value="" class="regular-text">
                                    <p class="description"><?php _e('A URL associated with the author.', 'content-signing'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="key_type"><?php _e('Key Type', 'content-signing'); ?></label>
                                </th>
                                <td>
                                    <select name="key_type" id="key_type" class="regular-text" required>
                                        <option value="HUMAN"><?php _e('Human', 'content-signing'); ?></option>
                                        <option value="AI"><?php _e('AI', 'content-signing'); ?></option>
                                        <option value="HUMAN_AI_MIX"><?php _e('Human + AI', 'content-signing'); ?></option>
                                        <option value="ORGANIZATION"><?php _e('Organization', 'content-signing'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('The type of the author key.', 'content-signing'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="key_algorithm"><?php _e('Key Algorithm', 'content-signing'); ?></label>
                                </th>
                                <td>
                                    <select name="key_algorithm" id="key_algorithm" class="regular-text">
                                        <option value="RSA"><?php _e('RSA', 'content-signing'); ?></option>
                                        <option value="ECDSA"><?php _e('ECDSA', 'content-signing'); ?></option>
                                        <option value="ED25519"><?php _e('ED25519', 'content-signing'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('The cryptographic algorithm to use for the key pair.', 'content-signing'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <?php submit_button(__('Create Author', 'content-signing')); ?>
                </form>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Render the authors list.
     *
     * @since    1.0.0
     * @param    array    $authors    The authors array.
     * @return   void
     */
    private function render_authors_list($authors) {
        ?>
        <div class="tablenav top">
            <div class="alignleft actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=content-signing-authors&action=add')); ?>" class="button"><?php _e('Add New Author Profile', 'content-signing'); ?></a>
            </div>
            <br class="clear">
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php _e('WordPress User', 'content-signing'); ?></th>
                    <th scope="col"><?php _e('Signing Author ID', 'content-signing'); ?></th>
                    <th scope="col"><?php _e('Server', 'content-signing'); ?></th>
                    <th scope="col"><?php _e('Key Type', 'content-signing'); ?></th>
                    <th scope="col"><?php _e('Site Endorser', 'content-signing'); ?></th>
                    <th scope="col"><?php _e('Actions', 'content-signing'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($authors)) : ?>
                    <tr>
                        <td colspan="6"><?php _e('No author profiles found.', 'content-signing'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($authors as $author) : 
                        $user = get_userdata($author->wp_user_id);
                        $display_name = $user ? $user->display_name : __('Unknown User', 'content-signing');
                        $server = $this->db->get_server($author->server_id);
                        $server_name = (int) $author->server_id === 0 ? __('Browser-local only', 'content-signing') : ($server ? $server->name : __('Unknown Server', 'content-signing'));
                    ?>
                        <tr>
                            <td><?php echo esc_html($display_name); ?></td>
                            <td><?php echo esc_html($author->signing_author_id); ?></td>
                            <td><?php echo esc_html($server_name); ?></td>
                            <td><?php echo esc_html($author->default_key_type); ?></td>
                            <td><?php echo $author->is_site_endorser ? '✓' : ''; ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=content-signing-authors&action=edit&edit=' . $author->author_profile_id)); ?>" class="button-link"><?php _e('Edit', 'content-signing'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Add user profile fields.
     *
     * @since    1.0.0
     * @param    WP_User   $user    The user object.
     * @return   void
     */
    public function add_user_profile_fields($user) {
        // Check if user has an author profile
        $author = $this->db->get_author_by_wp_user_id($user->ID);
        
        ?>
        <h2><?php _e('Content Signing', 'content-signing'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label><?php _e('Author Profile', 'content-signing'); ?></label></th>
                <td>
                    <?php if ($author) : 
                        $server = $this->db->get_server($author->server_id);
                        $server_name = (int) $author->server_id === 0 ? __('Browser-local only', 'content-signing') : ($server ? $server->name : __('Unknown Server', 'content-signing'));
                    ?>
                        <p>
                            <?php _e('Signing Author ID:', 'content-signing'); ?> <strong><?php echo esc_html($author->signing_author_id); ?></strong><br>
                            <?php _e('Server:', 'content-signing'); ?> <strong><?php echo esc_html($server_name); ?></strong><br>
                            <?php _e('Key Type:', 'content-signing'); ?> <strong><?php echo esc_html($author->default_key_type); ?></strong><br>
                            <?php _e('Site Endorser:', 'content-signing'); ?> <strong><?php echo $author->is_site_endorser ? __('Yes', 'content-signing') : __('No', 'content-signing'); ?></strong>
                        </p>
                        <p>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=content-signing-authors&action=edit&edit=' . $author->author_profile_id)); ?>" class="button"><?php _e('Edit Author Profile', 'content-signing'); ?></a>
                        </p>
                    <?php else : ?>
                        <p><?php _e('No author profile found for this user.', 'content-signing'); ?></p>
                        <p>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=content-signing-authors&action=add')); ?>" class="button"><?php _e('Add Author Profile', 'content-signing'); ?></a>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save user profile fields.
     *
     * @since    1.0.0
     * @param    int       $user_id    The user ID.
     * @return   void
     */
    public function save_user_profile_fields($user_id) {
        // No fields to save currently
    }

    /**
     * Sanitize claims.
     *
     * @since    1.0.0
     * @param    string    $claims_json    The claims JSON string.
     * @return   array                     The sanitized claims array.
     */
    private function sanitize_claims($claims_json) {
        if (empty($claims_json)) {
            return array();
        }
        
        $claims = json_decode($claims_json, true);
        if (!is_array($claims)) {
            return array();
        }
        
        // Sanitize each claim
        $sanitized = array();
        foreach ($claims as $key => $value) {
            $sanitized_key = sanitize_text_field($key);
            
            if (is_array($value)) {
                $sanitized_value = array_map('sanitize_text_field', $value);
            } else {
                $sanitized_value = sanitize_text_field($value);
            }
            
            $sanitized[$sanitized_key] = $sanitized_value;
        }
        
        return $sanitized;
    }

    /**
     * Derive the stable local identity used by a browser-local profile.
     *
     * @param int $wp_user_id WordPress user ID.
     * @return string Local signing author identity.
     */
    private function local_signing_author_id($wp_user_id) {
        return 'local-wp-user-' . (int) $wp_user_id;
    }

    /**
     * Enqueue scripts and styles for the author profiles page.
     *
     * @since    1.0.0
     * @return   void
     */
    public function enqueue_scripts() {
        // Enqueue author profiles-specific scripts and styles if needed
    }
}
