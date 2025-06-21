<?php
/**
 * Cleanup Script for Fragmented Audio Files
 * 
 * This script removes fragmented audio files created during chunked upload experiments
 * from both WordPress Media Library and Bunny.net CDN
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    require_once('../../../wp-config.php');
}

// Security check
if (!current_user_can('manage_options')) {
    die('Access denied');
}

echo "<h1>🧹 Bunny Auto Uploader - Cleanup Fragmented Files</h1>";

// Get all fragmented files (files with -1, -2, -3, etc. suffix)
$fragmented_files = get_posts(array(
    'post_type' => 'attachment',
    'post_mime_type' => 'audio',
    'posts_per_page' => -1,
    'meta_query' => array(
        array(
            'key' => '_bunny_cdn_url',
            'compare' => 'EXISTS'
        )
    )
));

$fragments_found = array();
$original_files = array();

foreach ($fragmented_files as $file) {
    $filename = basename(get_attached_file($file->ID));
    
    // Check if this looks like a fragment (ends with -1, -2, etc.)
    if (preg_match('/^(.+)-(\d+)\.(\w+)$/', $filename, $matches)) {
        $base_name = $matches[1] . '.' . $matches[3];
        $fragment_number = intval($matches[2]);
        
        $fragments_found[$base_name][] = array(
            'id' => $file->ID,
            'filename' => $filename,
            'fragment_number' => $fragment_number,
            'cdn_url' => get_post_meta($file->ID, '_bunny_cdn_url', true)
        );
    } else {
        // This might be an original file
        $original_files[] = array(
            'id' => $file->ID,
            'filename' => $filename,
            'cdn_url' => get_post_meta($file->ID, '_bunny_cdn_url', true)
        );
    }
}

echo "<h2>📊 Analysis Results</h2>";
echo "<p><strong>Fragmented file groups found:</strong> " . count($fragments_found) . "</p>";
echo "<p><strong>Original files found:</strong> " . count($original_files) . "</p>";

if (!empty($fragments_found)) {
    echo "<h3>🔍 Fragmented Files Detected:</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Base File</th><th>Fragments</th><th>Actions</th></tr>";
    
    foreach ($fragments_found as $base_name => $fragments) {
        echo "<tr>";
        echo "<td><strong>$base_name</strong></td>";
        echo "<td>" . count($fragments) . " fragments<br>";
        
        // Sort fragments by number
        usort($fragments, function($a, $b) {
            return $a['fragment_number'] - $b['fragment_number'];
        });
        
        foreach ($fragments as $fragment) {
            echo "- {$fragment['filename']} (ID: {$fragment['id']})<br>";
        }
        echo "</td>";
        echo "<td>";
        echo "<a href='?cleanup=fragments&base=" . urlencode($base_name) . "' onclick='return confirm(\"Delete all fragments for $base_name?\")'>Delete All Fragments</a>";
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Handle cleanup action
if (isset($_GET['cleanup']) && $_GET['cleanup'] === 'fragments' && isset($_GET['base'])) {
    $base_name = sanitize_text_field($_GET['base']);
    
    if (isset($fragments_found[$base_name])) {
        echo "<h3>🗑️ Cleaning up fragments for: $base_name</h3>";
        
        $deleted_count = 0;
        $errors = array();
        
        foreach ($fragments_found[$base_name] as $fragment) {
            // Delete from WordPress
            $wp_deleted = wp_delete_attachment($fragment['id'], true);
            
            if ($wp_deleted) {
                echo "✅ Deleted from WordPress: {$fragment['filename']}<br>";
                
                // Delete from Bunny CDN via FTP
                $ftp_deleted = delete_from_bunny_ftp($fragment['filename']);
                
                if ($ftp_deleted) {
                    echo "✅ Deleted from Bunny CDN: {$fragment['filename']}<br>";
                    $deleted_count++;
                } else {
                    echo "⚠️ Failed to delete from Bunny CDN: {$fragment['filename']}<br>";
                    $errors[] = "Failed to delete {$fragment['filename']} from Bunny CDN";
                }
            } else {
                echo "❌ Failed to delete from WordPress: {$fragment['filename']}<br>";
                $errors[] = "Failed to delete {$fragment['filename']} from WordPress";
            }
        }
        
        echo "<p><strong>Summary:</strong> Deleted $deleted_count fragments</p>";
        
        if (!empty($errors)) {
            echo "<p><strong>Errors:</strong></p><ul>";
            foreach ($errors as $error) {
                echo "<li>$error</li>";
            }
            echo "</ul>";
        }
        
        echo "<p><a href='?'>← Back to main cleanup</a></p>";
    }
}

/**
 * Delete file from Bunny CDN via FTP
 */
function delete_from_bunny_ftp($filename) {
    // Get Bunny settings
    $ftp_username = get_option('bunny_auto_uploader_ftp_username', '');
    $ftp_password = get_option('bunny_auto_uploader_ftp_password', '');
    $storage_zone = get_option('bunny_auto_uploader_storage_zone', '');
    $storage_region = get_option('bunny_auto_uploader_storage_region', '');
    
    if (empty($ftp_username) || empty($ftp_password) || empty($storage_zone)) {
        return false;
    }
    
    // Determine FTP host
    $ftp_host = !empty($storage_region) ? 
        $storage_region . '.storage.bunnycdn.com' : 
        'storage.bunnycdn.com';
    
    // Connect to FTP
    $ftp_conn = ftp_connect($ftp_host, 21, 30);
    if (!$ftp_conn) {
        return false;
    }
    
    // Login
    if (!ftp_login($ftp_conn, $ftp_username, $ftp_password)) {
        ftp_close($ftp_conn);
        return false;
    }
    
    // Set passive mode
    ftp_pasv($ftp_conn, true);
    
    // Delete file
    $remote_file = '/' . $storage_zone . '/' . $filename;
    $result = ftp_delete($ftp_conn, $remote_file);
    
    ftp_close($ftp_conn);
    
    return $result;
}

echo "<hr>";
echo "<p><strong>Note:</strong> This cleanup only removes fragmented files (files ending with -1, -2, etc.). Original files are preserved.</p>";
echo "<p><strong>Backup Recommendation:</strong> Consider backing up your database before running cleanup operations.</p>";
?> 