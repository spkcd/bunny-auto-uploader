# Changelog

All notable changes to the Bunny Auto Uploader plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2025-01-27

### Changed
- **Author Update**: Plugin now officially maintained by SPARKWEB Studio
- **Professional Support**: Enhanced support and development by SPARKWEB Studio team
- **Branding**: Updated plugin authorship to reflect SPARKWEB Studio's professional WordPress development services

### Added
- **SPARKWEB Studio Branding**: Official authorship and website information
- **Professional Maintenance**: Ongoing support and updates by SPARKWEB Studio
- **Enhanced Documentation**: Improved documentation with professional services information

## [2.0.0] - 2025-06-05

### Major Rewrite - Revolutionary Upload Interception System

This is a complete overhaul of the plugin with advanced upload interception technology that transforms how audio files are handled in WordPress.

### Added
- **Advanced Upload Interception System**: Real-time XMLHttpRequest override technology
- **Smart Audio File Detection**: Automatic detection of audio files by extension and MIME type
- **Zero Local Storage**: Audio files bypass WordPress storage entirely, saving server space
- **Professional UI Messages**: Clean, client-friendly progress indicators and status messages
- **Auto-Save Integration**: Automatic draft saving before page refresh to prevent browser warnings
- **Smart Media Popup Management**: Automatic reopening of media library after upload completion
- **Enhanced Audio Format Support**: Added support for .ogg and .flac audio formats
- **FormData Deep Analysis**: Advanced inspection of upload data for accurate file routing
- **Chunking Prevention**: Optimized handling for large files up to 10GB
- **Overlay Management**: Comprehensive cleanup of modal overlays to prevent UI blocking

### Improved
- **User Experience**: Seamless workflow from upload to file selection
- **Error Handling**: Enhanced error recovery with detailed logging and user feedback
- **Performance**: Direct CDN upload bypasses WordPress processing overhead
- **Browser Compatibility**: Eliminated "unsaved changes" warnings during workflow
- **Upload Reliability**: Robust handling of various upload scenarios and edge cases
- **Media Library Integration**: Smoother registration of CDN files in WordPress
- **Debug Logging**: More comprehensive error tracking and troubleshooting information

### Fixed
- **Modal Overlay Issues**: Resolved blocking overlays that prevented media selection
- **File Duplication**: Eliminated duplicate storage of audio files locally and on CDN
- **Large File Upload Problems**: Improved reliability for files over 100MB
- **Browser Warning Popups**: Completely eliminated "changes may not be saved" warnings
- **Upload Progress Tracking**: Fixed inaccurate progress reporting during uploads
- **Media Library Refresh**: Resolved issues with stale media library cache after uploads
- **Memory Usage**: Optimized memory consumption during large file processing

### Changed
- **Function Names**: Updated all function names from "nuclear" to professional terminology
- **User Messages**: Replaced emoji-heavy technical messages with clean business language
- **Upload Strategy**: Completely redesigned from post-upload processing to real-time interception
- **Workflow Logic**: Streamlined from complex auto-selection to simple save-and-refresh pattern
- **Error Messages**: Professional, user-friendly error reporting suitable for client sites

### Technical Improvements
- **Code Architecture**: Modular design with separation of concerns
- **JavaScript Performance**: Optimized DOM manipulation and event handling
- **PHP Error Handling**: Robust exception handling and graceful degradation
- **WordPress Integration**: Better compliance with WordPress coding standards
- **Security**: Enhanced input validation and sanitization
- **Debugging**: Comprehensive logging system for troubleshooting

### Breaking Changes
- **Settings Migration**: Some legacy settings may need reconfiguration
- **API Changes**: Internal function names changed (affects custom integrations)
- **File Handling**: Audio files are no longer stored locally (may affect existing workflows)

## [1.0.0] - 2024-XX-XX

### Initial Release

#### Added
- Basic audio file upload functionality for .mp3 and .wav files
- Bunny.net API integration for CDN uploads
- Alternative FTP upload method
- WordPress admin settings panel
- Media Library integration with custom column
- Attachment meta box for CDN URL management
- Error logging and retry functionality
- JetEngine integration for dynamic fields

#### Features
- Automatic detection of audio file uploads
- Storage of CDN URLs as attachment metadata
- Admin interface for Bunny.net configuration
- Failed upload retry mechanism
- Basic error handling and logging

---

## Version Numbering

- **Major version** (X.0.0): Significant architectural changes, breaking changes
- **Minor version** (0.X.0): New features, improvements, non-breaking changes  
- **Patch version** (0.0.X): Bug fixes, security updates, minor improvements

## Support

For detailed information about any version:
- Check the plugin settings page for current configuration
- Review WordPress debug logs for technical details
- Ensure Bunny.net credentials and storage zone are properly configured 