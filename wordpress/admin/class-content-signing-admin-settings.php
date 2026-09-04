<?php
/**
 * The settings page functionality of the plugin.
 *
 * @since      1.0.0
 * @package    Content_Signing
 * @subpackage Content_Signing/admin
 */

class ContentSigning_Admin_Settings {

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
     * Register the settings.
     *
     * @since    1.0.0
     * @return   void
     */
    public function register_settings() {
        // Register settings
        register_setting(
            'content_signing_settings',
            'content_signing_enable_signing',
            array(
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            )
        );
        
        register_setting(
            'content_signing_settings',
            'content_signing_enable_endorsements',
            array(
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            )
        );
        
        register_setting(
            'content_signing_settings',
            'content_signing_sign_on_publish',
            array(
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
            )
        );
        
        register_setting(
            'content_signing_settings',
            'content_signing_sign_on_update',
            array(
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            )
        );
        
        register_setting(
            'content_signing_settings',
            'content_signing_sign_days_before_publish',
            array(
                'type' => 'integer',
                'default' => 0,
                'sanitize_callback' => 'absint',
            )
        );
        
        register_setting(
            'content_signing_settings',
            'content_signing_sign_days_after_publish',
            array(
                'type' => 'integer',
                'default' => 0,
                'sanitize_callback' => 'absint',
            )
        );
        
        register_setting(
            'content_signing_settings',
            'content_signing_post_types',
            array(
                'type' => 'array',
                'default' => array('post'),
                'sanitize_callback' => array($this, 'sanitize_post_types'),
            )
        );
        
        register_setting(
            'content_signing_settings',
            'content_signing_endorser_profiles',
            array(
                'type' => 'array',
                'default' => array(),
                'sanitize_callback' => array($this, 'sanitize_endorser_profiles'),
            )
        );
        
        register_setting(
            'content_signing_settings',
            'content_signing_embed_signature',
            array(
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            )
        );
        
        // Register settings sections
        add_settings_section(
            'content_signing_general_section',
            __('General Settings', 'content-signing'),
            array($this, 'render_general_section'),
            'content_signing_settings'
        );
        
        add_settings_section(
            'content_signing_signing_section',
            __('Signing Settings', 'content-signing'),
            array($this, 'render_signing_section'),
            'content_signing_settings'
        );
        
        add_settings_section(
            'content_signing_endorsement_section',
            __('Endorsement Settings', 'content-signing'),
            array($this, 'render_endorsement_section'),
            'content_signing_settings'
        );
        
        add_settings_section(
            'content_signing_display_section',
            __('Display Settings', 'content-signing'),
            array($this, 'render_display_section'),
            'content_signing_settings'
        );
        
        // Register settings fields
        add_settings_field(
            'content_signing_enable_signing',
            __('Enable Content Signing', 'content-signing'),
            array($this, 'render_enable_signing_field'),
            'content_signing_settings',
            'content_signing_general_section'
        );
        
        add_settings_field(
            'content_signing_post_types',
            __('Post Types', 'content-signing'),
            array($this, 'render_post_types_field'),
            'content_signing_settings',
            'content_signing_general_section'
        );
        
        add_settings_field(
            'content_signing_sign_on_publish',
            __('Sign on Publish', 'content-signing'),
            array($this, 'render_sign_on_publish_field'),
            'content_signing_settings',
            'content_signing_signing_section'
        );
        
        add_settings_field(
            'content_signing_sign_on_update',
            __('Sign on Update', 'content-signing'),
            array($this, 'render_sign_on_update_field'),
            'content_signing_settings',
            'content_signing_signing_section'
        );
        
        add_settings_field(
            'content_signing_sign_days_before_publish',
            __('Sign Days Before Publish', 'content-signing'),
            array($this, 'render_sign_days_before_publish_field'),
            'content_signing_settings',
            'content_signing_signing_section'
        );
        
        add_settings_field(
            'content_signing_sign_days_after_publish',
            __('Sign Days After Publish', 'content-signing'),
            array($this, 'render_sign_days_after_publish_field'),
            'content_signing_settings',
            'content_signing_signing_section'
        );
        
        add_settings_field(
            'content_signing_enable_endorsements',
            __('Enable Endorsements', 'content-signing'),
            array($this, 'render_enable_endorsements_field'),
            'content_signing_settings',
            'content_signing_endorsement_section'
        );
        
        add_settings_field(
            'content_signing_endorser_profiles',
            __('Endorser Profiles', 'content-signing'),
            array($this, 'render_endorser_profiles_field'),
            'content_signing_settings',
            'content_signing_endorsement_section'
        );
        
        add_settings_field(
            'content_signing_embed_signature',
            __('Embed Signature in Content', 'content-signing'),
            array($this, 'render_embed_signature_field'),
            'content_signing_settings',
            'content_signing_display_section'
        );
    }

    /**
     * Render the settings page.
     *
     * @since    1.0.0
     * @return   void
     */
    public function render_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('content_signing_settings');
                do_settings_sections('content_signing_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the general section.
     *
     * @since    1.0.0
     * @param    array    $args    The section arguments.
     * @return   void
     */
    public function render_general_section($args) {
        ?>
        <p><?php _e('Configure general settings for content signing.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Render the signing section.
     *
     * @since    1.0.0
     * @param    array    $args    The section arguments.
     * @return   void
     */
    public function render_signing_section($args) {
        ?>
        <p><?php _e('Configure when content should be signed.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Render the endorsement section.
     *
     * @since    1.0.0
     * @param    array    $args    The section arguments.
     * @return   void
     */
    public function render_endorsement_section($args) {
        ?>
        <p><?php _e('Configure endorsement settings.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Render the display section.
     *
     * @since    1.0.0
     * @param    array    $args    The section arguments.
     * @return   void
     */
    public function render_display_section($args) {
        ?>
        <p><?php _e('Configure how signatures are displayed on the frontend.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Render the enable signing field.
     *
     * @since    1.0.0
     * @return   void
     */
    public function render_enable_signing_field() {
        $enable_signing = get_option('content_signing_enable_signing', true);
        ?>
        <label>
            <input type="checkbox" name="content_signing_enable_signing" value="1" <?php checked($enable_signing); ?>>
            <?php _e('Enable content signing for this site', 'content-signing'); ?>
        </label>
        <p class="description"><?php _e('When enabled, content will be signed according to the settings below.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Render the post types field.
     *
     * @since    1.0.0
     * @return   void
     */
    public function render_post_types_field() {
        $post_types = get_option('content_signing_post_types', array('post'));
        $available_post_types = get_post_types(array('public' => true), 'objects');
        ?>
        <fieldset>
            <legend class="screen-reader-text"><?php _e('Post Types', 'content-signing'); ?></legend>
            <?php foreach ($available_post_types as $post_type) : ?>
                <label>
                    <input type="checkbox" name="content_signing_post_types[]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked(in_array($post_type->name, $post_types)); ?>>
                    <?php echo esc_html($post_type->label); ?>
                </label><br>
            <?php endforeach; ?>
        </fieldset>
        <p class="description"><?php _e('Select which post types should be signed.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Render the sign on publish field.
     *
     * @since    1.0.0
     * @return   void
     */
    public function render_sign_on_publish_field() {
        $sign_on_publish = get_option('content_signing_sign_on_publish', true);
        ?>
        <label>
            <input type="checkbox" name="content_signing_sign_on_publish" value="1" <?php checked($sign_on_publish); ?>>
            <?php _e('Sign content when it is published', 'content-signing'); ?>
        </label>
        <p class="description"><?php _e('When enabled, content will be signed when it is published.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Render the sign on update field.
     *
     * @since    1.0.0
     * @return   void
     */
    public function render_sign_on_update_field() {
        $sign_on_update = get_option('content_signing_sign_on_update', false);
        ?>
        <label>
            <input type="checkbox" name="content_signing_sign_on_update" value="1" <?php checked($sign_on_update); ?>>
            <?php _e('Sign content when it is updated', 'content-signing'); ?>
        </label>
        <p class="description"><?php _e('When enabled, content will be signed when it is updated.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Render the sign days before publish field.
     *
     * @since    1.0.0
     * @return   void
     */
    public function render_sign_days_before_publish_field() {
        $sign_days_before_publish = get_option('content_signing_sign_days_before_publish', 0);
        ?>
        <input type="number" name="content_signing_sign_days_before_publish" value="<?php echo esc_attr($sign_days_before_publish); ?>" min="0" step="1" class="small-text">
        <p class="description"><?php _e('Number of days before publish date to sign scheduled content. Set to 0 to disable.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Render the sign days after publish field.
     *
     * @since    1.0.0
     * @return   void
     */
    public function render_sign_days_after_publish_field() {
        $sign_days_after_publish = get_option('content_signing_sign_days_after_publish', 0);
        ?>
        <input type="number" name="content_signing_sign_days_after_publish" value="<?php echo esc_attr($sign_days_after_publish); ?>" min="0" step="1" class="small-text">
        <p class="description"><?php _e('Number of days after publish date to sign content. Set to 0 to disable.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Render the enable endorsements field.
     *
     * @since    1.0.0
     * @return   void
     */
    public function render_enable_endorsements_field() {
        ?>
        <p class="description"><?php _e('Server-side endorsement signing is disabled. Existing endorsement settings remain readable for migration records.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Render the endorser profiles field.
     *
     * @since    1.0.0
     * @return   void
     */
    public function render_endorser_profiles_field() {
        $endorser_profiles = get_option('content_signing_endorser_profiles', array());
        $site_endorsers = $this->db->get_site_endorsers();
        
        if (empty($site_endorsers)) {
            ?>
            <p class="description"><?php _e('No site endorser profiles found. Create author profiles and mark them as site endorsers.', 'content-signing'); ?></p>
            <?php
            return;
        }
        
        ?>
        <fieldset>
            <legend class="screen-reader-text"><?php _e('Endorser Profiles', 'content-signing'); ?></legend>
            <?php foreach ($site_endorsers as $endorser) : 
                $user = get_userdata($endorser->wp_user_id);
                $display_name = $user ? $user->display_name : __('Unknown User', 'content-signing');
                $server = $this->db->get_server($endorser->server_id);
                $server_name = $server ? $server->name : __('Unknown Server', 'content-signing');
            ?>
                <label>
                    <input type="checkbox" name="content_signing_endorser_profiles[]" value="<?php echo esc_attr($endorser->author_profile_id); ?>" <?php checked(in_array($endorser->author_profile_id, $endorser_profiles)); ?>>
                    <?php echo esc_html(sprintf('%s (%s)', $display_name, $server_name)); ?>
                </label><br>
            <?php endforeach; ?>
        </fieldset>
        <p class="description"><?php _e('Remote endorser profiles are shown for migration records. Browser-local profiles cannot be endorsers.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Render the embed signature field.
     *
     * @since    1.0.0
     * @return   void
     */
    public function render_embed_signature_field() {
        $embed_signature = get_option('content_signing_embed_signature', false);
        ?>
        <label>
            <input type="checkbox" name="content_signing_embed_signature" value="1" <?php checked($embed_signature); ?>>
            <?php _e('Embed signature in content', 'content-signing'); ?>
        </label>
        <p class="description"><?php _e('When enabled, signatures will be embedded in the content HTML.', 'content-signing'); ?></p>
        <?php
    }

    /**
     * Sanitize the post types setting.
     *
     * @since    1.0.0
     * @param    array    $input    The input array.
     * @return   array              The sanitized array.
     */
    public function sanitize_post_types($input) {
        if (!is_array($input)) {
            return array('post');
        }
        
        $available_post_types = get_post_types(array('public' => true));
        $sanitized = array();
        
        foreach ($input as $post_type) {
            if (in_array($post_type, $available_post_types)) {
                $sanitized[] = $post_type;
            }
        }
        
        if (empty($sanitized)) {
            $sanitized = array('post');
        }
        
        return $sanitized;
    }

    /**
     * Sanitize the endorser profiles setting.
     *
     * @since    1.0.0
     * @param    array    $input    The input array.
     * @return   array              The sanitized array.
     */
    public function sanitize_endorser_profiles($input) {
        if (!is_array($input)) {
            return array();
        }
        
        $sanitized = array();
        
        foreach ($input as $profile_id) {
            $profile_id = intval($profile_id);
            $profile = $this->db->get_author($profile_id);
            
            if ($profile && $profile->is_site_endorser && (int) $profile->server_id > 0) {
                $sanitized[] = $profile_id;
            }
        }
        
        return $sanitized;
    }

    /**
     * Enqueue scripts and styles for the settings page.
     *
     * @since    1.0.0
     * @return   void
     */
    public function enqueue_scripts() {
        // Enqueue settings-specific scripts and styles if needed
    }
}
