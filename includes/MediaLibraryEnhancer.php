<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Media Library Enhancer Class
 * 
 * Enhances the WordPress Media Library with BunnyCDN integration features
 */
class MediaLibraryEnhancer {
    
    /**
     * BunnyCDN Manager instance
     */
    private $bunny_cdn_manager;
    
    /**
     * Constructor
     * 
     * @param BunnyCDNManager $bunny_cdn_manager BunnyCDN manager instance
     */
    public function __construct($bunny_cdn_manager) {
        $this->bunny_cdn_manager = $bunny_cdn_manager;
    }
    
    /**
     * Initialize hooks
     */
    public function init_hooks() {
        // Only add hooks in admin area and for users with proper permissions
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        
        // Add CDN column to media library
        add_filter('manage_upload_columns', [$this, 'add_bunnycdn_column']);
        add_action('manage_media_custom_column', [$this, 'render_bunnycdn_column'], 10, 2);
        
        // Add admin styles and scripts for the CDN column
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Add custom column width styles to admin head
        add_action('admin_head', [$this, 'add_column_width_styles']);
        
        // Add dropdown filter to Media Library
        add_action('restrict_manage_posts', [$this, 'add_offload_status_filter']);
        
        // Handle filtering logic in Media query - only in admin after initialization
        add_action('admin_init', function() {
            add_action('pre_get_posts', [$this, 'filter_attachments_by_offload_status']);
        });
        
        // Register AJAX handler for retry uploads
        add_action('wp_ajax_bunnycdn_retry_upload', [$this, 'ajax_retry_upload']);
    }
    
    /**
     * Add BunnyCDN and Offloaded columns to media library
     * 
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function add_bunnycdn_column($columns) {
        // Check permissions before adding column
        if (!current_user_can('manage_options')) {
            return $columns;
        }
        
        // Insert CDN and Offloaded columns before the date column
        $new_columns = [];
        
        foreach ($columns as $key => $value) {
            if ($key === 'date') {
                $new_columns['bunnycdn'] = __('CDN', 'attachment-lookup-optimizer');
                $new_columns['bunnycdn_offloaded'] = __('Offloaded', 'attachment-lookup-optimizer');
            }
            $new_columns[$key] = $value;
        }
        
        // If date column doesn't exist, add CDN and Offloaded columns at the end
        if (!isset($columns['date'])) {
            $new_columns['bunnycdn'] = __('CDN', 'attachment-lookup-optimizer');
            $new_columns['bunnycdn_offloaded'] = __('Offloaded', 'attachment-lookup-optimizer');
        }
        
        return $new_columns;
    }
    
    /**
     * Render BunnyCDN and Offloaded column content
     * 
     * @param string $column_name Column name
     * @param int $post_id Attachment post ID
     */
    public function render_bunnycdn_column($column_name, $post_id) {
        if ($column_name === 'bunnycdn') {
            $this->render_cdn_column_content($post_id);
        } elseif ($column_name === 'bunnycdn_offloaded') {
            $this->render_offloaded_column_content($post_id);
        }
    }
    
    /**
     * Render CDN column content
     * 
     * @param int $post_id Attachment post ID
     */
    private function render_cdn_column_content($post_id) {
        
        // Check permissions before rendering content
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get CDN URL for this attachment
        $cdn_url = get_post_meta($post_id, '_bunnycdn_url', true);
        $cdn_filename = get_post_meta($post_id, '_bunnycdn_filename', true);
        $migrated_at = get_post_meta($post_id, '_bunnycdn_migrated_at', true);
        
        // Get upload tracking information
        $last_upload_status = get_post_meta($post_id, '_bunnycdn_last_upload_status', true);
        $last_upload_time = get_post_meta($post_id, '_bunnycdn_last_upload_time', true);
        $upload_attempts = get_post_meta($post_id, '_bunnycdn_upload_attempts', true);
        
        if ($cdn_url) {
            // Show success indicator with link to CDN file
            echo '<div class="alo-cdn-status alo-cdn-uploaded">';
            echo '<a href="' . esc_url($cdn_url) . '" target="_blank" title="' . esc_attr__('View on CDN', 'attachment-lookup-optimizer') . '">';
            echo '<span class="alo-cdn-icon">✅</span>';
            echo '<span class="alo-cdn-text">' . esc_html__('CDN', 'attachment-lookup-optimizer') . '</span>';
            echo '</a>';
            
            // Add tooltip with additional info
            $tooltip_parts = [];
            if ($cdn_filename) {
                $tooltip_parts[] = __('Filename:', 'attachment-lookup-optimizer') . ' ' . $cdn_filename;
            }
            if ($migrated_at) {
                $tooltip_parts[] = __('Uploaded:', 'attachment-lookup-optimizer') . ' ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($migrated_at));
            }
            if ($upload_attempts) {
                $tooltip_parts[] = __('Attempts:', 'attachment-lookup-optimizer') . ' ' . $upload_attempts;
            }
            if ($last_upload_status) {
                $tooltip_parts[] = __('Status:', 'attachment-lookup-optimizer') . ' ' . $last_upload_status;
            }
            
            if (!empty($tooltip_parts)) {
                echo '<div class="alo-cdn-tooltip">' . esc_html(implode(' | ', $tooltip_parts)) . '</div>';
            }
            
            echo '</div>';
        } else {
            // Show not uploaded indicator with retry button
            echo '<div class="alo-cdn-status alo-cdn-not-uploaded" id="alo-cdn-status-' . $post_id . '">';
            echo '<span class="alo-cdn-icon">❌</span>';
            echo '<span class="alo-cdn-text">' . esc_html__('Not uploaded', 'attachment-lookup-optimizer') . '</span>';
            
            // Add retry button if BunnyCDN is enabled
            if ($this->is_bunnycdn_enabled()) {
                echo '<button type="button" class="alo-retry-upload-btn" data-attachment-id="' . $post_id . '" title="' . esc_attr__('Retry BunnyCDN Upload', 'attachment-lookup-optimizer') . '">';
                echo '<span class="dashicons dashicons-update-alt"></span>';
                echo '</button>';
            }
            
            // Add tooltip with upload attempt info if available
            $tooltip_parts = [];
            if ($upload_attempts) {
                $tooltip_parts[] = __('Attempts:', 'attachment-lookup-optimizer') . ' ' . $upload_attempts;
            }
            if ($last_upload_status) {
                $tooltip_parts[] = __('Last Status:', 'attachment-lookup-optimizer') . ' ' . $last_upload_status;
            }
            if ($last_upload_time) {
                $tooltip_parts[] = __('Last Attempt:', 'attachment-lookup-optimizer') . ' ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_upload_time));
            }
            
            if (!empty($tooltip_parts)) {
                echo '<div class="alo-cdn-tooltip">' . esc_html(implode(' | ', $tooltip_parts)) . '</div>';
            }
            
            echo '</div>';
        }
    }
    
    /**
     * Render Offloaded column content
     * 
     * @param int $post_id Attachment post ID
     */
    private function render_offloaded_column_content($post_id) {
        // Check permissions before rendering content
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get attachment metadata
        $cdn_url = get_post_meta($post_id, '_bunnycdn_url', true);
        $is_offloaded = get_post_meta($post_id, '_bunnycdn_offloaded', true);
        $offloaded_at = get_post_meta($post_id, '_bunnycdn_offloaded_at', true);
        
        // Logic as specified:
        // 1. If has both _bunnycdn_url and _bunnycdn_offloaded = true, display "✅ Yes"
        // 2. If has _bunnycdn_url but not _bunnycdn_offloaded, display "🕗 Still local"
        // 3. If no _bunnycdn_url, display a dash or leave blank
        
        if ($cdn_url && $is_offloaded) {
            // Has CDN URL and is offloaded - display "✅ Yes"
            $tooltip_text = __('This file has been removed from local disk after successful BunnyCDN upload.', 'attachment-lookup-optimizer');
            if ($offloaded_at) {
                $formatted_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($offloaded_at));
                $tooltip_text .= ' ' . sprintf(__('Offloaded on: %s', 'attachment-lookup-optimizer'), $formatted_date);
            }
            
            echo '<div class="alo-offload-status alo-offload-yes" title="' . esc_attr($tooltip_text) . '">';
            echo '<span class="alo-offload-display">✅ Yes</span>';
            echo '<div class="alo-offload-tooltip">' . esc_html($tooltip_text) . '</div>';
            echo '</div>';
        } elseif ($cdn_url && !$is_offloaded) {
            // Has CDN URL but not offloaded - display "🕗 Still local"
            $tooltip_text = __('File has been uploaded to BunnyCDN but still exists on local disk. Enable "Delete local files after upload" setting to offload files automatically.', 'attachment-lookup-optimizer');
            
            echo '<div class="alo-offload-status alo-offload-still-local" title="' . esc_attr($tooltip_text) . '">';
            echo '<span class="alo-offload-display">🕗 Still local</span>';
            echo '<div class="alo-offload-tooltip">' . esc_html($tooltip_text) . '</div>';
            echo '</div>';
        } else {
            // No CDN URL - display dash
            $tooltip_text = __('File has not been uploaded to BunnyCDN and exists only on local disk.', 'attachment-lookup-optimizer');
            
            echo '<div class="alo-offload-status alo-offload-none" title="' . esc_attr($tooltip_text) . '">';
            echo '<span class="alo-offload-display">—</span>';
            echo '<div class="alo-offload-tooltip">' . esc_html($tooltip_text) . '</div>';
            echo '</div>';
        }
    }
    
    /**
     * Enqueue admin styles and scripts for CDN column
     * 
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        // Only load on media library pages and for users with proper permissions
        if ($hook !== 'upload.php' && $hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Add inline CSS for CDN column styling
        wp_add_inline_style('wp-admin', $this->get_cdn_column_css());
        
        // Add inline JavaScript for retry functionality
        wp_add_inline_script('jquery', $this->get_retry_upload_js());
        
        // Add JavaScript for filter functionality
        wp_add_inline_script('jquery', $this->get_filter_js());
        
        // Localize script with AJAX URL and nonce
        wp_localize_script('jquery', 'aloBunnyCDN', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('alo_bunnycdn_retry_upload'),
            'strings' => [
                'retrying' => __('Retrying...', 'attachment-lookup-optimizer'),
                'success' => __('CDN', 'attachment-lookup-optimizer'),
                'error' => __('Retry failed', 'attachment-lookup-optimizer'),
                'networkError' => __('Network error', 'attachment-lookup-optimizer')
            ]
        ]);
    }
    
    /**
     * Add custom column width styles to admin head
     * Only applies to Media Library list view for better alignment
     */
    public function add_column_width_styles() {
        // Only add styles on upload.php (Media Library) page
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'upload') {
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <style type="text/css">
        /* Media Library Column Width Styling */
        .wp-list-table.media .column-bunnycdn_offloaded {
            width: 100px !important;
            max-width: 100px !important;
            min-width: 100px !important;
            text-align: center;
        }
        
        .wp-list-table.media .column-bunnycdn {
            width: 100px !important;
            max-width: 100px !important;
            min-width: 100px !important;
            text-align: center;
        }
        
        /* Ensure proper alignment in list view */
        .wp-list-table.media .column-bunnycdn_offloaded,
        .wp-list-table.media .column-bunnycdn {
            vertical-align: middle;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Responsive adjustments for smaller screens */
        @media screen and (max-width: 782px) {
            .wp-list-table.media .column-bunnycdn_offloaded,
            .wp-list-table.media .column-bunnycdn {
                width: 80px !important;
                max-width: 80px !important;
                min-width: 80px !important;
            }
        }
        
        /* Ensure content fits within column width */
        .wp-list-table.media .column-bunnycdn_offloaded .alo-offload-status,
        .wp-list-table.media .column-bunnycdn .alo-cdn-status {
            max-width: 100%;
            overflow: visible; /* Allow tooltips to show */
            position: relative;
        }
        
        .wp-list-table.media .column-bunnycdn_offloaded .alo-offload-display {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Enhanced tooltip styling for better visibility */
        .wp-list-table.media .alo-offload-tooltip {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #2c3338;
            color: #fff;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 11px;
            line-height: 1.4;
            white-space: normal;
            width: 250px;
            max-width: 250px;
            text-align: left;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            z-index: 10000;
            margin-bottom: 5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .wp-list-table.media .alo-offload-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-top: 6px solid #2c3338;
        }
        
        .wp-list-table.media .alo-offload-status:hover .alo-offload-tooltip {
            opacity: 1;
        }
        
        /* Ensure tooltips don't get cut off at table edges */
        .wp-list-table.media .column-bunnycdn_offloaded {
            overflow: visible;
        }
        
        /* Offload Status Filter Dropdown Styling */
        #offload-status-filter {
            margin-left: 8px;
            margin-right: 8px;
            min-width: 160px;
            height: 28px;
            line-height: 28px;
            padding: 0 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            background: #fff;
            font-size: 13px;
        }
        
        #offload-status-filter:focus {
            border-color: #0073aa;
            box-shadow: 0 0 0 1px #0073aa;
            outline: none;
        }
        
        /* Responsive adjustments for filter */
        @media screen and (max-width: 782px) {
            #offload-status-filter {
                min-width: 140px;
                font-size: 12px;
                margin: 4px;
            }
        }
        </style>
        <?php
    }
    
    /**
     * Add offload status dropdown filter to Media Library
     * Only shows on attachment list view with proper scope checking
     */
    public function add_offload_status_filter() {
        // Comprehensive scope checking
        if (!is_admin()) {
            return;
        }
        
        // Check current screen context
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'attachment') {
            return;
        }
        
        // Additional check for Media Library page
        if ($screen->id !== 'upload') {
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get current filter value and preserve selection on reload
        $current_filter = isset($_GET['offload_status']) ? sanitize_text_field($_GET['offload_status']) : '';
        
        // Validate filter value to prevent invalid selections
        $valid_filters = ['', 'offloaded', 'still_local', 'not_uploaded'];
        if (!in_array($current_filter, $valid_filters)) {
            $current_filter = '';
        }
        
        // Define filter options
        $filter_options = [
            '' => __('All files', 'attachment-lookup-optimizer'),
            'offloaded' => __('Offloaded to BunnyCDN', 'attachment-lookup-optimizer'),
            'still_local' => __('Still stored locally', 'attachment-lookup-optimizer'),
            'not_uploaded' => __('Not uploaded to BunnyCDN', 'attachment-lookup-optimizer')
        ];
        
        ?>
        <select name="offload_status" id="offload-status-filter">
            <?php foreach ($filter_options as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($current_filter, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }
    
    /**
     * Filter attachments by offload status based on dropdown selection
     * Properly scoped to Media Library only
     * 
     * @param WP_Query $query The WordPress query object
     */
    public function filter_attachments_by_offload_status($query) {
        // Comprehensive scope checking
        if (!is_admin()) {
            return;
        }
        
        // Ensure we're in the proper WordPress admin context - this prevents 
        // the filter from running during early initialization when admin functions aren't loaded
        if (!function_exists('get_current_screen') || !did_action('admin_init')) {
            return;
        }
        
        // Check current screen context
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'attachment') {
            return;
        }
        
        // Additional check for Media Library page
        if ($screen->id !== 'upload') {
            return;
        }
        
        // Only apply to main query to avoid affecting other queries
        if (!$query->is_main_query()) {
            return;
        }
        
        // Ensure we're dealing with attachment queries
        if ($query->get('post_type') !== 'attachment') {
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get filter value and validate
        $offload_status = isset($_GET['offload_status']) ? sanitize_text_field($_GET['offload_status']) : '';
        
        // Validate filter value to prevent invalid queries
        $valid_filters = ['offloaded', 'still_local', 'not_uploaded'];
        if (empty($offload_status) || !in_array($offload_status, $valid_filters)) {
            return; // No valid filter applied
        }
        
        // Get existing meta query or initialize
        $meta_query = $query->get('meta_query') ?: [];
        
        switch ($offload_status) {
            case 'offloaded':
                // Filter where _bunnycdn_offloaded = true
                $meta_query[] = [
                    'key' => '_bunnycdn_offloaded',
                    'value' => '1',
                    'compare' => '='
                ];
                break;
                
            case 'still_local':
                // Filter where _bunnycdn_url exists but _bunnycdn_offloaded does not exist or is not true
                $meta_query['relation'] = 'AND';
                $meta_query[] = [
                    'key' => '_bunnycdn_url',
                    'value' => '',
                    'compare' => '!='
                ];
                $meta_query[] = [
                    'relation' => 'OR',
                    [
                        'key' => '_bunnycdn_offloaded',
                        'compare' => 'NOT EXISTS'
                    ],
                    [
                        'key' => '_bunnycdn_offloaded',
                        'value' => '1',
                        'compare' => '!='
                    ]
                ];
                break;
                
            case 'not_uploaded':
                // Filter where _bunnycdn_url does not exist or is empty
                $meta_query[] = [
                    'relation' => 'OR',
                    [
                        'key' => '_bunnycdn_url',
                        'compare' => 'NOT EXISTS'
                    ],
                    [
                        'key' => '_bunnycdn_url',
                        'value' => '',
                        'compare' => '='
                    ]
                ];
                break;
        }
        
        // Apply the meta query
        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }
    
    /**
     * Get CSS for CDN column styling
     * 
     * @return string CSS styles
     */
    private function get_cdn_column_css() {
        return "
        /* CDN Column Styling */
        .wp-list-table .column-bunnycdn {
            width: 100px;
            text-align: center;
        }
        
        /* Offloaded Column Styling */
        .wp-list-table .column-bunnycdn_offloaded {
            width: 100px;
            text-align: center;
        }
        
        .alo-cdn-status {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            padding: 5px;
        }
        
        .alo-cdn-status a {
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            color: inherit;
        }
        
        .alo-cdn-status a:hover {
            text-decoration: none;
        }
        
        .alo-cdn-icon {
            font-size: 16px;
            line-height: 1;
            margin-bottom: 2px;
        }
        
        .alo-cdn-text {
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .alo-cdn-uploaded .alo-cdn-text {
            color: #00a32a;
        }
        
        .alo-cdn-not-uploaded .alo-cdn-text {
            color: #d63638;
        }
        
        .alo-cdn-uploaded a:hover .alo-cdn-icon {
            transform: scale(1.1);
            transition: transform 0.2s ease;
        }
        
        /* Offloaded Status Styling */
        .alo-offload-status {
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            padding: 5px;
            text-align: center;
        }
        
        .alo-offload-display {
            font-size: 12px;
            font-weight: 500;
            line-height: 1.2;
        }
        
        .alo-offload-yes .alo-offload-display {
            color: #00a32a;
        }
        
        .alo-offload-still-local .alo-offload-display {
            color: #dba617;
        }
        
        .alo-offload-none .alo-offload-display {
            color: #8c8f94;
            font-size: 16px;
        }
        
        .alo-offload-tooltip {
            position: absolute;
            bottom: -25px;
            left: 50%;
            transform: translateX(-50%);
            background: #2c3338;
            color: #fff;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 10px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
            z-index: 1000;
        }
        
        .alo-offload-tooltip::before {
            content: '';
            position: absolute;
            top: -4px;
            left: 50%;
            transform: translateX(-50%);
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-bottom: 4px solid #2c3338;
        }
        
        .alo-offload-status:hover .alo-offload-tooltip {
            opacity: 1;
        }
        
        /* Retry Upload Button */
        .alo-retry-upload-btn {
            background: #0073aa;
            border: none;
            border-radius: 3px;
            color: #fff;
            cursor: pointer;
            font-size: 10px;
            margin-top: 4px;
            padding: 2px 4px;
            transition: background-color 0.2s ease;
        }
        
        .alo-retry-upload-btn:hover {
            background: #005a87;
        }
        
        .alo-retry-upload-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .alo-retry-upload-btn .dashicons {
            font-size: 12px;
            width: 12px;
            height: 12px;
            line-height: 1;
        }
        
        .alo-retry-upload-btn.retrying .dashicons {
            animation: alo-spin 1s linear infinite;
        }
        
        @keyframes alo-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .alo-cdn-tooltip {
            position: absolute;
            bottom: -25px;
            left: 50%;
            transform: translateX(-50%);
            background: #2c3338;
            color: #fff;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 10px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
            z-index: 1000;
        }
        
        .alo-cdn-tooltip::before {
            content: '';
            position: absolute;
            top: -4px;
            left: 50%;
            transform: translateX(-50%);
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-bottom: 4px solid #2c3338;
        }
        
        .alo-cdn-status:hover .alo-cdn-tooltip {
            opacity: 1;
        }
        
        /* Responsive adjustments */
        @media screen and (max-width: 782px) {
            .wp-list-table .column-bunnycdn {
                width: 80px;
            }
            
            .wp-list-table .column-bunnycdn_offloaded {
                width: 80px;
            }
            
            .alo-cdn-text {
                font-size: 10px;
            }
            
            .alo-cdn-icon {
                font-size: 14px;
            }
            
            .alo-offload-display {
                font-size: 11px;
            }
            
            .alo-offload-none .alo-offload-display {
                font-size: 14px;
            }
        }
        
        /* List view specific styling */
        .wp-list-table.media .column-bunnycdn {
            vertical-align: middle;
        }
        
        .wp-list-table.media .column-bunnycdn_offloaded {
            vertical-align: middle;
        }
        
        /* Grid view - hide tooltips to prevent overflow */
        .attachments-browser .alo-cdn-tooltip,
        .attachments-browser .alo-offload-tooltip {
            display: none;
        }
        ";
    }
    
    /**
     * Get JavaScript for retry upload functionality
     * 
     * @return string JavaScript code
     */
    private function get_retry_upload_js() {
        return "
        jQuery(document).ready(function($) {
            // Handle retry upload button clicks
            $(document).on('click', '.alo-retry-upload-btn', function(e) {
                e.preventDefault();
                
                var button = $(this);
                var attachmentId = button.data('attachment-id');
                var statusContainer = $('#alo-cdn-status-' + attachmentId);
                var icon = statusContainer.find('.alo-cdn-icon');
                var text = statusContainer.find('.alo-cdn-text');
                
                // Disable button and show retrying state
                button.prop('disabled', true).addClass('retrying');
                text.text(aloBunnyCDN.strings.retrying);
                
                // Make AJAX request
                $.post(aloBunnyCDN.ajaxUrl, {
                    action: 'bunnycdn_retry_upload',
                    attachment_id: attachmentId,
                    nonce: aloBunnyCDN.nonce
                })
                .done(function(response) {
                    if (response.success) {
                        // Success - update to show uploaded state
                        statusContainer.removeClass('alo-cdn-not-uploaded').addClass('alo-cdn-uploaded');
                        icon.text('✅');
                        text.text(aloBunnyCDN.strings.success);
                        button.remove(); // Remove retry button
                        
                        // Add link to CDN file if URL provided
                        if (response.data.cdn_url) {
                            var link = $('<a></a>')
                                .attr('href', response.data.cdn_url)
                                .attr('target', '_blank')
                                .attr('title', 'View on CDN')
                                .append(icon)
                                .append(text);
                            statusContainer.empty().append(link);
                        }
                        
                        // Show success message briefly
                        if (response.data.message) {
                            var tooltip = $('<div class=\"alo-cdn-tooltip\" style=\"opacity: 1;\">' + response.data.message + '</div>');
                            statusContainer.append(tooltip);
                            setTimeout(function() {
                                tooltip.fadeOut();
                            }, 3000);
                        }
                    } else {
                        // Error - show error state
                        icon.text('❌');
                        text.text(aloBunnyCDN.strings.error);
                        button.prop('disabled', false).removeClass('retrying');
                        
                        // Show error message
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Upload failed';
                        var tooltip = $('<div class=\"alo-cdn-tooltip\" style=\"opacity: 1; background: #d63638;\">' + errorMsg + '</div>');
                        statusContainer.append(tooltip);
                        setTimeout(function() {
                            tooltip.fadeOut();
                        }, 5000);
                    }
                })
                .fail(function() {
                    // Network error
                    icon.text('❌');
                    text.text(aloBunnyCDN.strings.networkError);
                    button.prop('disabled', false).removeClass('retrying');
                    
                    var tooltip = $('<div class=\"alo-cdn-tooltip\" style=\"opacity: 1; background: #d63638;\">' + aloBunnyCDN.strings.networkError + '</div>');
                    statusContainer.append(tooltip);
                    setTimeout(function() {
                        tooltip.fadeOut();
                    }, 5000);
                });
            });
        });
        ";
    }
    
    /**
     * Get JavaScript for filter functionality
     * Ensures proper form submission and parameter preservation
     * 
     * @return string JavaScript code
     */
    private function get_filter_js() {
        return "
        jQuery(document).ready(function($) {
            // Handle offload status filter change
            $('#offload-status-filter').on('change', function() {
                var filterValue = $(this).val();
                var currentUrl = new URL(window.location.href);
                
                // Update or remove the offload_status parameter
                if (filterValue) {
                    currentUrl.searchParams.set('offload_status', filterValue);
                } else {
                    currentUrl.searchParams.delete('offload_status');
                }
                
                // Reset to first page when filtering
                currentUrl.searchParams.delete('paged');
                
                // Navigate to the new URL
                window.location.href = currentUrl.toString();
            });
            
            // Preserve filter selection on page load
            var urlParams = new URLSearchParams(window.location.search);
            var currentFilter = urlParams.get('offload_status');
            if (currentFilter) {
                $('#offload-status-filter').val(currentFilter);
            }
        });
        ";
    }
    
    /**
     * AJAX handler for retry upload
     */
    public function ajax_retry_upload() {
        // Verify nonce and permissions
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'alo_bunnycdn_retry_upload')) {
            wp_send_json_error(['message' => __('Permission denied', 'attachment-lookup-optimizer')]);
        }
        
        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            wp_send_json_error(['message' => __('Invalid attachment ID', 'attachment-lookup-optimizer')]);
        }
        
        // Check if BunnyCDN is enabled
        if (!$this->is_bunnycdn_enabled()) {
            wp_send_json_error(['message' => __('BunnyCDN integration is not enabled', 'attachment-lookup-optimizer')]);
        }
        
        try {
            // Get attachment file path
            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            if (!$attached_file) {
                wp_send_json_error(['message' => __('Attachment file not found', 'attachment-lookup-optimizer')]);
            }
            
            // Build full file path
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['basedir'] . '/' . $attached_file;
            
            if (!file_exists($file_path)) {
                wp_send_json_error(['message' => __('Physical file not found on server', 'attachment-lookup-optimizer')]);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($attached_file, PATHINFO_EXTENSION);
            $file_basename = pathinfo($attached_file, PATHINFO_FILENAME);
            $unique_filename = $file_basename . '_' . $attachment_id . '.' . $file_extension;
            
            // Attempt upload using BunnyCDNManager
            $cdn_url = $this->bunny_cdn_manager->upload_file($file_path, $unique_filename, $attachment_id);
            
            if ($cdn_url) {
                // Store CDN URL and metadata
                update_post_meta($attachment_id, '_bunnycdn_url', $cdn_url);
                update_post_meta($attachment_id, '_bunnycdn_filename', $unique_filename);
                update_post_meta($attachment_id, '_bunnycdn_uploaded_at', current_time('mysql', true));
                
                // Rewrite post content URLs if enabled
                $this->rewrite_post_content_urls($attachment_id, $cdn_url);
                
                // Delete local files if offload is enabled
                if (get_option('alo_bunnycdn_offload_enabled', false)) {
                    $this->delete_local_files_after_upload($attachment_id);
                }
                
                // Log successful retry
                error_log("ALO: Successfully retried BunnyCDN upload for attachment {$attachment_id}: {$cdn_url}");
                
                wp_send_json_success([
                    'message' => __('Upload successful!', 'attachment-lookup-optimizer'),
                    'cdn_url' => $cdn_url,
                    'filename' => $unique_filename
                ]);
            } else {
                // Get the last upload status for more specific error
                $last_status = get_post_meta($attachment_id, '_bunnycdn_last_upload_status', true);
                $error_message = $last_status ? 
                    sprintf(__('Upload failed: %s', 'attachment-lookup-optimizer'), $last_status) :
                    __('Upload failed - check server logs for details', 'attachment-lookup-optimizer');
                
                wp_send_json_error(['message' => $error_message]);
            }
            
        } catch (Exception $e) {
            error_log("ALO: BunnyCDN retry upload exception for attachment {$attachment_id}: " . $e->getMessage());
            wp_send_json_error(['message' => __('Upload failed due to server error', 'attachment-lookup-optimizer')]);
        }
    }
    
    /**
     * Rewrite post content URLs after successful upload
     * 
     * @param int $attachment_id
     * @param string $cdn_url
     */
    private function rewrite_post_content_urls($attachment_id, $cdn_url) {
        try {
            $plugin = \AttachmentLookupOptimizer\Plugin::getInstance();
            if ($plugin) {
                $content_rewriter = $plugin->get_bunnycdn_content_rewriter();
                if ($content_rewriter) {
                    $results = $content_rewriter->rewrite_content_urls($attachment_id, $cdn_url);
                    
                    // Log the rewrite results
                    if (isset($results['enabled']) && !$results['enabled']) {
                        error_log("ALO: Media library enhancer - content URL rewriting is disabled for attachment {$attachment_id}");
                    } elseif (isset($results['updated_posts']) && $results['updated_posts'] > 0) {
                        error_log("ALO: Media library enhancer - content rewriter updated {$results['updated_posts']} posts with {$results['total_replacements']} URL replacements for attachment {$attachment_id}");
                    } elseif (isset($results['message'])) {
                        error_log("ALO: Media library enhancer - content rewriter for attachment {$attachment_id}: {$results['message']}");
                    }
                }
            }
        } catch (Exception $e) {
            error_log("ALO: Media library enhancer - content rewriter error for attachment {$attachment_id}: " . $e->getMessage());
        }
    }
    
    /**
     * Delete local files after successful upload to BunnyCDN
     * 
     * @param int $attachment_id Attachment post ID
     */
    private function delete_local_files_after_upload($attachment_id) {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return;
        }
        
        try {
            // Get the main attachment file
            $local_path = get_attached_file($attachment_id);
            if (!$local_path || !file_exists($local_path)) {
                error_log("ALO: Media library enhancer offload - main file not found for attachment {$attachment_id}: {$local_path}");
                return;
            }
            
            // Delete the main file
            if (@unlink($local_path)) {
                error_log("ALO: Media library enhancer offload - deleted main file for attachment {$attachment_id}: {$local_path}");
            } else {
                error_log("ALO: Media library enhancer offload - failed to delete main file for attachment {$attachment_id}: {$local_path}");
                return; // Don't proceed if main file deletion failed
            }
            
            // Get attachment metadata to find image sizes
            $meta = wp_get_attachment_metadata($attachment_id);
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                $upload_dir = wp_upload_dir();
                $base_dir = $upload_dir['basedir'];
                
                // Get the directory of the main file
                if (!empty($meta['file'])) {
                    $file_dir = dirname($meta['file']);
                    $full_base_dir = $base_dir . '/' . $file_dir;
                } else {
                    $full_base_dir = dirname($local_path);
                }
                
                // Delete each image size variant
                foreach ($meta['sizes'] as $size_name => $size_data) {
                    if (!empty($size_data['file'])) {
                        $size_file_path = $full_base_dir . '/' . $size_data['file'];
                        
                        if (file_exists($size_file_path)) {
                            if (@unlink($size_file_path)) {
                                error_log("ALO: Media library enhancer offload - deleted {$size_name} size for attachment {$attachment_id}: {$size_file_path}");
                            } else {
                                error_log("ALO: Media library enhancer offload - failed to delete {$size_name} size for attachment {$attachment_id}: {$size_file_path}");
                            }
                        }
                    }
                }
            }
            
            // Mark attachment as offloaded
            update_post_meta($attachment_id, '_bunnycdn_offloaded', true);
            update_post_meta($attachment_id, '_bunnycdn_offloaded_at', current_time('mysql', true));
            
            error_log("ALO: Media library enhancer offload - completed for attachment {$attachment_id}");
            
        } catch (Exception $e) {
            error_log("ALO: Media library enhancer offload - exception for attachment {$attachment_id}: " . $e->getMessage());
        }
    }
    
    /**
     * Get CDN status for an attachment
     * 
     * @param int $post_id Attachment post ID
     * @return array CDN status information
     */
    public function get_cdn_status($post_id) {
        // Check permissions before returning CDN information
        if (!current_user_can('manage_options')) {
            return [
                'has_cdn' => false,
                'cdn_url' => '',
                'cdn_filename' => '',
                'migrated_at' => '',
                'formatted_date' => '',
                'last_upload_status' => '',
                'last_upload_time' => '',
                'upload_attempts' => 0,
                'formatted_last_upload_time' => ''
            ];
        }
        
        $cdn_url = get_post_meta($post_id, '_bunnycdn_url', true);
        $cdn_filename = get_post_meta($post_id, '_bunnycdn_filename', true);
        $migrated_at = get_post_meta($post_id, '_bunnycdn_migrated_at', true);
        
        // Get upload tracking information
        $last_upload_status = get_post_meta($post_id, '_bunnycdn_last_upload_status', true);
        $last_upload_time = get_post_meta($post_id, '_bunnycdn_last_upload_time', true);
        $upload_attempts = get_post_meta($post_id, '_bunnycdn_upload_attempts', true);
        
        return [
            'has_cdn' => !empty($cdn_url),
            'cdn_url' => $cdn_url,
            'cdn_filename' => $cdn_filename,
            'migrated_at' => $migrated_at,
            'formatted_date' => $migrated_at ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($migrated_at)) : '',
            'last_upload_status' => $last_upload_status,
            'last_upload_time' => $last_upload_time,
            'upload_attempts' => (int) $upload_attempts,
            'formatted_last_upload_time' => $last_upload_time ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_upload_time)) : ''
        ];
    }
    
    /**
     * Check if BunnyCDN integration is enabled
     * 
     * @return bool True if enabled
     */
    public function is_bunnycdn_enabled() {
        return $this->bunny_cdn_manager && $this->bunny_cdn_manager->is_enabled();
    }
} 