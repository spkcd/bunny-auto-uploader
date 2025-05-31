/**
 * Bunny Auto Uploader Admin JavaScript
 */
jQuery(document).ready(function($) {
    
    // Common function to handle uploads via AJAX
    function uploadToBunny(element, attachmentId, isMediaLibrary) {
        // Show loading state
        var originalText = element.text();
        element.text(bunnyAutoUploaderVars.uploading);
        
        if (element.is('button')) {
            element.prop('disabled', true);
        } else {
            element.addClass('disabled');
        }
        
        // Send AJAX request to upload the file
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bunny_upload_attachment',
                attachment_id: attachmentId,
                nonce: bunnyAutoUploaderVars.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (isMediaLibrary) {
                        // Replace the link with the CDN URL input in Media Library
                        var html = '<div class="bunny-cdn-url-wrapper">';
                        html += '<input type="text" class="bunny-cdn-url-readonly" value="' + response.data.url + '" readonly onclick="this.select();" />';
                        html += '<a href="' + response.data.url + '" target="_blank" class="bunny-cdn-open" title="' + bunnyAutoUploaderVars.openUrl + '">';
                        html += '<span class="dashicons dashicons-external"></span>';
                        html += '</a>';
                        html += '</div>';
                        
                        element.parent().html(html);
                    } else {
                        // Replace the button with the CDN URL in attachment edit screen
                        var html = '<a href="' + response.data.url + '" target="_blank" class="bunny-cdn-url">';
                        html += response.data.url;
                        html += '</a>';
                        html += '<br><span class="description">' + bunnyAutoUploaderVars.hostedOnBunny + '</span>';
                        
                        element.parent().html(html);
                    }
                } else {
                    // Show error and restore button
                    if (element.is('button')) {
                        element.prop('disabled', false).text(originalText);
                    } else {
                        element.removeClass('disabled').text(originalText);
                    }
                    alert(response.data.message || bunnyAutoUploaderVars.uploadFailed);
                }
            },
            error: function() {
                // Show error and restore button
                if (element.is('button')) {
                    element.prop('disabled', false).text(originalText);
                } else {
                    element.removeClass('disabled').text(originalText);
                }
                alert(bunnyAutoUploaderVars.uploadFailed);
            }
        });
    }
    
    // Handle Upload to Bunny CDN button click in attachment edit screen
    $(document).on('click', '.upload-to-bunny', function(e) {
        e.preventDefault();
        var button = $(this);
        var attachmentId = button.data('attachment-id');
        uploadToBunny(button, attachmentId, false);
    });
    
    // Handle Upload to Bunny link click in Media Library
    $(document).on('click', '.bunny-upload-now, .bunny-retry-upload', function(e) {
        e.preventDefault();
        var link = $(this);
        var attachmentId = link.data('attachment-id');
        uploadToBunny(link, attachmentId, true);
    });
}); 