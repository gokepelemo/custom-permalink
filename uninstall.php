<?php
/**
 * Uninstall script for Custom Permalink Domain plugin
 * 
 * This file is executed when the plugin is deleted through WordPress admin
 * It ensures complete cleanup of all plugin data from the database
 * 
 * Note: As of v1.3.3, respects network preservation settings with site-level opt-out capability
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Check if data preservation is enabled
 * 
 * Logic for multisite:
 * 1. If network preserve is enabled, preserve site settings unless site explicitly opted out (unchecked)
 * 2. If network preserve is disabled, only preserve if site explicitly opted in (checked)
 * 
 * Logic for single site:
 * 1. Only preserve if site explicitly opted in (checked)
 * 
 * Option values:
 * - false: option doesn't exist (default for sites before preservation feature)
 * - 0: explicitly unchecked (site opted out)
 * - 1: explicitly checked (site opted in)
 */
function custom_permalink_domain_should_preserve_data() {
    // Get the site-level setting - don't use default to detect if option exists
    $site_preserve = get_option('custom_permalink_domain_preserve_data');
    
    // For multisite, check network-level setting first
    if (is_multisite()) {
        $network_preserve = get_site_option('custom_permalink_domain_network_preserve_data', false);
        
        if ($network_preserve) {
            // Network preservation is enabled
            // Preserve unless site explicitly opted out (set to 0)
            // Values: 1 → preserve, 0 → don't preserve, false/null → preserve (inherit network)
            return $site_preserve !== 0;
        }
    }
    
    // Default behavior: only preserve if site explicitly enabled it (set to 1)
    return $site_preserve == 1;
}

/**
 * Clean up plugin data for a single site
 */
function custom_permalink_domain_cleanup_site() {
    // Check if we should preserve data
    if (custom_permalink_domain_should_preserve_data()) {
        // Only remove non-essential data, keep settings
        delete_transient('custom_permalink_domain_cache');
        delete_transient('custom_permalink_domain_version_check');
        
        // Remove any settings errors that might be stored (these are temporary)
        delete_option('custom_permalink_domain_settings_errors');
        
        // Keep main plugin options:
        // - custom_permalink_domain (main domain setting)
        // - custom_permalink_domain_types (content types)
        // - custom_permalink_domain_relative_urls (relative URLs setting)
        // - custom_permalink_domain_preserve_data (preservation setting itself)
        
        return; // Exit early, preserving settings
    }
    
    // Normal cleanup - remove all plugin data
    // Remove main plugin options
    delete_option('custom_permalink_domain');
    delete_option('custom_permalink_domain_types');
    delete_option('custom_permalink_domain_relative_urls');
    delete_option('custom_permalink_domain_preserve_data');
    
    // Remove any settings errors that might be stored
    delete_option('custom_permalink_domain_settings_errors');
    
    // Remove any transients
    delete_transient('custom_permalink_domain_cache');
    delete_transient('custom_permalink_domain_version_check');
    
    // Remove any user meta
    delete_metadata('user', 0, 'custom_permalink_domain_dismissed_notices', '', true);
    delete_metadata('user', 0, 'custom_permalink_domain_user_settings', '', true);
    
    // Clean up any auto-generated WordPress options that might exist
    global $wpdb;
    
    // Remove any options that start with our plugin prefix
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            'custom_permalink_domain_%'
        )
    );
    
    // Remove any autoload options that might have been created
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'yes'",
            '%custom_permalink_domain%'
        )
    );
}

// Clean up for single site
custom_permalink_domain_cleanup_site();

// For multisite installations, clean up all sites
if (is_multisite()) {
    global $wpdb;
    
    // Check if network-level data preservation is enabled
    $network_preserve = get_site_option('custom_permalink_domain_network_preserve_data', false);
    
    // Get all sites in the network
    $sites = get_sites(array(
        'number' => 0,
        'fields' => 'ids'
    ));
    
    foreach ($sites as $site_id) {
        switch_to_blog($site_id);
        custom_permalink_domain_cleanup_site();
        restore_current_blog();
    }
    
    if (!$network_preserve) {
        // Clean up network-wide options only if preservation is disabled
        delete_site_option('custom_permalink_domain_network_enabled');
        delete_site_option('custom_permalink_domain_network_domain');
        delete_site_option('custom_permalink_domain_network_override');
        delete_site_option('custom_permalink_domain_network_relative_enabled');
        delete_site_option('custom_permalink_domain_network_relative_override');
        delete_site_option('custom_permalink_domain_network_preserve_data');
        
        // Remove legacy options
        delete_site_option('custom_permalink_domain_network_settings');
        delete_site_option('custom_permalink_domain_network_version');
        
        // Clean up network options table completely
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                'custom_permalink_domain_%'
            )
        );
    } else {
        // Preserve network settings but clean up transients
        delete_site_transient('custom_permalink_domain_network_cache');
    }
    
    // Always remove transients (they're temporary anyway)
    delete_site_transient('custom_permalink_domain_network_cache');
}

// Clear object cache
wp_cache_flush();

// Clear any opcache if available
if (function_exists('opcache_reset')) {
    opcache_reset();
}
?>
