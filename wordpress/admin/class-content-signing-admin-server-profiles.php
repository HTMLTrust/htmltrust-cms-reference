<?php
/**
 * The server profiles admin page functionality of the plugin.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/admin
 */

class ContentSigning_Admin_ServerProfiles {

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
     * Render the server profiles page.
     *
     * @since    1.0.0
     * @return   void
     */
    public function render_page() {
        // Handle form submissions
        $this->handle_form_submissions();
        
        // Get all servers
        $servers = $this->db->get_servers();
        
        // Get the server being edited (if any)
        $server_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $server = $server_id ? $this->db->get_server($server_id) : null;
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Server Profiles', 'content-signing'); ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=content-signing-servers&action=add')); ?>" class="page-title-action"><?php _e('Add New', 'content-signing'); ?></a>
            <hr class="wp-header-end">
            
            <?php
            // Show messages
            $this->show_admin_notices();
            
            // Show the add/edit form if adding or editing
            if (isset($_GET['action']) && ($_GET['action'] === 'add' || $_GET['action'] === 'edit')) {
                $this->render_server_form($server);
            } else {
                // Show the servers list
                $this->render_servers_list($servers);
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
        if (!isset($_POST['content_signing_server_nonce'])) {
            return;
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['content_signing_server_nonce'], 'content_signing_server')) {
            add_settings_error(
                'content_signing_server',
                'nonce_error',
                __('Security check failed.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            add_settings_error(
                'content_signing_server',
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
            case 'add_server':
                $this->handle_add_server();
                break;
            case 'edit_server':
                $this->handle_edit_server();
                break;
            case 'delete_server':
                $this->handle_delete_server();
                break;
        }
    }

    /**
     * Handle adding a new server.
     *
     * @since    1.0.0
     * @return   void
     */
    private function handle_add_server() {
        // Get and sanitize form data
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $api_url = isset($_POST['api_url']) ? esc_url_raw($_POST['api_url']) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $is_default_server = isset($_POST['is_default_server']) ? 1 : 0;
        
        // Validate required fields
        if (empty($name) || empty($api_url) || empty($api_key)) {
            add_settings_error(
                'content_signing_server',
                'required_fields',
                __('All fields are required.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Insert the server
        $server_id = $this->db->insert_server(array(
            'name' => $name,
            'api_url' => $api_url,
            'api_key' => $api_key,
            'is_default_server' => $is_default_server,
        ));
        
        if ($server_id) {
            // Redirect to the servers list with a success message
            wp_redirect(add_query_arg(array(
                'page' => 'content-signing-servers',
                'message' => 'added',
            ), admin_url('admin.php')));
            exit;
        } else {
            add_settings_error(
                'content_signing_server',
                'insert_error',
                __('Failed to add server profile.', 'content-signing'),
                'error'
            );
        }
    }

    /**
     * Handle editing a server.
     *
     * @since    1.0.0
     * @return   void
     */
    private function handle_edit_server() {
        // Get and sanitize form data
        $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $api_url = isset($_POST['api_url']) ? esc_url_raw($_POST['api_url']) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $is_default_server = isset($_POST['is_default_server']) ? 1 : 0;
        
        // Validate required fields
        if (!$server_id || empty($name) || empty($api_url)) {
            add_settings_error(
                'content_signing_server',
                'required_fields',
                __('Server ID, name, and API URL are required.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Prepare update data
        $data = array(
            'name' => $name,
            'api_url' => $api_url,
            'is_default_server' => $is_default_server,
        );
        
        // Only update API key if provided
        if (!empty($api_key)) {
            $data['api_key'] = $api_key;
        }
        
        // Update the server
        $result = $this->db->update_server($server_id, $data);
        
        if ($result) {
            // Redirect to the servers list with a success message
            wp_redirect(add_query_arg(array(
                'page' => 'content-signing-servers',
                'message' => 'updated',
            ), admin_url('admin.php')));
            exit;
        } else {
            add_settings_error(
                'content_signing_server',
                'update_error',
                __('Failed to update server profile.', 'content-signing'),
                'error'
            );
        }
    }

    /**
     * Handle deleting a server.
     *
     * @since    1.0.0
     * @return   void
     */
    private function handle_delete_server() {
        // Get and sanitize form data
        $server_id = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
        
        // Validate server ID
        if (!$server_id) {
            add_settings_error(
                'content_signing_server',
                'invalid_id',
                __('Invalid server ID.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Check if server is in use by any authors
        $authors = $this->db->get_authors(array('server_id' => $server_id));
        if (!empty($authors)) {
            add_settings_error(
                'content_signing_server',
                'server_in_use',
                __('Cannot delete server profile because it is in use by one or more authors.', 'content-signing'),
                'error'
            );
            return;
        }
        
        // Delete the server
        $result = $this->db->delete_server($server_id);
        
        if ($result) {
            // Redirect to the servers list with a success message
            wp_redirect(add_query_arg(array(
                'page' => 'content-signing-servers',
                'message' => 'deleted',
            ), admin_url('admin.php')));
            exit;
        } else {
            add_settings_error(
                'content_signing_server',
                'delete_error',
                __('Failed to delete server profile.', 'content-signing'),
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
        settings_errors('content_signing_server');
        
        // Show success messages
        if (isset($_GET['message'])) {
            $message = sanitize_text_field($_GET['message']);
            $class = 'notice notice-success is-dismissible';
            $message_text = '';
            
            switch ($message) {
                case 'added':
                    $message_text = __('Server profile added successfully.', 'content-signing');
                    break;
                case 'updated':
                    $message_text = __('Server profile updated successfully.', 'content-signing');
                    break;
                case 'deleted':
                    $message_text = __('Server profile deleted successfully.', 'content-signing');
                    break;
            }
            
            if ($message_text) {
                printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message_text));
            }
        }
    }

    /**
     * Render the server form.
     *
     * @since    1.0.0
     * @param    object    $server    The server object.
     * @return   void
     */
    private function render_server_form($server) {
        $is_edit = $server !== null;
        $form_title = $is_edit ? __('Edit Server Profile', 'content-signing') : __('Add Server Profile', 'content-signing');
        $submit_text = $is_edit ? __('Update Server', 'content-signing') : __('Add Server', 'content-signing');
        $action = $is_edit ? 'edit_server' : 'add_server';
        
        ?>
        <h2><?php echo esc_html($form_title); ?></h2>
        <form method="post" action="">
            <?php wp_nonce_field('content_signing_server', 'content_signing_server_nonce'); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <?php if ($is_edit) : ?>
                <input type="hidden" name="server_id" value="<?php echo esc_attr($server->server_id); ?>">
            <?php endif; ?>
            
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="name"><?php _e('Name', 'content-signing'); ?></label>
                        </th>
                        <td>
                            <input name="name" type="text" id="name" value="<?php echo $is_edit ? esc_attr($server->name) : ''; ?>" class="regular-text" required>
                            <p class="description"><?php _e('A friendly name for this server profile.', 'content-signing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="api_url"><?php _e('API URL', 'content-signing'); ?></label>
                        </th>
                        <td>
                            <input name="api_url" type="url" id="api_url" value="<?php echo $is_edit ? esc_attr($server->api_url) : ''; ?>" class="regular-text" required>
                            <p class="description"><?php _e('The base URL for the Content Signing API.', 'content-signing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="api_key"><?php _e('API Key', 'content-signing'); ?></label>
                        </th>
                        <td>
                            <input name="api_key" type="password" id="api_key" value="" class="regular-text" <?php echo $is_edit ? '' : 'required'; ?>>
                            <p class="description">
                                <?php 
                                if ($is_edit) {
                                    _e('Leave blank to keep the current API key.', 'content-signing');
                                } else {
                                    _e('The general API key for this server.', 'content-signing');
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Default Server', 'content-signing'); ?>
                        </th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php _e('Default Server', 'content-signing'); ?></legend>
                                <label for="is_default_server">
                                    <input name="is_default_server" type="checkbox" id="is_default_server" value="1" <?php checked($is_edit && $server->is_default_server); ?>>
                                    <?php _e('Set as default server', 'content-signing'); ?>
                                </label>
                                <p class="description"><?php _e('The default server will be used when no specific server is selected.', 'content-signing'); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <?php submit_button($submit_text); ?>
            
            <a href="<?php echo esc_url(admin_url('admin.php?page=content-signing-servers')); ?>" class="button"><?php _e('Cancel', 'content-signing'); ?></a>
            
            <?php if ($is_edit) : ?>
                <div class="delete-server-container" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <h3><?php _e('Delete Server Profile', 'content-signing'); ?></h3>
                    <p><?php _e('Warning: This action cannot be undone.', 'content-signing'); ?></p>
                    <form method="post" action="" onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete this server profile?', 'content-signing'); ?>');">
                        <?php wp_nonce_field('content_signing_server', 'content_signing_server_nonce'); ?>
                        <input type="hidden" name="action" value="delete_server">
                        <input type="hidden" name="server_id" value="<?php echo esc_attr($server->server_id); ?>">
                        <?php submit_button(__('Delete Server', 'content-signing'), 'delete', 'submit', false); ?>
                    </form>
                </div>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * Render the servers list.
     *
     * @since    1.0.0
     * @param    array    $servers    The servers array.
     * @return   void
     */
    private function render_servers_list($servers) {
        ?>
        <div class="tablenav top">
            <div class="alignleft actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=content-signing-servers&action=add')); ?>" class="button"><?php _e('Add New Server', 'content-signing'); ?></a>
            </div>
            <br class="clear">
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php _e('Name', 'content-signing'); ?></th>
                    <th scope="col"><?php _e('API URL', 'content-signing'); ?></th>
                    <th scope="col"><?php _e('Default', 'content-signing'); ?></th>
                    <th scope="col"><?php _e('Actions', 'content-signing'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($servers)) : ?>
                    <tr>
                        <td colspan="4"><?php _e('No server profiles found.', 'content-signing'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($servers as $server) : ?>
                        <tr>
                            <td><?php echo esc_html($server->name); ?></td>
                            <td><?php echo esc_html($server->api_url); ?></td>
                            <td><?php echo $server->is_default_server ? '✓' : ''; ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=content-signing-servers&action=edit&edit=' . $server->server_id)); ?>" class="button-link"><?php _e('Edit', 'content-signing'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Enqueue scripts and styles for the server profiles page.
     *
     * @since    1.0.0
     * @return   void
     */
    public function enqueue_scripts() {
        // Enqueue server profiles-specific scripts and styles if needed
    }
}