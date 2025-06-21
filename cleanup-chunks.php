<?php
/**
 * Bunny Auto Uploader - Cleanup Broken Chunks
 * 
 * This script helps clean up the broken chunked files that were created
 * when the prefilter was interfering with WordPress's chunk reassembly.
 */

// WordPress integration
if (!defined('ABSPATH')) {
    require_once(dirname(__FILE__) . '/wp-config.php');
}

/**
 * Find and list all broken chunk files
 */
function find_broken_chunks() {
    global $wpdb;
    
    echo "=== BUNNY AUTO UPLOADER CHUNK CLEANUP ===\n\n";
    
    // Find attachments with chunk-like names (updated for recent uploads)
    $chunk_patterns = array(
        '%M111Podcast2025-%',  // Previous broken chunks
        '%sample4-%'           // Recent broken chunks
    );
    
    $all_chunks = array();
    
    foreach ($chunk_patterns as $pattern) {
        $query = $wpdb->prepare("
            SELECT ID, post_title, post_mime_type, guid
            FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_title LIKE %s
            ORDER BY ID DESC
            LIMIT 50
        ", $pattern);
        
        $chunks = $wpdb->get_results($query);
        $all_chunks = array_merge($all_chunks, $chunks);
    }
    
    $chunks = $all_chunks;
    
    if (empty($chunks)) {
        echo "No chunk files found.\n";
        return array();
    }
    
    echo "Found " . count($chunks) . " potential chunk files:\n\n";
    
    $chunk_info = array();
    foreach ($chunks as $chunk) {
        $file_path = get_attached_file($chunk->ID);
        $file_size = file_exists($file_path) ? filesize($file_path) : 0;
        $file_size_mb = round($file_size / 1024 / 1024, 2);
        
        $bunny_url = get_post_meta($chunk->ID, '_bunny_cdn_url', true);
        
        $chunk_info[] = array(
            'id' => $chunk->ID,
            'title' => $chunk->post_title,
            'file_path' => $file_path,
            'file_size_mb' => $file_size_mb,
            'bunny_url' => $bunny_url,
            'exists' => file_exists($file_path)
        );
        
        echo "ID: {$chunk->ID} | {$chunk->post_title} | {$file_size_mb}MB | " . 
             ($bunny_url ? "✅ CDN" : "❌ No CDN") . " | " .
             ($chunk_info[count($chunk_info)-1]['exists'] ? "✅ File" : "❌ Missing") . "\n";
    }
    
    return $chunk_info;
}

/**
 * Delete chunk files from WordPress and optionally from Bunny CDN
 */
function cleanup_chunks($chunk_info, $delete_from_bunny = false) {
    echo "\n=== CLEANUP PROCESS ===\n\n";
    
    $deleted_count = 0;
    $bunny_deleted_count = 0;
    
    foreach ($chunk_info as $chunk) {
        echo "Processing: {$chunk['title']} (ID: {$chunk['id']})...\n";
        
        // Delete from WordPress
        $deleted = wp_delete_attachment($chunk['id'], true);
        if ($deleted) {
            echo "  ✅ Deleted from WordPress\n";
            $deleted_count++;
        } else {
            echo "  ❌ Failed to delete from WordPress\n";
        }
        
        // Optionally delete from Bunny CDN
        if ($delete_from_bunny && !empty($chunk['bunny_url'])) {
            $filename = basename($chunk['bunny_url']);
            $deleted_from_bunny = delete_from_bunny_cdn($filename);
            if ($deleted_from_bunny) {
                echo "  ✅ Deleted from Bunny CDN\n";
                $bunny_deleted_count++;
            } else {
                echo "  ❌ Failed to delete from Bunny CDN\n";
            }
        }
        
        echo "\n";
    }
    
    echo "=== CLEANUP COMPLETE ===\n";
    echo "WordPress deletions: {$deleted_count}\n";
    if ($delete_from_bunny) {
        echo "Bunny CDN deletions: {$bunny_deleted_count}\n";
    }
}

/**
 * Delete file from Bunny CDN
 */
function delete_from_bunny_cdn($filename) {
    // Get Bunny settings
    $api_key = get_option('bunny_auto_uploader_api_key', '');
    $storage_zone = get_option('bunny_auto_uploader_storage_zone', '');
    $storage_region = get_option('bunny_auto_uploader_storage_region', '');
    
    if (empty($api_key) || empty($storage_zone)) {
        return false;
    }
    
    // Determine storage endpoint
    $storage_endpoint = !empty($storage_region) ? 
        $storage_region . '.storage.bunnycdn.com' : 
        'storage.bunnycdn.com';
    
    // Delete via HTTP API
    $url = "https://{$storage_endpoint}/{$storage_zone}/{$filename}";
    
    $response = wp_remote_request($url, array(
        'method' => 'DELETE',
        'headers' => array(
            'AccessKey' => $api_key
        ),
        'timeout' => 30
    ));
    
    return !is_wp_error($response) && wp_remote_response_code($response) === 200;
}

// CLI interface
if (php_sapi_name() === 'cli') {
    $chunks = find_broken_chunks();
    
    if (!empty($chunks)) {
        echo "\nOptions:\n";
        echo "1. Delete from WordPress only\n";
        echo "2. Delete from WordPress AND Bunny CDN\n";
        echo "3. Cancel\n\n";
        
        echo "Enter choice (1-3): ";
        $handle = fopen("php://stdin", "r");
        $choice = trim(fgets($handle));
        fclose($handle);
        
        switch ($choice) {
            case '1':
                cleanup_chunks($chunks, false);
                break;
            case '2':
                cleanup_chunks($chunks, true);
                break;
            case '3':
                echo "Cancelled.\n";
                break;
            default:
                echo "Invalid choice.\n";
        }
    }
}

echo "\n=== SCRIPT COMPLETE ===\n";
?> 