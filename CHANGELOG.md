# Changelog

All notable changes to the Attachment Lookup Optimizer plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.3] - 2025-01-28

### Major Architecture Improvement
- **Asynchronous Upload System**: Completely redesigned BunnyCDN upload workflow to be asynchronous, eliminating form submission timeouts
- **Background Processing Queue**: Implemented persistent queue system for BunnyCDN uploads with retry logic and exponential backoff
- **Instant Form Responses**: Forms now complete immediately while uploads happen in background, dramatically improving user experience
- **Intelligent Queue Management**: Automatic queue cleanup, duplicate detection, and batch processing with configurable limits
- **Enhanced Reliability**: Multi-attempt retry system with smart failure handling and comprehensive error logging

### Technical Implementation
- Created async upload scheduling system with database persistence
- Added background cron processing for queued uploads (every 30 seconds)
- Implemented queue cleanup system (hourly) to prevent database bloat
- Added comprehensive queue monitoring and status tracking
- Enhanced error handling with exponential backoff retry logic

## [1.1.2] - 2025-01-28

### Performance Improvements
- **Enhanced Upload Timeout Handling**: Implemented adaptive timeout based on file size (30s base + 1s per 100KB, capped at 120s) to prevent timeouts with large files
- **Upload Concurrency Control**: Added throttling mechanism limiting to 3 concurrent BunnyCDN uploads to prevent server overload and resource contention
- **Performance Monitoring**: Added comprehensive upload performance logging with throughput metrics, memory usage tracking, and detailed error categorization
- **Duplicate Processing Fix**: Eliminated duplicate delayed offload scheduling between BunnyCDNManager and UploadPreprocessor to improve efficiency

### Technical Enhancements
- Enhanced error handling with specific timeout detection, SSL/certificate issue identification, and rate limiting detection
- Added memory usage monitoring and warnings for large file uploads (>50MB)
- Improved upload optimization with disabled redirects and compression for faster transfers
- Added automatic upload counter management with proper cleanup on all exit paths

## [1.1.1] - 2025-01-21

### Fixed
- **BunnyCDN Offload PHP Warnings**: Resolved critical PHP warnings (`exif_imagetype()` and `file_get_contents()` "Failed to open stream: No such file or directory") that occurred during BunnyCDN file offloading
- **Delayed File Deletion**: Implemented delayed local file deletion system to prevent WordPress core from accessing deleted files during image processing
- **EXIF Processing Compatibility**: Fixed timing conflict between BunnyCDN offload and WordPress core EXIF data extraction
- **Image Processing Pipeline**: Enhanced compatibility with WordPress image processing workflows

### Enhanced
- **Offload Timing**: Local files now deleted after WordPress completes all image processing operations
- **Error Prevention**: Eliminated WordPress core errors in `functions.php` lines 3338 and 3358
- **Processing Safety**: Added `_alo_pending_offload` metadata to safely track files pending deletion
- **Hook Integration**: Leveraged `wp_generate_attachment_metadata` hook (priority 99) for proper timing

### Technical Improvements
- **Component Coverage**: Applied fix across all BunnyCDN components (UploadPreprocessor, BunnyCDNManager, SyncController, CronSync, Migrator, MediaLibraryEnhancer)
- **Safe Deletion Pipeline**: Replaced immediate `delete_local_files_after_upload()` with `schedule_delayed_file_deletion()`
- **WordPress Core Compatibility**: Ensures WordPress can complete EXIF extraction and thumbnail generation before file removal
- **Error Log Cleanup**: Eliminates recurring PHP warnings from server error logs

## [1.1.0] - 2025-01-20

### Fixed
- **Critical Error Resolution**: Fixed fatal error "Call to undefined function get_current_screen()" that occurred during WordPress initialization
- **MediaLibraryEnhancer Safety**: Added comprehensive safety checks to prevent filter execution during early WordPress initialization
- **Admin Interface Cleanup**: Fixed duplicate HTML ID issues with nonce fields causing 13 DOM validation errors

### Enhanced
- **Admin Interface**: All admin forms now use unique nonce field IDs to comply with HTML standards
- **Initialization Safety**: Improved plugin initialization to prevent conflicts with other plugins during WordPress startup
- **Error Prevention**: Added function_exists() and did_action() checks to prevent premature admin function calls

### Developer
- **Hook Management**: Improved hook registration timing to prevent early execution conflicts
- **Code Quality**: Enhanced error handling and safety checks throughout the codebase
- **HTML Compliance**: Resolved all duplicate ID warnings in admin interface

### Author Update
- **Ownership**: Plugin now maintained by SPARKWEB Studio
- **Website**: Updated all references to https://sparkwebstudio.com

## [1.0.9] - 2024-12-16

### Added
- **JetEngine Custom Field Integration**: Comprehensive support for JetEngine meta fields with URL rewriting
- **JetFormBuilder Support**: Full integration with JetFormBuilder file upload fields
- **Custom Field URL Rewriting**: Automatic replacement of local URLs with BunnyCDN URLs in custom fields
- **Admin Control Setting**: New checkbox to enable/disable custom field rewriting (disabled by default)
- **Field Type Detection**: Smart detection of image, file, gallery, and text fields
- **Nested Field Support**: Recursive processing of repeater fields and complex data structures
- **Attachment ID Resolution**: Support for both URL strings and attachment ID integers
- **Comprehensive Field Patterns**: Support for `field_123456`, `jetform_`, `jfb_`, and custom patterns

### Enhanced
- **BunnyCDN Content Rewriter**: Extended to process JetEngine and JetFormBuilder fields
- **Bulk Processing**: Custom field rewriting integrated into bulk URL replacement workflows
- **Error Handling**: Comprehensive error logging and graceful failure handling
- **Performance**: Optimized field detection and processing algorithms

### Security
- **Safe Processing**: Early return when custom field rewriting is disabled
- **Input Validation**: Comprehensive sanitization of field values and patterns
- **Permission Checks**: Proper capability checks for all admin functions

### Developer Features
- **Extensible Architecture**: Modular design for easy extension to other form builders
- **Debug Logging**: Detailed logging of custom field processing activities
- **Filter Hooks**: Multiple hooks for customizing field detection and processing
- **API Functions**: Helper functions for manual custom field processing

## [1.0.8] - 2024-12-15

### Added
- **BunnyCDN Background Sync Control**: New admin setting to enable/disable automatic background sync
- **Enhanced Admin Interface**: Improved BunnyCDN settings with better organization and descriptions
- **Sync Status Dashboard**: Real-time display of background sync status and statistics
- **Manual Sync Controls**: Admin tools for triggering manual sync operations

### Enhanced
- **Background Processing**: More reliable cron-based sync with configurable intervals
- **Error Recovery**: Improved retry logic for failed uploads
- **Admin UX**: Better visual indicators and status messages
- **Performance Monitoring**: Enhanced tracking of sync operations and success rates

### Fixed
- **Cron Scheduling**: Resolved issues with background sync scheduling
- **Memory Management**: Optimized batch processing to prevent memory exhaustion
- **Error Handling**: Better error reporting and recovery mechanisms

## [1.0.7] - 2024-12-10

### Added
- **BunnyCDN Integration**: Complete CDN integration with automatic uploads
- **Media Library Enhancements**: CDN status columns and retry functionality
- **Migration Tools**: Bulk migration of existing media to BunnyCDN
- **Background Sync**: Automatic retry system for failed uploads
- **Admin Dashboard**: Comprehensive BunnyCDN management interface

### Enhanced
- **Upload Processing**: Automatic CDN upload on media upload
- **URL Management**: Seamless CDN URL serving with fallback support
- **Progress Tracking**: Real-time migration progress with statistics
- **Error Handling**: Comprehensive error logging and retry mechanisms

## [1.0.6] - 2024-12-05

### Added
- **WebP Support**: Full support for WebP and modern image formats
- **AVIF Integration**: Support for next-generation AVIF format
- **HEIC Compatibility**: Apple HEIC format support
- **Format Detection**: Automatic detection of modern image formats

### Enhanced
- **Image Processing**: Optimized handling of various image formats
- **Cache Efficiency**: Improved caching for modern image formats
- **Performance**: Better handling of large image files

## [1.0.5] - 2024-11-30

### Added
- **JetEngine Preloader**: Intelligent preloading for JetEngine listings
- **Lazy Loading**: Advanced lazy loading with above-fold optimization
- **Query Optimization**: JetEngine query performance improvements
- **Debug Tools**: Comprehensive debugging and monitoring tools

### Enhanced
- **Performance Monitoring**: Real-time statistics and performance tracking
- **Cache Management**: Improved cache warming and invalidation
- **Admin Interface**: Enhanced settings page with live statistics

## [1.0.4] - 2024-11-25

### Added
- **Custom Lookup Table**: O(1) attachment URL resolution
- **Reverse Mapping**: File path to attachment ID mapping
- **Batch Processing**: Efficient bulk URL lookups
- **Background Processing**: Automatic table population

### Enhanced
- **Database Performance**: Significant improvement in lookup speed
- **Memory Efficiency**: Optimized memory usage for large datasets
- **Scalability**: Better handling of sites with many attachments

## [1.0.3] - 2024-11-20

### Added
- **Redis Support**: Redis caching backend integration
- **Memcached Support**: Memcached caching backend
- **Cache Backends**: Multiple caching strategy support
- **Performance Monitoring**: Cache hit/miss statistics

### Enhanced
- **Caching Strategy**: Multi-level caching with fallbacks
- **Performance**: Significant speed improvements with external caching
- **Reliability**: Better cache invalidation and consistency

## [1.0.2] - 2024-11-15

### Added
- **Global Override**: Replace WordPress core `attachment_url_to_postid()`
- **Batch Lookups**: Efficient batch URL processing
- **Cache Warming**: Bulk cache population tools
- **Admin Interface**: Comprehensive settings and monitoring page

### Enhanced
- **User Experience**: Intuitive admin interface with real-time statistics
- **Performance**: Optimized cache warming and batch processing
- **Monitoring**: Detailed performance metrics and debugging tools

## [1.0.1] - 2024-11-10

### Added
- **Smart Caching**: Intelligent cache invalidation
- **URL Normalization**: Consistent caching regardless of query parameters
- **Cache Statistics**: Performance monitoring and metrics
- **Debug Logging**: Comprehensive logging system

### Enhanced
- **Cache Efficiency**: Improved cache hit rates
- **Performance**: Faster URL lookups with better caching
- **Reliability**: More robust cache invalidation

### Fixed
- **Cache Consistency**: Resolved cache invalidation edge cases
- **Memory Usage**: Optimized memory consumption
- **Error Handling**: Better error recovery and logging

## [1.0.0] - 2024-11-05

### Added
- **Initial Release**: Core attachment URL lookup optimization
- **Database Indexes**: Composite indexes for `wp_postmeta` table
- **Basic Caching**: WordPress transient-based caching
- **Function Interception**: Hook into `attachment_url_to_postid()`
- **Admin Interface**: Basic settings and status page

### Features
- **Performance Optimization**: Significant improvement in attachment URL lookups
- **Database Enhancement**: Automatic index creation and management
- **Cache Management**: Basic cache warming and clearing tools
- **Monitoring**: Simple performance statistics and status indicators

---

## Version History Summary

- **v1.1.3**: Asynchronous upload system, form timeout elimination, background processing queue
- **v1.1.2**: Performance improvements, upload timeout optimization, concurrency control
- **v1.1.1**: BunnyCDN offload PHP warnings fix, delayed file deletion system
- **v1.1.0**: Critical error fixes, admin interface improvements, SPARKWEB Studio ownership
- **v1.0.9**: JetEngine/JetFormBuilder custom field integration
- **v1.0.8**: BunnyCDN background sync controls and enhanced admin interface
- **v1.0.7**: Complete BunnyCDN integration with migration tools
- **v1.0.6**: WebP and modern image format support
- **v1.0.5**: JetEngine preloader and lazy loading features
- **v1.0.4**: Custom lookup table for O(1) performance
- **v1.0.3**: Redis/Memcached support and advanced caching
- **v1.0.2**: Global override and batch processing
- **v1.0.1**: Smart caching and URL normalization
- **v1.0.0**: Initial release with core optimization features

## Upgrade Notes

### From v1.1.2 to v1.1.3
- **Revolutionary Improvement**: This update completely eliminates form submission timeouts
- **Automatic Activation**: Asynchronous uploads activate automatically with no configuration needed
- **Background Processing**: BunnyCDN uploads now happen in background via WordPress cron
- **Immediate Benefits**: Forms submit instantly while uploads process behind the scenes
- **Enhanced Monitoring**: Check error logs for async upload status and queue processing
- **Zero Downtime**: Fully backward compatible with existing configurations

### From v1.1.1 to v1.1.2
- **Performance Enhancements**: This update improves BunnyCDN upload reliability and performance
- **No Action Required**: All improvements are automatic with backward compatibility
- **Reduced Timeouts**: Adaptive timeout handling should reduce upload failures
- **Better Monitoring**: Enhanced logging provides better insight into upload performance

### From v1.1.0 to v1.1.1
- **PHP Warning Fixes**: This update resolves PHP warnings during BunnyCDN file offloading
- **No Action Required**: All fixes are automatic and fully backward compatible
- **Error Log Cleanup**: Eliminates recurring warnings in WordPress error logs
- **Improved Compatibility**: Enhanced compatibility with WordPress core image processing

### From v1.0.9 to v1.1.0
- **Critical Fixes**: This update resolves fatal errors during WordPress initialization
- **No Action Required**: All fixes are automatic and backward compatible
- **Admin Interface**: Resolved duplicate ID warnings in browser console
- **Improved Stability**: Better compatibility with other plugins during startup

### From v1.0.8 to v1.0.9
- New custom field rewriting feature is **disabled by default**
- Enable via **Tools > Attachment Optimizer > BunnyCDN Integration**
- Check the "Rewrite BunnyCDN URLs in JetEngine/JetFormBuilder fields" option
- Test with a few posts before enabling site-wide

### From v1.0.7 to v1.0.8
- Background sync can now be disabled if not needed
- Check sync settings in admin panel after upgrade
- Review sync statistics for any pending operations

### From v1.0.6 to v1.0.7
- BunnyCDN integration requires API configuration
- Existing attachments can be migrated via admin tools
- Review CDN settings before enabling automatic uploads

## Support

For support, feature requests, or bug reports, please visit:
- **Documentation**: [Plugin README](README.md)
- **WebP Guide**: [WebP Support Documentation](WEBP-SUPPORT.md)
- **Security**: [Security Hardening Guide](SECURITY-HARDENING-SUMMARY.md) 