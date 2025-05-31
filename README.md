# Bunny Auto Uploader

A WordPress plugin that automatically uploads audio files to Bunny.net CDN when they are added to the WordPress Media Library.

## Features

- Automatically detects when audio files (.mp3, .wav) are uploaded to the Media Library
- Uploads these files to Bunny.net CDN via API or FTP
- Stores the Bunny.net CDN URL as attachment metadata
- Provides an admin settings page for Bunny.net API and FTP configuration
- Adds a meta box to the attachment edit screen to view/edit the CDN URL
- Includes a custom column in the Media Library to display CDN status

## Installation

1. Download the plugin files
2. Upload the plugin files to the `/wp-content/plugins/bunny-auto-uploader` directory
3. Activate the plugin through the 'Plugins' screen in WordPress
4. Configure the plugin settings under 'Settings > Bunny Auto Uploader'

## Configuration

1. Navigate to 'Settings > Bunny Auto Uploader'
2. Choose between API or FTP upload method:

   **API Upload Method (Default):**
   - Enter your Bunny.net API key
   - Enter your Bunny.net storage zone name
   - Enter your Bunny.net pull zone URL

   **FTP Upload Method:**
   - Check the "Use FTP Instead of API" option
   - Enter your FTP Host (usually storage.bunnycdn.com)
   - Enter your FTP Username (usually the same as your storage zone name)
   - Enter your FTP Password
   - Enter your Bunny.net pull zone URL

3. Save changes

## Usage

Once configured, the plugin works automatically:

1. Upload an audio file (.mp3, .wav) to your WordPress Media Library
2. The plugin will automatically upload the file to Bunny.net
3. The Bunny.net CDN URL will be stored as attachment metadata
4. You can view/edit the CDN URL on the attachment edit screen

## Error Handling

The plugin includes comprehensive error handling:

- Failed uploads are logged in the plugin settings page
- You can retry failed uploads with a single click
- Detailed error messages help troubleshoot issues

## License

GPL v2 or later 