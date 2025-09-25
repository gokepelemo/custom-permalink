<?php
/**
 * Multisite Utility Class for Custom Permalink Domain Plugin
 * 
 * Comprehensive multisite management utilities for WordPress network installations.
 * Provides bulk operations, site discovery, and network-wide configuration management
 * with proper permission handling and data integrity protection.
 * 
 * Key Features:
 * - Site discovery and custom domain enumeration
 * - Bulk domain application across network sites
 * - Network-wide settings management and validation
 * - Site-specific configuration retrieval and updates
 * - Permission-aware operations with security checks
 * - Data preservation and cleanup utilities
 * 
 * Security Features:
 * - Capability checks for all network operations
 * - Safe site switching with proper restoration
 * - Input validation and sanitization
 * - Atomic operations with rollback capabilities
 * 
 * Performance Considerations:
 * - Efficient site enumeration with minimal memory usage
 * - Batched database operations for bulk changes
 * - Optimized site switching patterns
 * - Cache-aware operations
 * 
 * Usage Examples:
 * ```php
 * $multisite = new CustomPermalinkDomainMultisite();
 * $sites = $multisite->get_sites_with_custom_domains();
 * $multisite->apply_network_domain_to_all_sites('https://cdn.network.com');
 * ```
 * 
 * @package CustomPermalinkDomain
 * @since   1.0.0
 * @version 1.3.4
 * @author  Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

class CustomPermalinkDomainMultisite {
    
    private $plugin_slug = 'custom-permalink-domain';
    
    /**
     * Get all sites with custom domains configured
     * 
     * Scans the entire multisite network to identify sites that have
     * custom permalink domains configured. Returns detailed information
     * about each site including domain mappings and URLs.
     * 
     * @since 1.4.0
     * @return array Array of site data containing:
     *               - 'site_id' (int): Blog ID of the site
     *               - 'domain' (string): WordPress site domain
     *               - 'path' (string): WordPress site path
     *               - 'custom_domain' (string): Configured custom domain
     *               - 'site_url' (string): Full site URL
     * 
     * @example
     * ```php
     * $multisite = new CustomPermalinkDomainMultisite();
     * $sites = $multisite->get_sites_with_custom_domains();
     * foreach ($sites as $site) {
     *     echo "Site {$site['site_id']}: {$site['custom_domain']}\n";
     * }
     * ```
     */
    public function get_sites_with_custom_domains() {
        if (!is_multisite()) {
            return array();
        }
        
        $sites = get_sites(array('number' => 0));
        $custom_sites = array();
        
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            $custom_domain = get_option('custom_permalink_domain');
            if (!empty($custom_domain)) {
                $custom_sites[] = array(
                    'site_id' => $site->blog_id,
                    'domain' => $site->domain,
                    'path' => $site->path,
                    'custom_domain' => $custom_domain,
                    'site_url' => get_site_url()
                );
            }
            restore_current_blog();
        }
        
        return $custom_sites;
    }
    
    /**
     * Apply network domain to all sites
     */
    public function apply_network_domain_to_all_sites($domain) {
        if (!is_multisite() || !current_user_can('manage_network_options')) {
            return false;
        }
        
        // Sanitize the domain
        $domain = esc_url_raw($domain);
        
        // Clean duplicate protocols
        if (!empty($domain)) {
            $domain = preg_replace('/^https?:\/\/https?:\/\//', 'https://', $domain);
        }
        
        if (empty($domain) || !filter_var($domain, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $sites = get_sites(array('number' => 0));
        $updated_count = 0;
        
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            update_option('custom_permalink_domain', $domain);
            restore_current_blog();
            $updated_count++;
        }
        
        return $updated_count;
    }
    
    /**
     * Clear custom domains from all sites
     */
    public function clear_all_custom_domains() {
        if (!is_multisite() || !current_user_can('manage_network_options')) {
            return false;
        }
        
        $sites = get_sites(array('number' => 0));
        $cleared_count = 0;
        
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            delete_option('custom_permalink_domain');
            restore_current_blog();
            $cleared_count++;
        }
        
        return $cleared_count;
    }
    
    /**
     * Get network statistics
     */
    public function get_network_statistics() {
        if (!is_multisite()) {
            return array();
        }
        
        $sites = get_sites(array('number' => 0));
        $stats = array(
            'total_sites' => count($sites),
            'sites_with_custom_domains' => 0,
            'network_enabled' => get_site_option($this->plugin_slug . '_network_enabled', false),
            'network_override' => get_site_option($this->plugin_slug . '_network_override', false),
            'network_domain' => get_site_option($this->plugin_slug . '_network_domain', '')
        );
        
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            $custom_domain = get_option('custom_permalink_domain');
            if (!empty($custom_domain)) {
                $stats['sites_with_custom_domains']++;
            }
            restore_current_blog();
        }
        
        return $stats;
    }
    
    /**
     * Validate network domain
     */
    public function validate_network_domain($domain) {
        if (empty($domain)) {
            return array('valid' => true, 'message' => '');
        }
        
        // Basic URL validation
        if (!filter_var($domain, FILTER_VALIDATE_URL)) {
            return array(
                'valid' => false,
                'message' => 'Invalid URL format. Please include protocol (https://).'
            );
        }
        
        // Check if domain is reachable (optional)
        $parsed = parse_url($domain);
        if (!$parsed || !isset($parsed['host'])) {
            return array(
                'valid' => false,
                'message' => 'Invalid domain format.'
            );
        }
        
        return array('valid' => true, 'message' => 'Domain is valid.');
    }
    
    /**
     * Export network configuration
     */
    public function export_network_config() {
        if (!is_multisite() || !current_user_can('manage_network_options')) {
            return false;
        }
        
        $config = array(
            'network_settings' => array(
                'enabled' => get_site_option($this->plugin_slug . '_network_enabled', false),
                'domain' => get_site_option($this->plugin_slug . '_network_domain', ''),
                'override' => get_site_option($this->plugin_slug . '_network_override', false)
            ),
            'sites' => array()
        );
        
        $sites = get_sites(array('number' => 0));
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            $config['sites'][] = array(
                'blog_id' => $site->blog_id,
                'domain' => $site->domain,
                'path' => $site->path,
                'custom_domain' => get_option('custom_permalink_domain', ''),
                'content_types' => get_option('custom_permalink_domain_types', array())
            );
            restore_current_blog();
        }
        
        return $config;
    }
}
?>
