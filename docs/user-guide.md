# Content Signing for WordPress - User Guide

This guide provides detailed instructions for using the Content Signing for WordPress plugin.

## Table of Contents

- [Introduction](#introduction)
- [Plugin Overview](#plugin-overview)
- [Getting Started](#getting-started)
- [Server Profiles](#server-profiles)
- [Author Profiles](#author-profiles)
- [Global Settings](#global-settings)
- [Signing Content](#signing-content)
- [Verifying Signatures](#verifying-signatures)
- [Post-Specific Settings](#post-specific-settings)
- [Endorsements](#endorsements)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)

## Introduction

Content Signing for WordPress allows authors to sign their content using cryptographic signatures, providing a verifiable link between content, author, and claims. This helps address challenges with declining trust, misinformation, AI content identification, and content theft on the web.

The plugin integrates with external Content Signing API services that handle the cryptographic operations, while providing a seamless WordPress integration for managing signing profiles, configuring signing options, and embedding signatures in content.

## Plugin Overview

The plugin consists of several components:

- **Admin Interface**: Manage server profiles, author profiles, and global settings
- **Post Meta Box**: Configure and view signatures for individual posts
- **Signing Service**: Handles the signing process
- **API Client**: Communicates with the Content Signing API
- **Database**: Stores server profiles, author profiles, and signatures

## Getting Started

After installing and activating the plugin, you'll need to:

1. Set up at least one server profile
2. Create author profiles for your WordPress users
3. Configure global settings
4. Start publishing content with signatures

## Server Profiles

Server profiles connect your WordPress site to Content Signing API servers.

### Adding a Server Profile

1. Go to Content Signing > Server Profiles
2. Click "Add New Server Profile"
3. Enter the following information:
   - **Name**: A descriptive name for the server (e.g., "Primary Signing Server")
   - **API URL**: The base URL of the Content Signing API (e.g., "https://api.contentsigning.example.com")
   - **API Key**: The general API key for the server
4. Check "Set as Default Server" if this is your primary server
5. Click "Save Server Profile"

### Managing Server Profiles

- **Edit**: Click the "Edit" link next to a server profile to modify its settings
- **Delete**: Click the "Delete" link to remove a server profile
- **Set as Default**: Click "Set as Default" to make a server profile the default for new author profiles

## Author Profiles

Author profiles link WordPress users to external signing authors.

### Adding an Author Profile

1. Go to Content Signing > Author Profiles
2. Click "Add New Author Profile"
3. Enter the following information:
   - **WordPress User**: Select the WordPress user to link
   - **Server**: Select the server profile to use
   - **Signing Author ID**: The author ID from the Content Signing API
   - **Author API Key**: The API key for this author
   - **Default Key Type**: The default key type for this author (HUMAN, AI, HUMAN_ASSISTED_AI, AI_ASSISTED_HUMAN)
   - **Default Claims**: Any default claims to include in signatures
   - **Site Endorser**: Check if this author should be available for site-wide endorsements
4. Click "Save Author Profile"

### Managing Author Profiles

- **Edit**: Click the "Edit" link next to an author profile to modify its settings
- **Delete**: Click the "Delete" link to remove an author profile
- **View Signatures**: Click "View Signatures" to see all signatures created by this author

## Global Settings

Global settings control when and how content is signed.

### Configuring Global Settings

1. Go to Content Signing > Settings
2. Configure the following options:

#### General Settings

- **Enable Signing**: Turn content signing on or off globally
- **Post Types**: Select which post types should be signed (Posts, Pages, etc.)
- **Embed Signatures**: Embed signatures in HTML content

#### Signing Timing

- **Sign on Publish**: Sign content when it's first published
- **Sign on Update**: Sign content when it's updated
- **Sign Before Publish**: Sign content X days before scheduled publish date
- **Sign After Publish**: Sign content X days after publish date

#### Endorsements

- **Enable Endorsements**: Enable site-wide endorsements
- **Endorser Profiles**: Select which endorser profiles to use

3. Click "Save Settings"

## Signing Content

Content will be signed automatically based on your configuration settings. The plugin will:

1. Detect when a post is published or updated
2. Check if signing is enabled for this post type
3. Check if signing is enabled for this specific post
4. Sign the content using the author's profile
5. Add any configured endorsement signatures
6. Store the signatures in the database

### Manual Signing

You can also manually sign content from the post edit screen:

1. Edit a post
2. Scroll to the "Content Signing" meta box
3. Click "Sign Content Now"

### Scheduled Signing

If you've configured signing before or after publish:

1. The plugin will schedule the signing operation
2. The content will be signed automatically at the scheduled time
3. You can view the scheduled signing time in the "Content Signing" meta box

## Verifying Signatures

To verify a signature:

1. Edit a post
2. Scroll to the "Content Signing" meta box
3. View the list of signatures
4. Click "Verify" next to a signature

The plugin will check with the signing server to ensure the signature is valid and display the result.

### Verification Results

- **Valid**: The signature is valid and matches the current content
- **Invalid**: The signature is invalid or the content has been modified
- **Error**: There was an error verifying the signature

## Post-Specific Settings

You can override global settings for individual posts:

1. Edit a post
2. Scroll to the "Content Signing" meta box
3. Configure the following options:
   - **Disable Signing**: Prevent this post from being signed
   - **Custom Claims**: Add post-specific claims to the signature

### Custom Claims

Custom claims allow you to add additional metadata to the signature. Common claims include:

- **ContentType**: The type of content (Article, Image, Video, etc.)
- **AuthorType**: The type of author (HUMAN, AI, etc.)
- **License**: The content license (CC-BY, CC-BY-SA, etc.)
- **Source**: The source of the content
- **Custom claims**: Any custom claims supported by your Content Signing API

## Endorsements

Endorsements are additional signatures from designated site endorsers. They can be used to:

- Add organizational verification to content
- Indicate editorial approval
- Add third-party verification

### Configuring Endorsements

1. Create author profiles for your endorsers
2. Check the "Site Endorser" option for these profiles
3. Go to Content Signing > Settings
4. Enable endorsements
5. Select which endorser profiles to use
6. Click "Save Settings"

### Viewing Endorsements

Endorsements appear as additional signatures in the "Content Signing" meta box.

## Troubleshooting

### Common Issues

#### Signing Fails

- Check that the author has a valid author profile
- Verify the API key is correct
- Ensure the Content Signing API server is accessible
- Check the WordPress error log for more details

#### Verification Fails

- The content may have been modified since it was signed
- The signature may be invalid
- The Content Signing API server may be unavailable

#### Scheduled Signing Doesn't Work

- Check that WordPress cron is functioning correctly
- Verify that the scheduled time is in the future
- Ensure the author profile is still valid

### Logging

The plugin logs errors and important events to the WordPress error log. You can enable detailed logging in the plugin settings.

## FAQ

### How secure are the API keys?

API keys are stored encrypted in the WordPress database. However, the security of your keys also depends on the overall security of your WordPress installation.

### Can I sign content retroactively?

Yes, you can manually sign any published content by editing the post and clicking "Sign Content Now" in the Content Signing meta box.

### What happens if a user doesn't have an author profile?

Content authored by users without author profiles will not be signed automatically. You can either create an author profile for the user or manually sign the content using another author profile.

### Can I use multiple signing servers?

Yes, you can create multiple server profiles and assign different authors to different servers.

### How do endorsements work?

Endorsements are additional signatures from designated site endorsers. When endorsements are enabled, the plugin will automatically add endorsement signatures to all signed content.

### Can I customize the signature format?

The signature format is determined by the Content Signing API. However, you can add custom claims to the signature to include additional metadata.