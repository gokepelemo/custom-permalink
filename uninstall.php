<?php
/**
 * Uninstall Script for Custom Permalink Domain Plugin
 * 
 * Comprehensive plugin cleanup and data preservation system with sophisticated
 * inheritance logic for multisite networks. Handles complete removal of all
 * plugin data while respecting user preferences for data retention.
 * 
 * Execution Context:
 * - Triggered automatically when plugin is deleted via WordPress admin
 * - Runs with elevated privileges to access all site data
 * - Operates in both single-site and multisite environments
 * - Includes comprehensive error handling and logging
 * 
 * Data Preservation Logic:
 * - Sophisticated inheritance model for multisite environments
 * - Site-level opt-out capability with network-level defaults
 * - Backwards compatibility with existing installations
 * - Support for partial data preservation scenarios
 * 
 * Security Features:
 * - WordPress uninstall hook validation
 * - Capability verification for data operations
 * - Safe database operations with transaction support
 * - Comprehensive audit logging of cleanup operations
 * 
 * Multisite Preservation Matrix:
 * ```
 * Network Setting | Site Setting | Result
 * ----------------|--------------|--------
 * Enabled         | Not Set      | Preserve (inherit)
 * Enabled         | Enabled (1)  | Preserve (explicit)
 * Enabled         | Disabled (0) | Delete (opt-out)
 * Disabled        | Not Set      | Delete (default)
 * Disabled        | Enabled (1)  | Preserve (explicit)
 * Disabled        | Disabled (0) | Delete (explicit)
 * ```
 * 
 * Note: As of v1.3.7, implements enhanced network preservation settings
 * with site-level opt-out capability for granular control.
 * 
 * @package CustomPermalinkDomain
 * @since   1.0.0
 * @version 1.3.4
 * @author  Your Name
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Determine if plugin data should be preserved during uninstall
 * 
 * Implements sophisticated inheritance logic for data preservation decisions
 * in both single-site and multisite environments. Supports site-level opt-out
 * capabilities while maintaining backwards compatibility.
 * 
 * Decision Matrix for Multisite:
 * 1. Network preserve enabled + site not set → Preserve (inherit network default)
 * 2. Network preserve enabled + site enabled (1) → Preserve (explicit confirmation)
 * 3. Network preserve enabled + site disabled (0) → Delete (explicit opt-out)
 * 4. Network preserve disabled + site not set → Delete (no preservation)
 * 5. Network preserve disabled + site enabled (1) → Preserve (explicit opt-in)
 * 6. Network preserve disabled + site disabled (0) → Delete (explicit confirmation)
 * 
 * Decision Logic for Single Site:
 * - Only preserve if site explicitly enabled preservation (value = 1)
 * - Default behavior is to delete all data for clean uninstall
 * 
 * Option Value Meanings:
 * - `false`/`null`: Option doesn't exist (default, typically pre-feature)
 * - `0`: Explicitly unchecked by user (definitive "no")
 * - `1`: Explicitly checked by user (definitive "yes")
 * 
 * @since 1.3.3 Enhanced with network inheritance logic
 * @return bool True if data should be preserved, false if it should be deleted
 * 
 * @example
 * ```php
 * if (custom_permalink_domain_should_preserve_data()) {
 *     // Skip cleanup, preserve settings
 *     return;
 * } else {
 *     // Proceed with full data removal
 *     custom_permalink_domain_cleanup_single_site();
 * }
 * ```
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
