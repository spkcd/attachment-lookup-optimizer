# Release Notes - Version 1.0.9

**Release Date**: December 16, 2024

## 🎉 Major New Feature: JetEngine & JetFormBuilder Integration

Version 1.0.9 introduces comprehensive support for **custom field URL rewriting** in JetEngine and JetFormBuilder, making the plugin even more powerful for sites using these popular form and content builders.

## ✨ What's New

### 🎨 Custom Field URL Rewriting
- **Automatic Processing**: When images are uploaded to BunnyCDN, the plugin now automatically finds and updates custom fields containing those URLs
- **Smart Detection**: Intelligently identifies image fields, file fields, galleries, and complex nested structures
- **Safe by Default**: Feature is disabled by default to prevent unexpected changes - enable via admin settings

### 🔧 Supported Field Types
- **JetEngine Meta Fields**: All standard JetEngine custom fields
- **JetFormBuilder Fields**: Form fields with patterns like `field_123456`, `jetform_`, `jfb_`
- **File Upload Fields**: Image uploads, document attachments, gallery fields
- **Complex Structures**: Repeater fields, nested arrays, grouped field data

### 🛡️ Safety & Control
- **Admin Control**: New checkbox in BunnyCDN settings to enable/disable the feature
- **Comprehensive Logging**: Detailed logs of all custom field processing activities
- **Error Handling**: Graceful failure recovery and input validation
- **Permission Checks**: Proper capability validation for all operations

## 🚀 How It Works

1. **Upload Detection**: Plugin monitors new BunnyCDN uploads
2. **Field Scanning**: Searches all posts for custom fields containing the uploaded image URLs
3. **Smart Replacement**: Replaces local URLs with CDN URLs based on field type
4. **Bulk Integration**: Works with existing bulk URL replacement workflows

## ⚙️ Configuration

### Enable the Feature
1. Go to **WordPress Admin > Tools > Attachment Optimizer**
2. Navigate to the **BunnyCDN Integration** section
3. Check the box: **"Rewrite BunnyCDN URLs in JetEngine/JetFormBuilder fields"**
4. Save settings

### Recommended Testing
- Start with a few test posts to verify functionality
- Monitor the logs for any processing activities
- Enable site-wide once you're confident in the behavior

## 🎯 Use Cases

Perfect for sites using:
- **Real Estate Listings**: Property gallery images in JetEngine
- **Product Catalogs**: Product images in custom fields
- **Portfolio Sites**: Project galleries and media files
- **Event Listings**: Event photos and document attachments
- **Directory Sites**: Business logos and image galleries
- **Form Submissions**: User-uploaded files via JetFormBuilder

## 🔧 Developer Features

### New API Functions
```php
// Check if custom field rewriting is enabled
$enabled = get_option('alo_bunnycdn_rewrite_meta_enabled', false);

// Process custom fields for a specific post
$plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
$content_rewriter = $plugin->get_bunnycdn_content_rewriter();
$replacements = $content_rewriter->process_jetengine_custom_fields($post_id, $attachment_info, $cdn_url);
```

### Field Detection Methods
- `is_jetengine_field()` - Detect JetEngine fields
- `is_jetformbuilder_field()` - Detect JetFormBuilder fields
- `detect_jetformbuilder_field_type()` - Smart field type detection
- `process_jetformbuilder_fields()` - Process all JetFormBuilder fields for a post

## 📊 Performance Impact

- **Zero Overhead**: When disabled, no performance impact whatsoever
- **Efficient Processing**: Optimized algorithms for field detection and processing
- **Smart Caching**: Leverages existing cache infrastructure
- **Batch Operations**: Efficient handling of multiple fields per post

## 🔄 Upgrade Instructions

### Automatic Upgrade
- The feature is **disabled by default** after upgrade
- No immediate action required
- Existing functionality continues to work unchanged

### Manual Activation
1. Update to version 1.0.9
2. Go to **Tools > Attachment Optimizer > BunnyCDN Integration**
3. Enable **"Rewrite BunnyCDN URLs in JetEngine/JetFormBuilder fields"**
4. Test with a few posts before enabling site-wide

## 🐛 Bug Fixes & Improvements

- Enhanced error handling in BunnyCDN content rewriter
- Improved field pattern detection algorithms
- Better logging and debugging capabilities
- Optimized database queries for field processing

## 📚 Documentation

- **Full Documentation**: [README.md](README.md)
- **Changelog**: [CHANGELOG.md](CHANGELOG.md)
- **WebP Support**: [WEBP-SUPPORT.md](WEBP-SUPPORT.md)
- **Security Guide**: [SECURITY-HARDENING-SUMMARY.md](SECURITY-HARDENING-SUMMARY.md)

## 🆘 Support

If you encounter any issues with the new custom field features:

1. **Check the logs** for detailed processing information
2. **Verify settings** in the BunnyCDN Integration section
3. **Test with individual posts** before enabling site-wide
4. **Review field patterns** to ensure they match supported formats

## 🔮 What's Next

Future versions will include:
- Support for additional form builders
- Enhanced field type detection
- Bulk custom field processing tools
- Advanced field mapping configurations

---

**Happy optimizing!** 🚀

The Attachment Lookup Optimizer Team 