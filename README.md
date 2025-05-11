# WP Stateless Google Cloud Storage Thumbnail Generator

A WordPress plugin that efficiently regenerates image thumbnails for media stored in Google Cloud Storage, with or without WP-Stateless integration.

## Introduction

The GCS Thumbnail Generator plugin solves a common challenge faced by WordPress sites using Google Cloud Storage for media: the regeneration of image thumbnails. When images are stored in GCS rather than on the local server, WordPress's built-in thumbnail generation tools often fail due to compatibility issues with stream wrappers and remote file access.

This plugin provides a robust solution by:

- Downloading the original image from GCS to a temporary location
- Generating thumbnails locally using WordPress's image manipulation functions
- Uploading the generated thumbnails back to GCS with proper naming conventions
- Maintaining correct WordPress metadata for all images

## Features

- **GCS Integration**: Works directly with Google Cloud Storage buckets
- **WP-Stateless Compatible**: Seamless integration with WP-Stateless plugin if installed
- **Batch Processing**: Process images in configurable batches to avoid timeouts
- **Selective Processing**: Choose to process all images, recent uploads, or specific images
- **Fallback Mechanism**: Utilizes WordPress's built-in functions as a fallback if custom generation fails
- **Detailed Logging**: Comprehensive debug information for troubleshooting
- **Admin Interface**: User-friendly admin interface to monitor and control the regeneration process
- **GD Library Compatible**: Works with GD Library through improved file handling
- **Stream Wrapper Workaround**: Avoids issues with PHP stream wrappers (ex. [https://scarff.id.au/blog/2020/wordpress-gcs-plugin-broken-thumbnails/](https://scarff.id.au/blog/2020/wordpress-gcs-plugin-broken-thumbnails/))

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- GD Library or ImageMagick for image manipulation
- Google Cloud Storage bucket for media storage
- Google Cloud Storage API credentials

## Installation

1. Upload the gcs-thumbnail-generator folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the GCS credentials in Settings → Media
4. Access the tool from Tools → GCS Thumbnails

## Configuration

The plugin requires Google Cloud Storage credentials to function. You can configure these in WordPress Settings → Media:

- **GCS Credentials File Path**: Path to your service account JSON credentials file on the server
- **GCS Credentials JSON**: Alternatively, you can paste the credentials JSON directly
- **GCS Project ID**: Your Google Cloud Project ID
- **GCS Bucket Name**: The name of your GCS bucket where media is stored

## Usage

1. Navigate to Tools → GCS Thumbnails in your WordPress admin
2. Select processing options:
   - **All Images**: Process all images in the media library
   - **Recent Images (Today)**: Process images uploaded in the last 2 days
   - **Recent Images (30 days)**: Process images uploaded in the last 30 days
   - **Images with Missing Thumbnails**: Detect and process images with missing thumbnails
   - **Specific Image ID**: Process a single image by ID
3. Set batch size (10-100 images per batch)
4. Enable debug mode for detailed information if needed
5. Click "Start Regeneration" to begin the process
6. Monitor progress and logs in real-time on the admin page

## Integration with WP-Stateless

The plugin integrates with WP-Stateless in multiple ways:

- If WP-Stateless is active, the plugin will use its media handling functions for improved compatibility
- The plugin respects WP-Stateless file naming conventions and prefixes
- For deletion, the plugin attempts to use WP-Stateless methods first
- When regenerating thumbnails, the plugin maintains WP-Stateless metadata

## Recent Improvements

The plugin has undergone significant improvements to enhance reliability:

- **GD Library Compatibility**: Improved file handling to work better with GD Library by avoiding file handles
- **Stream Wrapper Workaround**: Implemented direct file content handling to avoid issues with PHP stream wrappers
- **Fallback Mechanism**: Added WordPress's built-in wp_generate_attachment_metadata() as a fallback
- **Enhanced Error Handling**: Added comprehensive try/catch blocks throughout for better error detection
- **Detailed Debugging**: Added more detailed debugging information for troubleshooting
- **AJAX Reliability**: Improved AJAX handlers to provide more precise error information
- **Memory Management**: Optimized file handling to prevent memory issues with large images
- **Today Images Option**: Added option to process images uploaded within the last 2 days

## Troubleshooting

### Debug Mode
Enable debug mode when running the regeneration process to get detailed information about what's happening. This can help identify issues with specific images or configuration problems.

- Verify GCS credentials are correct
- Check that the original image exists in GCS and is accessible
- Ensure GD Library or ImageMagick is installed and working

### AJAX Errors
- Check PHP error logs for more details
- Increase PHP memory limit if processing large images
- Adjust PHP max execution time if timeouts occur

### Upload Failures
- Verify bucket permissions allow write access
- Check that authentication token is valid
- Try reducing batch size to troubleshoot individual images

## Technical Notes

### File Handling Approach
The plugin uses a direct file content approach rather than file handles to avoid issues with stream wrappers.

### Thumbnail Generation
The plugin attempts two methods for thumbnail generation:

1. **Custom Method**: Uses WordPress's image editor API directly with carefully managed file paths
2. **Fallback Method**: Uses WordPress's built-in wp_generate_attachment_metadata() function
