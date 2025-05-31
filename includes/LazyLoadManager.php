<?php
namespace AttachmentLookupOptimizer;

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Lazy Load Manager Class
 * 
 * Handles lazy loading integration for images to reduce rendering pressure
 * on image-heavy listings, especially JetEngine galleries and grids
 */
class LazyLoadManager {
    
    /**
     * Whether lazy loading is enabled
     */
    private $lazy_loading_enabled = true;
    
    /**
     * Loading strategy options
     */
    private $loading_strategy = 'lazy'; // 'lazy', 'eager', 'auto'
    
    /**
     * Skip lazy loading for above-the-fold images
     */
    private $above_fold_limit = 3;
    
    /**
     * Statistics tracking
     */
    private $stats = [
        'images_processed' => 0,
        'lazy_applied' => 0,
        'eager_applied' => 0,
        'jetengine_images' => 0,
        'above_fold_skipped' => 0
    ];
    
    /**
     * Image counter for above-the-fold detection
     */
    private $image_counter = 0;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks for lazy loading
     */
    private function init_hooks() {
        // Enable/disable based on admin setting
        $this->lazy_loading_enabled = apply_filters('alo_lazy_loading_enabled', true);
        
        if (!$this->lazy_loading_enabled) {
            return;
        }
        
        // Hook into image output functions
        add_filter('wp_get_attachment_image_attributes', [$this, 'add_lazy_loading_to_attachment'], 10, 3);
        add_filter('the_content', [$this, 'add_lazy_loading_to_content'], 15);
        
        // JetEngine specific hooks
        add_filter('jet-engine/listings/dynamic-image/custom-image', [$this, 'add_lazy_loading_to_jetengine_image'], 10, 3);
        add_action('jet-engine/listing/before-item', [$this, 'reset_image_counter'], 5);
        add_action('jet-engine/listing/after-item', [$this, 'increment_image_counter'], 15);
        
        // Gallery hooks
        add_filter('wp_get_attachment_image', [$this, 'process_gallery_image'], 10, 5);
        
        // Reset counter on new page/request
        add_action('wp', [$this, 'reset_page_counters']);
        
        // Add preload hints for critical images
        add_action('wp_head', [$this, 'add_preload_hints'], 5);
        
        // Intersection Observer script for advanced lazy loading
        add_action('wp_footer', [$this, 'add_intersection_observer_script']);
    }
    
    /**
     * Add lazy loading to attachment images
     */
    public function add_lazy_loading_to_attachment($attr, $attachment, $size) {
        if (!$this->should_apply_lazy_loading()) {
            return $attr;
        }
        
        $this->stats['images_processed']++;
        
        // Check if this is an above-the-fold image
        if ($this->is_above_fold_image()) {
            $attr['loading'] = 'eager';
            $this->stats['eager_applied']++;
            $this->stats['above_fold_skipped']++;
        } else {
            $attr['loading'] = $this->loading_strategy;
            if ($this->loading_strategy === 'lazy') {
                $this->stats['lazy_applied']++;
            }
        }
        
        // Add decoding hint for better performance
        $attr['decoding'] = 'async';
        
        // Add data attributes for advanced lazy loading
        if ($this->loading_strategy === 'lazy' && !$this->is_above_fold_image()) {
            $attr['data-lazy'] = 'true';
            
            // Add intersection observer target class
            $existing_class = $attr['class'] ?? '';
            $attr['class'] = trim($existing_class . ' alo-lazy-image');
        }
        
        return $attr;
    }
    
    /**
     * Add lazy loading to content images
     */
    public function add_lazy_loading_to_content($content) {
        if (!$this->should_apply_lazy_loading() || empty($content)) {
            return $content;
        }
        
        // Skip if content doesn't contain images
        if (strpos($content, '<img') === false) {
            return $content;
        }
        
        return preg_replace_callback(
            '/<img([^>]*)>/i',
            [$this, 'process_content_image'],
            $content
        );
    }
    
    /**
     * Process individual content images
     */
    private function process_content_image($matches) {
        $img_tag = $matches[0];
        $attributes = $matches[1];
        
        $this->stats['images_processed']++;
        
        // Skip if already has loading attribute
        if (strpos($attributes, 'loading=') !== false) {
            return $img_tag;
        }
        
        // Determine loading strategy
        if ($this->is_above_fold_image()) {
            $loading_attr = 'loading="eager"';
            $this->stats['eager_applied']++;
            $this->stats['above_fold_skipped']++;
        } else {
            $loading_attr = 'loading="' . $this->loading_strategy . '"';
            if ($this->loading_strategy === 'lazy') {
                $this->stats['lazy_applied']++;
            }
        }
        
        // Add decoding attribute
        $decoding_attr = 'decoding="async"';
        
        // Build new img tag
        $new_img = '<img' . $attributes . ' ' . $loading_attr . ' ' . $decoding_attr . '>';
        
        return $new_img;
    }
    
    /**
     * Add lazy loading to JetEngine images
     */
    public function add_lazy_loading_to_jetengine_image($image_html, $settings, $render) {
        if (!$this->should_apply_lazy_loading() || empty($image_html)) {
            return $image_html;
        }
        
        $this->stats['jetengine_images']++;
        
        // Process JetEngine image output
        if (strpos($image_html, '<img') !== false) {
            $image_html = preg_replace_callback(
                '/<img([^>]*)>/i',
                [$this, 'process_jetengine_image_tag'],
                $image_html
            );
        }
        
        return $image_html;
    }
    
    /**
     * Process JetEngine image tags
     */
    private function process_jetengine_image_tag($matches) {
        $img_tag = $matches[0];
        $attributes = $matches[1];
        
        $this->stats['images_processed']++;
        
        // Skip if already has loading attribute
        if (strpos($attributes, 'loading=') !== false) {
            return $img_tag;
        }
        
        // JetEngine images are typically in listings, so more likely to benefit from lazy loading
        // But still respect above-the-fold rules
        if ($this->is_above_fold_image()) {
            $loading_attr = 'loading="eager"';
            $this->stats['eager_applied']++;
        } else {
            $loading_attr = 'loading="lazy"';
            $this->stats['lazy_applied']++;
        }
        
        // Add performance attributes
        $decoding_attr = 'decoding="async"';
        $class_attr = $this->add_lazy_class($attributes);
        
        // Build enhanced img tag
        $new_attributes = $attributes . ' ' . $loading_attr . ' ' . $decoding_attr . $class_attr;
        $new_img = '<img' . $new_attributes . '>';
        
        return $new_img;
    }
    
    /**
     * Process gallery images
     */
    public function process_gallery_image($html, $id, $size, $permalink, $icon) {
        if (!$this->should_apply_lazy_loading()) {
            return $html;
        }
        
        // Gallery images are good candidates for lazy loading
        if (strpos($html, '<img') !== false && strpos($html, 'loading=') === false) {
            $this->stats['images_processed']++;
            
            if ($this->is_above_fold_image()) {
                $loading = 'eager';
                $this->stats['eager_applied']++;
            } else {
                $loading = 'lazy';
                $this->stats['lazy_applied']++;
            }
            
            $html = str_replace('<img', '<img loading="' . $loading . '" decoding="async"', $html);
        }
        
        return $html;
    }
    
    /**
     * Add lazy loading class to existing class attribute
     */
    private function add_lazy_class($attributes) {
        if (preg_match('/class=["\']([^"\']*)["\']/', $attributes, $matches)) {
            $existing_classes = $matches[1];
            $new_classes = trim($existing_classes . ' alo-lazy-image');
            return str_replace($matches[0], 'class="' . $new_classes . '"', '');
        } else {
            return ' class="alo-lazy-image"';
        }
    }
    
    /**
     * Check if lazy loading should be applied
     */
    private function should_apply_lazy_loading() {
        // Don't apply in admin
        if (is_admin()) {
            return false;
        }
        
        // Don't apply if user agent doesn't support it
        if ($this->is_unsupported_browser()) {
            return false;
        }
        
        // Allow filtering
        return apply_filters('alo_should_apply_lazy_loading', $this->lazy_loading_enabled);
    }
    
    /**
     * Check if current image is above the fold
     */
    private function is_above_fold_image() {
        return $this->image_counter < $this->above_fold_limit;
    }
    
    /**
     * Check for unsupported browsers
     */
    private function is_unsupported_browser() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Very old browsers that don't support loading="lazy"
        $unsupported_patterns = [
            '/MSIE [6-9]\./',
            '/Chrome\/[1-6][0-9]\./',
            '/Firefox\/[1-6][0-9]\./'
        ];
        
        foreach ($unsupported_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Reset image counter for new listing item
     */
    public function reset_image_counter() {
        // Don't reset completely, just note we're in a new item
        // This helps with maintaining above-the-fold detection across items
    }
    
    /**
     * Increment image counter after processing item
     */
    public function increment_image_counter() {
        $this->image_counter++;
    }
    
    /**
     * Reset counters for new page
     */
    public function reset_page_counters() {
        $this->image_counter = 0;
        $this->stats['images_processed'] = 0;
        $this->stats['lazy_applied'] = 0;
        $this->stats['eager_applied'] = 0;
        $this->stats['jetengine_images'] = 0;
        $this->stats['above_fold_skipped'] = 0;
    }
    
    /**
     * Add preload hints for critical above-the-fold images
     */
    public function add_preload_hints() {
        if (!$this->lazy_loading_enabled) {
            return;
        }
        
        // Add preload for hero images or featured images that are likely above the fold
        $preload_images = apply_filters('alo_preload_images', []);
        
        foreach ($preload_images as $image_url) {
            echo '<link rel="preload" as="image" href="' . esc_url($image_url) . '">' . "\n";
        }
        
        // Add fetchpriority support hint
        echo '<link rel="preload" as="script" href="data:,/* fetchpriority support */">' . "\n";
    }
    
    /**
     * Add Intersection Observer script for advanced lazy loading
     */
    public function add_intersection_observer_script() {
        if (!$this->lazy_loading_enabled) {
            return;
        }
        
        // Only add if we have lazy images on the page
        if ($this->stats['lazy_applied'] === 0) {
            return;
        }
        
        ?>
        <script>
        // ALO Advanced Lazy Loading with Intersection Observer
        document.addEventListener('DOMContentLoaded', function() {
            if ('IntersectionObserver' in window) {
                const lazyImages = document.querySelectorAll('.alo-lazy-image[loading="lazy"]');
                
                if (lazyImages.length === 0) return;
                
                const imageObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            
                            // Add loading class for CSS transitions
                            img.classList.add('alo-lazy-loading');
                            
                            // Handle load completion
                            img.addEventListener('load', function() {
                                img.classList.remove('alo-lazy-loading');
                                img.classList.add('alo-lazy-loaded');
                            }, { once: true });
                            
                            // Handle load errors
                            img.addEventListener('error', function() {
                                img.classList.remove('alo-lazy-loading');
                                img.classList.add('alo-lazy-error');
                            }, { once: true });
                            
                            observer.unobserve(img);
                        }
                    });
                }, {
                    rootMargin: '50px 0px',
                    threshold: 0.01
                });
                
                lazyImages.forEach(img => imageObserver.observe(img));
            }
        });
        </script>
        
        <style>
        /* ALO Lazy Loading CSS */
        .alo-lazy-image {
            transition: opacity 0.3s ease-in-out;
        }
        .alo-lazy-loading {
            opacity: 0.7;
        }
        .alo-lazy-loaded {
            opacity: 1;
        }
        .alo-lazy-error {
            opacity: 0.5;
            filter: grayscale(100%);
        }
        </style>
        <?php
    }
    
    /**
     * Get lazy loading statistics
     */
    public function get_stats() {
        return array_merge($this->stats, [
            'lazy_loading_enabled' => $this->lazy_loading_enabled,
            'loading_strategy' => $this->loading_strategy,
            'above_fold_limit' => $this->above_fold_limit,
            'current_image_counter' => $this->image_counter,
            'lazy_percentage' => $this->stats['images_processed'] > 0 ? 
                round(($this->stats['lazy_applied'] / $this->stats['images_processed']) * 100, 2) : 0
        ]);
    }
    
    /**
     * Set loading strategy
     */
    public function set_loading_strategy($strategy) {
        $allowed_strategies = ['lazy', 'eager', 'auto'];
        if (in_array($strategy, $allowed_strategies)) {
            $this->loading_strategy = $strategy;
        }
    }
    
    /**
     * Set above-the-fold limit
     */
    public function set_above_fold_limit($limit) {
        $this->above_fold_limit = max(0, intval($limit));
    }
    
    /**
     * Enable/disable lazy loading
     */
    public function set_lazy_loading_enabled($enabled) {
        $this->lazy_loading_enabled = (bool) $enabled;
    }
    
    /**
     * Check if lazy loading is enabled
     */
    public function is_lazy_loading_enabled() {
        return $this->lazy_loading_enabled;
    }
} 