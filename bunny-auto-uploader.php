<?php
/**
 * Plugin Name: Bunny Auto Uploader
 * Description: Automatically uploads audio files (.mp3, .wav, .m4a) to Bunny.net CDN when added to the Media Library
 * Version: 1.0.0
 * Author: WordPress Developer
 * Text Domain: bunny-auto-uploader
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Bunny_Auto_Uploader {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hook into WordPress media upload
        add_filter('wp_handle_upload', array($this, 'handle_audio_upload'), 10, 2);
        
        // Hook into new attachment creation
        add_action('add_attachment', array($this, 'process_new_attachment'));
        
        // Add meta box to media edit screen
        add_action('add_meta_boxes', array($this, 'add_media_meta_box'));
        
        // Save meta box data
        add_action('edit_attachment', array($this, 'save_media_meta_box_data'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Enqueue admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        
        // Add Bunny CDN URL to attachment fields
        add_filter('attachment_fields_to_edit', array($this, 'add_bunny_cdn_url_field'), 10, 2);
        
        // Handle AJAX upload request
        add_action('wp_ajax_bunny_upload_attachment', array($this, 'ajax_upload_attachment'));
        
        // Replace attachment URLs with Bunny CDN URLs on the frontend
        add_filter('wp_get_attachment_url', array($this, 'replace_attachment_url'), 10, 2);
        add_filter('attachment_url_filter', array($this, 'replace_attachment_url'), 10, 2);
        add_filter('wp_get_attachment_url_filter', array($this, 'replace_attachment_url'), 10, 2);
        add_filter('wp_audio_shortcode_override', array($this, 'override_audio_shortcode'), 10, 4);
        add_filter('wp_get_attachment_metadata', array($this, 'filter_attachment_metadata'), 10, 2);
        add_filter('wp_prepare_attachment_for_js', array($this, 'prepare_attachment_for_js'), 10, 3);
        
        // Add custom column to media library
        add_filter('manage_media_columns', array($this, 'add_media_library_column'));
        add_action('manage_media_custom_column', array($this, 'display_media_library_column_content'), 10, 2);
        
        // Add custom CSS for the media library column
        add_action('admin_head', array($this, 'add_media_library_column_style'));
        
        // Display admin notices
        add_action('admin_notices', array($this, 'display_admin_notices'));
        
        // Register JetEngine custom callback
        add_filter('jet-engine/listings/dynamic-field/custom-callbacks', array($this, 'register_jetengine_callback'));
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_styles($hook) {
        $plugin_dir_url = plugin_dir_url(__FILE__);
        
        // Only load on our settings page or attachment edit screen or media pages
        if ($hook === 'settings_page_bunny-auto-uploader' || $hook === 'post.php' || $hook === 'upload.php' || $hook === 'media-new.php') {
            // Enqueue CSS
            wp_enqueue_style('bunny-auto-uploader-admin', $plugin_dir_url . 'assets/css/admin.css', array(), '1.0.0');
            
            // Enqueue JavaScript
            wp_enqueue_script('bunny-auto-uploader-admin', $plugin_dir_url . 'assets/js/admin.js', array('jquery'), '1.0.0', true);
            
            // Localize script with texts and nonce
            wp_localize_script('bunny-auto-uploader-admin', 'bunnyAutoUploaderVars', array(
                'uploadToBunny' => __('Upload to Bunny CDN', 'bunny-auto-uploader'),
                'uploading'     => __('Uploading...', 'bunny-auto-uploader'),
                'uploadFailed'  => __('Upload failed. Please try again.', 'bunny-auto-uploader'),
                'hostedOnBunny' => __('Audio file hosted on Bunny CDN', 'bunny-auto-uploader'),
                'openUrl'       => __('Open URL', 'bunny-auto-uploader'),
                'retry'         => __('Retry', 'bunny-auto-uploader'),
                'nonce'         => wp_create_nonce('bunny_upload_attachment')
            ));
        }
    }
    
    /**
     * Handle audio file uploads
     * 
     * @param array $upload Upload data
     * @param string $context Context of the upload
     * @return array Modified upload data
     */
    public function handle_audio_upload($upload, $context) {
        // Check if this is an audio file
        $file_type = wp_check_filetype($upload['file']);
        $mime_type = $file_type['type'];
        $is_audio = strpos($mime_type, 'audio') !== false || 
                    in_array(strtolower($file_type['ext']), array('mp3', 'wav', 'm4a'));
        
        if (!$is_audio) {
            return $upload;
        }
        
        // Log that we detected an audio file
        $this->log_debug('Detected audio file upload: ' . basename($upload['file']));
        
        // Get the attachment ID
        $attachment_id = $this->get_attachment_id_by_file($upload['file']);
        
        if (!$attachment_id) {
            $this->log_error('Could not find attachment ID for file: ' . basename($upload['file']));
            return $upload;
        }
        
        // Check if settings are configured
        $storage_zone = get_option('bunny_auto_uploader_storage_zone');
        $api_key = get_option('bunny_auto_uploader_api_key');
        $pull_zone_url = get_option('bunny_auto_uploader_pull_zone_url');
        
        if (empty($storage_zone) || empty($api_key) || empty($pull_zone_url)) {
            $this->log_error('Bunny.net settings not configured. Please set up the plugin before uploading.');
            
            // Add an admin notice
            add_action('admin_notices', function() {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php _e('Bunny Auto Uploader: Your Bunny.net settings are not fully configured. Please configure them under Settings > Bunny Auto Uploader.', 'bunny-auto-uploader'); ?></p>
                </div>
                <?php
            });
            
            return $upload;
        }
        
        // Upload to Bunny.net
        $bunny_cdn_url = $this->upload_to_bunny($upload['file']);
        
                    // Save the CDN URL as attachment meta if successful
            if ($bunny_cdn_url) {
                update_post_meta($attachment_id, '_bunny_cdn_url', $bunny_cdn_url);
                
                // Add upload timestamp
                update_post_meta($attachment_id, '_bunny_cdn_upload_time', time());
                
                // Log success
                $this->log_debug('Successfully uploaded to Bunny.net: ' . basename($upload['file']));
                
                // Add admin notice for successful upload
                $this->add_success_notice(basename($upload['file']), $bunny_cdn_url);
            } else {
                // Get error message
                $error = get_post_meta($attachment_id, '_bunny_upload_error', true);
                $error_message = !empty($error['message']) 
                    ? $error['message'] 
                    : 'Unknown error occurred during upload.';
                
                // Log failure
                $this->log_error('Failed to upload to Bunny.net: ' . basename($upload['file']));
                
                // Set failed upload flag on the attachment
                update_post_meta($attachment_id, '_bunny_cdn_upload_failed', '1');
                update_post_meta($attachment_id, '_bunny_cdn_upload_attempt_time', time());
                
                // Add admin notice for failed upload
                $this->add_error_notice(basename($upload['file']), $error_message, $attachment_id);
            }
        
        return $upload;
    }
    
    /**
     * Upload file to Bunny.net using FTP
     * 
     * @param string $file_path Path to the file to upload
     * @return string|false CDN URL on success, false on failure
     */
    private function upload_to_bunny_ftp($file_path) {
        // Try the HTTP API method first (most reliable)
        $result = $this->upload_to_bunny_http($file_path);
        if ($result !== false) {
            return $result;
        }
        $this->log_debug('HTTP API upload failed, trying cURL FTP as fallback');
        
        // Check if cURL is available for FTP
        if (function_exists('curl_init')) {
            $result = $this->upload_to_bunny_ftp_curl($file_path);
            if ($result !== false) {
                return $result;
            }
            $this->log_debug('cURL FTP upload failed, trying native FTP functions as fallback');
        }
        
        // Check if FTP extension is available
        if (!function_exists('ftp_connect')) {
            $this->log_error('PHP FTP extension is not available. Please enable it on your server.', 0, 'ftp_extension_missing');
            return false;
        }
        
        // Get FTP settings
        $settings = $this->get_bunny_settings();
        if (!$settings) {
            $this->log_error('Missing FTP credentials. Please configure the FTP settings.', 0, 'missing_ftp_settings');
            return false;
        }
        
        $ftp_host = $settings['ftp_host'];
        $ftp_username = $settings['ftp_username'];
        $ftp_password = $settings['ftp_password'];
        $pull_zone_url = $settings['pull_zone_url'];
        
        // Force host to be storage.bunnycdn.com if it's a regional variant
        if (strpos($ftp_host, '.storage.bunnycdn.com') !== false) {
            $this->log_debug('Converting regional host ' . $ftp_host . ' to storage.bunnycdn.com');
            $ftp_host = 'storage.bunnycdn.com';
        }
        
        // Debug log for all credentials
        $this->log_error('DEBUG FTP - Host: '.$ftp_host.', Username: '.$ftp_username.', Pass: '.(empty($ftp_password)?'empty':'set').', URL: '.$pull_zone_url, 0, 'debug_ftp_settings');
        
        // If FTP credentials are not set, log error and return false
        if (empty($ftp_host) || empty($ftp_username) || empty($ftp_password) || empty($pull_zone_url)) {
            $this->log_error('Missing FTP credentials. Please configure the FTP settings.', 0, 'missing_ftp_settings');
            
            // Debug which specific credential is missing
            if (empty($ftp_host)) $this->log_error('FTP Host is empty', 0, 'missing_ftp_host');
            if (empty($ftp_username)) $this->log_error('FTP Username is empty', 0, 'missing_ftp_username');
            if (empty($ftp_password)) $this->log_error('FTP Password is empty', 0, 'missing_ftp_password');
            if (empty($pull_zone_url)) $this->log_error('Pull Zone URL is empty', 0, 'missing_pull_zone_url');
            
            return false;
        }
        
        // Get attachment ID and filename
        $attachment_id = $this->get_attachment_id_by_file($file_path);
        $filename = basename($file_path);
        
        // Check if file exists
        if (!file_exists($file_path)) {
            $this->log_error('File does not exist: ' . $file_path, $attachment_id, 'file_not_found');
            return false;
        }
        
        // Try FTPS connection first (explicit SSL)
        $this->log_debug('Trying explicit FTPS connection to: ' . $ftp_host);
        $conn_id = false;
        
        if (function_exists('ftp_ssl_connect')) {
            // Try FTPS with a longer timeout (30 seconds)
            $conn_id = @ftp_ssl_connect($ftp_host, 21, 30);
            if ($conn_id) {
                $this->log_debug('Successfully connected using FTPS');
            } else {
                $this->log_debug('FTPS connection failed, will try regular FTP');
            }
        }
        
        // If FTPS failed or isn't available, try regular FTP
        if (!$conn_id) {
            $this->log_debug('Trying regular FTP connection to: ' . $ftp_host);
            // Try with a longer timeout (30 seconds)
            $conn_id = @ftp_connect($ftp_host, 21, 30);
        }
        
        if (!$conn_id) {
            $this->log_error('Could not connect to FTP server: ' . $ftp_host, $attachment_id, 'ftp_connect_failed');
            
            // Add more debugging info
            if (!function_exists('ftp_ssl_connect')) {
                $this->log_error('PHP FTPS support is not available', 0, 'ftps_not_available');
            }
            
            // Check if we can resolve the hostname
            if (function_exists('gethostbyname')) {
                $ip = gethostbyname($ftp_host);
                if ($ip === $ftp_host) {
                    $this->log_error('Could not resolve hostname: ' . $ftp_host, 0, 'hostname_resolution_failed');
                } else {
                    $this->log_error('Hostname resolved to IP: ' . $ip, 0, 'hostname_resolved');
                }
            }
            
            return false;
        }
        
        // Login to FTP server
        $this->log_debug('Attempting FTP login with username: ' . $ftp_username);
        $login_result = @ftp_login($conn_id, $ftp_username, $ftp_password);
        
        if (!$login_result) {
            $this->log_error('FTP login failed for user: ' . $ftp_username, $attachment_id, 'ftp_login_failed');
            ftp_close($conn_id);
            return false;
        }
        
        // Enable passive mode (usually needed for firewalls and NAT)
        $this->log_debug('Enabling FTP passive mode');
        ftp_pasv($conn_id, true);
        
        // Upload the file
        $this->log_debug('Uploading file via FTP: ' . $filename . ' (size: ' . filesize($file_path) . ' bytes)');
        $upload_result = ftp_put($conn_id, $filename, $file_path, FTP_BINARY);
        
        // Close the connection
        ftp_close($conn_id);
        
        if (!$upload_result) {
            $this->log_error('FTP upload failed for file: ' . $filename, $attachment_id, 'ftp_upload_failed');
            return false;
        }
        
        // Successfully uploaded, return CDN URL
        $cdn_url = $pull_zone_url . $filename;
        
        $this->log_debug('Successfully uploaded file via FTP: ' . $filename);
        return $cdn_url;
    }
    
    /**
     * Upload file to Bunny.net using cURL's FTP implementation
     * 
     * @param string $file_path Path to the file to upload
     * @return string|false CDN URL on success, false on failure
     */
    private function upload_to_bunny_ftp_curl($file_path) {
        // Get FTP settings
        $settings = $this->get_bunny_settings();
        if (!$settings) {
            $this->log_error('Missing FTP credentials for cURL upload', 0, 'curl_missing_credentials');
            return false;
        }
        
        $ftp_host = $settings['ftp_host'];
        $ftp_username = $settings['ftp_username'];
        $ftp_password = $settings['ftp_password'];
        $pull_zone_url = $settings['pull_zone_url'];
        
        // Force host to be storage.bunnycdn.com if it's a regional variant
        if (strpos($ftp_host, '.storage.bunnycdn.com') !== false) {
            $this->log_debug('Converting regional host ' . $ftp_host . ' to storage.bunnycdn.com');
            $ftp_host = 'storage.bunnycdn.com';
        }
        
        // If FTP credentials are not set, log error and return false
        if (empty($ftp_host) || empty($ftp_username) || empty($ftp_password) || empty($pull_zone_url)) {
            $this->log_error('Missing FTP credentials for cURL upload', 0, 'curl_missing_credentials');
            return false;
        }
        
        // Get attachment ID and filename
        $attachment_id = $this->get_attachment_id_by_file($file_path);
        $filename = basename($file_path);
        
        // Check if file exists
        if (!file_exists($file_path)) {
            $this->log_error('File does not exist for cURL upload: ' . $file_path, $attachment_id, 'curl_file_not_found');
            return false;
        }
        
        $this->log_debug('Attempting cURL FTP upload to ' . $ftp_host);
        
        // Initialize cURL
        $ch = curl_init();
        
        // Set the FTP URL
        $url = 'ftp://' . $ftp_host . '/' . $filename;
        curl_setopt($ch, CURLOPT_URL, $url);
        
        // Set credentials
        curl_setopt($ch, CURLOPT_USERPWD, $ftp_username . ':' . $ftp_password);
        
        // Upload settings
        curl_setopt($ch, CURLOPT_UPLOAD, 1);
        curl_setopt($ch, CURLOPT_FTP_CREATE_MISSING_DIRS, 1);
        
        // Try both active and passive modes
        curl_setopt($ch, CURLOPT_FTP_USE_EPSV, 0); // Disable EPSV
        curl_setopt($ch, CURLOPT_FTPPORT, '-'); // Use passive mode
        
        // Set timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60 seconds timeout
        
        // Verbose debugging
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        $verbose_output = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose_output);
        
        // Open file for upload
        $fp = fopen($file_path, 'rb');
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($file_path));
        
        // Execute the request
        curl_exec($ch);
        
        // Check for errors
        $curl_error = curl_errno($ch);
        $curl_error_message = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Get verbose information
        rewind($verbose_output);
        $verbose_log = stream_get_contents($verbose_output);
        fclose($verbose_output);
        
        // Close resources
        curl_close($ch);
        fclose($fp);
        
        // Log verbose output
        $this->log_debug('cURL FTP verbose log: ' . $verbose_log);
        
        if ($curl_error) {
            $this->log_error('cURL FTP error (' . $curl_error . '): ' . $curl_error_message, $attachment_id, 'curl_ftp_error');
            return false;
        }
        
        if ($http_code >= 400) {
            $this->log_error('cURL FTP HTTP error: ' . $http_code, $attachment_id, 'curl_ftp_http_error');
            return false;
        }
        
        // If we got here, assume success
        $this->log_debug('Successfully uploaded file via cURL FTP: ' . $filename);
        
        // Return the CDN URL
        $cdn_url = $pull_zone_url . $filename;
        
        return $cdn_url;
    }
    
    /**
     * Upload file to Bunny.net using HTTP API
     * 
     * @param string $file_path Path to the file to upload
     * @return string|false CDN URL on success, false on failure
     */
    private function upload_to_bunny_http($file_path) {
        // Get settings
        $settings = $this->get_bunny_settings();
        if (!$settings) {
            $this->log_error('Missing credentials for HTTP upload', 0, 'http_missing_credentials');
            return false;
        }
        
        // Extract settings
        $storage_zone = $settings['storage_zone'];
        $access_key = !empty($settings['api_key']) ? $settings['api_key'] : $settings['ftp_password'];
        $pull_zone_url = $settings['pull_zone_url'];
        $storage_endpoint = $settings['storage_endpoint'];
        
        // Debug log credentials
        $this->log_debug('HTTP API credentials - Storage Zone: ' . $storage_zone . ', Access Key: ' . substr($access_key, 0, 5) . '...');
        
        // Get attachment ID and filename
        $attachment_id = $this->get_attachment_id_by_file($file_path);
        $filename = basename($file_path);
        
        // Check if file exists
        if (!file_exists($file_path)) {
            $this->log_error('File does not exist for HTTP upload: ' . $file_path, $attachment_id, 'http_file_not_found');
            return false;
        }
        
        $this->log_debug('Attempting HTTP API upload to Bunny.net storage');
        
        // Read file contents
        $file_contents = file_get_contents($file_path);
        if ($file_contents === false) {
            $this->log_error('Could not read file for HTTP upload', $attachment_id, 'http_file_read_error');
            return false;
        }
        
        // Construct the Bunny.net Storage URL exactly as in the documentation
        $api_url = 'https://' . $storage_endpoint . '/' . $storage_zone . '/' . $filename;
        $this->log_debug('HTTP API URL: ' . $api_url);
        
        // Setup cURL for HTTP upload
        $ch = curl_init($api_url);
        
        // Use only the AccessKey header as specified in the documentation
        $headers = array(
            'Content-Type: application/octet-stream',
            'AccessKey: ' . $access_key
        );
        
        $this->log_debug('HTTP Headers: ' . json_encode($headers));
        
        // Set cURL options
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $file_contents);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60 seconds timeout
        
        // Verbose debugging
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        $verbose_output = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose_output);
        
        // Execute the request
        $response = curl_exec($ch);
        
        // Check for errors
        $curl_error = curl_errno($ch);
        $curl_error_message = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Get verbose information
        rewind($verbose_output);
        $verbose_log = stream_get_contents($verbose_output);
        fclose($verbose_output);
        
        // Close resources
        curl_close($ch);
        
        // Log verbose output
        $this->log_debug('HTTP API verbose log: ' . $verbose_log);
        
        if ($curl_error) {
            $this->log_error('HTTP API error (' . $curl_error . '): ' . $curl_error_message, $attachment_id, 'http_curl_error');
            return false;
        }
        
        if ($http_code < 200 || $http_code >= 300) {
            $this->log_error('HTTP API HTTP error: ' . $http_code . ', Response: ' . $response, $attachment_id, 'http_error_' . $http_code);
            
            // Try direct HTTP upload with wp_remote_request as a last resort
            return $this->upload_to_bunny_wp_http($file_path);
        }
        
        // If we got here, assume success
        $this->log_debug('Successfully uploaded file via HTTP API: ' . $filename);
        
        // Return the CDN URL
        $cdn_url = $pull_zone_url . $filename;
        
        return $cdn_url;
    }
    
    /**
     * Upload file to Bunny.net using WordPress HTTP API
     * 
     * This is a last resort method using WordPress's built-in HTTP API
     * 
     * @param string $file_path Path to the file to upload
     * @return string|false CDN URL on success, false on failure
     */
    private function upload_to_bunny_wp_http($file_path) {
        // Get settings
        $settings = $this->get_bunny_settings();
        if (!$settings) {
            $this->log_error('Missing credentials for WP HTTP upload', 0, 'wp_http_missing_credentials');
            return false;
        }
        
        // Extract settings
        $storage_zone = $settings['storage_zone'];
        $access_key = !empty($settings['api_key']) ? $settings['api_key'] : $settings['ftp_password'];
        $pull_zone_url = $settings['pull_zone_url'];
        $storage_endpoint = $settings['storage_endpoint'];
        
        $this->log_debug('Trying WordPress HTTP API as last resort');
        
        // Get attachment ID and filename
        $attachment_id = $this->get_attachment_id_by_file($file_path);
        $filename = basename($file_path);
        
        // Read file contents
        $file_contents = file_get_contents($file_path);
        if ($file_contents === false) {
            $this->log_error('Could not read file for WP HTTP upload', $attachment_id, 'wp_http_file_read_error');
            return false;
        }
        
        // Construct the Bunny.net Storage URL exactly as in the documentation
        $api_url = 'https://' . $storage_endpoint . '/' . $storage_zone . '/' . $filename;
        
        // Set up the request args with only the AccessKey header
        $args = array(
            'method'      => 'PUT',
            'timeout'     => 60,
            'redirection' => 5,
            'httpversion' => '1.1',
            'headers'     => array(
                'AccessKey'     => $access_key,
                'Content-Type'  => 'application/octet-stream'
            ),
            'body'        => $file_contents,
            'sslverify'   => true,
        );
        
        // Send the request
        $response = wp_remote_request($api_url, $args);
        
        // Check for WP errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_error('WP HTTP API Error: ' . $error_message, $attachment_id, 'wp_http_error');
            return false;
        }
        
        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            $this->log_debug('Successfully uploaded file via WP HTTP API: ' . $filename);
            
            // Return the CDN URL
            $cdn_url = $pull_zone_url . $filename;
            
            return $cdn_url;
        } else {
            $body = wp_remote_retrieve_body($response);
            $this->log_error(
                'WP HTTP API HTTP error: ' . $response_code . ', Response: ' . $body,
                $attachment_id,
                'wp_http_error_' . $response_code
            );
            return false;
        }
    }
    
    /**
     * Log an error message
     * 
     * @param string $message The error message to log
     * @param int $attachment_id Optional. Attachment ID to store error with
     * @param string $error_code Optional. Error code for programmatic identification
     */
    private function log_error($message, $attachment_id = 0, $error_code = '') {
        // Log to WordPress debug log
        error_log('Bunny Auto Uploader ERROR: ' . $message);
        
        // Create error data array
        $error_data = array(
            'time' => current_time('mysql'),
            'message' => $message,
            'error_code' => $error_code
        );
        
        // Store error with attachment if ID is provided
        if ($attachment_id > 0) {
            // Store detailed error in attachment meta
            update_post_meta($attachment_id, '_bunny_upload_error', $error_data);
            
            // Add timestamp of error
            update_post_meta($attachment_id, '_bunny_upload_error_time', time());
            
            // Flag the upload as failed
            update_post_meta($attachment_id, '_bunny_cdn_upload_failed', '1');
            
            // Store the attachment ID with the error
            $error_data['attachment_id'] = $attachment_id;
            $error_data['filename'] = basename(get_attached_file($attachment_id));
        }
        
        // Store the last few errors in an option for display in admin
        $errors = get_option('bunny_auto_uploader_errors', array());
        $errors[] = $error_data;
        
        // Keep only the last 20 errors
        if (count($errors) > 20) {
            $errors = array_slice($errors, -20);
        }
        
        update_option('bunny_auto_uploader_errors', $errors);
    }
    
    /**
     * Log a debug message
     * 
     * @param string $message The debug message to log
     */
    private function log_debug($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Bunny Auto Uploader DEBUG: ' . $message);
        }
    }
    
    /**
     * Get attachment ID from file path
     */
    private function get_attachment_id_by_file($file_path) {
        global $wpdb;
        
        $upload_dir = wp_upload_dir();
        $file_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
        
        $attachment = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s", $file_path));
        
        return isset($attachment[0]) ? $attachment[0] : 0;
    }
    
    /**
     * Add meta box to media edit screen
     */
    public function add_media_meta_box() {
        add_meta_box(
            'bunny-auto-uploader-meta-box',
            __('Bunny CDN Info', 'bunny-auto-uploader'),
            array($this, 'render_media_meta_box'),
            'attachment',
            'side',
            'high'
        );
    }
    
    /**
     * Render meta box content
     */
    public function render_media_meta_box($post) {
        // Get existing CDN URL
        $cdn_url = get_post_meta($post->ID, '_bunny_cdn_url', true);
        
        // Output meta box content
        ?>
        <p>
            <label for="bunny_cdn_url"><?php _e('Bunny CDN URL:', 'bunny-auto-uploader'); ?></label>
            <input type="text" id="bunny_cdn_url" name="bunny_cdn_url" value="<?php echo esc_attr($cdn_url); ?>" class="widefat">
        </p>
        <?php
    }
    
    /**
     * Save meta box data
     */
    public function save_media_meta_box_data($post_id) {
        if (isset($_POST['bunny_cdn_url'])) {
            update_post_meta($post_id, '_bunny_cdn_url', sanitize_text_field($_POST['bunny_cdn_url']));
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('Bunny Upload Settings', 'bunny-auto-uploader'),
            __('Bunny Upload', 'bunny-auto-uploader'),
            'manage_options',
            'bunny-auto-uploader',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Register settings section
        add_settings_section(
            'bunny_auto_uploader_settings_section',
            __('Bunny.net API Settings', 'bunny-auto-uploader'),
            array($this, 'render_settings_section'),
            'bunny-auto-uploader'
        );
        
        // Register API Key field
        register_setting('bunny-auto-uploader', 'bunny_auto_uploader_api_key');
        add_settings_field(
            'bunny_auto_uploader_api_key',
            __('API Key (Storage Zone)', 'bunny-auto-uploader'),
            array($this, 'render_api_key_field'),
            'bunny-auto-uploader',
            'bunny_auto_uploader_settings_section'
        );
        
        // Register Storage Zone field
        register_setting('bunny-auto-uploader', 'bunny_auto_uploader_storage_zone');
        add_settings_field(
            'bunny_auto_uploader_storage_zone',
            __('Storage Zone Name', 'bunny-auto-uploader'),
            array($this, 'render_storage_zone_field'),
            'bunny-auto-uploader',
            'bunny_auto_uploader_settings_section'
        );
        
        // Register Storage Region field
        register_setting('bunny-auto-uploader', 'bunny_auto_uploader_storage_region');
        add_settings_field(
            'bunny_auto_uploader_storage_region',
            __('Storage Region', 'bunny-auto-uploader'),
            array($this, 'render_storage_region_field'),
            'bunny-auto-uploader',
            'bunny_auto_uploader_settings_section'
        );
        
        // Register Pull Zone URL field
        register_setting('bunny-auto-uploader', 'bunny_auto_uploader_pull_zone_url');
        add_settings_field(
            'bunny_auto_uploader_pull_zone_url',
            __('Pull Zone Hostname', 'bunny-auto-uploader'),
            array($this, 'render_pull_zone_url_field'),
            'bunny-auto-uploader',
            'bunny_auto_uploader_settings_section'
        );
        
        // Register FTP settings section
        add_settings_section(
            'bunny_auto_uploader_ftp_settings_section',
            __('Bunny.net FTP Settings', 'bunny-auto-uploader'),
            array($this, 'render_ftp_settings_section'),
            'bunny-auto-uploader'
        );
        
        // Register FTP Host field
        register_setting('bunny-auto-uploader', 'bunny_auto_uploader_ftp_host');
        add_settings_field(
            'bunny_auto_uploader_ftp_host',
            __('FTP Host', 'bunny-auto-uploader'),
            array($this, 'render_ftp_host_field'),
            'bunny-auto-uploader',
            'bunny_auto_uploader_ftp_settings_section'
        );
        
        // Register FTP Username field
        register_setting('bunny-auto-uploader', 'bunny_auto_uploader_ftp_username');
        add_settings_field(
            'bunny_auto_uploader_ftp_username',
            __('FTP Username', 'bunny-auto-uploader'),
            array($this, 'render_ftp_username_field'),
            'bunny-auto-uploader',
            'bunny_auto_uploader_ftp_settings_section'
        );
        
        // Register FTP Password field
        register_setting('bunny-auto-uploader', 'bunny_auto_uploader_ftp_password');
        add_settings_field(
            'bunny_auto_uploader_ftp_password',
            __('FTP Password', 'bunny-auto-uploader'),
            array($this, 'render_ftp_password_field'),
            'bunny-auto-uploader',
            'bunny_auto_uploader_ftp_settings_section'
        );
        
        // Register Use FTP option
        register_setting('bunny-auto-uploader', 'bunny_auto_uploader_use_ftp');
        add_settings_field(
            'bunny_auto_uploader_use_ftp',
            __('Use FTP Instead of API', 'bunny-auto-uploader'),
            array($this, 'render_use_ftp_field'),
            'bunny-auto-uploader',
            'bunny_auto_uploader_ftp_settings_section'
        );
    }
    
    /**
     * Render settings section
     */
    public function render_settings_section() {
        echo '<p>' . __('Configure your Bunny.net API settings below.', 'bunny-auto-uploader') . '</p>';
    }
    
    /**
     * Render API key field
     */
    public function render_api_key_field() {
        $api_key = get_option('bunny_auto_uploader_api_key');
        echo '<input type="text" name="bunny_auto_uploader_api_key" value="' . esc_attr($api_key) . '" class="regular-text">';
        echo '<p class="description">' . __('Your Bunny.net Storage Zone API key.', 'bunny-auto-uploader') . '</p>';
    }
    
    /**
     * Render storage zone field
     */
    public function render_storage_zone_field() {
        $storage_zone = get_option('bunny_auto_uploader_storage_zone');
        echo '<input type="text" name="bunny_auto_uploader_storage_zone" value="' . esc_attr($storage_zone) . '" class="regular-text">';
        echo '<p class="description">' . __('The name of your Bunny.net Storage Zone.', 'bunny-auto-uploader') . '</p>';
    }
    
    /**
     * Render storage region field
     */
    public function render_storage_region_field() {
        $storage_region = get_option('bunny_auto_uploader_storage_region', '');
        $regions = array(
            '' => __('Default (Global)', 'bunny-auto-uploader'),
            'de' => __('DE (Germany)', 'bunny-auto-uploader'),
            'ny' => __('NY (New York)', 'bunny-auto-uploader'),
            'la' => __('LA (Los Angeles)', 'bunny-auto-uploader'),
            'sg' => __('SG (Singapore)', 'bunny-auto-uploader')
        );
        
        echo '<select name="bunny_auto_uploader_storage_region" class="regular-text">';
        foreach ($regions as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($storage_region, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __('Select the storage region for your Bunny.net Storage Zone.', 'bunny-auto-uploader') . '</p>';
    }
    
    /**
     * Render pull zone URL field
     */
    public function render_pull_zone_url_field() {
        $pull_zone_url = get_option('bunny_auto_uploader_pull_zone_url');
        echo '<input type="url" name="bunny_auto_uploader_pull_zone_url" value="' . esc_attr($pull_zone_url) . '" class="regular-text">';
        echo '<p class="description">' . __('Your Bunny.net Pull Zone hostname (e.g., https://yourzone.b-cdn.net)', 'bunny-auto-uploader') . '</p>';
    }
    
    /**
     * Render FTP settings section description
     */
    public function render_ftp_settings_section() {
        echo '<p>' . __('Enter your Bunny.net FTP credentials to upload files using FTP instead of the API.', 'bunny-auto-uploader') . '</p>';
    }
    
    /**
     * Render FTP Host field
     */
    public function render_ftp_host_field() {
        $ftp_host = get_option('bunny_auto_uploader_ftp_host', 'storage.bunnycdn.com');
        echo '<input type="text" name="bunny_auto_uploader_ftp_host" class="regular-text" value="' . esc_attr($ftp_host) . '" />';
        echo '<p class="description">' . __('Use storage.bunnycdn.com (not regional servers like ny.storage.bunnycdn.com)', 'bunny-auto-uploader') . '</p>';
    }
    
    /**
     * Render FTP Username field
     */
    public function render_ftp_username_field() {
        $storage_zone = get_option('bunny_auto_uploader_storage_zone', '');
        $ftp_username = get_option('bunny_auto_uploader_ftp_username', $storage_zone);
        echo '<input type="text" name="bunny_auto_uploader_ftp_username" class="regular-text" value="' . esc_attr($ftp_username) . '" />';
        echo '<p class="description">' . __('Usually the same as your Storage Zone name', 'bunny-auto-uploader') . '</p>';
    }
    
    /**
     * Render FTP Password field
     */
    public function render_ftp_password_field() {
        $ftp_password = get_option('bunny_auto_uploader_ftp_password', '');
        echo '<input type="password" name="bunny_auto_uploader_ftp_password" class="regular-text" value="' . esc_attr($ftp_password) . '" />';
        echo '<p class="description">' . __('Your FTP/API password for this Storage Zone', 'bunny-auto-uploader') . '</p>';
    }
    
    /**
     * Render Use FTP field
     */
    public function render_use_ftp_field() {
        $use_ftp = get_option('bunny_auto_uploader_use_ftp', false);
        echo '<input type="checkbox" name="bunny_auto_uploader_use_ftp" value="1" ' . checked(1, $use_ftp, false) . ' />';
        echo '<p class="description">' . __('Check this to use FTP instead of API for uploads', 'bunny-auto-uploader') . '</p>';
    }
    
    /**
     * Render the settings page
     */
    public function render_settings_page() {
        $ftp_host = get_option('bunny_auto_uploader_ftp_host', '');
        $ftp_username = get_option('bunny_auto_uploader_ftp_username', '');
        $ftp_password = get_option('bunny_auto_uploader_ftp_password', '');
        $use_ftp = get_option('bunny_auto_uploader_use_ftp', '');
        $pull_zone_url = get_option('bunny_auto_uploader_pull_zone_url', '');
        echo "<div style='background:#f8f8f8;padding:10px;border:1px solid #ddd;margin-bottom:15px;'>";
        echo "<strong>Debug:</strong> FTP Host: $ftp_host<br>";
        echo "<strong>Debug:</strong> FTP Username: $ftp_username<br>";
        echo "<strong>Debug:</strong> FTP Password: " . (empty($ftp_password) ? "empty" : "set") . "<br>";
        echo "<strong>Debug:</strong> Use FTP: " . ($use_ftp ? "yes" : "no") . "<br>";
        echo "<strong>Debug:</strong> Pull Zone URL: " . (empty($pull_zone_url) ? "empty" : $pull_zone_url) . "<br>";
        echo "</div>";
        ?>
        <div class="wrap">
            <div class="bunny-auto-uploader-header">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <p><?php _e('Configure your Bunny.net integration settings below.', 'bunny-auto-uploader'); ?></p>
            </div>
            
            <div class="bunny-info-box">
                <p><?php _e('This plugin automatically uploads audio files (.mp3, .wav, .m4a) to Bunny.net when they are added to the Media Library.', 'bunny-auto-uploader'); ?></p>
            </div>
            
            <?php
            // Display a warning if API settings are not configured
            $storage_zone = get_option('bunny_auto_uploader_storage_zone');
            $api_key = get_option('bunny_auto_uploader_api_key');
            $pull_zone_url = get_option('bunny_auto_uploader_pull_zone_url');
            $use_ftp = get_option('bunny_auto_uploader_use_ftp', false);
            $ftp_username = get_option('bunny_auto_uploader_ftp_username', '');
            $ftp_password = get_option('bunny_auto_uploader_ftp_password', '');
            
            if ((!$use_ftp && (empty($storage_zone) || empty($api_key) || empty($pull_zone_url))) || 
                ($use_ftp && (empty($ftp_username) || empty($ftp_password) || empty($pull_zone_url)))) {
                ?>
                <div class="notice notice-warning inline">
                    <p><strong><?php _e('Warning:', 'bunny-auto-uploader'); ?></strong> <?php _e('Your Bunny.net settings are not fully configured. Audio uploads to Bunny.net will fail until all settings are provided.', 'bunny-auto-uploader'); ?></p>
                </div>
                <?php
            }
            ?>
            
            <form method="post" action="options.php" class="bunny-auto-uploader-form">
                <?php
                settings_fields('bunny-auto-uploader');
                do_settings_sections('bunny-auto-uploader');
                submit_button();
                ?>
            </form>
            
            <div class="bunny-upload-existing">
                <h2><?php _e('Upload Existing Audio Files', 'bunny-auto-uploader'); ?></h2>
                <p><?php _e('Use the button below to upload all existing audio files in your Media Library to Bunny.net.', 'bunny-auto-uploader'); ?></p>
                <form method="post" action="">
                    <?php wp_nonce_field('bunny_upload_existing', 'bunny_upload_existing_nonce'); ?>
                    <input type="submit" name="bunny_upload_existing" class="button button-primary" value="<?php _e('Upload Existing Audio Files', 'bunny-auto-uploader'); ?>">
                </form>
                
                <?php
                // Handle the form submission
                if (isset($_POST['bunny_upload_existing']) && check_admin_referer('bunny_upload_existing', 'bunny_upload_existing_nonce')) {
                    $this->upload_existing_audio_files();
                }
                ?>
            </div>
            
            <!-- Error Log Section -->
            <div class="bunny-error-log">
                <h2><?php _e('Error Log', 'bunny-auto-uploader'); ?></h2>
                
                <?php 
                // Clear error log if requested
                if (isset($_POST['bunny_clear_errors']) && check_admin_referer('bunny_clear_errors', 'bunny_clear_errors_nonce')) {
                    delete_option('bunny_auto_uploader_errors');
                    echo '<div class="notice notice-success inline"><p>' . __('Error log cleared successfully.', 'bunny-auto-uploader') . '</p></div>';
                }
                
                // Get stored errors
                $errors = get_option('bunny_auto_uploader_errors', array());
                
                if (empty($errors)) {
                    echo '<p>' . __('No errors logged.', 'bunny-auto-uploader') . '</p>';
                } else {
                    ?>
                    <div class="bunny-error-table-wrapper">
                        <table class="widefat bunny-error-table">
                            <thead>
                                <tr>
                                    <th><?php _e('Time', 'bunny-auto-uploader'); ?></th>
                                    <th><?php _e('File', 'bunny-auto-uploader'); ?></th>
                                    <th><?php _e('Error Code', 'bunny-auto-uploader'); ?></th>
                                    <th><?php _e('Error Message', 'bunny-auto-uploader'); ?></th>
                                    <th><?php _e('Actions', 'bunny-auto-uploader'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_reverse($errors) as $error) : ?>
                                    <tr>
                                        <td><?php echo esc_html($error['time']); ?></td>
                                        <td>
                                            <?php 
                                            if (!empty($error['filename'])) {
                                                echo esc_html($error['filename']);
                                            } else {
                                                echo '—';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <code><?php echo !empty($error['error_code']) ? esc_html($error['error_code']) : 'unknown'; ?></code>
                                        </td>
                                        <td><?php echo esc_html($error['message']); ?></td>
                                        <td>
                                            <?php if (!empty($error['attachment_id'])) : ?>
                                                <a href="<?php echo admin_url('post.php?post=' . $error['attachment_id'] . '&action=edit'); ?>" class="button button-small">
                                                    <?php _e('View Attachment', 'bunny-auto-uploader'); ?>
                                                </a>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <form method="post" action="" style="margin-top: 10px;">
                            <?php wp_nonce_field('bunny_clear_errors', 'bunny_clear_errors_nonce'); ?>
                            <input type="submit" name="bunny_clear_errors" class="button" value="<?php _e('Clear Error Log', 'bunny-auto-uploader'); ?>">
                        </form>
                    </div>
                    <?php
                }
                ?>
            </div>
            
            <!-- Failed Uploads Section -->
            <div class="bunny-failed-uploads">
                <h2><?php _e('Failed Uploads', 'bunny-auto-uploader'); ?></h2>
                <?php
                // Get attachments with failed uploads
                $args = array(
                    'post_type'      => 'attachment',
                    'post_mime_type' => 'audio',
                    'posts_per_page' => -1,
                    'post_status'    => 'inherit',
                    'meta_key'       => '_bunny_cdn_upload_failed',
                    'meta_value'     => '1'
                );
                
                $failed_uploads = get_posts($args);
                
                if (empty($failed_uploads)) {
                    echo '<p>' . __('No failed uploads found.', 'bunny-auto-uploader') . '</p>';
                } else {
                    ?>
                    <p><?php echo sprintf(_n('Found %d audio file with failed upload.', 'Found %d audio files with failed uploads.', count($failed_uploads), 'bunny-auto-uploader'), count($failed_uploads)); ?></p>
                    
                    <form method="post" action="">
                        <?php wp_nonce_field('bunny_retry_failed', 'bunny_retry_failed_nonce'); ?>
                        <input type="submit" name="bunny_retry_failed" class="button button-secondary" value="<?php _e('Retry Failed Uploads', 'bunny-auto-uploader'); ?>">
                    </form>
                    
                    <?php
                    // Handle retry failed uploads
                    if (isset($_POST['bunny_retry_failed']) && check_admin_referer('bunny_retry_failed', 'bunny_retry_failed_nonce')) {
                        $this->retry_failed_uploads();
                    }
                    ?>
                    
                    <div class="bunny-failed-table-wrapper" style="margin-top: 10px;">
                        <table class="widefat bunny-failed-table">
                            <thead>
                                <tr>
                                    <th><?php _e('File', 'bunny-auto-uploader'); ?></th>
                                    <th><?php _e('Last Attempt', 'bunny-auto-uploader'); ?></th>
                                    <th><?php _e('Actions', 'bunny-auto-uploader'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($failed_uploads as $attachment) : 
                                    $file_path = get_attached_file($attachment->ID);
                                    $filename = basename($file_path);
                                    $last_attempt = get_post_meta($attachment->ID, '_bunny_cdn_upload_attempt_time', true);
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html($filename); ?></td>
                                        <td>
                                            <?php 
                                            if ($last_attempt) {
                                                echo esc_html(human_time_diff($last_attempt, time()) . ' ' . __('ago', 'bunny-auto-uploader')); 
                                            } else {
                                                _e('Unknown', 'bunny-auto-uploader');
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo admin_url('post.php?post=' . $attachment->ID . '&action=edit'); ?>" class="button button-small"><?php _e('Edit', 'bunny-auto-uploader'); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Upload existing audio files to Bunny.net
     */
    private function upload_existing_audio_files() {
        // Query for audio attachments
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'audio',
            'posts_per_page' => -1,
            'post_status'    => 'inherit',
        );
        
        $audio_files = get_posts($args);
        $success_count = 0;
        $fail_count = 0;
        
        foreach ($audio_files as $audio) {
            // Skip if already uploaded
            $existing_url = get_post_meta($audio->ID, '_bunny_cdn_url', true);
            if (!empty($existing_url)) {
                continue;
            }
            
            // Get the file path
            $file_path = get_attached_file($audio->ID);
            
            // Upload to Bunny.net
            $bunny_cdn_url = $this->upload_to_bunny($file_path);
            
            if ($bunny_cdn_url) {
                update_post_meta($audio->ID, '_bunny_cdn_url', $bunny_cdn_url);
                $success_count++;
            } else {
                $fail_count++;
            }
        }
        
        // Display the results
        if ($success_count > 0) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo sprintf(__('Successfully uploaded %d audio files to Bunny.net.', 'bunny-auto-uploader'), $success_count);
            echo '</p></div>';
        }
        
        if ($fail_count > 0) {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo sprintf(__('Failed to upload %d audio files to Bunny.net. Check your error logs for details.', 'bunny-auto-uploader'), $fail_count);
            echo '</p></div>';
        }
        
        if ($success_count === 0 && $fail_count === 0) {
            echo '<div class="notice notice-info is-dismissible"><p>';
            echo __('No new audio files to upload. All existing audio files are already uploaded to Bunny.net.', 'bunny-auto-uploader');
            echo '</p></div>';
        }
    }
    
    /**
     * Add Bunny CDN URL field to attachment fields
     * 
     * @param array $form_fields An array of attachment form fields
     * @param WP_Post $post The attachment post
     * @return array Modified form fields
     */
    public function add_bunny_cdn_url_field($form_fields, $post) {
        // Only add the field for audio attachments
        if (strpos($post->post_mime_type, 'audio') === 0) {
            $bunny_cdn_url = get_post_meta($post->ID, '_bunny_cdn_url', true);
            
            if (!empty($bunny_cdn_url)) {
                // Create a clickable link to the CDN URL
                $html = '<a href="' . esc_url($bunny_cdn_url) . '" target="_blank" class="bunny-cdn-url">';
                $html .= esc_html($bunny_cdn_url);
                $html .= '</a>';
                $html .= '<br><span class="description">' . __('Audio file hosted on Bunny CDN', 'bunny-auto-uploader') . '</span>';
                
                $form_fields['bunny_cdn_url'] = array(
                    'label' => __('Bunny CDN URL', 'bunny-auto-uploader'),
                    'input' => 'html',
                    'html'  => $html,
                );
            } else {
                // Check if upload failed and get error details
                $upload_failed = get_post_meta($post->ID, '_bunny_cdn_upload_failed', true);
                $upload_error = get_post_meta($post->ID, '_bunny_upload_error', true);
                $error_time = get_post_meta($post->ID, '_bunny_upload_error_time', true);
                
                if ($upload_failed && !empty($upload_error)) {
                    // Show error information and retry button
                    $html = '<div class="bunny-upload-error">';
                    $html .= '<p class="error-message"><strong>' . __('Upload failed:', 'bunny-auto-uploader') . '</strong> ';
                    $html .= esc_html($upload_error['message']) . '</p>';
                    
                    if ($error_time) {
                        $html .= '<p class="error-time"><small>' . sprintf(
                            __('Last attempt: %s ago', 'bunny-auto-uploader'),
                            human_time_diff($error_time, time())
                        ) . '</small></p>';
                    }
                    
                    $html .= '<button type="button" class="button upload-to-bunny" data-attachment-id="' . esc_attr($post->ID) . '">';
                    $html .= __('Retry Upload', 'bunny-auto-uploader');
                    $html .= '</button>';
                    $html .= '</div>';
                } else {
                    // Show a message and upload button if no CDN URL exists
                    $html = '<span class="description">' . __('Not uploaded to Bunny CDN yet', 'bunny-auto-uploader') . '</span>';
                    $html .= '<br><button type="button" class="button upload-to-bunny" data-attachment-id="' . esc_attr($post->ID) . '">';
                    $html .= __('Upload to Bunny CDN', 'bunny-auto-uploader');
                    $html .= '</button>';
                }
                
                $form_fields['bunny_cdn_url'] = array(
                    'label' => __('Bunny CDN URL', 'bunny-auto-uploader'),
                    'input' => 'html',
                    'html'  => $html,
                );
            }
        }
        
        return $form_fields;
    }
    
    /**
     * Handle AJAX request to upload a file to Bunny CDN
     */
    public function ajax_upload_attachment() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bunny_upload_attachment')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'bunny-auto-uploader')));
        }
        
        // Check attachment ID
        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
        if (!$attachment_id) {
            wp_send_json_error(array('message' => __('Invalid attachment ID.', 'bunny-auto-uploader')));
        }
        
        // Check if it's an audio file
        $attachment = get_post($attachment_id);
        if (!$attachment || strpos($attachment->post_mime_type, 'audio') !== 0) {
            $this->log_error(
                'Not an audio file', 
                $attachment_id, 
                'not_audio_file'
            );
            wp_send_json_error(array('message' => __('Not an audio file.', 'bunny-auto-uploader')));
        }
        
        // Get file path
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            $this->log_error(
                'File not found: ' . $file_path, 
                $attachment_id, 
                'file_not_found'
            );
            wp_send_json_error(array('message' => __('File not found.', 'bunny-auto-uploader')));
        }
        
        // Clear previous error data before new attempt
        delete_post_meta($attachment_id, '_bunny_cdn_upload_failed');
        delete_post_meta($attachment_id, '_bunny_upload_error');
        delete_post_meta($attachment_id, '_bunny_upload_error_time');
        
        // Upload to Bunny.net
        $bunny_cdn_url = $this->upload_to_bunny($file_path);
        
        if ($bunny_cdn_url) {
            // Save the CDN URL
            update_post_meta($attachment_id, '_bunny_cdn_url', $bunny_cdn_url);
            
            // Add upload timestamp
            update_post_meta($attachment_id, '_bunny_cdn_upload_time', time());
            
            // Log success
            $this->log_debug('Successfully uploaded to Bunny.net via AJAX: ' . basename($file_path));
            
            // Add admin notice for successful upload
            $this->add_success_notice(basename($file_path), $bunny_cdn_url);
            
            // Return success response
            wp_send_json_success(array(
                'url' => $bunny_cdn_url,
                'message' => __('File uploaded to Bunny CDN successfully.', 'bunny-auto-uploader')
            ));
        } else {
            // Get the error message from attachment meta
            $error = get_post_meta($attachment_id, '_bunny_upload_error', true);
            $error_msg = !empty($error['message']) 
                ? $error['message'] 
                : __('Failed to upload to Bunny CDN. Check server logs for details.', 'bunny-auto-uploader');
            
            // Add admin notice for failed upload
            $this->add_error_notice(basename($file_path), $error_msg, $attachment_id);
            
            wp_send_json_error(array('message' => $error_msg));
        }
    }
    
    /**
     * Replace the attachment URL with the Bunny CDN URL if it exists
     * 
     * This is a critical filter that ensures Bunny CDN URLs are used
     * throughout WordPress, including in audio players, download links,
     * and any other place where wp_get_attachment_url() is called.
     *
     * @param string $url The current attachment URL
     * @param int $attachment_id The attachment ID
     * @return string The modified URL
     */
    public function replace_attachment_url($url, $attachment_id) {
        // Check if this is an audio attachment
        $post = get_post($attachment_id);
        if (!$post || strpos($post->post_mime_type, 'audio') !== 0) {
            return $url;
        }
        
        // Get the Bunny CDN URL
        $bunny_cdn_url = get_post_meta($attachment_id, '_bunny_cdn_url', true);
        
        // Only replace if we have a valid Bunny CDN URL
        if (!empty($bunny_cdn_url)) {
            // Log the URL replacement in debug mode
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->log_debug(sprintf(
                    'Replacing URL for attachment #%d: %s → %s',
                    $attachment_id,
                    basename($url),
                    basename($bunny_cdn_url)
                ));
            }
            
            return $bunny_cdn_url;
        }
        
        return $url;
    }
    
    /**
     * Override the audio shortcode to use Bunny CDN URL
     * 
     * This handles the [audio] shortcode used by WordPress to embed audio players.
     * It ensures that the Bunny CDN URL is used in the player, even when the
     * shortcode doesn't directly reference an attachment ID (e.g., when using 'src').
     *
     * @param string $html The shortcode HTML
     * @param array $attr The shortcode attributes
     * @param string $content The shortcode content
     * @param int $instance Unique numeric ID of this audio shortcode instance
     * @return string|null The HTML output. Null if using the default output
     */
    public function override_audio_shortcode($html, $attr, $content, $instance) {
        // If HTML is already set, return it
        if ($html) {
            return $html;
        }
        
        // Check if we have an attachment ID
        if (!empty($attr['id'])) {
            $attachment_id = (int) $attr['id'];
            
            // Get the Bunny CDN URL
            $bunny_cdn_url = get_post_meta($attachment_id, '_bunny_cdn_url', true);
            
            // If we have a CDN URL, update the src attribute
            if (!empty($bunny_cdn_url)) {
                $attr['src'] = $bunny_cdn_url;
                
                // Debug log when we modify a shortcode
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $this->log_debug('Modified audio shortcode with Bunny CDN URL for attachment #' . $attachment_id);
                }
            }
        } 
        // If no ID but there's a src attribute, try to find attachment by URL
        elseif (!empty($attr['src'])) {
            global $wpdb;
            
            // Try to find the attachment ID by comparing the original URL with stored filenames
            $src_filename = basename($attr['src']);
            $attachment = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                '%' . $wpdb->esc_like($src_filename)
            ));
            
            if (!empty($attachment[0])) {
                $attachment_id = (int) $attachment[0];
                $post = get_post($attachment_id);
                
                // Verify this is an audio attachment
                if ($post && strpos($post->post_mime_type, 'audio') === 0) {
                    // Get the Bunny CDN URL
                    $bunny_cdn_url = get_post_meta($attachment_id, '_bunny_cdn_url', true);
                    
                    // If we have a CDN URL, update the src attribute
                    if (!empty($bunny_cdn_url)) {
                        $attr['src'] = $bunny_cdn_url;
                        
                        // Debug log when we modify a shortcode
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            $this->log_debug('Modified audio shortcode with Bunny CDN URL by filename match: ' . $src_filename);
                        }
                    }
                }
            }
        }
        
        // Return null to let WordPress handle the shortcode with our modified attributes
        return null;
    }
    
    /**
     * Filter attachment metadata to include Bunny CDN URL
     *
     * @param array $data Attachment metadata
     * @param int $attachment_id Attachment ID
     * @return array Modified metadata
     */
    public function filter_attachment_metadata($data, $attachment_id) {
        // Only process audio attachments
        $post = get_post($attachment_id);
        if (!$post || strpos($post->post_mime_type, 'audio') !== 0) {
            return $data;
        }
        
        // Get the Bunny CDN URL
        $bunny_cdn_url = get_post_meta($attachment_id, '_bunny_cdn_url', true);
        
        // If we have a CDN URL, add it to the metadata
        if (!empty($bunny_cdn_url) && is_array($data)) {
            $data['bunny_cdn_url'] = $bunny_cdn_url;
            
            // Also modify the file URL if audiodata exists
            if (isset($data['audiodata']) && is_array($data['audiodata'])) {
                $data['audiodata']['file_url'] = $bunny_cdn_url;
            }
        }
        
        return $data;
    }
    
    /**
     * Prepare attachment for JavaScript with Bunny CDN URL
     *
     * This is used for the media modal in the admin and also affects
     * media used in the frontend through wp.media JavaScript
     *
     * @param array $response The attachment data for JS
     * @param WP_Post $attachment The attachment post object
     * @param array $meta The attachment meta
     * @return array Modified response
     */
    public function prepare_attachment_for_js($response, $attachment, $meta) {
        // Only process audio attachments
        if (strpos($attachment->post_mime_type, 'audio') !== 0) {
            return $response;
        }
        
        // Get the Bunny CDN URL
        $bunny_cdn_url = get_post_meta($attachment->ID, '_bunny_cdn_url', true);
        
        // If we have a CDN URL, modify the URLs in the response
        if (!empty($bunny_cdn_url)) {
            // Add a specific property for our CDN URL
            $response['bunny_cdn_url'] = $bunny_cdn_url;
            
            // Replace the URL in the primary URL properties
            if (isset($response['url'])) {
                $response['url'] = $bunny_cdn_url;
            }
            
            // Replace the URL in the media player specific properties
            if (isset($response['audioSourceUrl'])) {
                $response['audioSourceUrl'] = $bunny_cdn_url;
            }
        }
        
        return $response;
    }
    
    /**
     * Retry failed uploads
     */
    private function retry_failed_uploads() {
        // Get attachments with failed uploads
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'audio',
            'posts_per_page' => -1,
            'post_status'    => 'inherit',
            'meta_key'       => '_bunny_cdn_upload_failed',
            'meta_value'     => '1'
        );
        
        $failed_uploads = get_posts($args);
        $success_count = 0;
        $fail_count = 0;
        
        foreach ($failed_uploads as $attachment) {
            $file_path = get_attached_file($attachment->ID);
            
            if (!$file_path || !file_exists($file_path)) {
                $this->log_error('File not found for retry: ' . $attachment->ID);
                $fail_count++;
                continue;
            }
            
            // Upload to Bunny.net with fresh attempt
            $bunny_cdn_url = $this->upload_to_bunny($file_path);
            
            if ($bunny_cdn_url) {
                // Success - update metadata and remove failure flag
                update_post_meta($attachment->ID, '_bunny_cdn_url', $bunny_cdn_url);
                update_post_meta($attachment->ID, '_bunny_cdn_upload_time', time());
                delete_post_meta($attachment->ID, '_bunny_cdn_upload_failed');
                delete_post_meta($attachment->ID, '_bunny_upload_error');
                delete_post_meta($attachment->ID, '_bunny_upload_error_time');
                $success_count++;
                
                $this->log_debug('Successfully retried upload for: ' . basename($file_path));
                
                // Add admin notice for successful upload
                $this->add_success_notice(basename($file_path), $bunny_cdn_url);
            } else {
                // Get error message
                $error = get_post_meta($attachment->ID, '_bunny_upload_error', true);
                $error_message = !empty($error['message']) 
                    ? $error['message'] 
                    : 'Unknown error occurred during retry.';
                
                // Update the attempt time
                update_post_meta($attachment->ID, '_bunny_cdn_upload_attempt_time', time());
                $fail_count++;
                
                // Add admin notice for failed upload
                $this->add_error_notice(basename($file_path), $error_message, $attachment->ID);
            }
        }
        
        // Display results
        if ($success_count > 0) {
            echo '<div class="notice notice-success inline"><p>';
            echo sprintf(__('Successfully uploaded %d file(s) to Bunny.net.', 'bunny-auto-uploader'), $success_count);
            echo '</p></div>';
        }
        
        if ($fail_count > 0) {
            echo '<div class="notice notice-error inline"><p>';
            echo sprintf(__('Failed to upload %d file(s) to Bunny.net. Check the error log for details.', 'bunny-auto-uploader'), $fail_count);
            echo '</p></div>';
        }
        
        if ($success_count === 0 && $fail_count === 0) {
            echo '<div class="notice notice-info inline"><p>';
            echo __('No files were found to retry.', 'bunny-auto-uploader');
            echo '</p></div>';
        }
    }
    
    /**
     * Add custom column to the Media Library list view
     * 
     * @param array $columns Array of columns
     * @return array Modified columns
     */
    public function add_media_library_column($columns) {
        $columns['bunny_cdn_url'] = __('Bunny CDN URL', 'bunny-auto-uploader');
        return $columns;
    }
    
    /**
     * Display content for the custom column in Media Library list view
     * 
     * @param string $column_name Column name
     * @param int $post_id Post ID
     */
    public function display_media_library_column_content($column_name, $post_id) {
        if ('bunny_cdn_url' !== $column_name) {
            return;
        }
        
        // Check if this is an audio attachment
        $post = get_post($post_id);
        if (!$post || strpos($post->post_mime_type, 'audio') !== 0) {
            echo '<span class="bunny-not-applicable">—</span>';
            return;
        }
        
        // Get the Bunny CDN URL
        $bunny_cdn_url = get_post_meta($post_id, '_bunny_cdn_url', true);
        
        if (!empty($bunny_cdn_url)) {
            echo '<div class="bunny-cdn-url-wrapper">';
            echo '<input type="text" class="bunny-cdn-url-readonly" value="' . esc_attr($bunny_cdn_url) . '" readonly onclick="this.select();" />';
            echo '<a href="' . esc_url($bunny_cdn_url) . '" target="_blank" class="bunny-cdn-open" title="' . esc_attr__('Open URL', 'bunny-auto-uploader') . '">';
            echo '<span class="dashicons dashicons-external"></span>';
            echo '</a>';
            echo '</div>';
        } else {
            // Check if upload failed previously
            $upload_failed = get_post_meta($post_id, '_bunny_cdn_upload_failed', true);
            
            if ($upload_failed) {
                echo '<span class="bunny-upload-failed">' . __('Upload Failed', 'bunny-auto-uploader') . '</span>';
                echo ' <a href="#" class="bunny-retry-upload" data-attachment-id="' . esc_attr($post_id) . '">' . __('Retry', 'bunny-auto-uploader') . '</a>';
            } else {
                echo '<a href="#" class="bunny-upload-now" data-attachment-id="' . esc_attr($post_id) . '">' . __('Upload to Bunny', 'bunny-auto-uploader') . '</a>';
            }
        }
    }
    
    /**
     * Add custom CSS for the Media Library column
     */
    public function add_media_library_column_style() {
        $current_screen = get_current_screen();
        
        // Only apply on media library screen
        if (!$current_screen || 'upload' !== $current_screen->base) {
            return;
        }
        
        ?>
        <style type="text/css">
            .column-bunny_cdn_url {
                width: 25%;
            }
            
            .bunny-cdn-url-wrapper {
                display: flex;
                align-items: center;
            }
            
            .bunny-cdn-url-readonly {
                width: 85%;
                background: #f0f0f0;
                border: 1px solid #ddd;
                padding: 4px 8px;
                font-size: 12px;
                font-family: monospace;
                color: #555;
            }
            
            .bunny-cdn-open {
                margin-left: 5px;
                color: #0073aa;
                text-decoration: none;
            }
            
            .bunny-cdn-open:hover {
                color: #00a0d2;
            }
            
            .bunny-upload-failed {
                color: #dc3232;
            }
            
            .bunny-not-applicable {
                color: #999;
            }
            
            .bunny-retry-upload,
            .bunny-upload-now {
                color: #0073aa;
                text-decoration: none;
                cursor: pointer;
            }
            
            .bunny-retry-upload:hover,
            .bunny-upload-now:hover {
                color: #00a0d2;
                text-decoration: underline;
            }
        </style>
        <?php
    }
    
    /**
     * Add admin notice for successful uploads
     *
     * @param string $filename The uploaded filename
     * @param string $cdn_url The Bunny CDN URL
     */
    public function add_success_notice($filename, $cdn_url) {
        // Only add the notice for admin users
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Store the notice data in a transient specific to the current user
        $notices = get_transient('bunny_upload_notices_' . get_current_user_id()) ?: array();
        $notices[] = array(
            'type' => 'success',
            'filename' => $filename,
            'cdn_url' => $cdn_url,
            'time' => time()
        );
        set_transient('bunny_upload_notices_' . get_current_user_id(), $notices, 60);
    }
    
    /**
     * Add admin notice for failed uploads
     *
     * @param string $filename The uploaded filename
     * @param string $error_message The error message
     * @param int $attachment_id The attachment ID
     */
    public function add_error_notice($filename, $error_message, $attachment_id = 0) {
        // Only add the notice for admin users
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Store the notice data in a transient specific to the current user
        $notices = get_transient('bunny_upload_notices_' . get_current_user_id()) ?: array();
        $notices[] = array(
            'type' => 'error',
            'filename' => $filename,
            'message' => $error_message,
            'attachment_id' => $attachment_id,
            'time' => time()
        );
        set_transient('bunny_upload_notices_' . get_current_user_id(), $notices, 60);
    }
    
    /**
     * Display admin notices for Bunny uploads
     */
    public function display_admin_notices() {
        // Only show notices to administrators
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get notices from transient
        $notices = get_transient('bunny_upload_notices_' . get_current_user_id());
        
        if (empty($notices)) {
            return;
        }
        
        // Display each notice
        foreach ($notices as $notice) {
            if ($notice['type'] === 'success') {
                ?>
                <div class="notice notice-success is-dismissible bunny-admin-notice">
                    <h3><?php _e('Bunny CDN Upload Successful', 'bunny-auto-uploader'); ?></h3>
                    <p>
                        <?php echo sprintf(__('The file "%s" was successfully uploaded to Bunny CDN.', 'bunny-auto-uploader'), '<strong>' . esc_html($notice['filename']) . '</strong>'); ?>
                    </p>
                    <p class="bunny-cdn-url-display">
                        <strong><?php _e('CDN URL:', 'bunny-auto-uploader'); ?></strong> 
                        <a href="<?php echo esc_url($notice['cdn_url']); ?>" target="_blank" class="bunny-notice-url">
                            <?php echo esc_html($notice['cdn_url']); ?>
                        </a>
                    </p>
                </div>
                <?php
            } elseif ($notice['type'] === 'error') {
                ?>
                <div class="notice notice-error is-dismissible bunny-admin-notice">
                    <h3><?php _e('Bunny CDN Upload Failed', 'bunny-auto-uploader'); ?></h3>
                    <p>
                        <?php echo sprintf(__('Failed to upload "%s" to Bunny CDN.', 'bunny-auto-uploader'), '<strong>' . esc_html($notice['filename']) . '</strong>'); ?>
                    </p>
                    <p class="bunny-error-message">
                        <strong><?php _e('Error:', 'bunny-auto-uploader'); ?></strong> 
                        <?php echo esc_html($notice['message']); ?>
                    </p>
                    <?php if (!empty($notice['attachment_id'])) : ?>
                    <p class="bunny-error-actions">
                        <a href="<?php echo admin_url('post.php?post=' . $notice['attachment_id'] . '&action=edit'); ?>" class="button button-small">
                            <?php _e('View Attachment', 'bunny-auto-uploader'); ?>
                        </a>
                        <a href="<?php echo admin_url('options-general.php?page=bunny-auto-uploader'); ?>" class="button button-small">
                            <?php _e('View Settings', 'bunny-auto-uploader'); ?>
                        </a>
                    </p>
                    <?php endif; ?>
                </div>
                <?php
            }
        }
        
        // Clear the notices
        delete_transient('bunny_upload_notices_' . get_current_user_id());
    }
    
    /**
     * Register custom callback for JetEngine dynamic fields
     *
     * @param array $callbacks Array of registered callbacks
     * @return array Modified array of callbacks
     */
    public function register_jetengine_callback($callbacks) {
        $callbacks['jetengine_audio_stream_get_audio_url'] = array(
            'label'    => __('Bunny CDN Audio URL', 'bunny-auto-uploader'),
            'group'    => 'audio',
            'callback' => array($this, 'jetengine_audio_stream_get_audio_url'),
        );
        
        return $callbacks;
    }
    
    /**
     * JetEngine callback to get audio URL from Bunny CDN
     *
     * @param int $post_id The post ID or attachment ID
     * @param array $settings JetEngine field settings (not used)
     * @return string The audio URL (Bunny CDN URL if available, otherwise standard attachment URL)
     */
    public function jetengine_audio_stream_get_audio_url($post_id, $settings = array()) {
        // Try to get the Bunny CDN URL directly from the post
        $bunny_cdn_url = get_post_meta($post_id, '_bunny_cdn_url', true);
        
        // If the Bunny CDN URL exists and is valid, return it
        if (!empty($bunny_cdn_url) && filter_var($bunny_cdn_url, FILTER_VALIDATE_URL)) {
            // Log that we're using the direct Bunny CDN URL
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->log_debug('JetEngine using direct Bunny CDN URL for post #' . $post_id);
            }
            return $bunny_cdn_url;
        }
        
        // If no direct CDN URL, check if there's a recording meta field with attachment ID
        $recording_id = get_post_meta($post_id, 'recording', true);
        
        if (!empty($recording_id) && is_numeric($recording_id)) {
            // Check if this attachment has a Bunny CDN URL
            $attachment_cdn_url = get_post_meta($recording_id, '_bunny_cdn_url', true);
            
            if (!empty($attachment_cdn_url) && filter_var($attachment_cdn_url, FILTER_VALIDATE_URL)) {
                // Log that we're using the recording's Bunny CDN URL
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $this->log_debug('JetEngine using Bunny CDN URL from recording attachment #' . $recording_id . ' for post #' . $post_id);
                }
                return $attachment_cdn_url;
            }
            
            // If no CDN URL for the attachment, return the standard attachment URL
            return wp_get_attachment_url($recording_id);
        }
        
        // If all else fails, assume $post_id is the attachment ID and return its URL
        return wp_get_attachment_url($post_id);
    }
    
    /**
     * Upload large file to Bunny.net using direct cURL
     * 
     * This method is used for very large files (>50MB) that might exceed PHP memory limits
     * when loaded with file_get_contents(). It uses direct cURL instead of wp_remote_request().
     * 
     * @param string $file_path Path to the file to upload
     * @param string $storage_zone Bunny storage zone name
     * @param string $api_key Bunny API key
     * @return bool True on success, false on failure
     */
    private function upload_large_file_to_bunny($file_path, $storage_zone, $api_key) {
        // Check if we should use FTP instead
        $use_ftp = get_option('bunny_auto_uploader_use_ftp', false);
        if ($use_ftp) {
            // For FTP uploads, we don't need to handle large files differently
            // because FTP handles streaming by default
            $cdn_url = $this->upload_to_bunny_ftp($file_path);
            return $cdn_url !== false;
        }
    
        // Check if cURL is available
        if (!function_exists('curl_init') || !function_exists('curl_exec')) {
            $this->log_error('cURL is not available for large file upload', 0, 'curl_not_available');
            return false;
        }
        
        // Get file details
        $filename = basename($file_path);
        $file_size = filesize($file_path);
        
        // Construct the Bunny.net Storage URL - using the correct API endpoint
        $storage_url = 'https://storage.bunnycdn.com/' . $storage_zone . '/' . $filename;
        
        // Initialize cURL
        $ch = curl_init($storage_url);
        
        // Open file for reading
        $fp = fopen($file_path, 'rb');
        if (!$fp) {
            $this->log_error('Could not open file for cURL upload: ' . $file_path, 0, 'curl_file_open_error');
            return false;
        }
        
        // Set cURL options
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, $file_size);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600); // 10-minute timeout for large files
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'AccessKey: ' . $api_key,
            'Content-Type: application/octet-stream'
        ));
        
        // Execute the request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Check for errors
        if (curl_errno($ch)) {
            $error_message = curl_error($ch);
            $this->log_error(
                'cURL error during large file upload: ' . $error_message,
                0,
                'curl_error_' . curl_errno($ch)
            );
            curl_close($ch);
            fclose($fp);
            return false;
        }
        
        // Close resources
        curl_close($ch);
        fclose($fp);
        
        // Check response code
        if ($http_code >= 200 && $http_code < 300) {
            $this->log_debug('Successfully uploaded large file to Bunny.net: ' . $filename);
            return true;
        } else {
            $this->log_error(
                'HTTP error during large file upload: ' . $http_code . ' - ' . $response,
                0,
                'http_error_' . $http_code
            );
            return false;
        }
    }
    
    /**
     * Upload file to Bunny.net
     * 
     * Main upload function that tries different methods in order of reliability
     * 
     * @param string $file_path Path to the file to upload
     * @param int $retry_attempt Current retry attempt number (default 0)
     * @param int $max_retries Maximum number of retry attempts (default 2)
     * @return string|false CDN URL on success, false on failure
     */
    private function upload_to_bunny($file_path, $retry_attempt = 0, $max_retries = 2) {
        // Check if we should use FTP instead of API
        $use_ftp = get_option('bunny_auto_uploader_use_ftp', false);
        
        if ($use_ftp) {
            // If FTP is specifically selected, use that method
            return $this->upload_to_bunny_ftp($file_path);
        }
        
        // First try HTTP API method (most reliable)
        $result = $this->upload_to_bunny_http($file_path);
        if ($result !== false) {
            return $result;
        }
        
        // If HTTP API failed, try FTP as fallback
        $this->log_debug('HTTP API upload failed, trying FTP as fallback');
        return $this->upload_to_bunny_ftp($file_path);
    }
    
    /**
     * Process newly added attachment
     * 
     * @param int $post_id The attachment ID
     */
    public function process_new_attachment($post_id) {
        // Check if this is an audio file
        $mime_type = get_post_mime_type($post_id);
        if (strpos($mime_type, 'audio/') !== 0) {
            return;
        }
        
        // Check if already uploaded to Bunny CDN
        $existing_cdn_url = get_post_meta($post_id, '_bunny_cdn_url', true);
        if (!empty($existing_cdn_url)) {
            return;
        }
        
        // Get file path
        $file_path = get_attached_file($post_id);
        if (!$file_path || !file_exists($file_path)) {
            $this->log_error('File not found for new attachment: ' . $post_id, $post_id, 'file_not_found');
            return;
        }
        
        // Get file URL
        $file_url = wp_get_attachment_url($post_id);
        $this->log_debug('Processing new audio attachment: ' . basename($file_path) . ' (ID: ' . $post_id . ')');
        
        // Check if settings are configured
        $settings = $this->get_bunny_settings();
        if (!$settings) {
            $this->log_error('Bunny.net settings not configured. Please set up the plugin before uploading.', $post_id, 'settings_not_configured');
            
            // Schedule admin notice about missing settings
            $this->display_admin_notice_for_missing_settings();
            return;
        }
        
        // Upload to Bunny.net
        $bunny_cdn_url = $this->upload_to_bunny($file_path);
        
        // Save the CDN URL as attachment meta if successful
        if ($bunny_cdn_url) {
            update_post_meta($post_id, '_bunny_cdn_url', $bunny_cdn_url);
            
            // Add upload timestamp
            update_post_meta($post_id, '_bunny_cdn_upload_time', time());
            
            // Log success
            $this->log_debug('Successfully uploaded new attachment to Bunny.net: ' . basename($file_path));
            
            // Add admin notice for successful upload
            $this->add_success_notice(basename($file_path), $bunny_cdn_url);
        } else {
            // Get error message
            $error = get_post_meta($post_id, '_bunny_upload_error', true);
            $error_message = !empty($error['message']) 
                ? $error['message'] 
                : 'Unknown error occurred during upload.';
            
            // Log failure
            $this->log_error('Failed to upload new attachment to Bunny.net: ' . basename($file_path), $post_id, 'upload_failed');
            
            // Set failed upload flag on the attachment
            update_post_meta($post_id, '_bunny_cdn_upload_failed', '1');
            update_post_meta($post_id, '_bunny_cdn_upload_attempt_time', time());
            
            // Add admin notice for failed upload
            $this->add_error_notice(basename($file_path), $error_message, $post_id);
        }
    }
    
    /**
     * Display admin notice for missing or incomplete settings
     */
    public function display_admin_notice_for_missing_settings() {
        // Only show to admin users
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get settings to determine what's missing
        $settings = array(
            'api_key' => get_option('bunny_auto_uploader_api_key', ''),
            'storage_zone' => get_option('bunny_auto_uploader_storage_zone', ''),
            'storage_region' => get_option('bunny_auto_uploader_storage_region', ''),
            'pull_zone_url' => get_option('bunny_auto_uploader_pull_zone_url', ''),
            'use_ftp' => get_option('bunny_auto_uploader_use_ftp', false),
            'ftp_username' => get_option('bunny_auto_uploader_ftp_username', ''),
            'ftp_password' => get_option('bunny_auto_uploader_ftp_password', '')
        );
        
        // Determine which settings are missing
        $missing = array();
        if (empty($settings['storage_zone'])) $missing[] = 'Storage Zone';
        if (empty($settings['api_key']) && !$settings['use_ftp']) $missing[] = 'API Key';
        if (empty($settings['ftp_username']) && $settings['use_ftp']) $missing[] = 'FTP Username';
        if (empty($settings['ftp_password']) && $settings['use_ftp']) $missing[] = 'FTP Password';
        if (empty($settings['pull_zone_url'])) $missing[] = 'Pull Zone Hostname';
        
        if (empty($missing)) {
            return; // All required settings are present
        }
        
        // Add the notice to be displayed
        add_action('admin_notices', function() use ($missing) {
            ?>
            <div class="notice notice-error is-dismissible">
                <h3><?php _e('Bunny Auto Uploader: Missing Required Settings', 'bunny-auto-uploader'); ?></h3>
                <p><?php _e('The following required settings are missing or incomplete:', 'bunny-auto-uploader'); ?></p>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <?php foreach ($missing as $field): ?>
                    <li><strong><?php echo esc_html($field); ?></strong></li>
                    <?php endforeach; ?>
                </ul>
                <p>
                    <?php _e('Audio files will not be uploaded to Bunny CDN until these settings are configured.', 'bunny-auto-uploader'); ?>
                    <a href="<?php echo admin_url('options-general.php?page=bunny-auto-uploader'); ?>" class="button button-secondary">
                        <?php _e('Configure Settings', 'bunny-auto-uploader'); ?>
                    </a>
                </p>
            </div>
            <?php
        });
    }
    
    /**
     * Get and validate Bunny.net settings
     * 
     * @return array|false Array of validated settings or false if required settings are missing
     */
    public function get_bunny_settings() {
        // Get settings
        $settings = array(
            'api_key' => get_option('bunny_auto_uploader_api_key', ''),
            'storage_zone' => get_option('bunny_auto_uploader_storage_zone', ''),
            'storage_region' => get_option('bunny_auto_uploader_storage_region', ''),
            'pull_zone_url' => get_option('bunny_auto_uploader_pull_zone_url', ''),
            'use_ftp' => get_option('bunny_auto_uploader_use_ftp', false),
            'ftp_host' => get_option('bunny_auto_uploader_ftp_host', 'storage.bunnycdn.com'),
            'ftp_username' => get_option('bunny_auto_uploader_ftp_username', ''),
            'ftp_password' => get_option('bunny_auto_uploader_ftp_password', '')
        );
        
        // Determine the storage endpoint based on region
        if (!empty($settings['storage_region'])) {
            $settings['storage_endpoint'] = $settings['storage_region'] . '.storage.bunnycdn.com';
        } else {
            $settings['storage_endpoint'] = 'storage.bunnycdn.com';
        }
        
        // Format pull zone URL
        if (!empty($settings['pull_zone_url'])) {
            $settings['pull_zone_url'] = rtrim($settings['pull_zone_url'], '/') . '/';
        }
        
        // Check required settings
        if (empty($settings['storage_zone']) || 
            (empty($settings['api_key']) && !$settings['use_ftp']) || 
            (empty($settings['ftp_username']) && $settings['use_ftp']) || 
            (empty($settings['ftp_password']) && $settings['use_ftp']) || 
            empty($settings['pull_zone_url'])) {
            
            // Log which settings are missing
            $missing = array();
            if (empty($settings['storage_zone'])) $missing[] = 'Storage Zone';
            if (empty($settings['api_key']) && !$settings['use_ftp']) $missing[] = 'API Key';
            if (empty($settings['ftp_username']) && $settings['use_ftp']) $missing[] = 'FTP Username';
            if (empty($settings['ftp_password']) && $settings['use_ftp']) $missing[] = 'FTP Password';
            if (empty($settings['pull_zone_url'])) $missing[] = 'Pull Zone URL';
            
            $this->log_error('Missing required Bunny.net settings: ' . implode(', ', $missing), 0, 'missing_settings');
            
            return false;
        }
        
        return $settings;
    }
}

// Initialize the plugin
new Bunny_Auto_Uploader(); 