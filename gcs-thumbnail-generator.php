 class GCS_Thumbnail_Generator {
     
     /**
      * GCS Credentials Configuration
      */

     private $gcs_config = array(
        'credentials_file' => '',  // Path to service account JSON file
        'credentials_json' => '', // JSON content as string (alternative to file)
        'project_id' => '',        // Your GCS project ID
        'bucket' => '', // Your bucket name
    );
   
        /**
         * Constructor
        */
        public function __construct() {
            // Set up config
            $this->gcs_config = apply_filters('gcs_thumbnail_generator_config', $this->gcs_config);
            
            // Register the admin page
            add_action('admin_menu', array($this, 'add_admin_menu'));
            
            // Register settings
            add_action('admin_init', array($this, 'add_settings_fields'));
            
            // Register our AJAX handlers
            add_action('wp_ajax_gcs_regenerate_thumbnails', array($this, 'ajax_regenerate_thumbnails'));
            add_action('wp_ajax_gcs_get_regeneration_status', array($this, 'ajax_get_regeneration_status'));
            
            // Add admin scripts
            add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
            
            // Integrate with Force Regenerate Thumbnails if available
            add_action('plugins_loaded', array($this, 'integrate_with_force_regen'));
        }
        
        /**
         * Add settings field for GCS credentials
        */
        public function add_settings_fields() {
            // Create section in Media Settings
            add_settings_section(
                'gcs_thumbnails_section',
                'GCS Thumbnail Generator Settings',
                array($this, 'settings_section_callback'),
                'media'
            );
            
            // Add fields
            add_settings_field(
                'gcs_credentials_file',
                'GCS Credentials File Path',
                array($this, 'credentials_file_callback'),
                'media',
                'gcs_thumbnails_section'
            );
            
            add_settings_field(
                'gcs_credentials_json',
                'GCS Credentials JSON',
                array($this, 'credentials_json_callback'),
                'media',
                'gcs_thumbnails_section'
            );
            
            add_settings_field(
                'gcs_project_id',
                'GCS Project ID',
                array($this, 'project_id_callback'),
                'media',
                'gcs_thumbnails_section'
            );
            
            add_settings_field(
                'gcs_bucket',
                'GCS Bucket Name',
                array($this, 'bucket_callback'),
                'media',
                'gcs_thumbnails_section'
            );
            
            // Register settings
            register_setting('media', 'gcs_credentials_file');
            register_setting('media', 'gcs_credentials_json');
            register_setting('media', 'gcs_project_id');
            register_setting('media', 'gcs_bucket');
            
            // Load saved settings
            $this->gcs_config['credentials_file'] = get_option('gcs_credentials_file', '');
            $this->gcs_config['credentials_json'] = get_option('gcs_credentials_json', '');
            $this->gcs_config['project_id'] = get_option('gcs_project_id', '');
            $this->gcs_config['bucket'] = get_option('gcs_bucket', 'leadstartorg');
        }
        
        /**
         * Settings section description
        */
        public function settings_section_callback() {
            echo '<p>Configure Google Cloud Storage credentials for thumbnail generation.</p>';
            echo '<p>You can either provide a path to the credentials file OR paste the JSON credentials directly.</p>';
        }
        
        /**
         * Credentials file field callback
        */
        public function credentials_file_callback() {
            $value = get_option('gcs_credentials_file', '');
            echo '<input type="text" id="gcs_credentials_file" name="gcs_credentials_file" value="' . esc_attr($value) . '" class="regular-text">';
            echo '<p class="description">Full path to your service account JSON credentials file.</p>';
        }
        
        /**
         * Credentials JSON field callback
        */
        public function credentials_json_callback() {
            $value = get_option('gcs_credentials_json', '');
            echo '<textarea id="gcs_credentials_json" name="gcs_credentials_json" class="large-text code" rows="5">' . esc_textarea($value) . '</textarea>';
            echo '<p class="description">OR paste your service account JSON credentials here.</p>';
        }
        
        /**
         * Project ID field callback
        */
        public function project_id_callback() {
            $value = get_option('gcs_project_id', '');
            echo '<input type="text" id="gcs_project_id" name="gcs_project_id" value="' . esc_attr($value) . '" class="regular-text">';
            echo '<p class="description">Your Google Cloud Project ID.</p>';
        }
        
        /**
         * Bucket field callback
        */
        public function bucket_callback() {
            $value = get_option('gcs_bucket', 'leadstartorg');
            echo '<input type="text" id="gcs_bucket" name="gcs_bucket" value="' . esc_attr($value) . '" class="regular-text">';
            echo '<p class="description">Your Google Cloud Storage bucket name.</p>';
        }
        
        /**
         * Hook into Force Regenerate Thumbnails to handle GCS uploads
        */
        public function integrate_with_force_regen() {
            // Only run if Force Regenerate Thumbnails is active
            if (class_exists('ForceRegenerateThumbnails')) {
                add_filter('regenerate_thumbs_pre_delete', array($this, 'pre_delete_thumbnail'), 10, 1);
                add_filter('regenerate_thumbs_post_update', array($this, 'post_update_metadata'), 10, 2);
                add_filter('regenerate_thumbs_skip_image', array($this, 'should_skip_image'), 10, 2);
            }
        }
        
        /**
         * Before Force Regenerate deletes a thumbnail
        */
        public function pre_delete_thumbnail($thumb_path) {
            // Convert local path to GCS URL and delete from GCS
            $local_path_parts = explode('/wp-content/uploads/', $thumb_path);
            if (count($local_path_parts) === 2) {
                $gcs_url = 'https://storage.googleapis.com/' . $this->gcs_config['bucket'] . '/' . $local_path_parts[1];
                $this->delete_from_gcs($gcs_url);
            }
            return $thumb_path;
        }
        
        /**
         * After Force Regenerate updates metadata
        */
        public function post_update_metadata($attachment_id, $regenerated_path) {
            // Get updated metadata
            $metadata = wp_get_attachment_metadata($attachment_id);
            
            // Upload each thumbnail to GCS
            if (!empty($metadata['sizes'])) {
                $upload_dir = wp_upload_dir();
                $file_path = $metadata['file'];
                $file_dir = dirname($file_path);
                
                foreach ($metadata['sizes'] as $size => $size_data) {
                    if (empty($size_data['file'])) {
                        continue;
                    }
                    
                    // Local path to thumbnail
                    $thumb_path = $upload_dir['basedir'] . '/' . $file_dir . '/' . $size_data['file'];
                    
                    // Verify file exists and has content
                    if (!file_exists($thumb_path) || filesize($thumb_path) === 0) {
                        continue;
                    }
                    
                    // GCS path 
                    $gcs_upload_url = 'https://storage.googleapis.com/' . $this->gcs_config['bucket'] . '/' . $file_dir . '/' . $size_data['file'];
                    
                    // Upload to GCS
                    $this->improved_upload_file_to_gcs($thumb_path, $gcs_upload_url);
                }
            }
            
            return $attachment_id;
        }
        
        /**
         * Determine if an image should be skipped in Force Regenerate Thumbnails
        */
        public function should_skip_image($skip, $id) {
            // Skip non-GCS images
            $url = wp_get_attachment_url($id);
            if (strpos($url, 'storage.googleapis.com/' . $this->gcs_config['bucket']) === false) {
                return true;
            }
            
            return $skip;
        }
        
        /**
         * Add the admin menu item
        */
        public function add_admin_menu() {
            add_management_page(
                'GCS Thumbnail Generator',
                'GCS Thumbnails',
                'manage_options',
                'gcs-thumbnail-generator',
                array($this, 'render_admin_page')
            );
        }
        
        /**
         * Enqueue necessary scripts and styles
        */
        public function enqueue_scripts($hook) {
            if ($hook !== 'tools_page_gcs-thumbnail-generator') {
                return;
            }
            
            // Inline scripts and styles for simplicity
            add_action('admin_footer', array($this, 'output_scripts'));
            add_action('admin_footer', array($this, 'output_styles'));
        }
        
        /**
         * Output scripts for the admin page
        */
        public function output_scripts() {
            ?>
            <script>
            jQuery(document).ready(function($) {
                // Show/hide the specific ID field
                $('#image-selection').on('change', function() {
                    if ($(this).val() === 'specific') {
                        $('#specific-id-container').show();
                    } else {
                        $('#specific-id-container').hide();
                    }
                });
                
                // Start regeneration
                $('#start-regeneration').on('click', function() {
                    $(this).hide();
                    $('#stop-regeneration').show();
                    $('#progress-text').text('Starting regeneration...');
                    $('#regeneration-log').empty();
                    
                    // Start the regeneration process
                    processNextBatch(0);
                });
                
                // Stop regeneration
                $('#stop-regeneration').on('click', function() {
                    $(this).hide();
                    $('#start-regeneration').show();
                    $('#progress-text').text('Regeneration stopped by user');
                });
                
                // Process a batch of images
                function processNextBatch(offset) {
                    if ($('#stop-regeneration').is(':hidden')) {
                        return; // Process stopped by user
                    }
                    
                    var data = {
                        action: 'gcs_regenerate_thumbnails',
                        nonce: $('#gcs-thumbnails-nonce').val(),
                        image_selection: $('#image-selection').val(),
                        specific_id: $('#specific-id').val(),
                        batch_size: $('#batch-size').val(),
                        offset: offset,
                        debug_mode: $('#debug-mode').is(':checked')
                    };
                    
                    $.post(ajaxurl, data, function(response) {
                        if (!response.success) {
                            $('#progress-text').text('Error: ' + (response.data || 'Unknown error'));
                            $('#stop-regeneration').hide();
                            $('#start-regeneration').show();
                            return;
                        }
                        
                        // Update progress
                        var progress = Math.min(100, Math.round((response.data.offset / response.data.total) * 100));
                        $('#progress-bar').css('width', progress + '%');
                        $('#progress-text').text(
                            'Processed ' + response.data.offset + ' of ' + response.data.total + ' images (' + progress + '%)'
                        );
                        
                        // Log messages
                        if (response.data.log_messages && response.data.log_messages.length) {
                            var log = $('#regeneration-log');
                            $.each(response.data.log_messages, function(i, message) {
                                log.prepend('<div>' + message + '</div>');
                            });
                        }
                        
                        // Log debug info if available
                        if (response.data.debug_info && response.data.debug_info.length) {
                            var log = $('#regeneration-log');
                            log.prepend('<div class="debug-header">Debug Information:</div>');
                            $.each(response.data.debug_info, function(i, message) {
                                log.prepend('<div class="debug-message">' + message + '</div>');
                            });
                        }
                        
                        // Continue or finish
                        if (response.data.done) {
                            $('#progress-text').text('Regeneration complete. Processed ' + response.data.offset + ' images.');
                            $('#stop-regeneration').hide();
                            $('#start-regeneration').show();
                        } else {
                            // Continue with next batch
                            processNextBatch(response.data.offset);
                        }
                    }).fail(function(xhr, status, error) {
                        $('#progress-text').text('AJAX Error: ' + error);
                        $('#stop-regeneration').hide();
                        $('#start-regeneration').show();
                    });
                }
            });
            </script>
            <?php
        }
        
        /**
         * Output styles for the admin page
        */
        public function output_styles() {
            ?>
            <style>
            .gcs-thumbnail-generator-container {
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
                margin-top: 20px;
            }
            
            .gcs-options, .gcs-progress {
                background: #fff;
                padding: 20px;
                border-radius: 5px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .gcs-options {
                flex: 0 0 300px;
            }
            
            .gcs-progress {
                flex: 1;
                min-width: 300px;
            }
            
            .form-group {
                margin-bottom: 15px;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
            }
            
            .form-group select, .form-group input {
                width: 100%;
            }
            
            .progress-bar-container {
                width: 100%;
                height: 20px;
                background-color: #f1f1f1;
                border-radius: 3px;
                margin-bottom: 10px;
            }
            
            .progress-bar {
                height: 100%;
                background-color: #0073aa;
                border-radius: 3px;
                width: 0%;
                transition: width 0.3s ease;
            }
            
            #progress-text {
                margin-bottom: 20px;
                font-weight: 500;
            }
            
            #log-container {
                border-top: 1px solid #eee;
                padding-top: 10px;
                max-height: 300px;
                overflow-y: auto;
            }
            
            #regeneration-log {
                font-family: monospace;
                font-size: 12px;
                line-height: 1.4;
            }
            
            #regeneration-log div {
                padding: 2px 0;
                border-bottom: 1px solid #f5f5f5;
            }
    
            .debug-header {
                font-weight: bold;
                color: #0073aa;
                margin-top: 10px;
                border-top: 1px solid #ccc;
                padding-top: 5px;
            }
    
            .debug-message {
                color: #666;
                padding-left: 10px;
                font-size: 11px;
            }
            </style>
            <?php
        }
        
        /**
         * Render the admin page
        */
        public function render_admin_page() {
            ?>
            <div class="wrap">
                <h1>GCS Thumbnail Generator</h1>
                
                <div class="notice notice-info">
                    <p><strong>Configuration Status:</strong>
                    <?php
                    if (empty($this->gcs_config['credentials_file']) && empty($this->gcs_config['credentials_json'])) {
                        echo ' <span style="color: red;">❌ Missing GCS credentials. Please configure in <a href="' . admin_url('options-media.php') . '">Media Settings</a>.</span>';
                    } else {
                        echo ' <span style="color: green;">✓ GCS credentials configured.</span>';
                    }
                    ?>
                    </p>
                    <p>Using bucket: <strong><?php echo esc_html($this->gcs_config['bucket']); ?></strong></p>
                </div>
                
                <div class="gcs-thumbnail-generator-container">
                    <div class="gcs-options">
                        <h2>Options</h2>
                        <form id="gcs-thumbnail-form">
                            <?php wp_nonce_field('gcs_thumbnail_generator', 'gcs-thumbnails-nonce'); ?>
                            
                            <div class="form-group">
                                <label for="image-selection">Select Images:</label>
                                <select id="image-selection" name="image_selection">
                                    <option value="all">All Images</option>
                                    <option value="today">Recent Images (Today)</option>
                                    <option value="recent">Recent Images (30 days)</option>
                                    <option value="missing">Images with Missing Thumbnails</option>
                                    <option value="specific">Specific Image ID</option>
                                </select>
                            </div>
                            
                            <div id="specific-id-container" class="form-group" style="display: none;">
                                <label for="specific-id">Image ID:</label>
                                <input type="number" id="specific-id" name="specific_id" min="1">
                            </div>
                            
                            <div class="form-group">
                                <label for="batch-size">Batch Size:</label>
                                <select id="batch-size" name="batch_size">
                                    <option value="10">10 images per batch</option>
                                    <option value="20">20 images per batch</option>
                                    <option value="50" selected>50 images per batch</option>
                                    <option value="100">100 images per batch</option>
                                </select>
                            </div>
    
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="debug-mode" name="debug_mode">
                                    Enable Debug Mode
                                </label>
                            </div>
                            
                            <div class="form-group">
                                <button type="button" id="start-regeneration" class="button button-primary">Start Regeneration</button>
                                <button type="button" id="stop-regeneration" class="button" style="display: none;">Stop Regeneration</button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="gcs-progress">
                        <h2>Progress</h2>
                        <div class="progress-bar-container">
                            <div id="progress-bar" class="progress-bar"></div>
                        </div>
                        <div id="progress-text">Ready to start</div>
                        
                        <div id="log-container">
                            <h3>Log</h3>
                            <div id="regeneration-log"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }
        
        /**
         * AJAX handler for regenerating thumbnails
        */
        public function ajax_regenerate_thumbnails() {
            // Check nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gcs_thumbnail_generator')) {
                wp_send_json_error('Invalid nonce');
                return;
            }
            
            // Check if user has permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Insufficient permissions');
                return;
            }
            
            try {
                // Get parameters
                $image_selection = isset($_POST['image_selection']) ? sanitize_text_field($_POST['image_selection']) : 'all';
                $specific_id = isset($_POST['specific_id']) ? intval($_POST['specific_id']) : 0;
                $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
                $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
                $debug_mode = isset($_POST['debug_mode']) && $_POST['debug_mode'] === 'true';
                
                // For debugging - check GD support
                $debug_info = array();
                if ($debug_mode) {
                    if (function_exists('gd_info')) {
                        $debug_info[] = 'GD Library is available: ' . json_encode(gd_info());
                    } else {
                        $debug_info[] = 'GD Library is NOT available';
                    }
                    
                    if (extension_loaded('imagick')) {
                        $debug_info[] = 'ImageMagick extension is available';
                    } else {
                        $debug_info[] = 'ImageMagick extension is NOT available';
                    }
                }
                
                // Start a new batch or continue processing
                $result = $this->process_batch($image_selection, $specific_id, $batch_size, $offset, $debug_mode);
                
                // Add debug info if enabled
                if ($debug_mode && !empty($debug_info)) {
                    if (!isset($result['debug_info'])) {
                        $result['debug_info'] = array();
                    }
                    $result['debug_info'] = array_merge($result['debug_info'], $debug_info);
                }
                
                wp_send_json_success($result);
            } catch (Exception $e) {
                $error_message = 'Error processing batch: ' . $e->getMessage();
                error_log($error_message);
                wp_send_json_error($error_message);
            }
        }
        
        /**
         * Process a batch of images
        */
        public function process_batch($image_selection, $specific_id, $batch_size, $offset, $debug_mode = false) {
            // Get images to process
            $query_args = array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => $batch_size,
                'offset' => $offset,
                'fields' => 'ids',
                'order' => 'DESC',
                'orderby' => 'date',
            );
            
            // Modify query based on selection
            if ($image_selection === 'specific' && $specific_id > 0) {
                $query_args['p'] = $specific_id;
                $query_args['posts_per_page'] = 1;
            } elseif ($image_selection === 'today') {
                $query_args['date_query'] = array(
                    array(
                        'after' => '2 days ago',
                    ),
                );
            } elseif ($image_selection === 'recent') {
                $query_args['date_query'] = array(
                    array(
                        'after' => '30 days ago',
                    ),
                );
            } elseif ($image_selection === 'missing') {
                // This is more complex, but we'll filter during processing
                // No changes to query for now
            }
            
            $images = new WP_Query($query_args);
            $image_ids = $images->posts;
            
            if (empty($image_ids)) {
                return array(
                    'success' => true,
                    'done' => true,
                    'message' => 'No more images to process',
                    'processed' => 0,
                    'total' => 0,
                    'offset' => $offset,
                );
            }
            
            $processed_count = 0;
            $success_count = 0;
            $error_count = 0;
            $log_messages = array();
            $debug_info = array();
            
            foreach ($image_ids as $image_id) {
                try {
                    $result = $this->regenerate_image_thumbnails($image_id);
                    $processed_count++;
                    
                    if ($result['success']) {
                        $success_count++;
                        $log_messages[] = "ID {$image_id}: Successfully regenerated " . count($result['sizes']) . " thumbnail sizes";
                    } else {
                        $error_count++;
                        $log_messages[] = "ID {$image_id}: Error - " . $result['message'];
                    }
                    
                    // Add debug info if enabled
                    if ($debug_mode && !empty($result['debug'])) {
                        $debug_info = array_merge($debug_info, $result['debug']);
                    }
                } catch (Exception $e) {
                    $error_count++;
                    $log_messages[] = "ID {$image_id}: Exception - " . $e->getMessage();
                    if ($debug_mode) {
                        $debug_info[] = "Exception trace for ID {$image_id}: " . $e->getTraceAsString();
                    }
                }
            }
            
            // Get the total number of images (for progress calculation)
            $total_query = new WP_Query(array_merge(
                $query_args,
                array(
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                    'no_found_rows' => false,
                )
            ));
            $total_images_count = $total_query->found_posts;
            
            // Check if there are more images to process
            $done = count($image_ids) < $batch_size;
            
            $response = array(
                'success' => true,
                'done' => $done,
                'message' => "{$processed_count} images processed. {$success_count} successful, {$error_count} errors.",
                'processed' => $processed_count,
                'total' => $total_images_count,
                'offset' => $offset + $processed_count,
                'log_messages' => $log_messages,
            );
            
            // Add debug info if enabled
            if ($debug_mode && !empty($debug_info)) {
                $response['debug_info'] = $debug_info;
            }
            
            return $response;
        }
        
        /**
         * Generate an auth token for GCS
        * 
        * @return string GCS authorization token or empty string on failure
        */
        private function get_gcs_auth_token() {
            // Path to credentials file
            $credentials_file = $this->gcs_config['credentials_file'];
            $credentials_json = $this->gcs_config['credentials_json'];
            
            // If neither credentials file nor JSON is provided, return empty
            if (empty($credentials_file) && empty($credentials_json)) {
                return '';
            }
            
            try {
                // Load credentials from file or JSON
                if (!empty($credentials_file) && file_exists($credentials_file)) {
                    $credentials = json_decode(file_get_contents($credentials_file), true);
                } elseif (!empty($credentials_json)) {
                    $credentials = json_decode($credentials_json, true);
                } else {
                    return '';
                }
                
                // Check if credentials are valid
                if (empty($credentials['client_email']) || empty($credentials['private_key'])) {
                    return '';
                }
                
                // Current time
                $now = time();
                
                // Create JWT header
                $header = json_encode([
                    'alg' => 'RS256',
                    'typ' => 'JWT'
                ]);
                
                // Create JWT claim set
                $claim_set = json_encode([
                    'iss' => $credentials['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/devstorage.read_write',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'exp' => $now + 3600,
                    'iat' => $now
                ]);
                
                // Encode header and claim set
                $base64_header = $this->base64url_encode($header);
                $base64_claim_set = $this->base64url_encode($claim_set);
                
                // Create signature
                $jwt_unsigned = $base64_header . '.' . $base64_claim_set;
                $private_key = $credentials['private_key'];
                
                // Sign JWT
                $signature = '';
                openssl_sign($jwt_unsigned, $signature, $private_key, 'SHA256');
                $base64_signature = $this->base64url_encode($signature);
                
                // Complete JWT
                $jwt = $jwt_unsigned . '.' . $base64_signature;
                
                // Exchange JWT for access token
                $response = wp_remote_post('https://oauth2.googleapis.com/token', [
                    'body' => [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $jwt
                    ]
                ]);
                
                if (is_wp_error($response)) {
                    return '';
                }
                
                $body = json_decode(wp_remote_retrieve_body($response), true);
                
                if (isset($body['access_token'])) {
                    return $body['access_token'];
                }
            } catch (Exception $e) {
                error_log('GCS Auth Token Error: ' . $e->getMessage());
            }
            
            return '';
        }
        
        /**
         * Base64URL encode (JWT specific encoding)
        */
        private function base64url_encode($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        }
        
        /**
         * Regenerate thumbnails for a specific image
        */
        public function regenerate_image_thumbnails($attachment_id) {
            // Initialize result
            $result = array(
                'success' => false,
                'message' => '',
                'sizes' => array(),
                'debug' => array(),
            );
            
            try {
                // Verify the attachment exists
                if (!wp_attachment_is_image($attachment_id)) {
                    $result['message'] = 'Attachment is not an image or does not exist';
                    return $result;
                }
                
                // Get the full image path from GCS
                $full_url = wp_get_attachment_url($attachment_id);
                
                // If it's not a GCS URL, skip it
                if (strpos($full_url, 'storage.googleapis.com/' . $this->gcs_config['bucket']) === false) {
                    $result['message'] = 'Not a GCS image';
                    return $result;
                }
                
                $result['debug'][] = "Processing image: " . $full_url;
                
                // Create a temporary directory with proper permissions
                $upload_dir = wp_upload_dir();
                $temp_dir = $upload_dir['basedir'] . '/gcs-temp';
                if (!file_exists($temp_dir)) {
                    wp_mkdir_p($temp_dir);
                    @chmod($temp_dir, 0755);
                }
                
                // Get the file name from the URL
                $file_name = basename($full_url);
                $temp_file = $temp_dir . '/' . $file_name;
                
                // Download the image from GCS
                $download_result = $this->download_file_from_gcs($full_url, $temp_file);
                if (!$download_result['success']) {
                    $result['message'] = 'Failed to download image from GCS: ' . $download_result['message'];
                    return $result;
                }
                
                $result['debug'][] = "Downloaded original image to: " . $temp_file;
                
                // Get the metadata for this attachment
                $metadata = wp_get_attachment_metadata($attachment_id);
                if (!is_array($metadata)) {
                    $metadata = array();
                }
                
                // If the metadata doesn't have file info, add it
                if (empty($metadata['file'])) {
                    // Get the relative path of the file
                    $file_path = $this->get_relative_path($attachment_id);
                    $metadata['file'] = $file_path;
                }
                
                // Extract prefix from the filename
                $parts = $this->extract_gcs_prefix($file_name);
                $prefix = $parts['prefix'];
                $base_filename = $parts['filename'];
                
                $result['debug'][] = "Image prefix: " . $prefix . ", base filename: " . $base_filename;
                
                // Delete existing thumbnails
                $thumbnails_deleted = $this->delete_existing_thumbnails($attachment_id, $metadata);
                $result['debug'][] = "Deleted thumbnails: " . implode(', ', $thumbnails_deleted);
                
                // Get the file directory for storage
                //$file_dir = dirname($metadata['file']);

                $original_path = str_replace('https://storage.googleapis.com/' . $this->gcs_config['bucket'] . '/', '', $full_url);
                $file_dir = dirname($original_path);
                $result['debug'][] = "Original directory path: " . $file_dir;
                
                // Track if we successfully generated any thumbnails with custom method
                $generated_any_thumbnails = false;
                
                // First attempt: Custom thumbnail generation approach
                try {
                    // Get all registered image sizes
                    $sizes = $this->get_custom_image_sizes();
                    
                    // Create and upload thumbnails directly instead of using wp_generate_attachment_metadata
                    $generated_sizes = array();
                    
                    // Use WP's image editor to resize
                    $editor = wp_get_image_editor($temp_file);
                    if (is_wp_error($editor)) {
                        throw new Exception('Cannot create image editor: ' . $editor->get_error_message());
                    }
                    
                    // Get the original image dimensions
                    $editor->load();
                    $original_size = $editor->get_size();
                    $orig_width = $original_size['width'];
                    $orig_height = $original_size['height'];
                    
                    // Store original metadata
                    if (empty($metadata['width']) || empty($metadata['height'])) {
                        $metadata['width'] = $orig_width;
                        $metadata['height'] = $orig_height;
                    }
                    
                    // Update metadata for image sizes
                    if (!isset($metadata['sizes'])) {
                        $metadata['sizes'] = array();
                    }
                    
                    // Process each size
                    foreach ($sizes as $size => $size_data) {
                        $width = $size_data['width'];
                        $height = $size_data['height'];
                        $crop = $size_data['crop'];
                        
                        // Skip if either dimension is 0 or larger than original size
                        if (($width == 0 && $height == 0) || 
                            ($width > $orig_width && $height > $orig_height)) {
                            continue;
                        }
                        
                        // Create a new image editor for each size to avoid issues
                        $editor = wp_get_image_editor($temp_file);
                        if (is_wp_error($editor)) {
                            $result['debug'][] = "Error creating editor for {$size}: " . $editor->get_error_message();
                            continue;
                        }
                        
                        // Calculate dimensions
                        $dimensions = image_resize_dimensions($orig_width, $orig_height, $width, $height, $crop);
                        if (!$dimensions) {
                            $result['debug'][] = "Could not calculate dimensions for {$size}";
                            continue;
                        }
                        
                        list($dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h) = $dimensions;
                        
                        // Resize the image
                        $resize_result = $editor->resize($width, $height, $crop);
                        if (is_wp_error($resize_result)) {
                            $result['debug'][] = "Error resizing {$size}: " . $resize_result->get_error_message();
                            continue;
                        }
                        
                        // Get actual dimensions after resize
                        $resized = $editor->get_size();
                        $actual_width = $resized['width'];
                        $actual_height = $resized['height'];
                        
                        // Create the filename for the thumbnail
                        $path_parts = pathinfo($base_filename);
                        $ext = isset($path_parts['extension']) ? '.' . $path_parts['extension'] : '';
                        $name = $path_parts['filename'];
                        
                        // Format the thumbnail filename with dimensions
                        $thumb_name = $name . '-' . $actual_width . 'x' . $actual_height . $ext;
                        
                        // Add the prefix if it exists
                        if (!empty($prefix)) {
                            $thumb_name = $prefix . $thumb_name;
                        }
                        
                        $thumb_path = $temp_dir . '/' . $thumb_name;
                        
                        // Save the thumbnail
                        $save_result = $editor->save($thumb_path);
                        if (is_wp_error($save_result)) {
                            $result['debug'][] = "Error saving {$size}: " . $save_result->get_error_message();
                            continue;
                        }
                        
                        // Verify the thumbnail file exists and has content
                        if (!file_exists($thumb_path) || filesize($thumb_path) === 0) {
                            $result['debug'][] = "Generated thumbnail is empty or does not exist: {$thumb_path}";
                            continue;
                        }
                        
                        $result['debug'][] = "Generated thumbnail: {$thumb_name}";
                        
                        // Update metadata for this size
                        $metadata['sizes'][$size] = array(
                            'file' => $thumb_name,
                            'width' => $actual_width,
                            'height' => $actual_height,
                            'mime-type' => get_post_mime_type($attachment_id),
                        );
                        
                        // Upload to GCS - use the original directory path
                        $gcs_upload_url = 'https://storage.googleapis.com/' . $this->gcs_config['bucket'] . '/' . $file_dir . '/' . $thumb_name;
                        
                        $result['debug'][] = "Attempting to upload to GCS: {$gcs_upload_url}";
                        
                        $upload_result = $this->improved_upload_file_to_gcs($thumb_path, $gcs_upload_url);
                        
                        if ($upload_result['success']) {
                            $generated_sizes[] = $size;
                            $result['debug'][] = "Successfully uploaded {$size} to GCS";
                            $generated_any_thumbnails = true;
                        } else {
                            $result['debug'][] = "Failed to upload {$size}: " . $upload_result['message'];
                        }
                    }
                    
                    $result['success'] = !empty($generated_sizes);
                    $result['message'] = !empty($generated_sizes) ? 
                        'Successfully regenerated ' . count($generated_sizes) . ' thumbnail sizes' : 
                        'No thumbnails were generated with custom method';
                    $result['sizes'] = $generated_sizes;
                    
                } catch (Exception $e) {
                    $result['debug'][] = "Error in custom thumbnail generation: " . $e->getMessage();
                    $result['success'] = false;
                    $result['message'] = 'Custom thumbnail generation failed: ' . $e->getMessage();
                }
                
                // FALLBACK: If custom method didn't generate any thumbnails, try WordPress's built-in function
                if (!$generated_any_thumbnails) {
                    $result['debug'][] = "Attempting fallback with wp_generate_attachment_metadata()";
                    
                    try {
                        // Store the original metadata
                        $original_metadata = $metadata;
                        
                        // Use WordPress's built-in function to generate attachment metadata including thumbnails
                        $fallback_metadata = wp_generate_attachment_metadata($attachment_id, $temp_file);
                        
                        if (is_wp_error($fallback_metadata)) {
                            throw new Exception($fallback_metadata->get_error_message());
                        }
                        
                        if (empty($fallback_metadata) || !is_array($fallback_metadata)) {
                            throw new Exception('wp_generate_attachment_metadata() returned empty metadata');
                        }
                        
                        $result['debug'][] = "wp_generate_attachment_metadata() generated metadata successfully";
                        
                        // Keep track of which sizes were generated
                        $fallback_sizes = array();
                        
                        // Merge new size metadata with original metadata
                        if (!empty($fallback_metadata['sizes']) && is_array($fallback_metadata['sizes'])) {
                            // For each size generated by wp_generate_attachment_metadata
                            foreach ($fallback_metadata['sizes'] as $size => $size_data) {
                                if (!isset($original_metadata['sizes'][$size]) || 
                                    $original_metadata['sizes'][$size] != $size_data) {
                                    
                                    // This is a new or updated size
                                    $metadata['sizes'][$size] = $size_data;
                                    $fallback_sizes[] = $size;
                                    
                                    $thumb_file = $size_data['file'];
                                    $result['debug'][] = "Fallback generated thumbnail: {$thumb_file}";
                                    
                                    // Check if this thumbnail exists in the temp directory
                                    // WordPress might have created it in the uploads directory
                                    $upload_dir = wp_upload_dir();
                                    $possible_thumbnail_paths = array(
                                        $temp_dir . '/' . $thumb_file,
                                        $upload_dir['path'] . '/' . $thumb_file,
                                        $upload_dir['basedir'] . '/' . dirname($metadata['file']) . '/' . $thumb_file
                                    );
                                    
                                    $thumb_path = null;
                                    foreach ($possible_thumbnail_paths as $path) {
                                        if (file_exists($path)) {
                                            $thumb_path = $path;
                                            break;
                                        }
                                    }
                                    
                                    if ($thumb_path) {
                                        // Upload to GCS
                                        $gcs_upload_url = 'https://storage.googleapis.com/' . $this->gcs_config['bucket'] . '/' . $file_dir . '/' . $thumb_file;
                                        
                                        $result['debug'][] = "Attempting to upload fallback thumbnail to GCS: {$gcs_upload_url}";
                                        
                                        $upload_result = $this->improved_upload_file_to_gcs($thumb_path, $gcs_upload_url);
                                        
                                        if ($upload_result['success']) {
                                            $result['debug'][] = "Successfully uploaded fallback {$size} to GCS";
                                        } else {
                                            $result['debug'][] = "Failed to upload fallback {$size}: " . $upload_result['message'];
                                            // Remove from successful sizes if upload failed
                                            $key = array_search($size, $fallback_sizes);
                                            if ($key !== false) {
                                                unset($fallback_sizes[$key]);
                                            }
                                        }
                                    } else {
                                        $result['debug'][] = "Could not find fallback thumbnail file for {$size}";
                                        // Remove from successful sizes if file not found
                                        $key = array_search($size, $fallback_sizes);
                                        if ($key !== false) {
                                            unset($fallback_sizes[$key]);
                                        }
                                    }
                                }
                            }
                        }
                        
                        // If fallback generated any thumbnails, update result
                        if (!empty($fallback_sizes)) {
                            $result['success'] = true;
                            $result['message'] = 'Fallback successfully regenerated ' . count($fallback_sizes) . ' thumbnail sizes';
                            $result['sizes'] = array_merge($result['sizes'], $fallback_sizes);
                        } else {
                            $result['debug'][] = "Fallback did not generate any new thumbnails";
                        }
                        
                    } catch (Exception $e) {
                        $result['debug'][] = "Fallback error: " . $e->getMessage();
                        // If we already have a failure message from the primary method, keep it
                        if (!$result['success'] && empty($result['message'])) {
                            $result['message'] = 'Both custom and fallback thumbnail generation failed';
                        }
                    }
                }
                
                // Save the updated metadata
                wp_update_attachment_metadata($attachment_id, $metadata);
                
                // Clean up temporary files
                $this->cleanup_temp_files($temp_dir);
            } catch (Exception $e) {
                $result['debug'][] = "Exception in regenerate_image_thumbnails: " . $e->getMessage();
                $result['success'] = false;
                $result['message'] = 'Exception: ' . $e->getMessage();
            }
            
            return $result;
        }
        
        /**
         * Delete existing thumbnails for an attachment
        * 
        * @param int $attachment_id The attachment ID
        * @param array $metadata The attachment metadata
        * @return array List of deleted thumbnails
        */
        private function delete_existing_thumbnails($attachment_id, $metadata) {
            $deleted = array();
            
            // Get relative path
            if (empty($metadata['file'])) {
                return $deleted;
            }
            
            $file_path = $metadata['file'];
            $file_dir = dirname($file_path);
            
            // Delete from metadata
            if (!empty($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size => $size_data) {
                    if (empty($size_data['file'])) {
                        continue;
                    }
                    
                    // Construct GCS URL
                    $gcs_url = 'https://storage.googleapis.com/' . $this->gcs_config['bucket'] . '/' . $file_dir . '/' . $size_data['file'];
                    
                    // Delete from GCS
                    if ($this->delete_from_gcs($gcs_url)) {
                        $deleted[] = $size;
                    }
                }
            }
            
            // Search for additional thumbnails
            $full_url = wp_get_attachment_url($attachment_id);
            $filename = basename($full_url);
            $parts = $this->extract_gcs_prefix($filename);
            $prefix = $parts['prefix'];
            $base_name = pathinfo($parts['filename'], PATHINFO_FILENAME);
            $extension = pathinfo($parts['filename'], PATHINFO_EXTENSION);
            
            // Try to list objects from GCS bucket with matching prefix
            // This is difficult without direct GCS API access, so it's left as an enhancement
            
            return $deleted;
        }
        
        /**
         * Delete a file from GCS
        * 
        * @param string $gcs_url The GCS URL to delete
        * @return bool True if deleted, false otherwise
        */
        private function delete_from_gcs($gcs_url) {
            // Extract bucket and object name
            preg_match('/https:\/\/storage\.googleapis\.com\/([^\/]+)\/(.+)/', $gcs_url, $matches);
            
            if (count($matches) !== 3) {
                return false;
            }
            
            $bucket = $matches[1];
            $object_name = $matches[2];
            
            // Try to delete via wp-stateless first
            if (function_exists('ud_get_stateless_media')) {
                $sm = ud_get_stateless_media();
                
                if (method_exists($sm, 'delete_media')) {
                    try {
                        $sm->delete_media($object_name);
                        return true;
                    } catch (Exception $e) {
                        // Continue with other methods
                    }
                }
            }
            
            // Try to delete via Google Cloud SDK
            if (class_exists('Google\Cloud\Storage\StorageClient')) {
                try {
                    // Create storage client with credentials
                    $config = array();
                    
                    if (!empty($this->gcs_config['credentials_file'])) {
                        $config['keyFilePath'] = $this->gcs_config['credentials_file'];
                    } elseif (!empty($this->gcs_config['credentials_json'])) {
                        $config['keyFile'] = json_decode($this->gcs_config['credentials_json'], true);
                    }
                    
                    if (!empty($this->gcs_config['project_id'])) {
                        $config['projectId'] = $this->gcs_config['project_id'];
                    }
                    
                    $storage = new Google\Cloud\Storage\StorageClient($config);
                    $bucket_obj = $storage->bucket($bucket);
                    $object = $bucket_obj->object($object_name);
                    
                    if ($object->exists()) {
                        $object->delete();
                        return true;
                    }
                } catch (Exception $e) {
                    // Continue with other methods
                }
            }
            
            // Try direct HTTP DELETE request with authentication
            try {
                $auth_token = $this->get_gcs_auth_token();
                
                if (!empty($auth_token)) {
                    $ch = curl_init($gcs_url);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Authorization: Bearer ' . $auth_token
                    ));
                    
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($http_code >= 200 && $http_code < 300) {
                        return true;
                    }
                }
            } catch (Exception $e) {
                // Fail silently
            }
            
            return false;
        }
        
        /**
         * Define custom image sizes needed for your site
        */
        public function get_custom_image_sizes() {
            global $_wp_additional_image_sizes;
            
            $sizes = array();
            
            // Get sizes registered in WordPress
            $wp_sizes = wp_get_registered_image_subsizes();
            
            // Merge with sizes array
            foreach ($wp_sizes as $size => $dimensions) {
                $sizes[$size] = array(
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height'],
                    'crop' => !empty($dimensions['crop'])
                );
            }
            
            // Add custom sizes if needed
            $custom_sizes = array(
                'thumbnail_100' => array('width' => 100, 'height' => 100, 'crop' => true),
                'thumbnail_150' => array('width' => 150, 'height' => 150, 'crop' => true),
                'small' => array('width' => 300, 'height' => 300, 'crop' => true),
                'small_300' => array('width' => 300, 'height' => 9999, 'crop' => false),
                'custom_medium' => array('width' => 600, 'height' => 300, 'crop' => true),
                'medium_600' => array('width' => 600, 'height' => 9999, 'crop' => false),
                'archive_medium' => array('width' => 600, 'height' => 600, 'crop' => true),
                'custom_768' => array('width' => 768, 'height' => 768, 'crop' => true),
                'large_768' => array('width' => 768, 'height' => 9999, 'crop' => false),
                'custom_large' => array('width' => 800, 'height' => 800, 'crop' => true),
                'large_800' => array('width' => 800, 'height' => 9999, 'crop' => false),
                'custom_1024' => array('width' => 1024, 'height' => 1024, 'crop' => true),
                'original_1024' => array('width' => 1024, 'height' => 9999, 'crop' => false),
                'snipp_landscape' => array('width' => 800, 'height' => 408, 'crop' => true),
                'woocommerce_thumbnail' => array('width' => 600, 'height' => 600, 'crop' => true),
                'woocommerce_single' => array('width' => 600, 'height' => 0, 'crop' => false),
                'woocommerce_gallery_thumbnail' => array('width' => 100, 'height' => 100, 'crop' => true),
                'quick_view_image_size' => array('width' => 450, 'height' => 600, 'crop' => true),
            );
            
            // Merge custom sizes, but don't override existing ones
            foreach ($custom_sizes as $size => $dimensions) {
                if (!isset($sizes[$size])) {
                    $sizes[$size] = $dimensions;
                }
            }
            
            return $sizes;
        }
        
        /**
         * Extract GCS file prefix and original filename
        * 
        * @param string $filename The filename with potential prefix
        * @return array Array with 'prefix' and 'filename'
        */
        public function extract_gcs_prefix($filename) {
            // Check if the filename matches the pattern of having a prefix (8 hex chars followed by dash)
            if (preg_match('/^([a-f0-9]{8})-(.+)$/', $filename, $matches)) {
                return [
                    'prefix' => $matches[1] . '-',
                    'filename' => $matches[2]
                ];
            }
            
            return [
                'prefix' => '',
                'filename' => $filename
            ];
        }
        
        /**
         * Download a file from GCS
        */
        public function download_file_from_gcs($url, $local_path) {
            $auth_token = $this->get_gcs_auth_token();
            
            $args = array(
                'timeout' => 60,
                'stream' => true,
                'filename' => $local_path,
            );
            
            // Add authorization if we have a token
            if (!empty($auth_token)) {
                $args['headers'] = array(
                    'Authorization' => 'Bearer ' . $auth_token
                );
            }
            
            $response = wp_remote_get($url, $args);
            
            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => $response->get_error_message(),
                );
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            
            if ($http_code !== 200) {
                return array(
                    'success' => false,
                    'message' => "HTTP Error: {$http_code}",
                );
            }
            
            // Verify file exists and has size after download
            if (!file_exists($local_path) || filesize($local_path) === 0) {
                return array(
                    'success' => false,
                    'message' => "Download failed: File is empty or does not exist after download",
                );
            }
            
            return array(
                'success' => true,
                'message' => 'File downloaded successfully',
            );
        }
        
        /**
         * Improved upload to GCS with better error handling and proper file handling
        */
        public function improved_upload_file_to_gcs($local_path, $gcs_url) {
            // Verify file exists and has content
            if (!file_exists($local_path) || filesize($local_path) === 0) {
                return array(
                    'success' => false,
                    'message' => 'Source file empty or does not exist: ' . $local_path . ' (Size: ' . (file_exists($local_path) ? filesize($local_path) : 'N/A') . ')',
                );
            }
            
            // Extract the bucket and path from GCS URL
            preg_match('/https:\/\/storage\.googleapis\.com\/([^\/]+)\/(.+)/', $gcs_url, $matches);
            
            if (count($matches) !== 3) {
                return array(
                    'success' => false,
                    'message' => 'Invalid GCS URL format',
                );
            }
            
            $bucket = $matches[1];
            $object_name = $matches[2];
            
            // Create array to track attempts
            $attempts = array();
            
            // Method 1: Use wp-stateless directly (preferred method)
            if (function_exists('ud_get_stateless_media')) {
                $sm = ud_get_stateless_media();
                
                if (method_exists($sm, 'add_media')) {
                    try {
                        $sm_result = $sm->add_media(array(
                            'name' => $object_name,
                            'absolutePath' => $local_path,
                        ));
                        
                        $attempts[] = "WP-Stateless attempt: " . ($sm_result ? "Success" : "Failed");
                        
                        if ($sm_result) {
                            return array(
                                'success' => true,
                                'message' => 'File uploaded successfully via wp-stateless',
                            );
                        }
                    } catch (Exception $e) {
                        $attempts[] = "WP-Stateless error: " . $e->getMessage();
                    }
                }
            }
            
            // Method 2: Use Google Cloud Storage SDK with credentials
            if (class_exists('Google\Cloud\Storage\StorageClient')) {
                try {
                    // Create storage client with credentials
                    $config = array();
                    
                    if (!empty($this->gcs_config['credentials_file'])) {
                        $config['keyFilePath'] = $this->gcs_config['credentials_file'];
                    } elseif (!empty($this->gcs_config['credentials_json'])) {
                        $config['keyFile'] = json_decode($this->gcs_config['credentials_json'], true);
                    }
                    
                    if (!empty($this->gcs_config['project_id'])) {
                        $config['projectId'] = $this->gcs_config['project_id'];
                    }
                    
                    $storage = new Google\Cloud\Storage\StorageClient($config);
                    $bucket_obj = $storage->bucket($bucket);
                    
                    // Read file content instead of using a file handle
                    $file_content = file_get_contents($local_path);
                    if ($file_content === false) {
                        $attempts[] = "Failed to read file content for SDK upload";
                        throw new Exception("Cannot read file content");
                    }
                    
                    // Upload using content instead of file handle to avoid stream issues
                    $bucket_obj->upload(
                        $file_content,
                        [
                            'name' => $object_name,
                            'predefinedAcl' => 'publicRead',
                        ]
                    );
                    
                    $attempts[] = "Google Cloud SDK: Success";
                    
                    return array(
                        'success' => true,
                        'message' => 'File uploaded successfully via SDK using file content',
                    );
                } catch (Exception $e) {
                    $attempts[] = "Google Cloud SDK error: " . $e->getMessage();
                }
            }
            
            // Method 3: Direct HTTP PUT with cURL and authentication
            try {
                // Read file content in binary mode
                $file_content = file_get_contents($local_path);
                if ($file_content === false) {
                    $attempts[] = "Failed to read file content: " . $local_path;
                    throw new Exception("Cannot read file content");
                }
                
                $content_length = strlen($file_content);
                if ($content_length === 0) {
                    $attempts[] = "File content is empty: " . $local_path;
                    throw new Exception("File content is empty");
                }
                
                $auth_token = $this->get_gcs_auth_token();
                
                $headers = array(
                    'Content-Type: ' . mime_content_type($local_path),
                    'Content-Length: ' . $content_length
                );
                
                // Add authorization if we have a token
                if (!empty($auth_token)) {
                    $headers[] = 'Authorization: Bearer ' . $auth_token;
                }
                
                $ch = curl_init($gcs_url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $file_content);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);
                
                $attempts[] = "cURL attempt: HTTP " . $http_code . 
                            " (Response length: " . strlen($response) . ")" . 
                            (!empty($curl_error) ? " Error: " . $curl_error : "");
                
                if ($http_code >= 200 && $http_code < 300) {
                    return array(
                        'success' => true,
                        'message' => 'File uploaded successfully via direct HTTP PUT',
                    );
                }
            } catch (Exception $e) {
                $attempts[] = "cURL error: " . $e->getMessage();
            }
            
            // Fallback: Try direct file operation with WordPress HTTP API
            try {
                $file_content = file_get_contents($local_path);
                if ($file_content === false || strlen($file_content) === 0) {
                    $attempts[] = "Failed to read file content for WordPress HTTP API attempt";
                    throw new Exception("Cannot read file content");
                }
                
                $auth_token = $this->get_gcs_auth_token();
                $headers = array();
                
                if (!empty($auth_token)) {
                    $headers['Authorization'] = 'Bearer ' . $auth_token;
                }
                
                $response = wp_remote_request(
                    $gcs_url,
                    array(
                        'method' => 'PUT',
                        'headers' => array_merge(
                            $headers,
                            array(
                                'Content-Type' => mime_content_type($local_path),
                                'Content-Length' => strlen($file_content),
                            )
                        ),
                        'body' => $file_content,
                        'timeout' => 60,
                    )
                );
                
                if (!is_wp_error($response)) {
                    $http_code = wp_remote_retrieve_response_code($response);
                    $attempts[] = "WordPress HTTP API attempt: HTTP " . $http_code;
                    
                    if ($http_code >= 200 && $http_code < 300) {
                        return array(
                            'success' => true,
                            'message' => 'File uploaded successfully via WordPress HTTP API',
                        );
                    }
                } else {
                    $attempts[] = "WordPress HTTP API error: " . $response->get_error_message();
                }
            } catch (Exception $e) {
                $attempts[] = "WordPress HTTP API attempt error: " . $e->getMessage();
            }
            
            return array(
                'success' => false,
                'message' => 'All upload methods failed. Attempts: ' . implode(', ', $attempts),
            );
        }
        
        /**
         * Get relative path for an attachment
        */
        public function get_relative_path($attachment_id) {
            $upload_dir = wp_upload_dir();
            $metadata = wp_get_attachment_metadata($attachment_id);
            
            if (isset($metadata['file'])) {
                return $metadata['file'];
            }
            
            // Try to determine path from URL
            $url = wp_get_attachment_url($attachment_id);
            
            // Parse GCS URL
            if (strpos($url, 'storage.googleapis.com/' . $this->gcs_config['bucket'] . '/') !== false) {
                $path = str_replace('https://storage.googleapis.com/' . $this->gcs_config['bucket'] . '/', '', $url);
                return $path;
            }
            
            // Fallback to current date path and filename
            $filename = basename($url);
            $date = date('Y/m');
            return $date . '/' . $filename;
        }
        
        /**
         * Clean up a temporary file
        */
        public function cleanup_temp_file($file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        
        /**
         * Clean up all files in a temporary directory
        */
        public function cleanup_temp_files($dir) {
            if (!is_dir($dir)) {
                return;
            }
            
            $files = glob($dir . '/*');
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        
        /**
         * AJAX handler for getting regeneration status
        */
        public function ajax_get_regeneration_status() {
            // Implementation for checking status
            wp_send_json_success(array(
                'success' => true,
                'status' => 'processing',
            ));
        }
    }
    
    // Initialize
    $gcs_thumbnail_generator = new GCS_Thumbnail_Generator();

   
