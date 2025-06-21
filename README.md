# Bunny Auto Uploader

A WordPress plugin that automatically uploads audio files to Bunny.net CDN with advanced upload interception and seamless workflow integration.

**Developed by SPARKWEB Studio** - Professional WordPress development and custom solutions.

## Version 2.1.0 - Current Release

### Revolutionary Upload Interception System
- **Smart Audio Detection**: Automatically detects and intercepts audio file uploads in real-time
- **Seamless CDN Routing**: Bypasses WordPress upload system for audio files, routing directly to Bunny.net
- **Zero File Duplication**: Prevents audio files from being stored locally, saving server space
- **Professional UI**: Clean, client-friendly upload progress and status messages

## Features

### Core Functionality
- **Automatic Audio Upload**: Detects audio files (.mp3, .wav, .m4a, .ogg, .flac) and uploads to Bunny.net CDN
- **Upload Interception**: Advanced XMLHttpRequest interception system blocks WordPress uploads for audio files
- **Media Library Integration**: Automatically registers uploaded files in WordPress Media Library
- **Smart Workflow**: Auto-saves post as draft and refreshes page after upload to prevent browser warnings

### Upload Methods
- **API Upload**: Direct upload to Bunny.net Storage API (recommended)
- **FTP Upload**: Alternative FTP-based upload method
- **Large File Support**: Handles files up to 10GB with proper chunking prevention

### User Experience
- **Auto-Save Integration**: Automatically saves posts as drafts before page refresh
- **Smart Media Popup**: Automatically reopens media library after upload completion
- **Professional Messages**: Clean, business-appropriate progress and status messages
- **Error Recovery**: Comprehensive error handling with detailed logging

### Admin Features
- **Settings Panel**: Complete configuration interface under 'Settings > Bunny Auto Uploader'
- **Media Library Column**: Custom column showing CDN upload status
- **Attachment Meta Box**: View and edit CDN URLs on attachment edit screens
- **Failed Upload Recovery**: One-click retry for failed uploads
- **Debug Logging**: Comprehensive error and debug logging

## Installation

1. Download the plugin files
2. Upload to `/wp-content/plugins/bunny-auto-uploader/` directory
3. Activate the plugin through the 'Plugins' screen in WordPress
4. Configure settings under 'Settings > Bunny Auto Uploader'

## Configuration

### Quick Setup
1. Navigate to 'Settings > Bunny Auto Uploader'
2. Enter your Bunny.net credentials:
   - **Storage Zone**: Your Bunny.net storage zone name
   - **API Key**: Your Bunny.net API access key  
   - **Pull Zone URL**: Your CDN URL (e.g., https://your-zone.b-cdn.net/)
3. Save settings

### Advanced Options
- **Upload Method**: Choose between API (default) or FTP upload
- **Upload Replacement**: Enable to replace default WordPress uploader
- **Large File Handling**: Automatic chunking prevention for seamless uploads

## Usage

### Seamless Workflow
1. **Upload**: Drag and drop or select audio files in WordPress
2. **Automatic Processing**: Plugin intercepts and uploads to Bunny.net
3. **Auto-Save**: Post automatically saves as draft
4. **Page Refresh**: Clean refresh without browser warnings
5. **Media Selection**: Media library automatically opens for file selection

### Media Management
- **CDN URLs**: All audio files automatically get CDN URLs
- **Local Storage**: Audio files are not stored locally (saves space)
- **Media Library**: Files appear normally in WordPress Media Library
- **Frontend Delivery**: All audio playback uses CDN URLs automatically

## Technical Features

### Upload Interception
- **XMLHttpRequest Override**: Advanced JavaScript interception system
- **Audio File Detection**: Smart detection of audio files by extension and MIME type
- **FormData Analysis**: Deep inspection of upload data for accurate routing
- **WordPress Integration**: Seamless integration with WordPress upload workflow

### Error Handling & Recovery
- **Comprehensive Logging**: Detailed error tracking and debugging
- **Upload Retry**: Automatic and manual retry mechanisms
- **Graceful Degradation**: Fallback to normal WordPress uploads if needed
- **User Feedback**: Clear, professional error messages

### Performance Optimization
- **Direct Upload**: Bypasses WordPress processing for audio files
- **Server Resource Savings**: No local storage of audio files
- **CDN Benefits**: Fast global delivery via Bunny.net CDN
- **Chunking Prevention**: Optimized for large file uploads

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Active Bunny.net account with Storage Zone
- Modern browser with JavaScript enabled

## Changelog

### Version 2.1.0 (Current)
- **NEW**: Plugin now officially maintained by SPARKWEB Studio
- **NEW**: Professional support and development by expert WordPress team
- **IMPROVED**: Enhanced documentation and professional services information

### Version 2.0.0
- **NEW**: Advanced upload interception system
- **NEW**: Real-time audio file detection and routing
- **NEW**: Professional UI with clean status messages
- **NEW**: Auto-save and refresh workflow
- **NEW**: Automatic media library reopening
- **IMPROVED**: Complete elimination of browser warnings
- **IMPROVED**: Enhanced error handling and recovery
- **IMPROVED**: Support for additional audio formats (.ogg, .flac)
- **FIXED**: Overlay issues with media modal
- **FIXED**: File duplication problems
- **FIXED**: Large file upload reliability

### Version 1.0.0
- Initial release
- Basic audio file upload functionality
- API and FTP upload methods
- Settings panel and media library integration

## Support

For issues, feature requests, or questions:
1. Check the plugin settings page for error logs
2. Review the WordPress debug log for detailed information
3. Ensure Bunny.net credentials are correct and storage zone is active

## Professional Services

**SPARKWEB Studio** provides professional WordPress development services including:
- Custom plugin development and maintenance
- WordPress website optimization and performance tuning
- CDN integration and media management solutions
- Technical support and consultation

Visit [https://sparkwebstudio.com/](https://sparkwebstudio.com/) for more information about our services.

## License

GPL v2 or later 