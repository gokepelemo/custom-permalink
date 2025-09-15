<?php
/**
 * Options Manager Class for Custom Permalink Domain Plugin
 * 
 * Handles all option retrieval and caching with batching optimization
 * to reduce redundant database queries.
 * 
 * @package CustomPermalinkDomain
 * @since 1.3.1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CPD_Options_Manager {
    
    /**
     * Plugin option name
     * @var string
     */
    private $option_name = 'custom_permalink_domain';
    
    /**
     * Plugin slug
     * @var string
     */
    private $plugin_slug = 'custom-permalink-domain';
    
    /**
     * Cache for custom domain
     * @var string|null
     */
    private $custom_domain_cache = null;
    
    /**
     * Cache for content types
     * @var array|null
     */
    private $content_types_cache = null;
    
    /**
     * Cache for network settings
     * @var array|null
     */
    private $network_settings_cache = null;
    
    /**
     * Cache for relative URLs settings
     * @var array|null
     */
    private $relative_urls_cache = null;
    
    /**
     * Batch cache for network options
     * @var array|null
     */
    private $network_options_batch = null;
    
    /**
     * Get custom domain with caching
     * 
     * @return string Custom domain or empty string
     */
    public function get_custom_domain() {
        if ($this->custom_domain_cache === null) {
            $this->custom_domain_cache = get_option($this->option_name, '');
        }
        return $this->custom_domain_cache;
    }
    
    /**
     * Get content types with caching
     * 
     * @return array Content types configuration
     */
    public function get_content_types() {
        if ($this->content_types_cache === null) {
            $this->content_types_cache = get_option($this->option_name . '_types', array(
                'posts' => 1,
                'pages' => 1,
                'categories' => 1,
                'tags' => 1,
                'authors' => 1,
                'attachments' => 1
            ));
        }
        return $this->content_types_cache;
    }
    
    /**
     * Get network settings with optimized batching
     * 
     * @return array Network settings
     */
    public function get_network_settings() {
        if ($this->network_settings_cache === null) {
            if (!is_multisite()) {
                $this->network_settings_cache = array(
                    'enabled' => false,
                    'domain' => '',
                    'override' => false
                );
            } else {
                $batch = $this->get_network_options_batch();
                $this->network_settings_cache = array(
                    'enabled' => $batch['network_enabled'],
                    'domain' => $batch['network_domain'],
                    'override' => $batch['network_override']
                );
            }
        }
        return $this->network_settings_cache;
    }
    
    /**
     * Get relative URLs settings with caching
     * 
     * @return array Relative URLs settings
     */
    public function get_relative_urls_settings() {
        if ($this->relative_urls_cache === null) {
            if (is_multisite()) {
                $batch = $this->get_network_options_batch();
                $network_relative_enabled = $batch['network_relative_enabled'];
                $network_relative_override = $batch['network_relative_override'];
                
                if ($network_relative_override) {
                    $this->relative_urls_cache = array(
                        'enabled' => $network_relative_enabled,
                        'source' => 'network_override'
                    );
                    return $this->relative_urls_cache;
                }
            }
            
            $site_relative_enabled = get_option($this->option_name . '_relative_urls', false);
            $this->relative_urls_cache = array(
                'enabled' => $site_relative_enabled,
                'source' => 'site'
            );
        }
        
        return $this->relative_urls_cache;
    }
    
    /**
     * Get network options in a single batch to reduce database calls
     * 
     * @return array Batch of network options
     */
    private function get_network_options_batch() {
        if ($this->network_options_batch === null && is_multisite()) {
            // Get all network options in a single batch
            $option_names = array(
                'network_enabled',
                'network_domain',
                'network_override',
                'network_relative_enabled',
                'network_relative_override',
                'network_preserve_data'
            );
            
            $this->network_options_batch = array();
            foreach ($option_names as $option) {
                $this->network_options_batch[$option] = get_site_option(
                    $this->plugin_slug . '_' . $option, 
                    false
                );
            }
        }
        
        return $this->network_options_batch ?: array();
    }
    
    /**
     * Update custom domain option
     * 
     * @param string $domain New domain value
     * @return bool Success status
     */
    public function update_custom_domain($domain) {
        $result = update_option($this->option_name, $domain);
        if ($result) {
            $this->custom_domain_cache = $domain;
        }
        return $result;
    }
    
    /**
     * Update content types option
     * 
     * @param array $types Content types configuration
     * @return bool Success status
     */
    public function update_content_types($types) {
        $result = update_option($this->option_name . '_types', $types);
        if ($result) {
            $this->content_types_cache = $types;
        }
        return $result;
    }
    
    /**
     * Update relative URLs option
     * 
     * @param bool $enabled Relative URLs enabled status
     * @return bool Success status
     */
    public function update_relative_urls($enabled) {
        $result = update_option($this->option_name . '_relative_urls', $enabled);
        if ($result) {
            $this->relative_urls_cache = null; // Force recalculation
        }
        return $result;
    }
    
    /**
     * Update multiple network options in batch
     * 
     * @param array $options Array of option_suffix => value pairs
     * @return array Results of update operations
     */
    public function update_network_options_batch($options) {
        $results = array();
        
        if (!is_multisite()) {
            return $results;
        }
        
        foreach ($options as $option_suffix => $value) {
            $option_name = $this->plugin_slug . '_' . $option_suffix;
            $results[$option_suffix] = update_site_option($option_name, $value);
        }
        
        // Clear cached network options to force reload
        $this->network_options_batch = null;
        $this->network_settings_cache = null;
        $this->relative_urls_cache = null;
        
        return $results;
    }
    
    /**
     * Get preserve data setting
     * 
     * @return bool Preserve data setting
     */
    public function get_preserve_data() {
        return get_option($this->option_name . '_preserve_data', false);
    }
    
    /**
     * Update preserve data setting
     * 
     * @param bool $preserve Preserve data flag
     * @return bool Success status
     */
    public function update_preserve_data($preserve) {
        return update_option($this->option_name . '_preserve_data', $preserve);
    }
    
    /**
     * Get network preserve data setting
     * 
     * @return bool Network preserve data setting
     */
    public function get_network_preserve_data() {
        if (!is_multisite()) {
            return false;
        }
        
        $batch = $this->get_network_options_batch();
        return $batch['network_preserve_data'] ?? false;
    }
    
    /**
     * Clear all internal caches
     */
    public function clear_cache() {
        $this->custom_domain_cache = null;
        $this->content_types_cache = null;
        $this->network_settings_cache = null;
        $this->relative_urls_cache = null;
        $this->network_options_batch = null;
    }
    
    /**
     * Get cleanup status for debugging
     * 
     * @return array Status information
     */
    public function get_cleanup_status() {
        $status = array(
            'main_option' => get_option($this->option_name, 'not_found'),
            'types_option' => get_option($this->option_name . '_types', 'not_found'),
            'transients_cleared' => !get_transient('custom_permalink_domain_cache'),
            'is_multisite' => is_multisite()
        );
        
        if (is_multisite()) {
            $batch = $this->get_network_options_batch();
            $status['network_options'] = $batch;
        }
        
        return $status;
    }
    
    /**
     * Get network statistics (for multisite)
     * 
     * @return array Network statistics
     */
    public function get_network_statistics() {
        if (!is_multisite()) {
            return array();
        }
        
        $sites = get_sites(array('number' => 0));
        $total_sites = count($sites);
        $sites_with_custom_domains = 0;
        
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            $custom_domain = get_option($this->option_name);
            if (!empty($custom_domain)) {
                $sites_with_custom_domains++;
            }
            restore_current_blog();
        }
        
        return array(
            'total_sites' => $total_sites,
            'sites_with_custom_domains' => $sites_with_custom_domains,
            'sites_without_custom_domains' => $total_sites - $sites_with_custom_domains
        );
    }
    
    /**
     * Check if network override is active for a specific setting
     * 
     * @param string $setting_type Setting type (domain, relative_urls)
     * @return bool True if network override is active
     */
    public function is_network_override_active($setting_type = 'domain') {
        if (!is_multisite()) {
            return false;
        }
        
        $batch = $this->get_network_options_batch();
        
        if ($setting_type === 'relative_urls') {
            return $batch['network_relative_enabled'] && $batch['network_relative_override'];
        }
        
        return $batch['network_enabled'] && $batch['network_override'];
    }
    
    /**
     * Get option name
     * 
     * @return string Option name
     */
    public function get_option_name() {
        return $this->option_name;
    }
    
    /**
     * Get plugin slug
     * 
     * @return string Plugin slug
     */
    public function get_plugin_slug() {
        return $this->plugin_slug;
    }
}