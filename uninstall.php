<?php
/**
 * Uninstall script for Custom Permalink Domain plugin
 * 
 * This file is executed when the plugin is deleted through WordPress admin
 * It ensures complete cleanup of all plugin data from the database
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up plugin data for a single site
 */
function custom_permalink_domain_cleanup_site() {
    // Remove main plugin options
    delete_option('custom_permalink_domain');
    delete_option('custom_permalink_domain_types');
    
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
    
    // Clean up network-wide options
    delete_site_option('custom_permalink_domain_network_settings');
    delete_site_option('custom_permalink_domain_network_version');
    
    // Remove any network-wide transients
    delete_site_transient('custom_permalink_domain_network_cache');
    
    // Clean up network options table
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
            'custom_permalink_domain_%'
        )
    );
}

// Clear object cache
wp_cache_flush();

// Clear any opcache if available
if (function_exists('opcache_reset')) {
    opcache_reset();
}
?>
