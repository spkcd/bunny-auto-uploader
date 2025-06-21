<?php
/**
 * Test Direct Upload AJAX
 * 
 * This is a simple test to verify the AJAX endpoint is working
 */

// Load WordPress
require_once('../../../wp-config.php');

// Security check
if (!current_user_can('manage_options')) {
    die('Access denied');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Direct Upload</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <h1>Test Direct Upload AJAX</h1>
    
    <form id="test-form" enctype="multipart/form-data">
        <p>
            <label>Select Audio File:</label><br>
            <input type="file" id="test_file" name="test_file" accept=".mp3,.wav,.m4a,.ogg,.flac" required>
        </p>
        <p>
            <label>Title:</label><br>
            <input type="text" id="test_title" name="test_title" placeholder="Optional title">
        </p>
        <p>
            <button type="submit">Test Upload</button>
        </p>
    </form>
    
    <div id="test-result"></div>
    
    <script>
    jQuery(document).ready(function($) {
        $('#test-form').on('submit', function(e) {
            e.preventDefault();
            
            var fileInput = $('#test_file')[0];
            var titleInput = $('#test_title');
            var resultDiv = $('#test-result');
            
            if (!fileInput.files || fileInput.files.length === 0) {
                alert('Please select a file');
                return;
            }
            
            var file = fileInput.files[0];
            
            // First test - simple ping to see if endpoint exists
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'bunny_direct_upload',
                    nonce: '<?php echo wp_create_nonce('bunny_direct_upload'); ?>',
                    test: 'ping'
                },
                success: function(response) {
                    console.log('Ping test response:', response);
                    
                    // Now test actual upload
                    var formData = new FormData();
                    formData.append('action', 'bunny_direct_upload');
                    formData.append('nonce', '<?php echo wp_create_nonce('bunny_direct_upload'); ?>');
                    formData.append('bunny_file', file);
                    formData.append('file_title', titleInput.val());
                    
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(response) {
                            console.log('Upload response:', response);
                            resultDiv.html('<div style="background: #d4edda; padding: 10px; border: 1px solid #c3e6cb; color: #155724;">SUCCESS: ' + JSON.stringify(response) + '</div>');
                        },
                        error: function(xhr, status, error) {
                            console.error('Upload error:', xhr, status, error);
                            resultDiv.html('<div style="background: #f8d7da; padding: 10px; border: 1px solid #f5c6cb; color: #721c24;">ERROR: ' + error + '<br>Status: ' + xhr.status + '<br>Response: ' + xhr.responseText + '</div>');
                        }
                    });
                },
                error: function(xhr, status, error) {
                    console.error('Ping error:', xhr, status, error);
                    resultDiv.html('<div style="background: #f8d7da; padding: 10px; border: 1px solid #f5c6cb; color: #721c24;">PING FAILED: ' + error + '<br>Status: ' + xhr.status + '<br>Response: ' + xhr.responseText + '</div>');
                }
            });
        });
    });
    </script>
</body>
</html> 