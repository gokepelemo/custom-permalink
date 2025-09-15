<?php
/**
 * URL Transformer Class for Custom Permalink Domain Plugin
 * 
 * Handles all URL transformation logic including domain changes,
 * relative URL conversion, and context-aware filtering.
 * 
 * @package CustomPermalinkDomain
 * @since 1.3.2
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CPD_URL_Transformer {
    
    /**
     * Plugin options manager
     * @var object
     */
    private $options;
    
    /**
     * Cache for custom domain
     * @var string|null
     */
    private $custom_domain_cache = null;
    
    /**
     * Cache for relative URL settings
     * @var array|null
     */
    private $relative_settings_cache = null;
    
    /**
     * Constructor
     * 
     * @param object $options_manager Plugin options manager instance
     */
    public function __construct($options_manager) {
        $this->options = $options_manager;
    }
    
    /**
     * Main URL transformation method
     * 
     * @param string $url URL to transform
     * @param bool $bypass_admin_check Whether to bypass admin context check
     * @return string Transformed URL
     */
    public function transform_url($url, $bypass_admin_check = false) {
        // Don't rewrite URLs in admin context unless bypassed
        if (!$bypass_admin_check && $this->is_admin_context()) {
            return $url;
        }
        
        // Don't rewrite protected URLs
        if ($this->is_protected_url($url)) {
            return $url;
        }
        
        // Apply custom domain transformation
        $url = $this->apply_custom_domain($url);
        
        // Apply relative URL conversion if enabled
        return $this->apply_relative_conversion($url);
    }
    
    /**
     * Transform URL for testing purposes (bypasses admin context check)
     * 
     * @param string $url URL to transform
     * @return string Transformed URL
     */
    public function transform_url_for_testing($url) {
        return $this->transform_url($url, true);
    }
    
    /**
     * Transform URL for indexing contexts (like search engines)
     * 
     * @param string $url URL to transform  
     * @return string Transformed URL
     */
    public function transform_url_for_indexing($url) {
        // Check for network override first (multisite)
        if (is_multisite()) {
            $network_settings = $this->options->get_network_settings();
            
            if ($network_settings['enabled'] && $network_settings['override']) {
                $custom_domain = $network_settings['domain'];
                if (!empty($custom_domain)) {
                    $site_url = get_site_url();
                    $url = str_replace($site_url, $custom_domain, $url);
                }
                return $this->apply_relative_conversion($url);
            }
        }
        
        // Use regular transformation for indexing
        return $this->transform_url($url, true);
    }
    
    /**
     * Apply custom domain to URL
     * 
     * @param string $url URL to transform
     * @return string URL with custom domain applied
     */
    private function apply_custom_domain($url) {
        $custom_domain = $this->get_custom_domain();
        if (empty($custom_domain)) {
            return $url;
        }
        
        $site_url = get_site_url();
        return str_replace($site_url, $custom_domain, $url);
    }
    
    /**
     * Apply relative URL conversion
     * 
     * @param string $url URL to convert
     * @return string Converted URL
     */
    private function apply_relative_conversion($url) {
        $relative_settings = $this->get_relative_settings();
        if (!$relative_settings['enabled']) {
            return $url;
        }
        
        // Convert https://example.com/path to //example.com/path
        return preg_replace('/^https?:\/\//', '//', $url);
    }
    
    /**
     * Check if URL should not be transformed
     * 
     * @param string $url URL to check
     * @return bool True if URL is protected
     */
    private function is_protected_url($url) {
        $protected_patterns = [
            '/wp-admin',
            '/wp-json/',
            'wp-login',
            'wp-register'
        ];
        
        foreach ($protected_patterns as $pattern) {
            if (strpos($url, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if current context is admin
     * 
     * @return bool True if admin context
     */
    private function is_admin_context() {
        return is_admin() || 
               (defined('DOING_AJAX') && DOING_AJAX) || 
               (defined('DOING_CRON') && DOING_CRON) ||
               (defined('REST_REQUEST') && REST_REQUEST) ||
               (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-admin') !== false);
    }
    
    /**
     * Get custom domain with caching
     * 
     * @return string Custom domain or empty string
     */
    private function get_custom_domain() {
        if ($this->custom_domain_cache === null) {
            // Check for network override first (multisite)
            if (is_multisite()) {
                $network_settings = $this->options->get_network_settings();
                
                if ($network_settings['enabled'] && $network_settings['override']) {
                    $this->custom_domain_cache = $network_settings['domain'];
                    return $this->custom_domain_cache;
                }
            }
            
            // Get site-specific custom domain
            $this->custom_domain_cache = $this->options->get_custom_domain();
        }
        
        return $this->custom_domain_cache;
    }
    
    /**
     * Get relative URL settings with caching
     * 
     * @return array Relative URL settings
     */
    private function get_relative_settings() {
        if ($this->relative_settings_cache === null) {
            $this->relative_settings_cache = $this->options->get_relative_urls_settings();
        }
        
        return $this->relative_settings_cache;
    }
    
    /**
     * Clear internal caches
     */
    public function clear_cache() {
        $this->custom_domain_cache = null;
        $this->relative_settings_cache = null;
    }
    
    /**
     * Transform home URL for frontend contexts
     * 
     * @param string $url URL to transform
     * @param string $path URL path
     * @param string $orig_scheme Original scheme
     * @param int $blog_id Blog ID
     * @return string Transformed URL
     */
    public function transform_home_url($url, $path, $orig_scheme, $blog_id) {
        // Only change for frontend requests, not admin
        if ($this->is_admin_context()) {
            return $url;
        }
        
        // Don't change login, register, or wp-admin URLs
        if (strpos($path, 'wp-login') !== false || 
            strpos($path, 'wp-admin') !== false || 
            strpos($path, 'wp-register') !== false) {
            return $url;
        }
        
        return $this->transform_url($url, true);
    }
    
    /**
     * Transform site URL for specific frontend contexts
     * 
     * @param string $url URL to transform
     * @param string $path URL path
     * @param string $scheme URL scheme
     * @param int $blog_id Blog ID
     * @return string Transformed URL
     */
    public function transform_site_url($url, $path, $scheme, $blog_id) {
        // Only change specific paths for frontend
        if ($this->is_admin_context()) {
            return $url;
        }
        
        // Don't rewrite wp-json URLs to prevent CORS issues
        if (strpos($path, 'wp-json/') !== false) {
            return $url;
        }
        
        // Change URLs for feeds, RSS, etc.
        $frontend_paths = array('feed', 'rdf', 'rss', 'rss2', 'atom', 'xmlrpc.php');
        foreach ($frontend_paths as $frontend_path) {
            if (strpos($path, $frontend_path) !== false) {
                return $this->transform_url($url, true);
            }
        }
        
        return $url;
    }
    
    /**
     * Transform REST URL for frontend contexts only
     * 
     * @param string $url REST URL
     * @return string Transformed URL
     */
    public function transform_rest_url($url) {
        // Don't rewrite REST URLs in admin, AJAX, or CRON contexts
        if ($this->is_admin_context()) {
            return $url;
        }
        
        // Don't rewrite if the request is from wp-admin
        if (isset($_SERVER['HTTP_REFERER']) && 
            strpos($_SERVER['HTTP_REFERER'], '/wp-admin') !== false) {
            return $url;
        }
        
        return $this->transform_url($url, true);
    }
    
    /**
     * Transform paginate links
     * 
     * @param string $result Pagination HTML
     * @return string Transformed HTML
     */
    public function transform_paginate_links($result) {
        if (empty($result)) {
            return $result;
        }
        
        $custom_domain = $this->get_custom_domain();
        if (empty($custom_domain)) {
            return $result;
        }
        
        $site_url = get_site_url();
        return str_replace($site_url, $custom_domain, $result);
    }
    
    /**
     * Transform sitemap entries
     * 
     * @param array $sitemap_entry Sitemap entry
     * @param string $object_type Object type
     * @param string $object_subtype Object subtype
     * @return array Transformed sitemap entry
     */
    public function transform_sitemap_entry($sitemap_entry, $object_type, $object_subtype) {
        if (isset($sitemap_entry['loc'])) {
            $sitemap_entry['loc'] = $this->transform_url($sitemap_entry['loc'], true);
        }
        return $sitemap_entry;
    }
    
    /**
     * Transform content URLs within text content
     * 
     * @param string $content HTML content
     * @return string Content with transformed URLs
     */
    public function transform_content_urls($content) {
        $custom_domain = $this->get_custom_domain();
        $relative_settings = $this->get_relative_settings();
        
        if (empty($custom_domain) && !$relative_settings['enabled']) {
            return $content;
        }
        
        if (empty($content)) {
            return $content;
        }
        
        $site_url = get_site_url();
        
        // Replace URLs in href attributes
        if (!empty($custom_domain)) {
            $content = str_replace('href="' . $site_url, 'href="' . $custom_domain, $content);
            $content = str_replace("href='" . $site_url, "href='" . $custom_domain, $content);
        }
        
        // Apply relative URL conversion if enabled
        if ($relative_settings['enabled']) {
            // Convert absolute URLs to protocol-relative in content
            $content = preg_replace('/href=["\']https?:\/\/([^"\']+)["\']/i', 'href="//$1"', $content);
            $content = preg_replace('/src=["\']https?:\/\/([^"\']+)["\']/i', 'src="//$1"', $content);
        }
        
        return $content;
    }
}