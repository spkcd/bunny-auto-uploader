<?php
/**
 * Plugin Name: Bunny Auto Uploader
 * Description: Automatically uploads audio files (.mp3, .wav, .m4a) to Bunny.net CDN when added to the Media Library
 * Version: 2.1.0
 * Author: SPARKWEB Studio
 * Author URI: https://sparkwebstudio.com/
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
        // Log plugin initialization
        $this->log_debug('Bunny Auto Uploader plugin initialized - hooks being registered');
        
        // Hook into WordPress media upload
        add_filter('wp_handle_upload', array($this, 'handle_audio_upload'), 10, 2);
        
        // Hook into new attachment creation
        add_action('add_attachment', array($this, 'process_new_attachment'));
        
        // Add support for .m4a files
        add_filter('upload_mimes', array($this, 'add_audio_mime_types'));
        add_filter('wp_check_filetype_and_ext', array($this, 'check_audio_filetype'), 10, 4);
        

        
        // Chunked upload approach - COMPLETELY DISABLED to prevent file fragmentation
        // Instead: Increase chunk size to prevent chunking for most files
        add_filter('plupload_default_settings', array($this, 'prevent_chunked_uploads'), 10, 1);
        
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
        
        // Handle direct Bunny.net upload (bypasses WordPress limits)
        add_action('wp_ajax_bunny_direct_upload', array($this, 'ajax_direct_upload_to_bunny'));
        
        // Handle registration of direct uploads in Media Library
        add_action('wp_ajax_bunny_register_direct_upload', array($this, 'ajax_register_direct_upload'));
        
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
        
        // Add large file upload notice
        add_action('admin_notices', array($this, 'display_large_file_notice'));
        
        // Replace default WordPress media uploader with Direct Bunny Upload (if enabled)
        $replace_default = get_option('bunny_replace_default_uploader', '0');
        error_log("🔍 Bunny: Replace default uploader setting: " . $replace_default);
        
        if ($replace_default === '1') {
            error_log("✅ Bunny: Default uploader replacement ENABLED");
            add_action('admin_enqueue_scripts', array($this, 'replace_default_uploader'));
            add_action('wp_enqueue_scripts', array($this, 'replace_frontend_uploader'));
            
            // Override upload handlers for all contexts
            add_filter('plupload_default_settings', array($this, 'override_plupload_settings'));
            add_filter('upload_dir', array($this, 'override_upload_dir'));
            
            // EMERGENCY BACKUP: Also load on all admin pages just in case
            add_action('admin_head', array($this, 'emergency_script_injection'));
        } else {
            error_log("❌ Bunny: Default uploader replacement DISABLED");
        }
        
        // Register JetEngine custom callback
        add_filter('jet-engine/listings/dynamic-field/custom-callbacks', array($this, 'register_jetengine_callback'));
    }
    
    /**
     * Add support for additional audio file types
     * 
     * @param array $mime_types Current allowed mime types
     * @return array Modified mime types
     */
    public function add_audio_mime_types($mime_types) {
        // Add support for .m4a files
        $mime_types['m4a'] = 'audio/mp4';
        
        // Also ensure other audio types are supported
        $mime_types['mp3'] = 'audio/mpeg';
        $mime_types['wav'] = 'audio/wav';
        $mime_types['ogg'] = 'audio/ogg';
        $mime_types['flac'] = 'audio/flac';
        
        return $mime_types;
    }
    
    /**
     * Check and validate audio file types
     * 
     * @param array $wp_check_filetype_and_ext File data array
     * @param string $file Full path to the file
     * @param string $filename The name of the file
     * @param array $mimes Key is the file extension with value as the mime type
     * @return array Modified file data array
     */
    public function check_audio_filetype($wp_check_filetype_and_ext, $file, $filename, $mimes) {
        // REMOVED all chunked upload interference - was causing file fragmentation
        
        // Only do regular file type checking for non-chunked uploads
        if (!$wp_check_filetype_and_ext['type']) {
            $check_filetype = wp_check_filetype($filename, $mimes);
            $ext = $check_filetype['ext'];
            $type = $check_filetype['type'];
            $proper_filename = $filename;
            
            // Check for specific audio file extensions
            if ($type && 0 === strpos($type, 'audio/')) {
                $wp_check_filetype_and_ext['ext'] = $ext;
                $wp_check_filetype_and_ext['type'] = $type;
                $wp_check_filetype_and_ext['proper_filename'] = $proper_filename;
            }
        }
        
        return $wp_check_filetype_and_ext;
    }
    

    

    

    
    /**
     * Prevent chunked uploads by setting very large chunk sizes
     * This forces uploads to happen as single files, avoiding chunking issues
     */
    public function prevent_chunked_uploads($settings) {
        // Set chunk size to the maximum allowed upload size to prevent chunking
        $max_upload = wp_max_upload_size();
        $max_upload_mb = round($max_upload / 1024 / 1024);
        
        // Set chunk size to match max upload size (prevents chunking)
        $settings['chunk_size'] = $max_upload_mb . 'mb';
        
        // Keep max file size reasonable
        $settings['max_file_size'] = $max_upload_mb . 'mb';
        
        $this->log_debug('PLUPLOAD: Preventing chunked uploads - chunk size: ' . $settings['chunk_size'] . ', max file: ' . $settings['max_file_size']);
        
        return $settings;
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_styles($hook) {
        $plugin_dir_url = plugin_dir_url(__FILE__);
        
        // Only load on our settings page or attachment edit screen or media pages
        if ($hook === 'settings_page_bunny-auto-uploader' || $hook === 'post.php' || $hook === 'upload.php' || $hook === 'media-new.php') {
            // Enqueue CSS
            wp_enqueue_style('bunny-auto-uploader-admin', $plugin_dir_url . 'assets/css/admin.css', array(), '2.1.0');
            
            // Enqueue JavaScript
            wp_enqueue_script('bunny-auto-uploader-admin', $plugin_dir_url . 'assets/js/admin.js', array('jquery'), '2.1.0', true);
            
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
            
            // Add direct upload JavaScript for settings page
            if ($hook === 'settings_page_bunny-auto-uploader') {
                wp_add_inline_script('bunny-auto-uploader-admin', $this->get_direct_upload_script());
                wp_localize_script('bunny-auto-uploader-admin', 'bunnyDirectUpload', array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'adminurl' => admin_url(),
                    'nonce' => wp_create_nonce('bunny_direct_upload'),
                    'uploading' => __('Uploading to Bunny.net...', 'bunny-auto-uploader'),
                    'success' => __('Upload completed successfully!', 'bunny-auto-uploader'),
                    'error' => __('Upload failed. Please try again.', 'bunny-auto-uploader')
                ));
            }
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
                    in_array(strtolower($file_type['ext']), array('mp3', 'wav', 'm4a', 'ogg', 'flac'));
        
        if ($is_audio) {
            $this->log_debug('Audio file detected: ' . basename($upload['file']) . ' (' . round(filesize($upload['file']) / 1024 / 1024, 2) . 'MB)');
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
        
        // Check file size - if larger than 50MB, use the large file upload method
        $file_size = filesize($file_path);
        $size_limit = 50 * 1024 * 1024; // 50MB in bytes
        
        if ($file_size > $size_limit) {
            $this->log_debug('File is large (' . round($file_size / 1024 / 1024, 2) . 'MB), using large file upload method');
            
            // Use the specialized large file upload method
            $upload_success = $this->upload_large_file_to_bunny($file_path, $storage_zone, $access_key);
            
            if ($upload_success) {
                // Return the CDN URL
                $cdn_url = $pull_zone_url . $filename;
                return $cdn_url;
            } else {
                return false;
            }
        }
        
        $this->log_debug('Attempting HTTP API upload to Bunny.net storage (file size: ' . round($file_size / 1024 / 1024, 2) . 'MB)');
        
        // Read file contents for smaller files only
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
            $error_message = 'HTTP API HTTP error: ' . $http_code;
            if ($response) {
                $error_message .= ', Response: ' . substr($response, 0, 200);
            }
            
            // Provide specific error messages for common HTTP codes
            switch ($http_code) {
                case 401:
                    $error_message .= ' - Invalid API key or authentication failed';
                    break;
                case 403:
                    $error_message .= ' - Access forbidden. Check your API key permissions';
                    break;
                case 404:
                    $error_message .= ' - Storage zone not found. Check your storage zone name';
                    break;
                case 429:
                    $error_message .= ' - Rate limit exceeded. Please try again later';
                    break;
                case 500:
                    $error_message .= ' - Server error. Please try again later';
                    break;
            }
            
            $this->log_error($error_message, $attachment_id, 'http_error_' . $http_code);
            
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
            $error_message = 'WP HTTP API HTTP error: ' . $response_code;
            if ($body) {
                $error_message .= ', Response: ' . substr($body, 0, 200);
            }
            
            // Provide specific error messages for common HTTP codes
            switch ($response_code) {
                case 401:
                    $error_message .= ' - Invalid API key or authentication failed';
                    break;
                case 403:
                    $error_message .= ' - Access forbidden. Check your API key permissions';
                    break;
                case 404:
                    $error_message .= ' - Storage zone not found. Check your storage zone name';
                    break;
                case 429:
                    $error_message .= ' - Rate limit exceeded. Please try again later';
                    break;
                case 500:
                    $error_message .= ' - Server error. Please try again later';
                    break;
            }
            
            $this->log_error(
                $error_message,
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
        // Always log debug messages for troubleshooting
        error_log('Bunny Auto Uploader DEBUG: ' . $message);
        
        // Also store in a custom option for easy viewing
        $debug_logs = get_option('bunny_auto_uploader_debug_log', array());
        $debug_logs[] = array(
            'time' => current_time('mysql'),
            'message' => $message
        );
        
        // Keep only last 50 debug messages
        if (count($debug_logs) > 50) {
            $debug_logs = array_slice($debug_logs, -50);
        }
        
        update_option('bunny_auto_uploader_debug_log', $debug_logs);
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
        
        // Replace Default Uploader setting
        register_setting('bunny-auto-uploader', 'bunny_replace_default_uploader');
        add_settings_field(
            'bunny_auto_uploader_use_ftp',
            __('Use FTP Instead of API', 'bunny-auto-uploader'),
            array($this, 'render_use_ftp_field'),
            'bunny-auto-uploader',
            'bunny_auto_uploader_ftp_settings_section'
        );
        
        // Add Replace Default Uploader field
        add_settings_field(
            'bunny_replace_default_uploader',
            '🚀 Replace Default WordPress Uploader',
            array($this, 'render_replace_default_uploader_field'),
            'bunny-auto-uploader',
            'bunny_auto_uploader_settings_section'
        );
    }
    
    /**
     * Render settings section
     */
    public function render_settings_section() {
        echo '<p>' . __('Configure your Bunny.net Storage Zone settings below. Storage Zones use FTP access only.', 'bunny-auto-uploader') . '</p>';
        echo '<p><strong>Note:</strong> Bunny.net Storage Zones do not have HTTP API access. This plugin uses FTP for uploads.</p>';
    }
    
    /**
     * Render API key field (DEPRECATED - not used for Storage Zones)
     */
    public function render_api_key_field() {
        $api_key = get_option('bunny_auto_uploader_api_key');
        echo '<input type="text" name="bunny_auto_uploader_api_key" value="' . esc_attr($api_key) . '" class="regular-text" disabled>';
        echo '<p class="description" style="color: #999;">' . __('NOT REQUIRED: Storage Zones use FTP, not API keys. This field is disabled.', 'bunny-auto-uploader') . '</p>';
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
        echo '<p>' . __('<strong>REQUIRED:</strong> Bunny.net Storage Zones only support FTP access. Enter your FTP credentials below.', 'bunny-auto-uploader') . '</p>';
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
     * Render Use FTP field (REMOVED - FTP is always used for Storage Zones)
     */
    public function render_use_ftp_field() {
        // Always use FTP for Storage Zones - this setting is deprecated but kept for compatibility
        echo '<input type="hidden" name="bunny_auto_uploader_use_ftp" value="1">';
        echo '<input type="checkbox" checked disabled> <strong>FTP Upload (Required)</strong>';
        echo '<p class="description">' . __('Storage Zones require FTP access. This setting is always enabled.', 'bunny-auto-uploader') . '</p>';
    }
    
    /**
     * Render Replace Default Uploader field
     */
    public function render_replace_default_uploader_field() {
        $replace_default = get_option('bunny_replace_default_uploader', '0');
        echo '<input type="checkbox" name="bunny_replace_default_uploader" value="1" ' . checked($replace_default, '1', false) . '>';
        echo ' <strong>Replace WordPress Default Uploader with Bunny Direct Upload</strong>';
        echo '<p class="description">' . __('When enabled, ALL audio file uploads in WordPress (Media Library, Post Editor, etc.) will use direct Bunny.net upload, bypassing server limits completely. Maximum file size: 10GB.', 'bunny-auto-uploader') . '</p>';
        echo '<p class="description" style="color: #d63638;"><strong>Advanced Feature:</strong> This will change how ALL audio uploads work across your entire site.</p>';
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
        $replace_default = get_option('bunny_replace_default_uploader', '0');
        echo "<div style='background:#f8f8f8;padding:10px;border:1px solid #ddd;margin-bottom:15px;'>";
        echo "<strong>Debug:</strong> FTP Host: $ftp_host<br>";
        echo "<strong>Debug:</strong> FTP Username: $ftp_username<br>";
        echo "<strong>Debug:</strong> FTP Password: " . (empty($ftp_password) ? "empty" : "set") . "<br>";
        echo "<strong>Debug:</strong> Use FTP: " . ($use_ftp ? "yes" : "no") . "<br>";
        echo "<strong>Debug:</strong> Pull Zone URL: " . (empty($pull_zone_url) ? "empty" : $pull_zone_url) . "<br>";
        echo "<strong>Debug:</strong> Replace Default Uploader: " . ($replace_default === '1' ? "✅ ENABLED" : "❌ DISABLED") . "<br>";
        echo "</div>";
        ?>
        <div class="wrap">
            <div class="bunny-auto-uploader-header">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <p><?php _e('Configure your Bunny.net integration settings below.', 'bunny-auto-uploader'); ?></p>
            </div>
            
            <div class="bunny-info-box">
                <p><?php _e('This plugin automatically uploads audio files (.mp3, .wav, .m4a) to Bunny.net when they are added to the Media Library.', 'bunny-auto-uploader'); ?></p>
                <?php if (get_option('bunny_replace_default_uploader', '0') === '1') : ?>
                    <div style="background: #e7f7ff; border: 1px solid #46b1c9; padding: 10px; margin: 10px 0; border-radius: 4px;">
                        <strong>🚀 Default Uploader Replacement Active!</strong><br>
                        All audio uploads across WordPress now use direct Bunny.net upload with unlimited file sizes.
                    </div>
                <?php endif; ?>
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
            
            <!-- Server Information Section -->
            <div class="bunny-server-info">
                <h2><?php _e('Server Upload Limits', 'bunny-auto-uploader'); ?></h2>
                <p><?php _e('Current server configuration for file uploads:', 'bunny-auto-uploader'); ?></p>
                
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><strong><?php _e('PHP Upload Max Filesize', 'bunny-auto-uploader'); ?></strong></td>
                            <td><?php echo ini_get('upload_max_filesize'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('PHP Post Max Size', 'bunny-auto-uploader'); ?></strong></td>
                            <td><?php echo ini_get('post_max_size'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('PHP Max Execution Time', 'bunny-auto-uploader'); ?></strong></td>
                            <td><?php echo ini_get('max_execution_time'); ?> seconds</td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('PHP Memory Limit', 'bunny-auto-uploader'); ?></strong></td>
                            <td><?php echo ini_get('memory_limit'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('WordPress Max Upload Size', 'bunny-auto-uploader'); ?></strong></td>
                            <td><?php echo size_format(wp_max_upload_size()); ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <?php
                $max_size_bytes = wp_max_upload_size();
                $max_size_mb = round($max_size_bytes / 1024 / 1024, 2);
                if ($max_size_mb < 500) {
                    echo '<div class="notice notice-warning inline">';
                    echo '<p><strong>' . __('Warning:', 'bunny-auto-uploader') . '</strong> ';
                    echo sprintf(__('Your current upload limit is %sMB, which may be too small for large audio files (485MB). Contact your hosting provider to increase these limits.', 'bunny-auto-uploader'), $max_size_mb);
                    echo '</p></div>';
                }
                ?>
            </div>
            
            <!-- Debug Log Section -->
            <div class="bunny-debug-log">
                <h2><?php _e('Debug Log', 'bunny-auto-uploader'); ?></h2>
                <p><?php _e('Recent plugin activity and debug messages:', 'bunny-auto-uploader'); ?></p>
                
                <?php 
                // Clear debug log if requested
                if (isset($_POST['bunny_clear_debug']) && check_admin_referer('bunny_clear_debug', 'bunny_clear_debug_nonce')) {
                    delete_option('bunny_auto_uploader_debug_log');
                    echo '<div class="notice notice-success inline"><p>' . __('Debug log cleared successfully.', 'bunny-auto-uploader') . '</p></div>';
                }
                
                // Get stored debug logs
                $debug_logs = get_option('bunny_auto_uploader_debug_log', array());
                
                if (empty($debug_logs)) {
                    echo '<p>' . __('No debug messages yet. Upload a file to see activity.', 'bunny-auto-uploader') . '</p>';
                } else {
                    ?>
                    <div style="background: #f1f1f1; padding: 10px; max-height: 400px; overflow-y: auto; border: 1px solid #ccc;">
                        <?php
                        foreach (array_reverse($debug_logs) as $log) {
                            echo '<div style="margin-bottom: 5px; font-family: monospace; font-size: 12px;">';
                            echo '<strong>' . esc_html($log['time']) . ':</strong> ';
                            echo esc_html($log['message']);
                            echo '</div>';
                        }
                        ?>
                    </div>
                    
                    <form method="post" action="" style="margin-top: 10px;">
                        <?php wp_nonce_field('bunny_clear_debug', 'bunny_clear_debug_nonce'); ?>
                        <input type="submit" name="bunny_clear_debug" class="button" value="<?php _e('Clear Debug Log', 'bunny-auto-uploader'); ?>">
                    </form>
                    <?php
                }
                ?>
            </div>
            
            <!-- Direct Bunny.net Upload Section -->
            <div class="bunny-direct-upload">
                <h2><?php _e('🚀 Direct Upload to Bunny.net', 'bunny-auto-uploader'); ?></h2>
                <p><strong><?php _e('Upload any size file directly to Bunny.net, bypassing ALL server limits!', 'bunny-auto-uploader'); ?></strong></p>
                <p><?php _e('This method uploads directly from your browser to Bunny.net, avoiding Cloudflare and server limits entirely.', 'bunny-auto-uploader'); ?></p>
                
                <div class="bunny-upload-methods">
                    <div style="display: flex; gap: 20px; margin: 15px 0;">
                        <label style="display: flex; align-items: center; gap: 5px;">
                            <input type="radio" name="upload_method" value="server" checked>
                            <strong>Via Server</strong> (up to <?php echo wp_max_upload_size() ? size_format(wp_max_upload_size()) : '100MB'; ?> - limited by Cloudflare)
                        </label>
                        <label style="display: flex; align-items: center; gap: 5px;">
                            <input type="radio" name="upload_method" value="direct">
                            <strong>🚀 Direct Upload</strong> (unlimited size - bypasses server completely)
                        </label>
                    </div>
                </div>
                
                <div id="bunny-direct-upload-area">
                    <form id="bunny-direct-upload-form" enctype="multipart/form-data">
                        <?php wp_nonce_field('bunny_direct_upload', 'bunny_direct_upload_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="bunny_file"><?php _e('Select Audio File', 'bunny-auto-uploader'); ?></label>
                                </th>
                                <td>
                                    <input type="file" 
                                           id="bunny_file" 
                                           name="bunny_file" 
                                           accept=".mp3,.wav,.m4a,.ogg,.flac"
                                           required />
                                    <p class="description"><?php _e('Supported formats: MP3, WAV, M4A, OGG, FLAC (any file size)', 'bunny-auto-uploader'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="file_title"><?php _e('Title in Media Library', 'bunny-auto-uploader'); ?></label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="file_title" 
                                           name="file_title" 
                                           class="regular-text" 
                                           placeholder="<?php _e('e.g., My Audio File', 'bunny-auto-uploader'); ?>" />
                                    <p class="description"><?php _e('Optional: Title for WordPress Media Library (auto-generated if empty)', 'bunny-auto-uploader'); ?></p>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <input type="submit" 
                                   id="bunny-upload-submit" 
                                   class="button button-primary" 
                                   value="<?php _e('Upload to Bunny.net', 'bunny-auto-uploader'); ?>" />
                        </p>
                    </form>
                    
                    <div id="bunny-upload-progress" style="display: none;">
                        <h3><?php _e('Upload Progress', 'bunny-auto-uploader'); ?></h3>
                        <div style="background: #f1f1f1; padding: 10px; border-radius: 4px; margin: 10px 0;">
                            <div id="bunny-progress-text"><?php _e('Preparing upload...', 'bunny-auto-uploader'); ?></div>
                            <div style="background: #fff; border-radius: 3px; height: 20px; margin-top: 8px; overflow: hidden;">
                                <div id="bunny-progress-bar" style="background: #0073aa; height: 100%; width: 0%; transition: width 0.3s;"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="bunny-upload-result" style="display: none;"></div>
                </div>
            </div>

            <!-- Manual Bunny URL Addition -->
            <div class="bunny-manual-add">
                <h2><?php _e('Add Bunny CDN File to Media Library', 'bunny-auto-uploader'); ?></h2>
                <p><?php _e('If you uploaded a file directly via FTP, you can add it to your Media Library here:', 'bunny-auto-uploader'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('bunny_add_manual', 'bunny_add_manual_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Filename', 'bunny-auto-uploader'); ?></th>
                            <td>
                                <input type="text" name="bunny_filename" class="regular-text" placeholder="MakandKhloePodcast2025.wav" />
                                <p class="description"><?php _e('The exact filename you uploaded to Bunny CDN', 'bunny-auto-uploader'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('File Title', 'bunny-auto-uploader'); ?></th>
                            <td>
                                <input type="text" name="bunny_title" class="regular-text" placeholder="Mak and Khloe Podcast 2025" />
                                <p class="description"><?php _e('Display title for the Media Library', 'bunny-auto-uploader'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <input type="submit" name="bunny_add_manual" class="button button-primary" value="<?php _e('Add to Media Library', 'bunny-auto-uploader'); ?>">
                </form>
                
                <?php
                // Handle manual addition
                if (isset($_POST['bunny_add_manual']) && check_admin_referer('bunny_add_manual', 'bunny_add_manual_nonce')) {
                    $filename = sanitize_file_name($_POST['bunny_filename']);
                    $title = sanitize_text_field($_POST['bunny_title']);
                    
                    if (!empty($filename) && !empty($title)) {
                        $this->add_bunny_file_to_media_library($filename, $title);
                    }
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
     * Handle direct upload to Bunny.net (bypasses WordPress upload limits)
     */
    public function ajax_direct_upload_to_bunny() {
        // Enhanced error logging for debugging
        $this->log_debug('DIRECT_UPLOAD: AJAX request received');
        
        // Log POST data (without sensitive info)
        $this->log_debug('DIRECT_UPLOAD: POST keys: ' . implode(', ', array_keys($_POST)));
        $this->log_debug('DIRECT_UPLOAD: FILES keys: ' . implode(', ', array_keys($_FILES)));
        
        // Verify nonce with better error handling
        if (!isset($_POST['nonce'])) {
            $this->log_error('DIRECT_UPLOAD: No nonce provided');
            wp_send_json_error('Security check failed - no nonce provided');
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'bunny_direct_upload')) {
            $this->log_error('DIRECT_UPLOAD: Invalid nonce - Expected: bunny_direct_upload');
            wp_send_json_error('Security check failed - invalid nonce');
            return;
        }
        
        // Check user permissions
        if (!current_user_can('upload_files')) {
            $this->log_error('DIRECT_UPLOAD: User lacks upload permissions');
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Check if file was uploaded with detailed error reporting
        if (!isset($_FILES['bunny_file'])) {
            $this->log_error('DIRECT_UPLOAD: No file field in request');
            wp_send_json_error('No file uploaded - file field missing');
            return;
        }
        
        $upload_error = $_FILES['bunny_file']['error'];
        if ($upload_error !== UPLOAD_ERR_OK) {
            $error_messages = array(
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
            );
            
            $error_msg = isset($error_messages[$upload_error]) ? $error_messages[$upload_error] : 'Unknown upload error';
            $this->log_error('DIRECT_UPLOAD: File upload error: ' . $error_msg . ' (Code: ' . $upload_error . ')');
            wp_send_json_error('File upload failed: ' . $error_msg);
            return;
        }
        
        $uploaded_file = $_FILES['bunny_file'];
        $temp_file = $uploaded_file['tmp_name'];
        $original_filename = sanitize_file_name($uploaded_file['name']);
        $file_size = $uploaded_file['size'];
        
        $this->log_debug('DIRECT_UPLOAD: Starting direct upload to Bunny - ' . $original_filename . ' (' . round($file_size / 1024 / 1024, 2) . 'MB)');
        
        // Validate file type
        $file_ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        if (!in_array($file_ext, array('mp3', 'wav', 'm4a', 'ogg', 'flac'))) {
            wp_send_json_error('Only audio files are allowed (.mp3, .wav, .m4a, .ogg, .flac)');
        }
        
        // Upload directly to Bunny.net via FTP
        $bunny_cdn_url = $this->upload_file_to_bunny_direct($temp_file, $original_filename);
        
        if ($bunny_cdn_url) {
            // Create attachment in WordPress Media Library
            $attachment_id = $this->create_media_library_entry($original_filename, $bunny_cdn_url, $file_size);
            
            if ($attachment_id) {
                $this->log_debug('DIRECT_UPLOAD: Successfully uploaded and added to Media Library - ID: ' . $attachment_id);
                
                wp_send_json_success(array(
                    'message' => 'File uploaded successfully to Bunny.net and added to Media Library',
                    'attachment_id' => $attachment_id,
                    'cdn_url' => $bunny_cdn_url,
                    'filename' => $original_filename
                ));
            } else {
                wp_send_json_error('File uploaded to Bunny.net but failed to add to Media Library');
            }
        } else {
            wp_send_json_error('Failed to upload file to Bunny.net');
        }
    }
    
    /**
     * Upload file directly to Bunny.net (bypassing WordPress)
     */
    private function upload_file_to_bunny_direct($temp_file_path, $filename) {
        // Get Bunny settings
        $settings = $this->get_bunny_settings();
        if (!$settings) {
            $this->log_error('Bunny.net settings not configured for direct upload');
            return false;
        }
        
        // Use the same FTP upload method but with custom file path
        return $this->upload_to_bunny_ftp_direct($temp_file_path, $filename, $settings);
    }
    
    /**
     * FTP upload with custom file path (for direct uploads)
     */
    private function upload_to_bunny_ftp_direct($file_path, $filename, $settings) {
        $this->log_debug('DIRECT_FTP: Starting FTP upload - ' . $filename);
        
        // Use the same proven FTP logic as the main upload function
        // Force host to be storage.bunnycdn.com (this works based on previous successful uploads)
        $ftp_host = 'storage.bunnycdn.com';
        
        $this->log_debug('DIRECT_FTP: Using FTP host: ' . $ftp_host);
        $this->log_debug('DIRECT_FTP: FTP Username: ' . $settings['ftp_username']);
        $this->log_debug('DIRECT_FTP: Storage Zone: ' . $settings['storage_zone']);
        
        // Try FTPS connection first (explicit SSL)
        $this->log_debug('DIRECT_FTP: Trying explicit FTPS connection');
        $ftp_conn = false;
        
        if (function_exists('ftp_ssl_connect')) {
            // Try FTPS with a longer timeout (30 seconds)
            $ftp_conn = @ftp_ssl_connect($ftp_host, 21, 30);
            if ($ftp_conn) {
                $this->log_debug('DIRECT_FTP: Successfully connected using FTPS');
            } else {
                $this->log_debug('DIRECT_FTP: FTPS connection failed, trying regular FTP');
            }
        }
        
        // If FTPS failed or isn't available, try regular FTP
        if (!$ftp_conn) {
            $this->log_debug('DIRECT_FTP: Trying regular FTP connection');
            $ftp_conn = @ftp_connect($ftp_host, 21, 30);
        }
        
        if (!$ftp_conn) {
            $this->log_error('DIRECT_FTP: Could not connect to FTP server: ' . $ftp_host);
            
            // Try HTTP API as fallback for direct uploads
            $this->log_debug('DIRECT_FTP: Trying HTTP API as fallback for direct upload');
            return $this->upload_to_bunny_http_direct($file_path, $filename, $settings);
        }
        
        // Login to FTP server
        $this->log_debug('DIRECT_FTP: Attempting FTP login with username: ' . $settings['ftp_username']);
        $login_result = @ftp_login($ftp_conn, $settings['ftp_username'], $settings['ftp_password']);
        
        if (!$login_result) {
            $this->log_error('DIRECT_FTP: FTP login failed for user: ' . $settings['ftp_username']);
            ftp_close($ftp_conn);
            
            // Try HTTP API as fallback
            $this->log_debug('DIRECT_FTP: Login failed, trying HTTP API as fallback');
            return $this->upload_to_bunny_http_direct($file_path, $filename, $settings);
        }
        
        // Enable passive mode (usually needed for firewalls and NAT)
        $this->log_debug('DIRECT_FTP: Enabling FTP passive mode');
        ftp_pasv($ftp_conn, true);
        
        // Upload the file
        $remote_file = $filename; // Try without the full path first
        $this->log_debug('DIRECT_FTP: Uploading file to: ' . $remote_file . ' (size: ' . filesize($file_path) . ' bytes)');
        $upload_success = ftp_put($ftp_conn, $remote_file, $file_path, FTP_BINARY);
        
        // Close the connection
        ftp_close($ftp_conn);
        
        if ($upload_success) {
            $cdn_url = $settings['pull_zone_url'] . $filename;
            $this->log_debug('DIRECT_FTP: Upload successful - ' . $cdn_url);
            return $cdn_url;
        } else {
            $this->log_error('DIRECT_FTP: FTP upload failed for file: ' . $filename);
            
            // Try HTTP API as fallback
            $this->log_debug('DIRECT_FTP: FTP upload failed, trying HTTP API as fallback');
            return $this->upload_to_bunny_http_direct($file_path, $filename, $settings);
        }
    }
    
    /**
     * HTTP API upload for direct uploads (fallback when FTP fails)
     */
    private function upload_to_bunny_http_direct($file_path, $filename, $settings) {
        $this->log_debug('DIRECT_HTTP: Starting HTTP API upload - ' . $filename);
        
        // Use the same HTTP API logic but for direct uploads
        $storage_zone = $settings['storage_zone'];
        $access_key = !empty($settings['api_key']) ? $settings['api_key'] : $settings['ftp_password'];
        $storage_endpoint = !empty($settings['storage_region']) ? 
            $settings['storage_region'] . '.storage.bunnycdn.com' : 
            'storage.bunnycdn.com';
            
        $this->log_debug('DIRECT_HTTP: Using endpoint: ' . $storage_endpoint);
        $this->log_debug('DIRECT_HTTP: Access key: ' . substr($access_key, 0, 8) . '...');
        
        // Read file contents
        $file_contents = file_get_contents($file_path);
        if ($file_contents === false) {
            $this->log_error('DIRECT_HTTP: Could not read file: ' . $file_path);
            return false;
        }
        
        // Construct the Bunny.net Storage URL
        $api_url = 'https://' . $storage_endpoint . '/' . $storage_zone . '/' . $filename;
        $this->log_debug('DIRECT_HTTP: Upload URL: ' . $api_url);
        
        // Set up headers
        $headers = array(
            'Content-Type' => 'application/octet-stream',
            'AccessKey' => $access_key
        );
        
        $this->log_debug('DIRECT_HTTP: Headers: ' . json_encode($headers));
        
        // Make the request
        $response = wp_remote_request($api_url, array(
            'method' => 'PUT',
            'headers' => $headers,
            'body' => $file_contents,
            'timeout' => 300, // 5 minute timeout
            'blocking' => true
        ));
        
        if (is_wp_error($response)) {
            $this->log_error('DIRECT_HTTP: WP Error: ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $this->log_debug('DIRECT_HTTP: Response status: ' . $status_code);
        
        if ($status_code >= 200 && $status_code < 300) {
            $cdn_url = $settings['pull_zone_url'] . $filename;
            $this->log_debug('DIRECT_HTTP: Upload successful - ' . $cdn_url);
            return $cdn_url;
        } else {
            $response_body = wp_remote_retrieve_body($response);
            $this->log_error('DIRECT_HTTP: Upload failed with status ' . $status_code . ': ' . $response_body);
            return false;
        }
    }
    
    /**
     * Create Media Library entry for directly uploaded file
     */
    private function create_media_library_entry($filename, $cdn_url, $file_size) {
        // Get file extension and MIME type
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime_type = 'audio/' . ($file_ext === 'mp3' ? 'mpeg' : ($file_ext === 'm4a' ? 'mp4' : $file_ext));
        
        // Create unique title
        $title = sanitize_text_field($_POST['file_title'] ?? pathinfo($filename, PATHINFO_FILENAME));
        
        // Create attachment data
        $attachment_data = array(
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_mime_type' => $mime_type,
            'guid'          => $cdn_url
        );
        
        // Insert attachment
        $attachment_id = wp_insert_attachment($attachment_data);
        
        if ($attachment_id) {
            // Set Bunny CDN metadata
            update_post_meta($attachment_id, '_bunny_cdn_url', $cdn_url);
            update_post_meta($attachment_id, '_bunny_cdn_upload_time', time());
            update_post_meta($attachment_id, '_bunny_direct_upload', true);
            
            // Create fake file path for WordPress compatibility
            $upload_dir = wp_upload_dir();
            $fake_file_path = $upload_dir['path'] . '/' . $filename;
            update_post_meta($attachment_id, '_wp_attached_file', str_replace($upload_dir['basedir'] . '/', '', $fake_file_path));
            
            // Set file size
            update_post_meta($attachment_id, '_file_size', $file_size);
            
            $this->log_debug('DIRECT_UPLOAD: Created Media Library entry - ID: ' . $attachment_id);
            return $attachment_id;
        }
        
        return false;
    }
    
    /**
     * Register a direct upload in WordPress Media Library
     */
    public function ajax_register_direct_upload() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bunny_direct_upload')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        // Check user permissions
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Get parameters
        $filename = sanitize_file_name($_POST['filename']);
        $cdn_url = esc_url($_POST['cdn_url']);
        $file_title = sanitize_text_field($_POST['file_title']);
        $file_size = intval($_POST['file_size']);
        
        $this->log_debug('REGISTER_DIRECT: Registering direct upload - ' . $filename);
        
        // Create the Media Library entry using existing method
        $attachment_id = $this->create_direct_media_library_entry($filename, $cdn_url, $file_size, $file_title);
        
        if ($attachment_id) {
            $this->log_debug('REGISTER_DIRECT: Successfully registered - ID: ' . $attachment_id);
            wp_send_json_success(array(
                'attachment_id' => $attachment_id,
                'message' => 'File registered in Media Library'
            ));
        } else {
            $this->log_error('REGISTER_DIRECT: Failed to register file in Media Library');
            wp_send_json_error('Failed to register file in Media Library');
        }
    }
    
    /**
     * Create Media Library entry for direct upload (browser → Bunny.net)
     */
    private function create_direct_media_library_entry($filename, $cdn_url, $file_size, $title) {
        // Get file extension and MIME type
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime_type = 'audio/' . ($file_ext === 'mp3' ? 'mpeg' : ($file_ext === 'm4a' ? 'mp4' : $file_ext));
        
        // Use provided title or generate from filename
        if (empty($title)) {
            $title = pathinfo($filename, PATHINFO_FILENAME);
        }
        
        // Create attachment data
        $attachment_data = array(
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_mime_type' => $mime_type,
            'guid'          => $cdn_url
        );
        
        // Insert attachment
        $attachment_id = wp_insert_attachment($attachment_data);
        
        if ($attachment_id) {
            // Set Bunny CDN metadata
            update_post_meta($attachment_id, '_bunny_cdn_url', $cdn_url);
            update_post_meta($attachment_id, '_bunny_cdn_upload_time', time());
            update_post_meta($attachment_id, '_bunny_direct_upload', true);
            update_post_meta($attachment_id, '_bunny_browser_direct', true); // Flag for browser direct upload
            
            // Create fake file path for WordPress compatibility
            $upload_dir = wp_upload_dir();
            $fake_file_path = $upload_dir['path'] . '/' . $filename;
            update_post_meta($attachment_id, '_wp_attached_file', str_replace($upload_dir['basedir'] . '/', '', $fake_file_path));
            
            // Set file size
            update_post_meta($attachment_id, '_file_size', $file_size);
            
            $this->log_debug('REGISTER_DIRECT: Created Media Library entry - ID: ' . $attachment_id);
            return $attachment_id;
        }
        
        return false;
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
        
        // Get attachment ID for logging
        $attachment_id = $this->get_attachment_id_by_file($file_path);
        
        // Log file size for debugging
        $this->log_debug('Large file upload - File: ' . $filename . ', Size: ' . round($file_size / 1024 / 1024, 2) . 'MB');
        
        // Construct the Bunny.net Storage URL - using the correct API endpoint
        $storage_url = 'https://storage.bunnycdn.com/' . $storage_zone . '/' . $filename;
        $this->log_debug('Large file upload URL: ' . $storage_url);
        
        // Initialize cURL
        $ch = curl_init($storage_url);
        
        // Open file for reading
        $fp = fopen($file_path, 'rb');
        if (!$fp) {
            $this->log_error('Could not open file for cURL upload: ' . $file_path, $attachment_id, 'curl_file_open_error');
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
                $attachment_id,
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
            $error_message = 'HTTP error during large file upload: ' . $http_code;
            if ($response) {
                $error_message .= ' - ' . substr($response, 0, 200);
            }
            
            // Provide specific error messages for common HTTP codes
            switch ($http_code) {
                case 401:
                    $error_message .= ' - Invalid API key or authentication failed';
                    break;
                case 403:
                    $error_message .= ' - Access forbidden. Check your API key permissions';
                    break;
                case 404:
                    $error_message .= ' - Storage zone not found. Check your storage zone name';
                    break;
                case 413:
                    $error_message .= ' - File too large for upload';
                    break;
                case 429:
                    $error_message .= ' - Rate limit exceeded. Please try again later';
                    break;
                case 500:
                    $error_message .= ' - Server error. Please try again later';
                    break;
            }
            
            $this->log_error(
                $error_message,
                $attachment_id,
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
        // IMPORTANT: Bunny.net Storage Zones only support FTP access, not HTTP API
        // The HTTP API is only available for Bunny Stream (different service)
        
        $this->log_debug('Using FTP upload method (Storage Zones do not support HTTP API)');
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
        $file_path = get_attached_file($post_id);
        
        // Check if it's an audio file by MIME type or extension
        $is_audio = false;
        if (strpos($mime_type, 'audio/') === 0) {
            $is_audio = true;
        } else if ($file_path) {
            $file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            $is_audio = in_array($file_ext, array('mp3', 'wav', 'm4a', 'ogg', 'flac'));
        }
        
        if (!$is_audio) {
            return;
        }
        
        // Check if already uploaded to Bunny CDN
        $existing_cdn_url = get_post_meta($post_id, '_bunny_cdn_url', true);
        if (!empty($existing_cdn_url)) {
            return;
        }
        
        // Check if already processing to avoid duplicate uploads
        $processing_flag = get_post_meta($post_id, '_bunny_processing', true);
        if ($processing_flag) {
            return;
        }
        
        // Set processing flag
        update_post_meta($post_id, '_bunny_processing', '1');
        
        // Get file path
        if (!$file_path || !file_exists($file_path)) {
            $this->log_error('File not found for new attachment: ' . $post_id, $post_id, 'file_not_found');
            delete_post_meta($post_id, '_bunny_processing');
            return;
        }
        
        $this->log_debug('Processing audio attachment: ' . basename($file_path) . ' (' . round(filesize($file_path) / 1024 / 1024, 2) . 'MB, ID: ' . $post_id . ')');
        
        // Check if settings are configured
        $settings = $this->get_bunny_settings();
        if (!$settings) {
            $this->log_error('Bunny.net settings not configured. Please set up the plugin before uploading.', $post_id, 'settings_not_configured');
            
            // Schedule admin notice about missing settings
            $this->display_admin_notice_for_missing_settings();
            delete_post_meta($post_id, '_bunny_processing');
            return;
        }
        
        $this->log_debug('Settings validated, starting Bunny upload for: ' . basename($file_path));
        
        // Upload to Bunny.net
        $bunny_cdn_url = $this->upload_to_bunny($file_path);
        
        $this->log_debug('Upload completed, result: ' . ($bunny_cdn_url ? $bunny_cdn_url : 'FAILED'));
        
        // Remove processing flag
        delete_post_meta($post_id, '_bunny_processing');
        
        // Save the CDN URL as attachment meta if successful
        if ($bunny_cdn_url) {
            update_post_meta($post_id, '_bunny_cdn_url', $bunny_cdn_url);
            
            // Add upload timestamp
            update_post_meta($post_id, '_bunny_cdn_upload_time', time());
            
            // Remove any previous error flags
            delete_post_meta($post_id, '_bunny_cdn_upload_failed');
            delete_post_meta($post_id, '_bunny_upload_error');
            
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
        
        // Determine which settings are missing (FTP only - Storage Zones don't have API)
        $missing = array();
        if (empty($settings['storage_zone'])) $missing[] = 'Storage Zone';
        if (empty($settings['ftp_username'])) $missing[] = 'FTP Username';
        if (empty($settings['ftp_password'])) $missing[] = 'FTP Password';
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
        
        // Check required settings (FTP only - Storage Zones don't have API access)
        if (empty($settings['storage_zone']) || 
            empty($settings['ftp_username']) || 
            empty($settings['ftp_password']) || 
            empty($settings['pull_zone_url'])) {
            
            // Log which settings are missing
            $missing = array();
            if (empty($settings['storage_zone'])) $missing[] = 'Storage Zone';
            if (empty($settings['ftp_username'])) $missing[] = 'FTP Username';
            if (empty($settings['ftp_password'])) $missing[] = 'FTP Password';
            if (empty($settings['pull_zone_url'])) $missing[] = 'Pull Zone URL';
            
            $this->log_error('Missing required Bunny.net settings: ' . implode(', ', $missing), 0, 'missing_settings');
            
            return false;
        }
        
        return $settings;
    }
    
    /**
     * Display large file upload notice
     */
    public function display_large_file_notice() {
        // Only show on media pages and to users who can upload files
        $screen = get_current_screen();
        if (!current_user_can('upload_files') || 
            !in_array($screen->id, array('upload', 'media', 'attachment'))) {
            return;
        }
        
        $max_upload_mb = round(wp_max_upload_size() / 1024 / 1024);
        
        if ($max_upload_mb < 100) { // Show notice if max upload is less than 100MB
            ?>
            <div class="notice notice-info is-dismissible">
                <h3><?php _e('Bunny Auto Uploader: Large File Upload Information', 'bunny-auto-uploader'); ?></h3>
                <p>
                    <strong><?php _e('Current upload limit:', 'bunny-auto-uploader'); ?></strong> <?php echo $max_upload_mb; ?>MB
                </p>
                <p>
                    <?php _e('For files larger than your upload limit, you have these options:', 'bunny-auto-uploader'); ?>
                </p>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li><?php _e('Ask your hosting provider to increase the upload limit', 'bunny-auto-uploader'); ?></li>
                    <li><?php _e('Upload large files via FTP and use the "Add FTP File to Media Library" feature in the plugin settings', 'bunny-auto-uploader'); ?></li>
                    <li><?php _e('Use a file compression tool to reduce file size before uploading', 'bunny-auto-uploader'); ?></li>
                </ul>
                <p>
                    <strong><?php _e('Note:', 'bunny-auto-uploader'); ?></strong> 
                    <?php _e('Chunked uploads have been disabled to prevent file fragmentation issues.', 'bunny-auto-uploader'); ?>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Add a Bunny CDN file to Media Library manually
     */
    public function add_bunny_file_to_media_library($filename, $title) {
        // Get settings
        $settings = $this->get_bunny_settings();
        if (!$settings) {
            echo '<div class="notice notice-error"><p>' . __('Bunny.net settings not configured.', 'bunny-auto-uploader') . '</p></div>';
            return;
        }
        
        // Construct CDN URL
        $cdn_url = $settings['pull_zone_url'] . $filename;
        
        // Get file extension and MIME type
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime_type = 'audio/' . ($file_ext === 'mp3' ? 'mpeg' : ($file_ext === 'm4a' ? 'mp4' : $file_ext));
        
        // Create attachment
        $attachment_data = array(
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_mime_type' => $mime_type,
            'guid'          => $cdn_url
        );
        
        // Insert attachment
        $attachment_id = wp_insert_attachment($attachment_data);
        
        if ($attachment_id) {
            // Set Bunny CDN URL meta
            update_post_meta($attachment_id, '_bunny_cdn_url', $cdn_url);
            update_post_meta($attachment_id, '_bunny_cdn_upload_time', time());
            
            // Create fake local file path for WordPress
            $upload_dir = wp_upload_dir();
            $fake_file_path = $upload_dir['path'] . '/' . $filename;
            update_post_meta($attachment_id, '_wp_attached_file', str_replace($upload_dir['basedir'] . '/', '', $fake_file_path));
            
            echo '<div class="notice notice-success"><p>' . 
                sprintf(__('Successfully added "%s" to Media Library. <a href="%s" target="_blank">View CDN URL</a>', 'bunny-auto-uploader'), 
                $title, $cdn_url) . '</p></div>';
            
            $this->log_debug('Manually added Bunny CDN file to Media Library: ' . $filename . ' (ID: ' . $attachment_id . ')');
        } else {
            echo '<div class="notice notice-error"><p>' . __('Failed to add file to Media Library.', 'bunny-auto-uploader') . '</p></div>';
        }
    }
    
    /**
     * Replace default WordPress media uploader with Direct Bunny Upload
     */
    public function replace_default_uploader($hook_suffix) {
        // Debug logging
        error_log("🔍 Bunny: replace_default_uploader called with hook: $hook_suffix");
        error_log("🔍 Bunny: Current page: " . $_SERVER['REQUEST_URI']);
        
        // More inclusive hook detection - load on ALL admin pages for now
        if (is_admin()) {
            error_log("✅ Bunny: Loading scripts on admin page");
            
            // Enqueue our replacement uploader
            wp_enqueue_script('bunny-default-uploader-replacement', '', array('jquery'), '1.0.1', true);
            wp_add_inline_script('bunny-default-uploader-replacement', $this->get_default_uploader_replacement_script());
            
            // Localize with Bunny settings
            wp_localize_script('bunny-default-uploader-replacement', 'bunnyDefaultUploader', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bunny_direct_upload'),
                'storageEndpoint' => 'https://ny.storage.bunnycdn.com',
                'storageZone' => 'riverrun',
                'accessKey' => '336cc65b-6043-4f65-b9ee34f50a25-d4f6-4928',
                'cdnUrl' => 'https://riverrunpool.b-cdn.net/',
                'uploading' => __('Uploading to Bunny.net...', 'bunny-auto-uploader'),
                'success' => __('Upload completed successfully!', 'bunny-auto-uploader'),
                'error' => __('Upload failed. Please try again.', 'bunny-auto-uploader'),
                'maxFileSize' => '10737418240', // 10GB in bytes
                'hookSuffix' => $hook_suffix,
                'currentPage' => $_SERVER['REQUEST_URI']
            ));
            
            // Add CSS for better integration
            wp_add_inline_style('wp-admin', $this->get_uploader_replacement_css());
        } else {
            error_log("❌ Bunny: Not admin page, skipping");
        }
    }
    
    /**
     * Replace frontend uploader for themes/plugins that use wp.media
     */
    public function replace_frontend_uploader() {
        if (is_user_logged_in() && current_user_can('upload_files')) {
            wp_enqueue_script('bunny-frontend-uploader', '', array('jquery', 'media-views'), '1.0.0', true);
            wp_add_inline_script('bunny-frontend-uploader', $this->get_frontend_uploader_script());
            
            wp_localize_script('bunny-frontend-uploader', 'bunnyFrontendUploader', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bunny_direct_upload'),
                'storageEndpoint' => 'https://ny.storage.bunnycdn.com',
                'storageZone' => 'riverrun',
                'accessKey' => '336cc65b-6043-4f65-b9ee34f50a25-d4f6-4928',
                'cdnUrl' => 'https://riverrunpool.b-cdn.net/'
            ));
        }
    }
    
    /**
     * Override plupload settings to use higher limits
     */
    public function override_plupload_settings($settings) {
        // Increase limits to match our capabilities
        $settings['max_file_size'] = '10737418240'; // 10GB
        $settings['chunk_size'] = '104857600'; // 100MB chunks
        
        // Add a flag to identify this as a Bunny upload
        $settings['bunny_direct_upload'] = true;
        
        return $settings;
    }
    
    /**
     * Override upload directory for audio files (they go to Bunny)
     */
    public function override_upload_dir($upload_dir) {
        // We'll still let WordPress handle the directory structure
        // but our JavaScript will intercept audio uploads
        return $upload_dir;
    }
    
        /**
     * Get JavaScript to replace default WordPress uploader
     */
    private function get_default_uploader_replacement_script() {
        return "
        console.log('🚀🚀🚀 BUNNY UPLOADER SCRIPT LOADED 🚀🚀🚀');
        console.log('📊 Hook suffix:', bunnyDefaultUploader.hookSuffix);
        console.log('📊 Current page:', bunnyDefaultUploader.currentPage);
        console.log('📊 Plupload available:', typeof window.plupload);
        console.log('📊 jQuery available:', typeof jQuery);
        
        // Also add to DOM for visual confirmation
        if (typeof jQuery !== 'undefined') {
            jQuery(document).ready(function($) {
                $('body').prepend('<div style=\"position: fixed; top: 0; left: 0; right: 0; background: red; color: white; padding: 10px; z-index: 999999; text-align: center;\">🚀 BUNNY UPLOADER LOADED - CHECK CONSOLE</div>');
                setTimeout(function() {
                    $('body > div:first').fadeOut();
                }, 3000);
            });
        }
        
        // Wait for plupload to be available and intercept it
        jQuery(document).ready(function($) {
            // Hook into plupload before it starts
            if (typeof window.plupload !== 'undefined') {
                console.log('📦 Plupload detected, installing interceptor');
                
                // Store original plupload Uploader
                var OriginalUploader = window.plupload.Uploader;
                
                // Override plupload.Uploader
                window.plupload.Uploader = function(settings) {
                    console.log('🔧 Creating new plupload instance with settings:', settings);
                    
                    // Create the original uploader
                    var uploader = new OriginalUploader(settings);
                    
                    // Override the start method to intercept audio files
                    var originalStart = uploader.start;
                    uploader.start = function() {
                        console.log('🚀 Plupload start intercepted, checking files...');
                        
                        var audioFiles = [];
                        var regularFiles = [];
                        
                        // Check all files in the queue
                        uploader.files.forEach(function(file) {
                            var audioExtensions = ['mp3', 'wav', 'm4a', 'ogg', 'flac'];
                            var fileExt = file.name.split('.').pop().toLowerCase();
                            
                            if (audioExtensions.includes(fileExt) || (file.type && file.type.startsWith('audio/'))) {
                                console.log('🎵 Audio file detected:', file.name);
                                audioFiles.push(file);
                                // Remove from plupload queue
                                uploader.removeFile(file);
                            } else {
                                regularFiles.push(file);
                            }
                        });
                        
                        // Upload audio files via Bunny
                        if (audioFiles.length > 0) {
                            console.log('📤 Processing', audioFiles.length, 'audio files via Bunny.net');
                            audioFiles.forEach(function(pluploadFile) {
                                uploadAudioFileToBunny(pluploadFile);
                            });
                        }
                        
                        // Continue with regular files if any remain
                        if (uploader.files.length > 0) {
                            console.log('📤 Processing', uploader.files.length, 'regular files via WordPress');
                            return originalStart.call(this);
                        } else {
                            console.log('✅ All files processed, no regular files to upload');
                        }
                    };
                    
                    return uploader;
                };
                
                // Copy static properties
                for (var prop in OriginalUploader) {
                    if (OriginalUploader.hasOwnProperty(prop)) {
                        window.plupload.Uploader[prop] = OriginalUploader[prop];
                    }
                }
                
                console.log('✅ Plupload interceptor installed');
            } else {
                console.log('⚠️ Plupload not found, trying alternative approach...');
                
                // Fallback: Try to intercept when plupload loads
                var checkPlupload = setInterval(function() {
                    if (typeof window.plupload !== 'undefined') {
                        console.log('📦 Plupload loaded, installing late interceptor');
                        clearInterval(checkPlupload);
                        // Install interceptor here too
                    }
                }, 100);
                
                // Clear interval after 10 seconds
                setTimeout(function() {
                    clearInterval(checkPlupload);
                }, 10000);
            }
            
            function uploadAudioFileToBunny(pluploadFile) {
                console.log('🚀 Starting Bunny upload for:', pluploadFile.name);
                
                // Get the native file from plupload
                var file = pluploadFile.getNative ? pluploadFile.getNative() : pluploadFile;
                
                // Create upload progress element
                var uploadId = 'bunny-upload-' + Date.now();
                var progressHtml = '<div id=\"' + uploadId + '\" class=\"bunny-upload-progress\" style=\"margin: 10px 0; padding: 15px; border: 2px solid #46b450; border-radius: 8px; background: linear-gradient(135deg, #f0f8f0 0%, #e7f7ff 100%); box-shadow: 0 2px 8px rgba(0,0,0,0.1);\">' +
                    '<div class=\"bunny-upload-filename\"><strong>🎵 ' + file.name + '</strong> (' + formatFileSize(file.size) + ')</div>' +
                    '<div class=\"bunny-upload-progress-bar\" style=\"background: #f1f1f1; height: 24px; border-radius: 12px; margin: 10px 0; overflow: hidden; border: 1px solid #ddd;\">' +
                        '<div class=\"bunny-upload-progress-fill\" style=\"background: linear-gradient(90deg, #46b450 0%, #2271b1 100%); height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 12px;\"></div>' +
                    '</div>' +
                    '<div class=\"bunny-upload-status\" style=\"color: #555; font-weight: 500;\">🚀 Preparing direct upload to Bunny.net...</div>' +
                '</div>';
                
                // Insert into upload area with better targeting
                if ($('.media-progress-bar').length > 0) {
                    $('.media-progress-bar').first().after(progressHtml);
                } else if ($('.upload-php .wrap h1').length > 0) {
                    $('.upload-php .wrap h1').after(progressHtml);
                } else if ($('.media-frame-content').length > 0) {
                    $('.media-frame-content').prepend(progressHtml);
                } else {
                    $('body').prepend(progressHtml);
                }
                
                var progressElement = $('#' + uploadId);
                var progressBar = progressElement.find('.bunny-upload-progress-fill');
                var statusText = progressElement.find('.bunny-upload-status');
                
                // Sanitize filename
                var filename = file.name.replace(/[^a-zA-Z0-9.-]/g, '_');
                var uploadUrl = bunnyDefaultUploader.storageEndpoint + '/' + bunnyDefaultUploader.storageZone + '/' + filename;
                
                console.log('📡 Uploading to:', uploadUrl);
                
                // Upload directly to Bunny.net
                $.ajax({
                    url: uploadUrl,
                    type: 'PUT',
                    data: file,
                    processData: false,
                    contentType: 'application/octet-stream',
                    headers: {
                        'AccessKey': bunnyDefaultUploader.accessKey
                    },
                    xhr: function() {
                        var xhr = new window.XMLHttpRequest();
                        xhr.upload.addEventListener('progress', function(evt) {
                            if (evt.lengthComputable) {
                                var percentComplete = (evt.loaded / evt.total) * 100;
                                progressBar.css('width', percentComplete + '%');
                                statusText.text('🚀 Uploading directly to Bunny.net... ' + Math.round(percentComplete) + '%');
                            }
                        }, false);
                        return xhr;
                    },
                    success: function(response, status, xhr) {
                        console.log('✅ Bunny upload successful:', xhr.status);
                        statusText.text('📝 Adding to WordPress Media Library...');
                        progressBar.css('width', '100%');
                        
                        var cdnUrl = bunnyDefaultUploader.cdnUrl + filename;
                        
                        // Register in WordPress Media Library
                        $.ajax({
                            url: bunnyDefaultUploader.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'bunny_register_direct_upload',
                                nonce: bunnyDefaultUploader.nonce,
                                filename: filename,
                                cdn_url: cdnUrl,
                                file_title: file.name.replace(/\.[^/.]+$/, ''),
                                file_size: file.size
                            },
                            success: function(response) {
                                console.log('✅ WordPress registration successful:', response);
                                if (response.success) {
                                    statusText.html('🎉 <strong>Success!</strong> Uploaded to <a href=\"' + cdnUrl + '\" target=\"_blank\" style=\"color: #2271b1;\">Bunny.net CDN</a> and added to Media Library!');
                                    progressElement.css({
                                        'border-color': '#46b450',
                                        'background': 'linear-gradient(135deg, #f0f8f0 0%, #e8f5e8 100%)'
                                    });
                                    
                                    // Auto-refresh after success
                                    setTimeout(function() {
                                        if (window.location.href.indexOf('upload.php') > -1) {
                                            window.location.reload();
                                        } else if (typeof wp !== 'undefined' && wp.media && wp.media.frame) {
                                            wp.media.frame.content.get().collection.fetch();
                                        }
                                        progressElement.fadeOut(500);
                                    }, 3000);
                                } else {
                                    statusText.html('⚠️ <strong>Partial Success:</strong> Uploaded to <a href=\"' + cdnUrl + '\" target=\"_blank\">Bunny.net</a> but WordPress registration failed');
                                    progressElement.css({
                                        'border-color': '#ffb900',
                                        'background': 'linear-gradient(135deg, #fff8e5 0%, #ffefcc 100%)'
                                    });
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('❌ WordPress registration failed:', xhr, status, error);
                                statusText.html('⚠️ <strong>Partial Success:</strong> Uploaded to <a href=\"' + cdnUrl + '\" target=\"_blank\">Bunny.net</a> but WordPress registration failed');
                                progressElement.css({
                                    'border-color': '#ffb900',
                                    'background': 'linear-gradient(135deg, #fff8e5 0%, #ffefcc 100%)'
                                });
                            }
                        });
                    },
                    error: function(xhr, status, error) {
                        console.error('❌ Bunny upload failed:', xhr, status, error, xhr.responseText);
                        statusText.html('❌ <strong>Upload Failed:</strong> ' + (error || 'Unknown error'));
                        progressElement.css({
                            'border-color': '#dc3232',
                            'background': 'linear-gradient(135deg, #fce8e8 0%, #f8d7da 100%)'
                        });
                    }
                });
            }
            
            function formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                var k = 1024;
                var sizes = ['Bytes', 'KB', 'MB', 'GB'];
                var i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
            
            // Also handle drag and drop
            $(document).on('dragover', '.upload-php .wrap, .media-frame', function(e) {
                e.preventDefault();
                e.stopPropagation();
            });
            
            $(document).on('drop', '.upload-php .wrap, .media-frame', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                var files = e.originalEvent.dataTransfer.files;
                console.log('🎯 Drag drop detected:', files.length, 'files');
                
                if (files.length > 0) {
                    for (var i = 0; i < files.length; i++) {
                        var file = files[i];
                        var audioExtensions = ['mp3', 'wav', 'm4a', 'ogg', 'flac'];
                        var fileExt = file.name.split('.').pop().toLowerCase();
                        
                        if (audioExtensions.includes(fileExt) || file.type.startsWith('audio/')) {
                            console.log('🎵 Processing audio file via drag drop:', file.name);
                            uploadAudioFileToBunny({name: file.name, size: file.size, getNative: function() { return file; }});
                        }
                    }
                }
            });
            
            // Add enhanced visual indicator
            setTimeout(function() {
                if ($('.bunny-upload-notice').length === 0) {
                    var notice = '<div class=\"bunny-upload-notice\" style=\"background: linear-gradient(135deg, #e7f7ff 0%, #f0f8f0 100%); border: 2px solid #46b1c9; padding: 20px; margin: 20px 0; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); position: relative; overflow: hidden;\">' +
                        '<div style=\"position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #46b450 0%, #2271b1 100%);\"></div>' +
                        '<div style=\"display: flex; align-items: center; margin-bottom: 8px;\">' +
                            '<span style=\"font-size: 24px; margin-right: 12px;\">🚀</span>' +
                            '<strong style=\"font-size: 18px; color: #2271b1;\">Bunny.net Direct Upload Active!</strong>' +
                        '</div>' +
                        '<div style=\"color: #666; font-size: 14px; line-height: 1.4;\">' +
                            '✅ Audio files bypass ALL server limits<br>' +
                            '✅ Direct upload to CDN (max: ' + formatFileSize(parseInt(bunnyDefaultUploader.maxFileSize)) + ')<br>' +
                            '✅ Zero Cloudflare interference<br>' +
                            '✅ Automatic WordPress integration' +
                        '</div>' +
                    '</div>';
                    
                    $('.media-frame-content, .upload-php .wrap').prepend(notice);
                }
            }, 1000);
            
            console.log('✅ Bunny plupload interceptor system ready');
        });
        ";
    }
    
    /**
     * Emergency script injection as backup
     */
    public function emergency_script_injection() {
        error_log("🚨 Bunny: Emergency script injection called");
        
        // Only on pages that might have uploads
        if (strpos($_SERVER['REQUEST_URI'], 'upload') !== false || 
            strpos($_SERVER['REQUEST_URI'], 'media') !== false ||
            strpos($_SERVER['REQUEST_URI'], 'post') !== false ||
            strpos($_SERVER['REQUEST_URI'], 'page') !== false) {
            
            echo "<script type='text/javascript'>\n";
            echo "console.log('Bunny CDN Auto Uploader: Script loaded');\n";
            echo "window.bunnyEmergencyUploader = {\n";
            echo "    ajaxurl: '" . admin_url('admin-ajax.php') . "',\n";
            echo "    nonce: '" . wp_create_nonce('bunny_direct_upload') . "',\n";
            echo "    storageEndpoint: 'https://ny.storage.bunnycdn.com',\n";
            echo "    storageZone: 'riverrun',\n";
            echo "    accessKey: '336cc65b-6043-4f65-b9ee34f50a25-d4f6-4928',\n";
            echo "    cdnUrl: 'https://riverrunpool.b-cdn.net/'\n";
            echo "};\n";
            
            // NUCLEAR OPTION: Block ALL XMLHttpRequest to async-upload.php for audio files
            echo "
            (function() {
                console.log('Bunny CDN: Initializing audio upload handler');
                
                // Check if we should reopen media popup after page refresh
                checkAndReopenMediaPopup();
                
                // Store original XMLHttpRequest
                var OriginalXHR = window.XMLHttpRequest;
                
                // Override XMLHttpRequest constructor
                window.XMLHttpRequest = function() {
                    var xhr = new OriginalXHR();
                    var originalOpen = xhr.open;
                    var originalSend = xhr.send;
                    
                    xhr.open = function(method, url) {
                        xhr._method = method;
                        xhr._url = url;
                        console.log('🔍 XHR Open:', method, url);
                        return originalOpen.apply(xhr, arguments);
                    };
                    
                    xhr.send = function(data) {
                        console.log('🔍 XHR Send to:', xhr._url, 'Data:', data);
                        
                        // If this is an upload to async-upload.php
                        if (xhr._url && xhr._url.indexOf('async-upload.php') > -1) {
                            console.log('🚨 DETECTED ASYNC-UPLOAD.PHP REQUEST!');
                            
                                                         // Check if it's an audio file
                             if (data instanceof FormData) {
                                 var audioFile = null;
                                 console.log('📋 Inspecting FormData...');
                                 
                                 try {
                                     // Try multiple methods to access FormData
                                     if (data.entries) {
                                         console.log('📋 Using FormData.entries()');
                                         var formDataPairs = {};
                                         
                                         // First pass: collect all FormData pairs
                                         for (var pair of data.entries()) {
                                             console.log('📋 FormData pair:', pair[0], pair[1]);
                                             formDataPairs[pair[0]] = pair[1];
                                         }
                                         
                                         // Check if we have both 'name' and file data
                                         var realFilename = formDataPairs['name'] || '';
                                         var fileBlob = formDataPairs['async-upload'];
                                         
                                         if (fileBlob instanceof File && realFilename) {
                                             var audioExtensions = ['mp3', 'wav', 'm4a', 'ogg', 'flac'];
                                             var fileExt = realFilename.split('.').pop().toLowerCase();
                                             
                                             console.log('📁 Real filename:', realFilename, 'Blob size:', fileBlob.size, 'Ext:', fileExt);
                                             
                                             if (audioExtensions.includes(fileExt)) {
                                                 // Create a proper file object with the real name
                                                 audioFile = new File([fileBlob], realFilename, {
                                                     type: fileBlob.type || 'audio/' + fileExt,
                                                     lastModified: fileBlob.lastModified || Date.now()
                                                 });
                                                 console.log('🎵 AUDIO FILE DETECTED:', realFilename);
                                             }
                                         }
                                     }
                                     
                                     // Fallback: try to get from global plupload queue
                                     if (!audioFile && window.uploader && window.uploader.files) {
                                         console.log('📋 Fallback: checking plupload queue');
                                         for (var i = 0; i < window.uploader.files.length; i++) {
                                             var pFile = window.uploader.files[i];
                                             if (pFile.status === 2) { // UPLOADING
                                                 var audioExtensions = ['mp3', 'wav', 'm4a', 'ogg', 'flac'];
                                                 var fileExt = pFile.name.split('.').pop().toLowerCase();
                                                 
                                                 console.log('📁 Plupload file:', pFile.name, 'Type:', pFile.type, 'Ext:', fileExt);
                                                 
                                                 if (audioExtensions.includes(fileExt) || (pFile.type && pFile.type.startsWith('audio/'))) {
                                                     // Create a File object from plupload data
                                                     audioFile = {
                                                         name: pFile.name,
                                                         size: pFile.size,
                                                         type: pFile.type || 'audio/' + fileExt,
                                                         getNativeFile: pFile.getNativeFile
                                                     };
                                                     console.log('🎵 AUDIO FILE DETECTED FROM PLUPLOAD:', pFile.name);
                                                     break;
                                                 }
                                             }
                                         }
                                     }
                                     
                                     // Last resort: check if URL contains audio filename
                                     if (!audioFile) {
                                         console.log('📋 Last resort: checking URL for audio patterns');
                                         var urlParams = xhr._url.split('?')[1] || '';
                                         var audioExtensions = ['mp3', 'wav', 'm4a', 'ogg', 'flac'];
                                         for (var ext of audioExtensions) {
                                             if (urlParams.toLowerCase().indexOf('.' + ext) > -1) {
                                                 console.log('🎵 AUDIO UPLOAD DETECTED BY URL PATTERN');
                                                 audioFile = { name: 'detected_audio_file.' + ext, size: 0, type: 'audio/' + ext };
                                                 break;
                                             }
                                         }
                                     }
                                     
                                 } catch (e) {
                                     console.log('❌ Error checking FormData:', e);
                                 }
                                
                                if (audioFile) {
                                    console.log('🛑 BLOCKING WORDPRESS UPLOAD, ROUTING TO BUNNY');
                                    
                                    // Cancel any existing plupload processes
                                    if (window.uploader && window.uploader.files) {
                                        console.log('🚫 Cancelling plupload files...');
                                        for (var i = 0; i < window.uploader.files.length; i++) {
                                            var pFile = window.uploader.files[i];
                                            if (pFile.status === 2) { // UPLOADING
                                                console.log('🚫 Stopping upload for:', pFile.name);
                                                pFile.status = 5; // FAILED
                                                window.uploader.trigger('FileUploaded', pFile, {
                                                    response: '<div>Upload intercepted by Bunny.net</div>',
                                                    status: 200
                                                });
                                            }
                                        }
                                        // Stop the uploader
                                        window.uploader.stop();
                                    }
                                    
                                    // Mock success response immediately
                                    setTimeout(function() {
                                        Object.defineProperty(xhr, 'status', { value: 200, configurable: true });
                                        Object.defineProperty(xhr, 'readyState', { value: 4, configurable: true });
                                        Object.defineProperty(xhr, 'responseText', { 
                                            value: '<div>Upload intercepted by Bunny.net</div>',
                                            configurable: true 
                                        });
                                        
                                        if (xhr.onreadystatechange) xhr.onreadystatechange();
                                        if (xhr.onload) xhr.onload();
                                        if (xhr.onloadend) xhr.onloadend();
                                    }, 50);
                                    
                                                         // AGGRESSIVE UI cleanup - remove uploading indicators
                     setTimeout(function() {
                         console.log('🧹 AGGRESSIVE cleanup of WordPress upload UI...');
                         
                         // Target specific WordPress media modal elements
                         var uploadingElements = document.querySelectorAll('[class*=\"uploading\"], [class*=\"upload\"]');
                         for (var i = 0; i < uploadingElements.length; i++) {
                             var el = uploadingElements[i];
                             console.log('🗑️ Found upload element:', el.className);
                             
                             // Hide elements that contain uploading text
                             if (el.textContent && el.textContent.toLowerCase().includes('uploading')) {
                                 console.log('🚫 Hiding element with uploading text');
                                 el.style.display = 'none';
                             }
                             
                             // Remove uploading classes
                             el.classList.remove('uploading', 'upload-progress', 'media-progress');
                             el.classList.add('upload-complete', 'completed');
                         }
                         
                         // Target media sidebar and attachment details
                         var mediaSidebar = document.querySelector('.media-sidebar, .attachment-details');
                         if (mediaSidebar) {
                             console.log('📋 Found media sidebar, checking for uploading content...');
                             var uploadingText = mediaSidebar.querySelector('*');
                             if (uploadingText && uploadingText.textContent.includes('Uploading')) {
                                 console.log('🚫 Hiding uploading sidebar');
                                 mediaSidebar.style.display = 'none';
                             }
                         }
                         
                         // Remove any attachment items that show uploading
                         var attachmentItems = document.querySelectorAll('.attachment, .media-item');
                         for (var j = 0; j < attachmentItems.length; j++) {
                             var item = attachmentItems[j];
                             if (item.textContent && item.textContent.includes('Uploading')) {
                                 console.log('🗑️ Removing uploading attachment item');
                                 item.remove();
                             }
                         }
                         
                         // Force close any upload dialogs/modals
                         var uploadDialogs = document.querySelectorAll('.upload-dialog, .media-modal');
                         for (var k = 0; k < uploadDialogs.length; k++) {
                             var dialog = uploadDialogs[k];
                             if (dialog.textContent && dialog.textContent.includes('Uploading')) {
                                 console.log('❌ Closing upload dialog');
                                 dialog.style.display = 'none';
                             }
                         }
                         
                     }, 100);
                     
                     // Additional cleanup with more delay - TARGETED ONLY
                     setTimeout(function() {
                         console.log('🧹 Secondary cleanup sweep (targeted)...');
                         
                         // Only target specific WordPress media modal elements, not the entire page
                         var targetSelectors = [
                             '.media-modal .uploading',
                             '.media-modal .attachment.uploading',
                             '.media-modal .media-uploader-status',
                             '.media-modal .upload-details',
                             '.media-modal .attachment-details',
                             '.media-sidebar .uploading',
                             '.media-frame .uploading'
                         ];
                         
                         for (var s = 0; s < targetSelectors.length; s++) {
                             var elements = document.querySelectorAll(targetSelectors[s]);
                             for (var i = 0; i < elements.length; i++) {
                                 var el = elements[i];
                                 if (el.textContent && el.textContent.includes('Uploading')) {
                                     console.log('🚫 Hiding targeted upload element:', el.tagName, el.className);
                                     el.style.display = 'none';
                                 }
                             }
                         }
                         
                         // Also specifically look for text nodes that say Uploading in media modal context only
                         var mediaModal = document.querySelector('.media-modal');
                         if (mediaModal) {
                             var walker = document.createTreeWalker(
                                 mediaModal,
                                 NodeFilter.SHOW_TEXT,
                                 null,
                                 false
                             );
                             var textNodes = [];
                             var node;
                             while (node = walker.nextNode()) {
                                 if (node.textContent.trim() === 'Uploading' || node.textContent.includes('Uploading Attachment Details')) {
                                     textNodes.push(node);
                                 }
                             }
                             for (var t = 0; t < textNodes.length; t++) {
                                 var textNode = textNodes[t];
                                 var parentEl = textNode.parentElement;
                                 if (parentEl && parentEl.tagName !== 'HTML' && parentEl.tagName !== 'HEAD' && parentEl.tagName !== 'BODY') {
                                     console.log('🚫 Hiding text node parent:', parentEl.tagName, parentEl.className);
                                     parentEl.style.display = 'none';
                                 }
                             }
                         }
                     }, 500);
                     
                     // Start CDN upload
                     uploadToBunnyCDN(audioFile);
                                    return; // Don't call original send
                                }
                            }
                        }
                        
                        // For non-audio files, proceed normally
                        return originalSend.apply(xhr, arguments);
                    };
                    
                    return xhr;
                };
                
                // Copy static properties
                for (var prop in OriginalXHR) {
                    if (OriginalXHR.hasOwnProperty(prop)) {
                        window.XMLHttpRequest[prop] = OriginalXHR[prop];
                    }
                }
                
                console.log('Bunny CDN: Audio upload handler ready');
                
                                 function uploadToBunnyCDN(file) {
                     console.log('Bunny CDN: Processing upload for', file.name);
                     
                     // Create visual feedback
                     var banner = document.createElement('div');
                     banner.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; background: linear-gradient(45deg, #ff6b6b, #4ecdc4); color: white; padding: 15px; z-index: 999999; text-align: center; font-weight: bold; font-size: 16px;';
                     banner.innerHTML = 'Uploading to CDN: ' + file.name + ' (' + Math.round(file.size/1024/1024) + ' MB)';
                     document.body.insertBefore(banner, document.body.firstChild);
                     
                     // Handle plupload file objects
                     function processFileUpload(actualFile) {
                         console.log('🚀 Processing file upload:', actualFile);
                         
                         // Upload to Bunny
                         var xhr = new OriginalXHR(); // Use original XHR
                         var filename = file.name.replace(/[^a-zA-Z0-9.-]/g, '_');
                         var uploadUrl = 'https://ny.storage.bunnycdn.com/riverrun/' + filename;
                         
                         xhr.open('PUT', uploadUrl);
                         xhr.setRequestHeader('AccessKey', '336cc65b-6043-4f65-b9ee34f50a25-d4f6-4928');
                         
                         xhr.upload.onprogress = function(evt) {
                             if (evt.lengthComputable) {
                                 var percent = Math.round((evt.loaded / evt.total) * 100);
                                 banner.innerHTML = 'Uploading to CDN: ' + percent + '% - ' + file.name;
                             }
                         };
                         
                         xhr.onload = function() {
                             if (xhr.status === 200 || xhr.status === 201) {
                                 banner.innerHTML = 'Upload Successful: ' + file.name;
                                 banner.style.background = 'linear-gradient(45deg, #4ecdc4, #44a08d)';
                                 
                                 // Register in WordPress
                                 var cdnUrl = 'https://riverrunpool.b-cdn.net/' + filename;
                                 registerInWordPress(filename, cdnUrl, file.size, banner);
                             } else {
                                 banner.innerHTML = 'Upload Failed: Error ' + xhr.status;
                                 banner.style.background = 'linear-gradient(45deg, #ff6b6b, #ee5a52)';
                             }
                         };
                         
                         xhr.onerror = function() {
                             banner.innerHTML = 'Upload Error Occurred';
                             banner.style.background = 'linear-gradient(45deg, #ff6b6b, #ee5a52)';
                         };
                         
                         xhr.send(actualFile);
                     }
                     
                     // Check if this is a plupload file object
                     if (file.getNativeFile && typeof file.getNativeFile === 'function') {
                         console.log('🔄 Getting native file from plupload object');
                         file.getNativeFile(function(nativeFile) {
                             console.log('✅ Got native file:', nativeFile);
                             processFileUpload(nativeFile);
                         }, function(error) {
                             console.log('❌ Failed to get native file:', error);
                             banner.innerHTML = 'Failed to Process File';
                             banner.style.background = 'linear-gradient(45deg, #ff6b6b, #ee5a52)';
                         });
                     } else {
                         // Regular File object
                         console.log('📁 Processing regular File object');
                         processFileUpload(file);
                     }
                 }
                
                                 function registerInWordPress(filename, cdnUrl, fileSize, banner) {
                     var xhr = new OriginalXHR();
                     xhr.open('POST', window.bunnyEmergencyUploader.ajaxurl);
                     xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                     
                     var data = 'action=bunny_register_direct_upload' +
                               '&nonce=' + encodeURIComponent(window.bunnyEmergencyUploader.nonce) +
                               '&filename=' + encodeURIComponent(filename) +
                               '&cdn_url=' + encodeURIComponent(cdnUrl) +
                               '&file_title=' + encodeURIComponent(filename.replace(/\.[^/.]+$/, '')) +
                               '&file_size=' + fileSize;
                     
                     xhr.onload = function() {
                         try {
                             var response = JSON.parse(xhr.responseText);
                             if (response.success) {
                                 banner.innerHTML = 'Upload Complete: ' + filename + ' has been added to your Media Library';
                                 
                                 // Handle different UI contexts
                                 setTimeout(function() {
                                     if (window.location.href.indexOf('upload.php') > -1) {
                                         // Main Media Library page - reload
                                         window.location.reload();
                                     } else {
                                         // Popup context - close, refresh page, and reopen
                                         refreshMediaPopup(response.data, filename);
                                     }
                                 }, 1000);
                             } else {
                                 banner.innerHTML = 'Upload completed but Media Library registration failed';
                             }
                         } catch (e) {
                             banner.innerHTML = 'Upload completed but Media Library registration failed';
                         }
                         
                         setTimeout(function() {
                             banner.style.display = 'none';
                         }, 5000);
                     };
                     
                     xhr.send(data);
                 }
                 
                 function refreshMediaPopup(attachmentData, filename) {
                     console.log('🔄 Save-then-refresh strategy for:', attachmentData, 'filename:', filename);
                     
                     try {
                         // Close any open media popup and remove overlays
                         if (window.wp && window.wp.media && window.wp.media.frame) {
                             console.log('❌ Closing current media popup...');
                             window.wp.media.frame.close();
                         }
                         
                         // Remove any modal overlays that might be left behind
                         var overlays = document.querySelectorAll('.media-modal-backdrop, .media-modal-overlay, [aria-hidden=\"true\"]');
                         for (var i = 0; i < overlays.length; i++) {
                             console.log('🗑️ Removing overlay:', overlays[i].className);
                             overlays[i].remove();
                         }
                         
                         // Reset body scroll and remove modal classes
                         document.body.style.overflow = '';
                         document.body.classList.remove('modal-open');
                         document.documentElement.classList.remove('wp-toolbar');
                         
                         // Show success message
                         var successMsg = document.createElement('div');
                         successMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #00a32a; color: white; padding: 20px; border-radius: 8px; z-index: 999999; font-weight: bold; box-shadow: 0 4px 20px rgba(0,0,0,0.3); text-align: center;';
                         successMsg.innerHTML = 'Upload Complete!<br>Saving draft and refreshing page...';
                         document.body.appendChild(successMsg);
                         
                         // First save the post as draft to avoid browser warning
                         savePostAsDraft(function() {
                             console.log('💾 Post saved as draft, now refreshing...');
                             
                             // Set flag to auto-open media library after refresh
                             sessionStorage.setItem('bunnyAutoOpenMedia', 'true');
                             console.log('🔖 Set flag to auto-open media library after refresh');
                             
                             setTimeout(function() {
                                 console.log('🔄 Refreshing page to show new media file...');
                                 window.location.reload();
                             }, 1000);
                         });
                         
                     } catch (error) {
                         console.log('❌ Error in refreshMediaPopup:', error);
                         setTimeout(function() {
                             window.location.reload();
                         }, 2000);
                     }
                 }
                 
                 function savePostAsDraft(callback) {
                     console.log('💾 Auto-saving post as draft before refresh...');
                     
                     try {
                         // Method 1: Try WordPress Gutenberg editor save
                         if (window.wp && window.wp.data && window.wp.data.dispatch) {
                             console.log('💾 Using Gutenberg editor save...');
                             var dispatch = window.wp.data.dispatch('core/editor');
                             if (dispatch && dispatch.savePost) {
                                 dispatch.savePost();
                                 console.log('✅ Gutenberg savePost called');
                                 if (callback) callback();
                                 return;
                             }
                         }
                         
                         // Method 2: Try WordPress autosave
                         if (window.wp && window.wp.autosave) {
                             console.log('💾 Using WordPress autosave...');
                             window.wp.autosave.server.triggerSave();
                             console.log('✅ WordPress autosave triggered');
                             if (callback) callback();
                             return;
                         }
                         
                         // Method 3: Try classic editor autosave
                         if (window.autosave) {
                             console.log('💾 Using classic editor autosave...');
                             window.autosave();
                             console.log('✅ Classic autosave called');
                             if (callback) callback();
                             return;
                         }
                         
                         // Method 4: Manual form submission (fallback)
                         var form = document.getElementById('post');
                         if (form) {
                             console.log('💾 Manual form submission fallback...');
                             var statusField = document.getElementById('post_status');
                             if (statusField) {
                                 statusField.value = 'draft';
                             }
                             var submitButton = document.getElementById('save-post');
                             if (submitButton) {
                                 submitButton.click();
                                 console.log('✅ Draft save button clicked');
                                 if (callback) callback();
                                 return;
                             }
                         }
                         
                         console.log('⚠️ No save method found, proceeding with refresh');
                         if (callback) callback();
                         
                     } catch (error) {
                         console.log('❌ Error saving post as draft:', error);
                         if (callback) callback();
                     }
                 }


                 
                 function checkAndReopenMediaPopup() {
                     console.log('🔍 Checking if we should auto-open media library...');
                     
                     // Check if we should auto-open media library after page refresh
                     if (sessionStorage.getItem('bunnyAutoOpenMedia') === 'true') {
                         console.log('🎯 Found auto-open flag, clearing it and opening media library...');
                         
                         // Clear the flag immediately
                         sessionStorage.removeItem('bunnyAutoOpenMedia');
                         
                         // Wait for page to fully load, then auto-open
                         setTimeout(function() {
                             autoOpenChooseMedia();
                         }, 1500);
                     } else {
                         console.log('🔍 No auto-open flag found, normal page load');
                     }
                 }
                 
                 function autoOpenChooseMedia() {
                     console.log('🎯 Auto-opening Choose Media dialog...');
                     
                     var attempts = 0;
                     var maxAttempts = 10;
                     
                     function tryOpenMedia() {
                         attempts++;
                         console.log('🔍 Media open attempt', attempts, 'of', maxAttempts);
                         
                         // Look for the Choose Media button with multiple selectors
                         var mediaButton = document.querySelector('button.cx-upload-button[data-title=\"Choose Media\"]') ||
                                          document.querySelector('button[data-title=\"Choose Media\"]') ||
                                          document.querySelector('.cx-upload-button') ||
                                          document.querySelector('button[value=\"Choose Media\"]') ||
                                          document.querySelector('button.upload-button.cx-upload-button');
                         
                         if (mediaButton) {
                             console.log('✅ Found Choose Media button, clicking...', mediaButton);
                             
                             // Show brief success message
                             var clickMsg = document.createElement('div');
                             clickMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #0073aa; color: white; padding: 15px; border-radius: 8px; z-index: 999999; font-weight: bold; box-shadow: 0 4px 20px rgba(0,0,0,0.3);';
                             clickMsg.innerHTML = 'Opening Media Library...';
                             document.body.appendChild(clickMsg);
                             
                             setTimeout(function() {
                                 mediaButton.click();
                                 console.log('✅ Choose Media button clicked successfully');
                                 
                                 setTimeout(function() {
                                     if (clickMsg.parentNode) {
                                         clickMsg.parentNode.removeChild(clickMsg);
                                     }
                                 }, 1000);
                             }, 500);
                             
                             return true;
                         }
                         
                         // Retry if not found and attempts remain
                         if (attempts < maxAttempts) {
                             console.log('⏳ Choose Media button not found, retrying in 1 second...');
                             setTimeout(tryOpenMedia, 1000);
                         } else {
                             console.log('❌ Could not find Choose Media button after', maxAttempts, 'attempts');
                         }
                     }
                     
                     // Start trying to open
                     tryOpenMedia();
                 }
            })();
            ";
            echo "</script>\n";
        }
    }
    
    /**
     * Get CSS for uploader replacement
     */
    private function get_uploader_replacement_css() {
        return "
        .bunny-upload-notice {
            animation: bunnySlideIn 0.5s ease-out;
        }
        
        .bunny-upload-progress {
            animation: bunnyFadeIn 0.3s ease-out;
        }
        
        @keyframes bunnySlideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes bunnyFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .bunny-upload-progress-fill {
            background: linear-gradient(90deg, #0073aa 0%, #005177 100%);
        }
        ";
    }
    
    /**
     * Get frontend uploader script for themes/plugins
     */
    private function get_frontend_uploader_script() {
        return "
        // Frontend uploader replacement for wp.media
        if (typeof wp !== 'undefined' && wp.media) {
            // Override wp.media upload behavior for audio files
            console.log('Bunny.net frontend uploader initialized');
        }
        ";
    }
    
    /**
     * Get JavaScript for direct upload functionality
     */
    private function get_direct_upload_script() {
        return "
        jQuery(document).ready(function($) {
            // Check upload method and show/hide appropriate UI
            $('input[name=\"upload_method\"]').on('change', function() {
                var method = $('input[name=\"upload_method\"]:checked').val();
                if (method === 'direct') {
                    $('#bunny-upload-submit').val('🚀 Direct Upload to Bunny.net');
                } else {
                    $('#bunny-upload-submit').val('Upload via Server');
                }
            });
            
            $('#bunny-direct-upload-form').on('submit', function(e) {
                e.preventDefault();
                
                var form = $(this);
                var fileInput = $('#bunny_file')[0];
                var titleInput = $('#file_title');
                var submitBtn = $('#bunny-upload-submit');
                var progressDiv = $('#bunny-upload-progress');
                var resultDiv = $('#bunny-upload-result');
                var progressBar = $('#bunny-progress-bar');
                var progressText = $('#bunny-progress-text');
                var uploadMethod = $('input[name=\"upload_method\"]:checked').val();
                
                // Validate file selection
                if (!fileInput.files || fileInput.files.length === 0) {
                    alert('Please select a file to upload.');
                    return;
                }
                
                var file = fileInput.files[0];
                var maxSize = 10 * 1024 * 1024 * 1024; // 10GB limit
                
                if (file.size > maxSize) {
                    alert('File is too large. Maximum size is 10GB.');
                    return;
                }
                
                // Show progress
                submitBtn.prop('disabled', true);
                progressDiv.show();
                resultDiv.hide().html('');
                progressText.text('Uploading ' + file.name + ' (' + formatFileSize(file.size) + ')...');
                
                // Choose upload method
                if (uploadMethod === 'direct') {
                    uploadDirectToBunny(file, titleInput.val());
                } else {
                    uploadViaServer(file, titleInput.val());
                }
                
                function uploadViaServer(file, title) {
                    submitBtn.val('Uploading via Server...');
                    
                    // Prepare form data
                    var formData = new FormData();
                    formData.append('action', 'bunny_direct_upload');
                    formData.append('nonce', bunnyDirectUpload.nonce);
                    formData.append('bunny_file', file);
                    formData.append('file_title', title);
                    
                    // Debug log
                    console.log('Server upload - File:', file.name, 'Size:', file.size, 'Type:', file.type);
                    
                    // Upload via AJAX
                                    $.ajax({
                        url: bunnyDirectUpload.ajaxurl,
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        xhr: function() {
                            var xhr = new window.XMLHttpRequest();
                            xhr.upload.addEventListener('progress', function(evt) {
                                if (evt.lengthComputable) {
                                    var percentComplete = (evt.loaded / evt.total) * 100;
                                    progressBar.css('width', percentComplete + '%');
                                    progressText.text('Uploading via server... ' + Math.round(percentComplete) + '%');
                                }
                            }, false);
                            return xhr;
                        },
                        success: function(response) {
                            handleUploadComplete(response, true);
                        },
                        error: function(xhr, status, error) {
                            handleUploadError(xhr, status, error, 'Server upload failed');
                        }
                    });
                }
                
                function uploadDirectToBunny(file, title) {
                    submitBtn.val('🚀 Direct uploading to Bunny.net...');
                    
                    console.log('Direct upload - File:', file.name, 'Size:', file.size);
                    
                    // Direct upload to Bunny.net Storage API
                    var storageEndpoint = 'https://ny.storage.bunnycdn.com';
                    var storageZone = 'riverrun';
                    var accessKey = '336cc65b-6043-4f65-b9ee34f50a25-d4f6-4928';
                    var filename = sanitizeFilename(file.name);
                    var uploadUrl = storageEndpoint + '/' + storageZone + '/' + filename;
                    
                    console.log('Direct upload URL:', uploadUrl);
                    
                    // Upload directly to Bunny.net
                    $.ajax({
                        url: uploadUrl,
                        type: 'PUT',
                        data: file,
                        processData: false,
                        contentType: 'application/octet-stream',
                        headers: {
                            'AccessKey': accessKey
                        },
                        xhr: function() {
                            var xhr = new window.XMLHttpRequest();
                            xhr.upload.addEventListener('progress', function(evt) {
                                if (evt.lengthComputable) {
                                    var percentComplete = (evt.loaded / evt.total) * 100;
                                    progressBar.css('width', percentComplete + '%');
                                    progressText.text('🚀 Direct upload... ' + Math.round(percentComplete) + '%');
                                }
                            }, false);
                            return xhr;
                        },
                        success: function(response, status, xhr) {
                            console.log('Direct upload successful:', xhr.status);
                            
                            // Now register in WordPress Media Library
                            var cdnUrl = 'https://riverrunpool.b-cdn.net/' + filename;
                            
                            progressText.text('Adding to Media Library...');
                            progressBar.css('width', '100%');
                            
                            $.ajax({
                                url: bunnyDirectUpload.ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'bunny_register_direct_upload',
                                    nonce: bunnyDirectUpload.nonce,
                                    filename: filename,
                                    cdn_url: cdnUrl,
                                    file_title: title || filename.replace(/\.[^/.]+$/, ''),
                                    file_size: file.size
                                },
                                success: function(response) {
                                    var mockResponse = {
                                        success: true,
                                        data: {
                                            filename: filename,
                                            cdn_url: cdnUrl,
                                            attachment_id: response.data ? response.data.attachment_id : 'Created'
                                        }
                                    };
                                    handleUploadComplete(mockResponse, false);
                                },
                                error: function(xhr, status, error) {
                                    // File uploaded but failed to register in WordPress
                                    resultDiv.html('<div class=\"notice notice-warning inline\"><p><strong>Partial Success!</strong><br>' + 
                                                  'File uploaded to Bunny.net: <a href=\"' + cdnUrl + '\" target=\"_blank\">' + cdnUrl + '</a><br>' +
                                                  'But failed to add to Media Library. You can add it manually using the form below.</p></div>').show();
                                    resetForm();
                                }
                            });
                        },
                        error: function(xhr, status, error) {
                            handleUploadError(xhr, status, error, 'Direct upload to Bunny.net failed');
                        }
                    });
                }
                
                function handleUploadComplete(response, resetTitle) {
                    progressDiv.hide();
                    
                    if (response.success) {
                        resultDiv.html('<div class=\"notice notice-success inline\"><p><strong>' + bunnyDirectUpload.success + '</strong><br>' + 
                                      'File: ' + response.data.filename + '<br>' +
                                      'CDN URL: <a href=\"' + response.data.cdn_url + '\" target=\"_blank\">' + response.data.cdn_url + '</a><br>' +
                                      'Added to <a href=\"' + bunnyDirectUpload.adminurl + 'upload.php\">Media Library</a> as ID #' + response.data.attachment_id + '</p></div>').show();
                        
                        // Reset form
                        form[0].reset();
                        if (resetTitle) titleInput.val('');
                    } else {
                        resultDiv.html('<div class=\"notice notice-error inline\"><p><strong>' + bunnyDirectUpload.error + '</strong><br>' + 
                                      (response.data ? response.data : 'Unknown error occurred.') + '</p></div>').show();
                    }
                    resetForm();
                }
                
                function handleUploadError(xhr, status, error, context) {
                    progressDiv.hide();
                    console.error(context + ' Error:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        statusCode: xhr.status
                    });
                    
                    var errorMsg = context + ': ' + error;
                    if (xhr.responseText) {
                        errorMsg += '<br>Response: ' + xhr.responseText.substring(0, 200);
                    }
                    if (xhr.status) {
                        errorMsg += '<br>Status Code: ' + xhr.status;
                    }
                    
                    resultDiv.html('<div class=\"notice notice-error inline\"><p><strong>' + bunnyDirectUpload.error + '</strong><br>' + 
                                  errorMsg + '</p></div>').show();
                    resetForm();
                }
                
                function resetForm() {
                    submitBtn.prop('disabled', false);
                    var method = $('input[name=\"upload_method\"]:checked').val();
                    if (method === 'direct') {
                        submitBtn.val('🚀 Direct Upload to Bunny.net');
                    } else {
                        submitBtn.val('Upload via Server');
                    }
                }
                
                function sanitizeFilename(filename) {
                    return filename.replace(/[^a-zA-Z0-9.-]/g, '_');
                }
            });
            
            // File size formatter
            function formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                var k = 1024;
                var sizes = ['Bytes', 'KB', 'MB', 'GB'];
                var i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
        });
        ";
    }
}

// Initialize the plugin
new Bunny_Auto_Uploader(); 