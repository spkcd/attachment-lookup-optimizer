<?php

namespace AttachmentLookupOptimizer;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * BunnyCDN Rewritten Posts List Table
 * 
 * Displays posts that have been processed by the content URL rewriting tool
 */
class BunnyCDNRewrittenPostsTable extends \WP_List_Table {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct([
            'singular' => __('Rewritten Post', 'attachment-lookup-optimizer'),
            'plural'   => __('Rewritten Posts', 'attachment-lookup-optimizer'),
            'ajax'     => false
        ]);
    }
    
    /**
     * Get supported post types for content URL rewriting
     * 
     * @return array List of supported post types
     */
    private function get_supported_post_types() {
        // Define default supported post types
        $default_post_types = ['post', 'page'];
        
        // Get all public post types
        $all_post_types = get_post_types(['public' => true], 'names');
        
        // Add common custom post types that typically have content
        $common_custom_types = ['tickets', 'products', 'events', 'portfolio', 'testimonials', 'services'];
        
        $supported_post_types = $default_post_types;
        
        // Add custom post types that exist on this site
        foreach ($common_custom_types as $post_type) {
            if (in_array($post_type, $all_post_types)) {
                $supported_post_types[] = $post_type;
            }
        }
        
        // Allow filtering of supported post types
        $supported_post_types = apply_filters('alo_content_rewrite_post_types', $supported_post_types);
        
        // Ensure we have valid post types
        $supported_post_types = array_intersect($supported_post_types, $all_post_types);
        
        return array_unique($supported_post_types);
    }
    
    /**
     * Get badge color for post type
     * 
     * @param string $post_type
     * @return string Hex color code
     */
    private function get_post_type_badge_color($post_type) {
        $colors = [
            'post'          => '#0073aa',  // WordPress blue
            'page'          => '#00a32a',  // WordPress green
            'tickets'       => '#d63638',  // Red
            'products'      => '#f56e28',  // Orange
            'events'        => '#8b5cf6',  // Purple
            'portfolio'     => '#06b6d4',  // Cyan
            'testimonials'  => '#10b981',  // Emerald
            'services'      => '#f59e0b',  // Amber
        ];
        
        return isset($colors[$post_type]) ? $colors[$post_type] : '#6b7280'; // Default gray
    }
    
    /**
     * Get table columns
     */
    public function get_columns() {
        return [
            'cb'              => '<input type="checkbox" />',
            'post_title'      => __('Post Title', 'attachment-lookup-optimizer'),
            'post_id'         => __('Post ID', 'attachment-lookup-optimizer'),
            'post_type'       => __('Type', 'attachment-lookup-optimizer'),
            'bunnycdn_urls'   => __('BunnyCDN URLs', 'attachment-lookup-optimizer'),
            'rewrite_date'    => __('Date Rewritten', 'attachment-lookup-optimizer'),
            'actions'         => __('Actions', 'attachment-lookup-optimizer')
        ];
    }
    
    /**
     * Get sortable columns
     */
    public function get_sortable_columns() {
        return [
            'post_title'    => ['post_title', false],
            'post_id'       => ['post_id', false],
            'post_type'     => ['post_type', false],
            'bunnycdn_urls' => ['bunnycdn_urls', false],
            'rewrite_date'  => ['rewrite_date', true] // Default sort
        ];
    }
    
    /**
     * Get bulk actions
     */
    public function get_bulk_actions() {
        return [
            'reprocess' => __('Reprocess Selected Posts', 'attachment-lookup-optimizer'),
            'reset'     => __('Reset Rewrite Status', 'attachment-lookup-optimizer')
        ];
    }
    
    /**
     * Prepare table items
     */
    public function prepare_items() {
        global $wpdb;
        
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        
        // Get search term
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        
        // Get post type filter
        $post_type_filter = isset($_REQUEST['post_type_filter']) ? sanitize_text_field($_REQUEST['post_type_filter']) : '';
        
        // Get orderby and order
        $orderby = isset($_REQUEST['orderby']) ? sanitize_text_field($_REQUEST['orderby']) : 'post_type';
        $order = isset($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'ASC';
        
        // Validate orderby
        $allowed_orderby = ['post_title', 'post_id', 'post_type', 'bunnycdn_urls', 'rewrite_date'];
        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'rewrite_date';
        }
        
        // Validate order
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        
        // Get supported post types
        $supported_post_types = $this->get_supported_post_types();
        $post_types_placeholders = implode(',', array_fill(0, count($supported_post_types), '%s'));
        
        // Build query
        $where_conditions = ["pm.meta_key = '_bunnycdn_rewritten'"];
        $join_conditions = [];
        
        // Add search condition
        if (!empty($search)) {
            $where_conditions[] = $wpdb->prepare(
                "(p.post_title LIKE %s OR p.ID = %d)",
                '%' . $wpdb->esc_like($search) . '%',
                intval($search)
            );
        }
        
        // Add post type filter condition
        if (!empty($post_type_filter) && in_array($post_type_filter, $supported_post_types)) {
            $where_conditions[] = $wpdb->prepare("p.post_type = %s", $post_type_filter);
            // Update the post types to query to only the filtered type
            $supported_post_types = [$post_type_filter];
            $post_types_placeholders = '%s';
        }
        
        // Build ORDER BY clause
        $order_clause = '';
        switch ($orderby) {
            case 'post_title':
                $order_clause = "p.post_type ASC, p.post_title $order";
                break;
            case 'post_id':
                $order_clause = "p.post_type ASC, p.ID $order";
                break;
            case 'post_type':
                $order_clause = "p.post_type $order, p.post_title ASC";
                break;
            case 'bunnycdn_urls':
                $order_clause = "p.post_type ASC, bunnycdn_url_count $order";
                break;
            case 'rewrite_date':
                $order_clause = "p.post_type ASC, pm2.meta_value $order";
                break;
            default:
                $order_clause = "p.post_type ASC, pm2.meta_value DESC";
                break;
        }
        
        // Main query to get rewritten posts
        $query = "
            SELECT 
                p.ID,
                p.post_title,
                p.post_type,
                p.post_status,
                p.post_date,
                pm.meta_value as rewritten_attachments,
                pm2.meta_value as rewrite_date,
                COALESCE(pm3.meta_value, 0) as bunnycdn_url_count
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bunnycdn_rewrite_date'
            LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_bunnycdn_rewrite_count'
            WHERE " . implode(' AND ', $where_conditions) . "
            AND p.post_type IN ($post_types_placeholders)
            AND p.post_status = 'publish'
            ORDER BY $order_clause
            LIMIT %d OFFSET %d
        ";
        
        $items = $wpdb->get_results($wpdb->prepare($query, array_merge($supported_post_types, [$per_page, $offset])));
        
        // Get total count for pagination
        $total_query = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE " . implode(' AND ', $where_conditions) . "
            AND p.post_type IN ($post_types_placeholders)
            AND p.post_status = 'publish'
        ";
        
        $total_items = $wpdb->get_var($wpdb->prepare($total_query, $supported_post_types));
        
        // Process items to add additional data
        foreach ($items as &$item) {
            // Parse rewritten attachments (stored as serialized array)
            $rewritten_attachments = maybe_unserialize($item->rewritten_attachments);
            if (is_array($rewritten_attachments)) {
                $item->attachment_count = count($rewritten_attachments);
                $item->attachment_ids = $rewritten_attachments;
            } else {
                $item->attachment_count = 0;
                $item->attachment_ids = [];
            }
            
            // If no rewrite count is stored, calculate from attachments
            if (empty($item->bunnycdn_url_count) && !empty($item->attachment_ids)) {
                $item->bunnycdn_url_count = $item->attachment_count;
                // Update the meta for future use
                update_post_meta($item->ID, '_bunnycdn_rewrite_count', $item->attachment_count);
            }
            
            // Set rewrite date if not set
            if (empty($item->rewrite_date)) {
                $item->rewrite_date = current_time('mysql');
                update_post_meta($item->ID, '_bunnycdn_rewrite_date', $item->rewrite_date);
            }
        }
        
        $this->items = $items;
        
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }
    
    /**
     * Default column display
     */
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'post_id':
                return $item->ID;
            case 'post_type':
                $post_type_obj = get_post_type_object($item->post_type);
                $label = $post_type_obj ? $post_type_obj->labels->singular_name : ucfirst($item->post_type);
                
                // Add a visual indicator/badge for the post type
                $badge_color = $this->get_post_type_badge_color($item->post_type);
                return sprintf(
                    '<span class="post-type-badge" style="background: %s; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">%s</span>',
                    esc_attr($badge_color),
                    esc_html($label)
                );
            case 'bunnycdn_urls':
                $count = !empty($item->bunnycdn_url_count) ? intval($item->bunnycdn_url_count) : $item->attachment_count;
                if ($count > 0) {
                    return sprintf(
                        '<span class="bunnycdn-url-count" title="%s">%d %s</span>',
                        esc_attr(sprintf(__('%d BunnyCDN URLs used in this post', 'attachment-lookup-optimizer'), $count)),
                        $count,
                        _n('URL', 'URLs', $count, 'attachment-lookup-optimizer')
                    );
                }
                return '<span class="bunnycdn-url-count-zero">0 URLs</span>';
            case 'rewrite_date':
                if (!empty($item->rewrite_date)) {
                    $date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item->rewrite_date));
                    return sprintf(
                        '<span title="%s">%s</span>',
                        esc_attr($item->rewrite_date),
                        $date
                    );
                }
                return __('Unknown', 'attachment-lookup-optimizer');
            default:
                return isset($item->$column_name) ? $item->$column_name : '';
        }
    }
    
    /**
     * Post title column with link
     */
    public function column_post_title($item) {
        $edit_link = get_edit_post_link($item->ID);
        $view_link = get_permalink($item->ID);
        
        $title = !empty($item->post_title) ? $item->post_title : __('(no title)', 'attachment-lookup-optimizer');
        
        $actions = [
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($edit_link),
                __('Edit', 'attachment-lookup-optimizer')
            ),
            'view' => sprintf(
                '<a href="%s" target="_blank">%s</a>',
                esc_url($view_link),
                __('View', 'attachment-lookup-optimizer')
            )
        ];
        
        return sprintf(
            '<strong><a href="%s" class="row-title">%s</a></strong>%s',
            esc_url($edit_link),
            esc_html($title),
            $this->row_actions($actions)
        );
    }
    
    /**
     * Actions column
     */
    public function column_actions($item) {
        $nonce = wp_create_nonce('bunnycdn_reprocess_post_' . $item->ID);
        
        $actions = [];
        
        // Reprocess button
        $actions[] = sprintf(
            '<button type="button" class="button button-small bunnycdn-reprocess-post" data-post-id="%d" data-nonce="%s" title="%s">%s</button>',
            $item->ID,
            $nonce,
            esc_attr(__('Reprocess this post to update BunnyCDN URLs', 'attachment-lookup-optimizer')),
            __('Reprocess', 'attachment-lookup-optimizer')
        );
        
        // View details button
        $actions[] = sprintf(
            '<button type="button" class="button button-small bunnycdn-view-details" data-post-id="%d" title="%s">%s</button>',
            $item->ID,
            esc_attr(__('View rewrite details for this post', 'attachment-lookup-optimizer')),
            __('Details', 'attachment-lookup-optimizer')
        );
        
        return implode(' ', $actions);
    }
    
    /**
     * Checkbox column
     */
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="post_ids[]" value="%d" />',
            $item->ID
        );
    }
    
    /**
     * Display when no items found
     */
    public function no_items() {
        echo '<div style="text-align: center; padding: 20px;">';
        echo '<p><strong>' . __('No rewritten posts found.', 'attachment-lookup-optimizer') . '</strong></p>';
        echo '<p>' . __('Posts will appear here after you use the Content URL Rewriting tool to replace local image URLs with BunnyCDN URLs.', 'attachment-lookup-optimizer') . '</p>';
        echo '<p><a href="' . admin_url('tools.php?page=attachment-lookup-optimizer') . '" class="button button-primary">' . __('Go to BunnyCDN Tools', 'attachment-lookup-optimizer') . '</a></p>';
        echo '</div>';
    }
    
    /**
     * Extra table navigation
     */
    public function extra_tablenav($which) {
        if ($which === 'top') {
            echo '<div class="alignleft actions">';
            
            // Post type filter with counts
            $supported_post_types = $this->get_supported_post_types();
            $selected_post_type = isset($_REQUEST['post_type_filter']) ? sanitize_text_field($_REQUEST['post_type_filter']) : '';
            
            // Get post type counts
            global $wpdb;
            $post_type_counts = [];
            foreach ($supported_post_types as $post_type) {
                $count = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(DISTINCT p.ID)
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE pm.meta_key = '_bunnycdn_rewritten'
                    AND p.post_type = %s
                    AND p.post_status = 'publish'
                ", $post_type));
                $post_type_counts[$post_type] = intval($count);
            }
            
            echo '<select name="post_type_filter" id="post-type-filter">';
            echo '<option value="">' . __('All post types', 'attachment-lookup-optimizer') . '</option>';
            foreach ($supported_post_types as $post_type_name) {
                $post_type_obj = get_post_type_object($post_type_name);
                if ($post_type_obj && $post_type_counts[$post_type_name] > 0) {
                    printf(
                        '<option value="%s"%s>%s (%d)</option>',
                        esc_attr($post_type_name),
                        selected($selected_post_type, $post_type_name, false),
                        esc_html($post_type_obj->labels->name),
                        $post_type_counts[$post_type_name]
                    );
                }
            }
            echo '</select>';
            
            submit_button(__('Filter', 'attachment-lookup-optimizer'), 'secondary', 'filter_action', false);
            
            echo '</div>';
            
            // Add post type tabs for quick filtering
            $this->display_post_type_tabs($supported_post_types, $post_type_counts, $selected_post_type);
        }
    }
    
    /**
     * Display post type tabs for quick filtering
     */
    private function display_post_type_tabs($supported_post_types, $post_type_counts, $selected_post_type) {
        $total_count = array_sum($post_type_counts);
        
        if ($total_count === 0) {
            return; // No posts to show tabs for
        }
        
        echo '<div class="subsubsub" style="margin: 10px 0;">';
        
        $tabs = [];
        
        // All posts tab
        $all_class = empty($selected_post_type) ? 'current' : '';
        $all_url = remove_query_arg('post_type_filter');
        $tabs[] = sprintf(
            '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
            esc_url($all_url),
            $all_class,
            __('All', 'attachment-lookup-optimizer'),
            $total_count
        );
        
        // Individual post type tabs
        foreach ($supported_post_types as $post_type) {
            if ($post_type_counts[$post_type] > 0) {
                $post_type_obj = get_post_type_object($post_type);
                if ($post_type_obj) {
                    $class = ($selected_post_type === $post_type) ? 'current' : '';
                    $url = add_query_arg('post_type_filter', $post_type);
                    $badge_color = $this->get_post_type_badge_color($post_type);
                    
                    $tabs[] = sprintf(
                        '<a href="%s" class="%s"><span style="color: %s;">●</span> %s <span class="count">(%d)</span></a>',
                        esc_url($url),
                        $class,
                        esc_attr($badge_color),
                        esc_html($post_type_obj->labels->name),
                        $post_type_counts[$post_type]
                    );
                }
            }
        }
        
        echo implode(' | ', $tabs);
        echo '</div>';
    }
    
    /**
     * Process bulk actions
     */
    public function process_bulk_action() {
        $action = $this->current_action();
        
        if (!$action) {
            return;
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'bulk-' . $this->_args['plural'])) {
            wp_die(__('Security check failed', 'attachment-lookup-optimizer'));
        }
        
        $post_ids = isset($_REQUEST['post_ids']) ? array_map('intval', $_REQUEST['post_ids']) : [];
        
        if (empty($post_ids)) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>' . __('No posts selected.', 'attachment-lookup-optimizer') . '</p></div>';
            });
            return;
        }
        
        switch ($action) {
            case 'reprocess':
                $this->bulk_reprocess_posts($post_ids);
                break;
            case 'reset':
                $this->bulk_reset_posts($post_ids);
                break;
        }
    }
    
    /**
     * Bulk reprocess posts
     */
    private function bulk_reprocess_posts($post_ids) {
        $processed = 0;
        $errors = [];
        
        foreach ($post_ids as $post_id) {
            try {
                // Reset the rewrite status to allow reprocessing
                delete_post_meta($post_id, '_bunnycdn_rewritten');
                delete_post_meta($post_id, '_bunnycdn_rewrite_date');
                delete_post_meta($post_id, '_bunnycdn_rewrite_count');
                
                $processed++;
            } catch (Exception $e) {
                $errors[] = sprintf(__('Post %d: %s', 'attachment-lookup-optimizer'), $post_id, $e->getMessage());
            }
        }
        
        if ($processed > 0) {
            add_action('admin_notices', function() use ($processed) {
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(_n('%d post marked for reprocessing.', '%d posts marked for reprocessing.', $processed, 'attachment-lookup-optimizer'), $processed) . 
                     ' ' . __('Run the Content URL Rewriting tool to reprocess these posts.', 'attachment-lookup-optimizer') . 
                     '</p></div>';
            });
        }
        
        if (!empty($errors)) {
            add_action('admin_notices', function() use ($errors) {
                echo '<div class="notice notice-error"><p>' . __('Some errors occurred:', 'attachment-lookup-optimizer') . '</p><ul>';
                foreach ($errors as $error) {
                    echo '<li>' . esc_html($error) . '</li>';
                }
                echo '</ul></div>';
            });
        }
    }
    
    /**
     * Bulk reset posts
     */
    private function bulk_reset_posts($post_ids) {
        $processed = 0;
        $errors = [];
        
        foreach ($post_ids as $post_id) {
            try {
                delete_post_meta($post_id, '_bunnycdn_rewritten');
                delete_post_meta($post_id, '_bunnycdn_rewrite_date');
                delete_post_meta($post_id, '_bunnycdn_rewrite_count');
                $processed++;
            } catch (Exception $e) {
                $errors[] = sprintf(__('Post %d: %s', 'attachment-lookup-optimizer'), $post_id, $e->getMessage());
            }
        }
        
        if ($processed > 0) {
            add_action('admin_notices', function() use ($processed) {
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(_n('%d post reset successfully.', '%d posts reset successfully.', $processed, 'attachment-lookup-optimizer'), $processed) . 
                     '</p></div>';
            });
        }
        
        if (!empty($errors)) {
            add_action('admin_notices', function() use ($errors) {
                echo '<div class="notice notice-error"><p>' . __('Some errors occurred:', 'attachment-lookup-optimizer') . '</p><ul>';
                foreach ($errors as $error) {
                    echo '<li>' . esc_html($error) . '</li>';
                }
                echo '</ul></div>';
            });
        }
    }
} 