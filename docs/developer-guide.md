# Content Signing for WordPress - Developer Guide

This guide provides technical information for developers who want to extend, customize, or integrate with the Content Signing for WordPress plugin.

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [Core Components](#core-components)
- [Database Schema](#database-schema)
- [WordPress Integration](#wordpress-integration)
- [API Integration](#api-integration)
- [Hooks and Filters](#hooks-and-filters)
- [Testing](#testing)
- [Extending the Plugin](#extending-the-plugin)
- [Security Considerations](#security-considerations)
- [Internationalization](#internationalization)
- [Best Practices](#best-practices)

## Architecture Overview

The Content Signing for WordPress plugin follows a modular architecture with clear separation of concerns. The main components are:

- **Core Plugin Class**: Initializes the plugin and loads components
- **Database Handler**: Manages database operations
- **API Client**: Communicates with the Content Signing API
- **Signing Service**: Orchestrates the signing process
- **Scheduler**: Manages scheduled signing operations
- **Admin Interface**: Provides the WordPress admin UI
- **Public Interface**: Handles frontend display

## Core Components

### ContentSigning_Plugin

The main plugin class that initializes all components and manages the plugin lifecycle.

```php
$plugin = ContentSigning_Plugin::get_instance();
$plugin->run();
```

### ContentSigning_DB

Handles all database operations, including CRUD operations for server profiles, author profiles, and signatures.

```php
$db = new ContentSigning_DB();
$servers = $db->get_servers();
```

### ContentSigning_API_Client

Responsible for all communication with the external Content Signing API.

```php
$api_client = new ContentSigning_API_Client($api_url, $api_key, $db);
$result = $api_client->sign_content($content_data, $author_api_key);
```

### ContentSigning_Signing_Service

Orchestrates the signing process, determining when to sign, preparing data, and handling results.

```php
$signing_service = new ContentSigning_Signing_Service($db, $api_client, $scheduler);
$result = $signing_service->sign_post($post_id);
```

### ContentSigning_Scheduler

Manages scheduled signing operations using WordPress cron.

```php
$scheduler = new ContentSigning_Scheduler($db);
$scheduler->schedule_signing($post_id, $timestamp);
```

### ContentSigning_Admin

Handles the WordPress admin interface, including settings pages and meta boxes.

```php
$admin = new ContentSigning_Admin($db, $api_client);
```

### ContentSigning_Hooks

Centralizes the registration and callback logic for WordPress actions and filters.

```php
$hooks = new ContentSigning_Hooks($signing_service, $admin);
$hooks->register_hooks();
```

## Database Schema

The plugin uses three custom tables:

### wp_content_signing_servers

Stores connection details for different signing API servers.

| Column | Type | Description |
|--------|------|-------------|
| server_id | BIGINT | Primary key |
| name | VARCHAR | Server name |
| api_url | VARCHAR | API URL |
| api_key_encrypted | VARCHAR | Encrypted API key |
| is_default_server | BOOLEAN | Whether this is the default server |
| created_at | DATETIME | Creation timestamp |
| updated_at | DATETIME | Update timestamp |

### wp_content_signing_authors

Links WordPress users to external signing Author IDs.

| Column | Type | Description |
|--------|------|-------------|
| author_profile_id | BIGINT | Primary key |
| wp_user_id | BIGINT | WordPress user ID |
| signing_author_id | VARCHAR | External author ID |
| server_id | BIGINT | Server ID |
| author_api_key_encrypted | VARCHAR | Encrypted author API key |
| default_key_type | VARCHAR | Default key type |
| default_claims_json | TEXT | Default claims as JSON |
| is_site_endorser | BOOLEAN | Whether this author is a site endorser |
| created_at | DATETIME | Creation timestamp |
| updated_at | DATETIME | Update timestamp |

### wp_content_signing_signatures

Stores details about each signature generated.

| Column | Type | Description |
|--------|------|-------------|
| signature_id | BIGINT | Primary key |
| post_id | BIGINT | WordPress post ID |
| server_id | BIGINT | Server ID |
| signing_author_id | VARCHAR | External author ID |
| wp_user_id | BIGINT | WordPress user ID |
| content_hash | VARCHAR | Content hash |
| domain | VARCHAR | Domain |
| signature | TEXT | Signature data |
| claims_json | TEXT | Claims as JSON |
| status | VARCHAR | Signature status |
| api_response_json | TEXT | API response as JSON |
| signed_at | DATETIME | Signing timestamp |
| created_at | DATETIME | Creation timestamp |

## WordPress Integration

### Hooks

The plugin integrates with WordPress using the following hooks:

- `save_post_{post_type}`: Triggers the signing process when a post is saved
- `publish_{post_type}`: Triggers the signing process when a post is published
- `transition_post_status`: Monitors post status changes
- `admin_menu`: Registers admin menu items
- `admin_init`: Initializes admin settings
- `add_meta_boxes`: Adds the Content Signing meta box to post edit screens
- `wp_ajax_{action}`: Handles AJAX requests

### Filters

- `the_content`: Optionally embeds signatures in post content
- `content_signing_should_sign_post`: Controls whether a post should be signed
- `content_signing_content_data`: Modifies content data before signing
- `content_signing_signature_html`: Customizes signature HTML

## API Integration

The plugin communicates with the Content Signing API using the `ContentSigning_API_Client` class. The API client handles:

- Authentication via API keys
- Request formatting
- Response parsing
- Error handling

### API Endpoints

The plugin uses the following API endpoints:

- `/authors`: Manage authors
- `/content/sign`: Sign content
- `/content/verify`: Verify signatures
- `/claims`: Get claim types
- `/directory/keys`: Search public keys
- `/directory/content`: Search signed content

## Hooks and Filters

The plugin provides the following hooks and filters for developers:

### Actions

- `content_signing_before_sign`: Fires before content is signed
- `content_signing_after_sign`: Fires after content is signed
- `content_signing_before_verify`: Fires before a signature is verified
- `content_signing_after_verify`: Fires after a signature is verified
- `content_signing_signature_created`: Fires when a signature is created
- `content_signing_signature_updated`: Fires when a signature is updated
- `content_signing_signature_deleted`: Fires when a signature is deleted

### Filters

- `content_signing_should_sign_post`: Controls whether a post should be signed
- `content_signing_content_data`: Modifies content data before signing
- `content_signing_signature_html`: Customizes signature HTML
- `content_signing_normalize_content`: Customizes content normalization
- `content_signing_calculate_hash`: Customizes hash calculation
- `content_signing_claims`: Modifies claims before signing
- `content_signing_verification_result`: Modifies verification result

## Testing

The plugin includes a comprehensive test suite using PHPUnit. The tests are organized into:

- **Unit Tests**: Test individual components in isolation
- **Integration Tests**: Test the interaction between components
- **WordPress Integration Tests**: Test integration with WordPress

### Running Tests

1. Set up the test environment:
```bash
bin/install-wp-tests.sh wordpress_test root password localhost latest
```

2. Run the tests:
```bash
composer test
```

### Writing Tests

When extending the plugin, it's recommended to write tests for your custom functionality. The plugin provides base test classes to make this easier:

- `ContentSigning_TestCase`: Base test case with common utilities
- `ContentSigning_DB_TestCase`: Test case for database operations
- `ContentSigning_API_Client_TestCase`: Test case for API client operations

## Extending the Plugin

### Adding Custom Claims

You can add custom claims to signatures using the `content_signing_claims` filter:

```php
add_filter('content_signing_claims', function($claims, $post_id) {
    $claims['CustomClaim'] = 'Custom Value';
    return $claims;
}, 10, 2);
```

### Custom Signature Display

You can customize how signatures are displayed using the `content_signing_signature_html` filter:

```php
add_filter('content_signing_signature_html', function($html, $signature) {
    // Customize the HTML
    return $html;
}, 10, 2);
```

### Custom Content Normalization

You can customize how content is normalized before hashing using the `content_signing_normalize_content` filter:

```php
add_filter('content_signing_normalize_content', function($content) {
    // Customize normalization
    return $content;
});
```

### Adding Custom Admin Pages

You can add custom admin pages by extending the `ContentSigning_Admin` class:

```php
class My_Custom_Admin extends ContentSigning_Admin {
    public function register_menu_pages() {
        parent::register_menu_pages();
        
        add_submenu_page(
            'content-signing',
            'Custom Page',
            'Custom Page',
            'manage_options',
            'content-signing-custom',
            array($this, 'render_custom_page')
        );
    }
    
    public function render_custom_page() {
        // Render your custom page
    }
}
```

## Security Considerations

### API Key Storage

API keys are stored encrypted in the database using the `ContentSigning_DB::encrypt()` and `ContentSigning_DB::decrypt()` methods. In a production environment, it's recommended to use a more secure encryption method, such as WordPress's Sodium compatibility layer.

### User Capabilities

The plugin restricts access to sensitive operations based on WordPress user capabilities:

- `manage_options`: Required for managing server profiles and global settings
- `edit_posts`: Required for signing content
- `edit_others_posts`: Required for signing content authored by other users

### Data Validation

All user input is validated and sanitized before use. When extending the plugin, make sure to follow the same practices.

## Internationalization

The plugin is fully translatable using WordPress's i18n functions. All user-facing strings are wrapped in `__()`, `_e()`, or similar functions with the 'content-signing' text domain.

### Translation Files

- `.pot` file: Template for translations
- `.po` files: Translations for specific languages
- `.mo` files: Compiled translations

### Adding Translations

1. Extract translatable strings:
```bash
wp i18n make-pot . languages/content-signing.pot
```

2. Create a translation file:
```bash
wp i18n make-json languages/content-signing-LOCALE.po
```

## Best Practices

### Performance

- Use the `content_signing_should_sign_post` filter to skip unnecessary signing operations
- Consider the impact of signing large numbers of posts
- Use scheduled signing for bulk operations

### Error Handling

- Always check for errors when calling API methods
- Log errors for debugging
- Provide user-friendly error messages

### Compatibility

- Test with different WordPress versions
- Test with different PHP versions
- Test with different themes and plugins

### Security

- Keep API keys secure
- Validate and sanitize all user input
- Follow WordPress security best practices