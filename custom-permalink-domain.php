<?php
/*
Plugin Name: Custom Permalink Domain
Plugin URI: https://wordpress.org/plugins/custom-permalink-domain/
Description: Changes permalink domain without affecting site URLs with admin interface. Fully multisite compatible with relative URLs support.
Version: 1.3.8
Author: Goke Pelemo
Author URI: https://gokepelemo.com
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Network: true
Text Domain: custom-permalink-domain
Domain Path: /languages
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!defined('CPD_VERSION')) {
    define('CPD_VERSION', '1.3.8');
}
if (!defined('CPD_PLUGIN_FILE')) {
    define('CPD_PLUGIN_FILE', __FILE__);
}
if (!defined('CPD_PLUGIN_DIR')) {
    define('CPD_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('CPD_PLUGIN_URL')) {
    define('CPD_PLUGIN_URL', plugin_dir_url(__FILE__));
}

// Include required classes
require_once plugin_dir_path(__FILE__) . 'includes/class-cpd-options-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cpd-cache-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cpd-url-transformer.php';

// Include multisite utilities
require_once plugin_dir_path(__FILE__) . 'multisite-utils.php';

/**
 * Main plugin class for Custom Permalink Domain
 * 
 * Manages custom permalink domain functionality for WordPress sites and multisite networks.
 * Provides comprehensive URL transformation with support for:
 * - Custom domain substitution for all content types
 * - Protocol-relative URL conversion
 * - Network-level override capabilities for multisite
 * - Caching and performance optimizations
 * - SEO plugin integration (Yoast, RankMath, SEO Framework)
 * - WordPress core filter integration
 * - Advanced admin interfaces with bulk operations
 * 
 * Architecture:
 * - Decomposed functionality into specialized components (Options, Cache, URL Transformer)
 * - Extensive caching to minimize database calls
 * - Context-aware filter registration (admin vs frontend)
 * - Sophisticated inheritance model for multisite environments
 * 
 * Performance Enhancements in v1.3.4:
 * - Consolidated database calls using caching properties
 * - Enhanced admin context checking with static caching
 * - Reduced redundant get_site_option() calls in admin pages
 * - Optimized network settings retrieval
 * - Improved JavaScript compression and error handling
 * - Better memory usage in admin script loading
 * - Consolidated network settings validation
 * 
 * @package CustomPermalinkDomain
 * @version 1.3.4
 * @author  Your Name
 * @since   1.0.0
 */
class CustomPermalinkDomain {
    
    /**
     * Options manager instance
     * @var CPD_Options_Manager
     */
    private $options;
    
    /**
     * Cache manager instance
     * @var CPD_Cache_Manager
     */
    private $cache_manager;
    
    /**
     * URL transformer instance
     * @var CPD_URL_Transformer
     */
    private $url_transformer;
    
    /**
     * Plugin slug
     * @var string
     */
    private $plugin_slug = 'custom-permalink-domain';
    
    /**
     * Option name for individual site settings
     * @var string
     */
    private $option_name = 'custom_permalink_domain';
    
    /**
     * Network admin flag
     * @var bool
     */
    private $is_network_admin = false;
    
    /**
     * Content types cache
     * @var array|null
     */
    private $content_types_cache = null;
    
    /**
     * Custom domain cache
     * @var string|null
     */
    private $custom_domain_cache = null;
    
    /**
     * Network settings cache
     * @var array|null
     */
    private $network_settings_cache = null;
    
    /**
     * Relative URLs cache
     * @var array|null
     */
    private $relative_urls_cache = null;
    
    /**
     * Initialize the plugin
     * 
     * Sets up component dependencies, hooks into WordPress actions,
     * and configures admin and frontend functionality based on environment.
     * 
     * @since 1.0.0
     */
    public function __construct() {
        // Initialize components
        $this->options = new CPD_Options_Manager();
        $this->cache_manager = new CPD_Cache_Manager();
        $this->url_transformer = new CPD_URL_Transformer($this->options);
        
        // Check if we're in network admin
        $this->is_network_admin = is_multisite() && is_network_admin();
        
        // Hook into WordPress
        add_action('init', array($this, 'init'));
        
        // Add admin menus for both single site and network
        if (is_multisite()) {
            add_action('network_admin_menu', array($this, 'add_network_admin_menu'));
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('network_admin_edit_' . $this->plugin_slug, array($this, 'handle_network_admin_save'));
        } else {
            add_action('admin_menu', array($this, 'add_admin_menu'));
        }
        
        add_action('admin_init', array($this, 'admin_init'), 5); // Run early to ensure settings are registered first
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('network_admin_notices', array($this, 'network_admin_notices'));
        add_action('wp_ajax_cpd_test_urls', array($this, 'ajax_test_urls'));
        
        // Only register frontend filters if we have a custom domain and we're not in admin
        $this->register_frontend_filters();
        
        // Register permalink filters based on configuration
        $this->register_permalink_filters();
    }
    
    /**
     * Register frontend-only filters for SEO and meta tags
     * 
     * Conditionally registers URL transformation filters for frontend pages only.
     * Includes support for major SEO plugins (Yoast, RankMath, SEO Framework)
     * and WordPress core URL functions.
     * 
     * @since 1.0.0
     * @return void
     */
    private function register_frontend_filters() {
        // Only add frontend filters if not in admin and we have a custom domain
        if (!is_admin() && $this->get_custom_domain()) {
            add_action('wp_head', array($this, 'output_custom_canonical'), 1);
            add_filter('wpseo_canonical', array($this->url_transformer, 'transform_url')); // Yoast SEO
            add_filter('wpseo_opengraph_url', array($this->url_transformer, 'transform_url')); // Yoast OG
            add_filter('wpseo_twitter_card_image', array($this->url_transformer, 'transform_url')); // Yoast Twitter
            add_filter('wpseo_schema_graph', array($this, 'transform_yoast_schema_urls')); // Yoast JSON-LD structured data
            add_filter('the_seo_framework_canonical_url', array($this->url_transformer, 'transform_url')); // SEO Framework
            add_filter('rank_math/frontend/canonical', array($this->url_transformer, 'transform_url')); // RankMath
            
            // WordPress core meta and link filters
            add_filter('get_shortlink', array($this->url_transformer, 'transform_url'));
            add_filter('post_comments_feed_link', array($this->url_transformer, 'transform_url'));
            
            // Filter URLs in content that might be generated
            add_filter('the_content', array($this->url_transformer, 'transform_content_urls'), 999);
            add_filter('widget_text', array($this->url_transformer, 'transform_content_urls'), 999);
        }
    }
    
    /**
     * Register permalink filters based on configuration
     * 
     * Conditionally registers URL transformation filters for different content types
     * based on the plugin's content type settings. Includes posts, pages, categories,
     * tags, authors, attachments, and various WordPress link types.
     * 
     * @since 1.0.0
     * @return void
     */
    private function register_permalink_filters() {
        $custom_domain = $this->get_custom_domain();
        if (empty($custom_domain)) {
            return;
        }
        
        $content_types = $this->get_content_types();
        
        // Register content type specific filters
        if (!empty($content_types['posts'])) {
            add_filter('post_link', array($this->url_transformer, 'transform_url'));
        }
        if (!empty($content_types['pages'])) {
            add_filter('page_link', array($this->url_transformer, 'transform_url'));
        }
        if (!empty($content_types['categories'])) {
            add_filter('category_link', array($this->url_transformer, 'transform_url'));
        }
        if (!empty($content_types['tags'])) {
            add_filter('tag_link', array($this->url_transformer, 'transform_url'));
        }
        if (!empty($content_types['authors'])) {
            add_filter('author_link', array($this->url_transformer, 'transform_url'));
        }
        if (!empty($content_types['attachments'])) {
            add_filter('attachment_link', array($this->url_transformer, 'transform_url'));
        }
        
        // Register comprehensive URL filters
        $this->register_comprehensive_filters();
        
        // Register Algolia filters (fixed duplicates)
        $this->register_algolia_filters();
    }
    
    /**
     * Register comprehensive URL filters
     */
    private function register_comprehensive_filters() {
        // Add comprehensive URL filtering for headers, feeds, and other outputs
        // Note: home_url and site_url filters have admin context checks to prevent
        // interference with wp-admin and wp-json requests (WP GraphQL compatibility)
        add_filter('home_url', array($this->url_transformer, 'transform_home_url'), 10, 4);
        add_filter('site_url', array($this->url_transformer, 'transform_site_url'), 10, 4);
        
        // RSS/Atom feeds
        add_filter('feed_link', array($this->url_transformer, 'transform_url'));
        add_filter('category_feed_link', array($this->url_transformer, 'transform_url'));
        add_filter('author_feed_link', array($this->url_transformer, 'transform_url'));
        add_filter('tag_feed_link', array($this->url_transformer, 'transform_url'));
        add_filter('search_feed_link', array($this->url_transformer, 'transform_url'));
        
        // Archives and other special pages
        add_filter('year_link', array($this->url_transformer, 'transform_url'));
        add_filter('month_link', array($this->url_transformer, 'transform_url'));
        add_filter('day_link', array($this->url_transformer, 'transform_url'));
        
        // REST API - special handling to avoid CORS issues with WP GraphQL and admin contexts
        // Uses dedicated function with admin context checks
        add_filter('rest_url', array($this->url_transformer, 'transform_rest_url'));

        // REST API response data filtering for posts and other content types
        add_filter('rest_prepare_post', array($this, 'transform_rest_post_data'), 10, 3);
        add_filter('rest_prepare_page', array($this, 'transform_rest_post_data'), 10, 3);
        add_filter('rest_prepare_attachment', array($this, 'transform_rest_post_data'), 10, 3);

        // Add filters for any custom post types that might be registered
        add_action('rest_api_init', array($this, 'register_rest_filters_for_custom_post_types'));
        
        // Comments
        add_filter('get_comments_link', array($this->url_transformer, 'transform_url'));
        
        // Search
        add_filter('search_link', array($this->url_transformer, 'transform_url'));
        
        // Canonical and meta URLs (for headers)
        add_filter('get_canonical_url', array($this->url_transformer, 'transform_url'));
        add_filter('wp_get_canonical_url', array($this->url_transformer, 'transform_url'));
        
        // Pagination links
        add_filter('paginate_links', array($this->url_transformer, 'transform_paginate_links'));
        
        // WordPress.org specific filters for sitemaps
        add_filter('wp_sitemaps_posts_entry', array($this->url_transformer, 'transform_sitemap_entry'), 10, 3);
        add_filter('wp_sitemaps_taxonomies_entry', array($this->url_transformer, 'transform_sitemap_entry'), 10, 3);
        add_filter('wp_sitemaps_users_entry', array($this->url_transformer, 'transform_sitemap_entry'), 10, 3);
    }
    
    /**
     * Register Algolia Search plugin integration filters (fixed duplicates)
     */
    private function register_algolia_filters() {
        // Algolia Search plugin integration - ensure custom permalinks are indexed correctly
        // These filters run during indexing operations to provide correct URLs to search engines
        add_filter('algolia_post_shared_attributes', array($this->url_transformer, 'transform_algolia_permalink'), 10, 2);
        add_filter('algolia_searchable_post_shared_attributes', array($this->url_transformer, 'transform_algolia_permalink'), 10, 2);
        add_filter('algolia_term_record', array($this->url_transformer, 'transform_algolia_term_permalink'), 10, 2);
    }
    
    /**
     * Initialize plugin after WordPress is fully loaded
     * 
     * Handles late initialization tasks that require WordPress core to be ready,
     * including loading translation files and setting up locale-specific functionality.
     * 
     * @since 1.0.0
     * @hook init
     * @return void
     */
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('custom-permalink-domain', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Get the custom domain setting with caching
     * 
     * Retrieves the custom domain from either network settings (if override is enabled)
     * or individual site settings. Results are cached to reduce database calls.
     * In multisite environments, network settings take precedence when override is enabled.
     * 
     * @since 1.0.0
     * @return string The custom domain URL or empty string if not set
     */
    private function get_custom_domain() {
        if ($this->custom_domain_cache !== null) {
            return $this->custom_domain_cache;
        }
        
        // Check for network override first (multisite)
        if (is_multisite()) {
            $network_settings = $this->get_network_settings();
            if ($network_settings['enabled'] && $network_settings['override']) {
                $this->custom_domain_cache = $network_settings['domain'];
                return $this->custom_domain_cache;
            }
        }
        
        // Fall back to individual site setting
        $this->custom_domain_cache = get_option($this->option_name, '');
        return $this->custom_domain_cache;
    }
    
    /**
     * Get content types configuration with caching
     * 
     * Retrieves which content types (posts, pages, categories, etc.) should have
     * their URLs transformed. Results are cached to improve performance.
     * Defaults to enabling all supported content types if no configuration exists.
     * 
     * @since 1.0.0
     * @return array Associative array of content type enablement flags
     *               Format: ['posts' => 1, 'pages' => 1, 'categories' => 1, ...]
     */
    private function get_content_types() {
        if ($this->content_types_cache !== null) {
            return $this->content_types_cache;
        }
        
        $this->content_types_cache = get_option($this->option_name . '_types', array(
            'posts' => 1,
            'pages' => 1,
            'categories' => 1,
            'tags' => 1,
            'authors' => 1,
            'attachments' => 1
        ));
        
        return $this->content_types_cache;
    }
    
    /**
     * Get network-level settings with caching
     * 
     * Retrieves network-wide configuration settings for multisite installations.
     * Returns default disabled state for single-site installations.
     * Results are cached to reduce database queries on network options.
     * 
     * @since 1.0.0
     * @return array Network settings containing:
     *               - 'enabled' (bool): Whether network-level domain is enabled
     *               - 'domain' (string): Network-level custom domain
     *               - 'override' (bool): Whether to override individual site settings
     */
    private function get_network_settings() {
        if (!is_multisite()) {
            return array('enabled' => false, 'domain' => '', 'override' => false);
        }
        
        if ($this->network_settings_cache !== null) {
            return $this->network_settings_cache;
        }
        
        $this->network_settings_cache = array(
            'enabled' => get_site_option($this->plugin_slug . '_network_enabled', false),
            'domain' => get_site_option($this->plugin_slug . '_network_domain', ''),
            'override' => get_site_option($this->plugin_slug . '_network_override', false)
        );
        
        return $this->network_settings_cache;
    }
    
    /**
     * Get relative URLs settings with caching
     * 
     * Retrieves relative URL configuration, checking network overrides first in multisite
     * environments, then falling back to individual site settings.
     * Results are cached to improve performance and include source information.
     * 
     * @since 1.0.0
     * @return array Relative URLs settings containing:
     *               - 'enabled' (bool): Whether relative URLs are enabled
     *               - 'source' (string): Source of setting ('network_override' or 'site')
     */
    private function get_relative_urls_settings() {
        if ($this->relative_urls_cache !== null) {
            return $this->relative_urls_cache;
        }
        
        // Check for network override first (multisite)
        if (is_multisite()) {
            $network_relative_enabled = get_site_option($this->plugin_slug . '_network_relative_enabled', false);
            $network_relative_override = get_site_option($this->plugin_slug . '_network_relative_override', false);
            
            if ($network_relative_enabled && $network_relative_override) {
                $this->relative_urls_cache = array(
                    'enabled' => true,
                    'source' => 'network_override'
                );
                return $this->relative_urls_cache;
            }
        }
        
        // Fall back to individual site setting
        $site_relative_enabled = get_option($this->option_name . '_relative_urls', false);
        $this->relative_urls_cache = array(
            'enabled' => $site_relative_enabled,
            'source' => 'site'
        );
        
        return $this->relative_urls_cache;
    }
    
    /**
     * Check if current request is in an admin context
     * 
     * Determines if the current request is happening in WordPress admin, AJAX,
     * cron, or admin-initiated REST API calls. Uses static caching to avoid
     * repeated calculations during a single request.
     * 
     * @since 1.0.0
     * @return bool True if in admin context, false otherwise
     */
    private function is_admin_context() {
        static $is_admin_context = null;
        
        if ($is_admin_context === null) {
            $is_admin_context = is_admin() || 
                               (defined('DOING_AJAX') && DOING_AJAX) || 
                               (defined('DOING_CRON') && DOING_CRON) ||
                               (defined('REST_REQUEST') && REST_REQUEST && 
                                isset($_SERVER['HTTP_REFERER']) && 
                                strpos($_SERVER['HTTP_REFERER'], '/wp-admin') !== false);
        }
        
        return $is_admin_context;
    }
    
    /**
     * Clear all internal caches when options are updated
     * 
     * Resets all cached configuration values and purges external caches
     * to ensure fresh data is loaded after settings changes.
     * Should be called whenever plugin options are modified.
     * 
     * @since 1.0.0
     * @return void
     */
    public function clear_cache() {
        $this->custom_domain_cache = null;
        $this->content_types_cache = null;
        $this->network_settings_cache = null;
        $this->relative_urls_cache = null;
        
        // Also purge all external caches when settings change
        $this->cache_manager->purge_all_caches();
    }
    
    /**
     * Convert absolute URL to protocol-relative URL
     * 
     * Converts HTTP/HTTPS URLs to protocol-relative format (//domain.com/path)
     * if relative URLs are enabled in settings. This helps avoid mixed content
     * warnings when switching between HTTP and HTTPS.
     * 
     * @since 1.0.0
     * @param string $url The absolute URL to convert
     * @return string The protocol-relative URL or original URL if conversion disabled
     */
    private function make_url_relative($url) {
        if (empty($url)) {
            return $url;
        }
        
        // Check if relative URLs are enabled
        $relative_settings = $this->get_relative_urls_settings();
        if (!$relative_settings['enabled']) {
            return $url;
        }
        
        // Convert https://example.com/path to //example.com/path
        // Convert http://example.com/path to //example.com/path
        $url = preg_replace('/^https?:\/\//', '//', $url);
        
        return $url;
    }
    
    /**
     * Apply relative URL conversion to a URL after domain transformation
     * 
     * Performs a two-step URL transformation: first applies custom domain
     * transformation, then converts to protocol-relative format if enabled.
     * This is the main method for transforming URLs throughout the site.
     * 
     * @since 1.0.0
     * @param string $url The original URL to transform
     * @return string The transformed URL (domain changed and optionally made relative)
     */
    private function apply_relative_url_conversion($url) {
        // First apply custom domain change if needed
        $url = $this->url_transformer->transform_url($url);
        
        // Then make it relative if enabled
        return $this->make_url_relative($url);
    }
    
    
    /**
     * Display admin notices for plugin status
     * 
     * Shows contextual notices on the plugin settings page to inform users
     * about configuration status. Only displays on the plugin's admin page
     * to avoid notice pollution.
     * 
     * @since 1.0.0
     * @hook admin_notices
     * @return void
     */
    public function admin_notices() {
        // Check if we're on the right page - safer check for WordPress 6.9+
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_' . $this->plugin_slug) {
            return;
        }
        
        $custom_domain = get_option($this->option_name);
        if (empty($custom_domain)) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>' . esc_html__('Custom Permalink Domain:', 'custom-permalink-domain') . '</strong> ';
            echo esc_html__('No custom domain is currently set. Enter a domain below to start using custom permalinks.', 'custom-permalink-domain');
            echo '</p></div>';
        }
    }
    
    /**
     * Show network admin notices
     */
    public function network_admin_notices() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_' . $this->plugin_slug . '-network-network') {
            return;
        }
        
        $network_enabled = get_site_option($this->plugin_slug . '_network_enabled', false);
        if (!$network_enabled) {
            echo '<div class="notice notice-info"><p>';
            echo '<strong>' . esc_html__('Custom Permalink Domain:', 'custom-permalink-domain') . '</strong> ';
            echo esc_html__('Network-wide settings are disabled. Individual sites can configure their own permalink domains.', 'custom-permalink-domain');
            echo '</p></div>';
        }
    }
    
    /**
     * Handle network admin form submission
     */
    public function handle_network_admin_save() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'], $this->plugin_slug . '-network-options')) {
            wp_die('Security check failed');
        }
        
        // Check capabilities
        if (!current_user_can('manage_network_options')) {
            wp_die('You do not have permission to manage these settings');
        }
        
        $multisite_utils = new CustomPermalinkDomainMultisite();
        $redirect_args = array('page' => $this->plugin_slug . '-network');
        
        // Handle bulk operations
        if (isset($_POST['bulk_action']) && !empty($_POST['bulk_action'])) {
            $bulk_action = sanitize_key($_POST['bulk_action']);
            switch ($bulk_action) {
                case 'apply_network_domain':
                    $network_domain = esc_url_raw($_POST['network_domain'] ?? '');
                    if (!empty($network_domain)) {
                        $count = $multisite_utils->apply_network_domain_to_all_sites($network_domain);
                        $redirect_args['bulk_updated'] = $count;
                    }
                    break;
                    
                case 'clear_all_domains':
                    $count = $multisite_utils->clear_all_custom_domains();
                    $redirect_args['bulk_cleared'] = $count;
                    break;
            }
        }
        
        // Save network settings
        $network_enabled = !empty($_POST['network_enabled']) ? 1 : 0;
        $network_domain = esc_url_raw($_POST['network_domain'] ?? '');
        $network_override = !empty($_POST['network_override']) ? 1 : 0;
        
        // Save relative URLs network settings
        $network_relative_enabled = !empty($_POST['network_relative_enabled']) ? 1 : 0;
        $network_relative_override = !empty($_POST['network_relative_override']) ? 1 : 0;
        
        // Save data preservation network setting
        $network_preserve_data = !empty($_POST['network_preserve_data']) ? 1 : 0;
        
        update_site_option($this->plugin_slug . '_network_enabled', $network_enabled);
        update_site_option($this->plugin_slug . '_network_domain', $network_domain);
        update_site_option($this->plugin_slug . '_network_override', $network_override);
        update_site_option($this->plugin_slug . '_network_relative_enabled', $network_relative_enabled);
        update_site_option($this->plugin_slug . '_network_relative_override', $network_relative_override);
        update_site_option($this->plugin_slug . '_network_preserve_data', $network_preserve_data);
        
        // Purge all caches when network settings change
        $this->cache_manager->purge_all_caches();
        
        // Redirect back with success message
        $redirect_args['updated'] = 'true';
        wp_redirect(add_query_arg($redirect_args, network_admin_url('settings.php')));
        exit;
    }
    
    /**
     * Get cleanup status for debugging
     */
    public function get_cleanup_status() {
        $status = array(
            'main_option' => get_option($this->option_name, 'not_found'),
            'types_option' => get_option($this->option_name . '_types', 'not_found'),
            'transients_cleared' => !get_transient('custom_permalink_domain_cache'),
            'is_multisite' => is_multisite()
        );
        
        return $status;
    }
    
    /**
     * Network admin page HTML (optimized database calls)
     */
    public function network_admin_page_html() {
        // Check user capabilities
        if (!current_user_can('manage_network_options')) {
            return;
        }
        
        // Handle success message
        if (isset($_GET['updated']) && $_GET['updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 esc_html__('Network settings saved successfully.', 'custom-permalink-domain') . 
                 '</p></div>';
        }
        
        if (isset($_GET['bulk_updated'])) {
            $count = absint($_GET['bulk_updated']);
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 sprintf(esc_html__('Applied network domain to %d sites.', 'custom-permalink-domain'), $count) . 
                 '</p></div>';
        }
        
        if (isset($_GET['bulk_cleared'])) {
            $count = absint($_GET['bulk_cleared']);
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 sprintf(esc_html__('Cleared custom domains from %d sites.', 'custom-permalink-domain'), $count) . 
                 '</p></div>';
        }
        
        $multisite_utils = new CustomPermalinkDomainMultisite();
        $stats = $multisite_utils->get_network_statistics();
        
        // Consolidate all network option calls for efficiency
        $network_enabled = get_site_option($this->plugin_slug . '_network_enabled', false);
        $network_domain = get_site_option($this->plugin_slug . '_network_domain', '');
        $network_override = get_site_option($this->plugin_slug . '_network_override', false);
        $network_relative_enabled = get_site_option($this->plugin_slug . '_network_relative_enabled', false);
        $network_relative_override = get_site_option($this->plugin_slug . '_network_relative_override', false);
        
        ?>
        <div class="wrap custom-permalink-domain">
            <h1>Custom Permalink Domain Network Settings</h1>
            
            <div class="cpd-admin-grid">
                <!-- Main Content Area -->
                <div class="cpd-main-content">
                    <div class="notice notice-info inline">
                        <p><strong>🌐 Network Overview:</strong> Managing <?= esc_html($stats['total_sites']); ?> sites (<?= esc_html($stats['sites_with_custom_domains']); ?> with custom domains).</p>
                    </div>
                    
                    <form method="post" action="edit.php?action=<?= esc_attr($this->plugin_slug); ?>">
                        <?php wp_nonce_field($this->plugin_slug . '-network-options'); ?>
                        
                        <!-- Network Domain Settings Section -->
                        <div class="cpd-form-section">
                            <div class="cpd-section-header">
                                <h3>🌍 Network Domain Settings</h3>
                                <p class="description">Configure network-wide domain settings for all sites</p>
                            </div>
                            <div class="cpd-section-body">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Enable Network Settings</th>
                                        <td>
                                            <div class="checkbox-label">
                                                <input type="checkbox" id="network_enabled" name="network_enabled" value="1" <?= checked($network_enabled, 1, false); ?> />
                                                <span>Enable network-wide permalink domain settings</span>
                                            </div>
                                            <p class="description">When enabled, allows network-level control over permalink domains across all sites.</p>
                                        </td>
                                    </tr>
                                    
                                    <tr>
                                        <th scope="row">Network Domain</th>
                                        <td>
                                            <input type="url" id="network_domain" name="network_domain" value="<?= esc_attr($network_domain); ?>" placeholder="https://cdn.network.com" />
                                            <p class="description">Domain to use for all sites in the network (only if network settings are enabled).</p>
                                        </td>
                                    </tr>
                                    
                                    <tr>
                                        <th scope="row">Override Site Settings</th>
                                        <td>
                                            <div class="checkbox-label">
                                                <input type="checkbox" id="network_override" name="network_override" value="1" <?= checked($network_override, 1, false); ?> />
                                                <span>Force network domain on all sites</span>
                                            </div>
                                            <p class="description">When enabled, network domain overrides individual site settings and prevents site admins from changing domains.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Relative URLs Settings Section -->
                        <div class="cpd-form-section">
                            <div class="cpd-section-header">
                                <h3>🔗 Relative URLs Settings</h3>
                                <p class="description">Configure network-wide relative URL settings</p>
                            </div>
                            <div class="cpd-section-body">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Enable Relative URLs</th>
                                        <td>
                                            <div class="checkbox-label">
                                                <input type="checkbox" id="network_relative_enabled" name="network_relative_enabled" value="1" <?= checked($network_relative_enabled, 1, false); ?> />
                                                <span>Enable network-wide relative URLs</span>
                                            </div>
                                            <p class="description">Convert all absolute URLs to protocol-relative URLs (//example.com/path) for all sites in the network.</p>
                                        </td>
                                    </tr>
                                    
                                    <tr>
                                        <th scope="row">Override Site Settings</th>
                                        <td>
                                            <div class="checkbox-label">
                                                <input type="checkbox" id="network_relative_override" name="network_relative_override" value="1" <?= checked($network_relative_override, 1, false); ?> />
                                                <span>Force relative URLs on all sites</span>
                                            </div>
                                            <p class="description">Override individual site relative URL preferences and prevent site admins from changing this setting.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Data Preservation Settings Section -->
                        <div class="cpd-form-section">
                            <div class="cpd-section-header">
                                <h3>💾 Data Preservation Settings</h3>
                                <p class="description">Control data retention during plugin updates</p>
                            </div>
                            <div class="cpd-section-body">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Preserve Network Settings</th>
                                        <td>
                                            <?php 
                                            $network_preserve_data = get_site_option($this->plugin_slug . '_network_preserve_data', false);
                                            ?>
                                            <div class="checkbox-label">
                                                <input type="checkbox" id="network_preserve_data" name="network_preserve_data" value="1" <?= checked($network_preserve_data, 1, false); ?> />
                                                <span>Preserve network settings on uninstall</span>
                                            </div>
                                            <p class="description">When enabled, network-wide plugin settings will be preserved if the plugin is uninstalled and reinstalled.</p>
                                            <div class="help-text">
                                                <strong>Note:</strong> Individual site preservation settings will also be respected, giving site admins control over their own data.
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="cpd-action-buttons">
                            <input type="submit" class="button-primary cpd-button-primary" value="💾 Save Network Settings" />
                            <button type="button" id="test-urls-btn" class="button-secondary cpd-button-secondary">🔍 Test URL Changes</button>
                        </div>
                        
                        <div id="url-test-results" class="url-test-results" style="display: none;"></div>
                    </form>
                    
                    <!-- Bulk Operations Section -->
                    <div class="cpd-form-section">
                        <div class="cpd-section-header">
                            <h3>⚡ Bulk Operations</h3>
                            <p class="description">Apply actions to all sites in the network at once</p>
                        </div>
                        <div class="cpd-section-body">
                            <form method="post" action="edit.php?action=<?= esc_attr($this->plugin_slug); ?>" style="margin: 0;">
                                <?php wp_nonce_field($this->plugin_slug . '-network-options'); ?>
                                <input type="hidden" name="network_enabled" value="<?= $network_enabled ? 1 : 0; ?>" />
                                <input type="hidden" name="network_domain" value="<?= esc_attr($network_domain); ?>" />
                                <input type="hidden" name="network_override" value="<?= $network_override ? 1 : 0; ?>" />
                                
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Apply Network Domain</th>
                                        <td>
                                            <button type="submit" name="bulk_action" value="apply_network_domain" class="cpd-button-secondary" 
                                                    <?= empty($network_domain) ? 'disabled title="Set a network domain first"' : ''; ?>>
                                                📢 Apply "<?= esc_html($network_domain ?: 'No domain set'); ?>" to All Sites
                                            </button>
                                            <p class="description">This will set the network domain on all sites, overriding individual settings.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Clear All Domains</th>
                                        <td>
                                            <button type="submit" name="bulk_action" value="clear_all_domains" class="cpd-button-secondary" 
                                                    onclick="return confirm('Are you sure? This will remove custom domains from all sites.')"
                                                    style="color: #d63638; border-color: #d63638;">
                                                🗑️ Clear All Custom Domains
                                            </button>
                                            <p class="description">This will remove custom permalink domains from all sites in the network.</p>
                                        </td>
                                    </tr>
                                </table>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div class="cpd-sidebar">
                    <!-- Network Status Card -->
                    <div class="card cpd-status-card">
                        <div class="card-header">
                            <h2>📊 Network Status</h2>
                        </div>
                        <div class="card-body">
                            <div class="cpd-status-info">
                                <div class="cpd-status-item">
                                    <div class="cpd-status-label">Total Sites</div>
                                    <div class="cpd-status-value"><?= esc_html($stats['total_sites']); ?></div>
                                </div>
                                <div class="cpd-status-item">
                                    <div class="cpd-status-label">With Custom Domains</div>
                                    <div class="cpd-status-value"><?= esc_html($stats['sites_with_custom_domains']); ?></div>
                                </div>
                                <div class="cpd-status-item">
                                    <div class="cpd-status-label">Network Override</div>
                                    <div class="cpd-status-value"><?= $network_override ? '✅ Active' : '❌ Disabled'; ?></div>
                                </div>
                                <div class="cpd-status-item">
                                    <div class="cpd-status-label">Network Domain</div>
                                    <div class="cpd-status-value"><?= $network_domain ? '✅ Set' : '❌ Not Set'; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Sites Card -->
                    <div class="card card-compact">
                        <div class="card-header">
                            <h3>🏢 Recent Site Activity</h3>
                        </div>
                        <div class="card-body">
                            <div style="max-height: 300px; overflow-y: auto;">
                                <table class="wp-list-table widefat" style="margin: 0;">
                                    <thead>
                                        <tr>
                                            <th style="padding: 8px;">Site</th>
                                            <th style="padding: 8px;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $sites = get_sites(array('number' => 10));
                                        foreach ($sites as $site) {
                                            switch_to_blog($site->blog_id);
                                            $site_domain = get_option($this->option_name);
                                            restore_current_blog();
                                            ?>
                                            <tr>
                                                <td style="padding: 8px; font-size: 0.9em;">
                                                    <strong><?= esc_html($site->domain . $site->path); ?></strong>
                                                    <br>
                                                    <small style="color: #646970;"><?= empty($site_domain) ? 'Default' : esc_html($site_domain); ?></small>
                                                </td>
                                                <td style="padding: 8px;">
                                                    <?php if ($network_enabled && $network_override): ?>
                                                        <span style="color: #2271b1; font-size: 0.9em;">🌐 Network</span>
                                                    <?php elseif (!empty($site_domain)): ?>
                                                        <span style="color: #00a32a; font-size: 0.9em;">✅ Custom</span>
                                                    <?php else: ?>
                                                        <span style="color: #646970; font-size: 0.9em;">➖ Default</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                        ?>
                                    </tbody>
                                </table>
                                <?php if (count($sites) >= 10): ?>
                                    <p style="margin: 10px 0 0 0; font-size: 0.9em; color: #646970;"><em>Showing first 10 sites. Visit Network Admin → Sites for complete list.</em></p>
                                <?php endif; ?>
                            </div>
                            
                            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.2);">
                                <p style="margin: 0; font-size: 0.9em;"><strong>Quick Actions:</strong></p>
                                <div style="margin-top: 8px;">
                                    <a href="<?= esc_url(network_admin_url('sites.php')); ?>" class="cpd-button-secondary" style="font-size: 0.9em; padding: 6px 12px;">
                                        🏢 Manage All Sites
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Help Card -->
                    <div class="card card-compact">
                        <div class="card-header">
                            <h3>📚 Network Documentation</h3>
                        </div>
                        <div class="card-body">
                            <p>Network administration resources:</p>
                            <ul style="margin: 10px 0; padding-left: 20px;">
                                <li><a href="https://wpengine.com/support/what-is-wordpress-multisite/" target="_blank">Network Setup Guide</a></li>
                                <li><a href="https://wpengine.com/wp-content/uploads/2017/02/White-Paper-Dos-Donts-WordPress-Multisite.pdf" target="_blank">Multisite Best Practices</a></li>
                            </ul>
                            
                            <div style="background: rgba(255,255,255,0.1); padding: 12px; border-radius: 6px; margin-top: 15px;">
                                <strong>💡 Pro Tip:</strong> Use bulk operations carefully and always test changes on a staging environment first.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Add admin menu page
     */
    /**
     * Add plugin settings page to WordPress admin menu
     * 
     * Registers the plugin's settings page under Settings > Permalink Domain
     * in the WordPress admin. Requires 'manage_options' capability.
     * 
     * @since 1.0.0
     * @hook admin_menu
     * @return void
     */
    public function add_admin_menu() {
        add_options_page(
            __('Custom Permalink Domain Settings', 'custom-permalink-domain'),
            __('Permalink Domain', 'custom-permalink-domain'),
            'manage_options',
            $this->plugin_slug,
            array($this, 'admin_page_html')
        );
    }
    
    /**
     * Add network admin menu page for multisite installations
     * 
     * Registers the plugin's network settings page under Network Settings
     * for multisite WordPress installations. Requires 'manage_network_options' capability.
     * 
     * @since 1.0.0
     * @hook network_admin_menu
     * @return void
     */
    public function add_network_admin_menu() {
        add_submenu_page(
            'settings.php',
            __('Custom Permalink Domain Network Settings', 'custom-permalink-domain'),
            __('Permalink Domain', 'custom-permalink-domain'),
            'manage_network_options',
            $this->plugin_slug . '-network',
            array($this, 'network_admin_page_html')
        );
    }
    
    /**
     * Initialize admin settings
     */
    public function admin_init() {
        // Register settings
        register_setting(
            $this->plugin_slug . '_settings',
            $this->option_name,
            array($this, 'sanitize_domain')
        );
        
        register_setting(
            $this->plugin_slug . '_settings',
            $this->option_name . '_types',
            array($this, 'sanitize_content_types')
        );
        
        register_setting(
            $this->plugin_slug . '_settings',
            $this->option_name . '_relative_urls',
            array($this, 'sanitize_relative_urls')
        );
        
        register_setting(
            $this->plugin_slug . '_settings',
            $this->option_name . '_preserve_data',
            array($this, 'sanitize_preserve_data')
        );
        
        // Add settings section
        add_settings_section(
            $this->plugin_slug . '_section',
            'Permalink Domain Configuration',
            array($this, 'settings_section_callback'),
            $this->plugin_slug
        );
        
        // Add settings field
        add_settings_field(
            'permalink_domain',
            'New Permalink Domain',
            array($this, 'domain_field_callback'),
            $this->plugin_slug,
            $this->plugin_slug . '_section'
        );
        
        // Add content types section
        add_settings_section(
            $this->plugin_slug . '_content_section',
            'Content Types to Modify',
            array($this, 'content_section_callback'),
            $this->plugin_slug
        );
        
        // Add relative URLs section
        add_settings_section(
            $this->plugin_slug . '_relative_section',
            'Relative URLs Settings',
            array($this, 'relative_section_callback'),
            $this->plugin_slug
        );
        
        // Add content type checkboxes
        $content_types = array(
            'posts' => 'Posts',
            'pages' => 'Pages',
            'categories' => 'Categories',
            'tags' => 'Tags',
            'authors' => 'Author Pages',
            'attachments' => 'Attachments'
        );
        
        foreach ($content_types as $key => $label) {
            add_settings_field(
                'content_type_' . $key,
                $label,
                array($this, 'content_type_field_callback'),
                $this->plugin_slug,
                $this->plugin_slug . '_content_section',
                array('type' => $key, 'label' => $label)
            );
        }
        
        // Add relative URLs field
        add_settings_field(
            'relative_urls',
            'Enable Relative URLs',
            array($this, 'relative_urls_field_callback'),
            $this->plugin_slug,
            $this->plugin_slug . '_relative_section'
        );
        
        // Add data preservation section
        add_settings_section(
            $this->plugin_slug . '_preservation_section',
            'Data Preservation Settings',
            array($this, 'preservation_section_callback'),
            $this->plugin_slug
        );
        
        // Add data preservation field
        add_settings_field(
            'preserve_data',
            'Preserve Settings on Uninstall',
            array($this, 'preserve_data_field_callback'),
            $this->plugin_slug,
            $this->plugin_slug . '_preservation_section'
        );
    }
    
    /**
     * Settings section callback
     */
    public function settings_section_callback() {
        echo '<p>' . esc_html__('Configure the domain to use for permalinks. This will not affect your WordPress admin or login URLs.', 'custom-permalink-domain') . '</p>';
    }
    
    /**
     * Content section callback
     */
    public function content_section_callback() {
        echo '<p>' . esc_html__('Select which content types should use the custom domain for their permalinks.', 'custom-permalink-domain') . '</p>';
    }
    
    /**
     * Relative URLs section callback
     */
    public function relative_section_callback() {
        echo '<p>' . esc_html__('Enable protocol-relative URLs (//example.com/path) for better cross-domain compatibility.', 'custom-permalink-domain') . '</p>';
    }
    
    /**
     * Domain field callback
     */
    public function domain_field_callback() {
        $value = get_option($this->option_name, '');
        echo '<input type="url" name="' . esc_attr($this->option_name) . '" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://example.com" />';
        echo '<p class="description">' . esc_html__('Enter the full URL including protocol (https://). Leave empty to disable custom domain.', 'custom-permalink-domain') . '</p>';
    }
    
    /**
     * Content type field callback
     */
    public function content_type_field_callback($args) {
        $options = get_option($this->option_name . '_types', array(
            'posts' => 1,
            'pages' => 1,
            'categories' => 1,
            'tags' => 1,
            'authors' => 1,
            'attachments' => 1
        ));
        $checked = isset($options[$args['type']]) && $options[$args['type']] ? 'checked="checked"' : '';
        echo '<input type="checkbox" id="content_type_' . $args['type'] . '" name="' . $this->option_name . '_types[' . $args['type'] . ']" value="1" ' . $checked . ' />';
        echo '<label for="content_type_' . $args['type'] . '">' . $args['label'] . '</label>';
    }
    
    /**
     * Relative URLs field callback
     */
    public function relative_urls_field_callback() {
        // Check if network override is active (optimized single call)
        if (is_multisite()) {
            $network_settings = $this->get_network_settings();
            $network_relative_enabled = get_site_option($this->plugin_slug . '_network_relative_enabled', false);
            $network_relative_override = get_site_option($this->plugin_slug . '_network_relative_override', false);
            
            if ($network_relative_enabled && $network_relative_override) {
                echo '<p><strong>Network Override Active:</strong> Relative URLs are controlled by network settings.</p>';
                echo '<input type="hidden" name="' . $this->option_name . '_relative_urls" value="' . ($network_relative_enabled ? '1' : '0') . '" />';
                return;
            }
        }
        
        $value = get_option($this->option_name . '_relative_urls', false);
        $checked = $value ? 'checked="checked"' : '';
        echo '<input type="checkbox" id="relative_urls" name="' . $this->option_name . '_relative_urls" value="1" ' . $checked . ' />';
        echo '<label for="relative_urls">Convert URLs to protocol-relative format (//example.com/path)</label>';
        echo '<p class="description">This converts absolute URLs like "https://example.com/page" to "//example.com/page" for better HTTPS/HTTP compatibility and CDN usage.</p>';
    }
    
    /**
     * Data preservation section callback
     */
    public function preservation_section_callback() {
        echo '<p>' . esc_html__('Configure what happens to plugin data when the plugin is uninstalled.', 'custom-permalink-domain') . '</p>';
    }
    
    /**
     * Data preservation field callback
     */
    public function preserve_data_field_callback() {
        $value = get_option($this->option_name . '_preserve_data', false);
        $checked = $value ? 'checked="checked"' : '';
        echo '<input type="checkbox" id="preserve_data" name="' . $this->option_name . '_preserve_data" value="1" ' . $checked . ' />';
        echo '<label for="preserve_data">Keep plugin settings when uninstalling</label>';
        
        // Show different descriptions based on multisite and network settings
        if (is_multisite()) {
            $network_preserve = get_site_option($this->plugin_slug . '_network_preserve_data', false);
            if ($network_preserve) {
                echo '<p class="description">Network preservation is enabled, so this site\'s settings will be preserved by default. <strong>Uncheck this option</strong> if you want this specific site\'s data to be deleted during uninstall (opt out of network preservation).</p>';
            } else {
                echo '<p class="description">When enabled, your custom domain and other plugin settings will be preserved if you uninstall and reinstall the plugin. This is useful for plugin updates or temporary deactivation.</p>';
            }
        } else {
            echo '<p class="description">When enabled, your custom domain and other plugin settings will be preserved if you uninstall and reinstall the plugin. This is useful for plugin updates or temporary deactivation. <strong>Warning:</strong> Disable this option if you want to completely remove all plugin data.</p>';
        }
    }
    
    /**
     * Sanitize domain input
     */
    public function sanitize_domain($input) {
        // Clear cache when settings change
        $this->clear_cache();
        
        if (empty($input)) {
            return '';
        }
        
        // Validate and sanitize URL
        $url = esc_url_raw(trim($input));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'])) {
            add_settings_error(
                $this->option_name,
                'invalid_url',
                esc_html__('Please enter a valid URL including protocol (https://).', 'custom-permalink-domain')
            );
            return get_option($this->option_name, '');
        }
        
        // Remove trailing slash
        return rtrim($url, '/');
    }
    
    /**
     * Sanitize content types input
     */
    public function sanitize_content_types($input) {
        // Clear cache when settings change
        $this->clear_cache();
        
        if (!is_array($input)) {
            return array();
        }
        
        $valid_types = array('posts', 'pages', 'categories', 'tags', 'authors', 'attachments');
        $sanitized = array();
        
        foreach ($valid_types as $type) {
            $sanitized[$type] = isset($input[$type]) ? 1 : 0;
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize relative URLs input
     */
    public function sanitize_relative_urls($input) {
        // Clear cache when settings change
        $this->clear_cache();
        
        // Check if network override is active (optimized single call)
        if (is_multisite()) {
            $network_settings = $this->get_relative_urls_settings();
            
            if ($network_settings['source'] === 'network_override') {
                // Return network setting, ignore user input
                return $network_settings['enabled'] ? 1 : 0;
            }
        }
        
        return !empty($input) ? 1 : 0;
    }
    
    /**
     * Sanitize preserve data input
     */
    public function sanitize_preserve_data($input) {
        // Clear cache when settings change
        $this->clear_cache();
        
        return !empty($input) ? 1 : 0;
    }
    
    /**
     * Debug settings for troubleshooting
     */
    public function debug_settings() {
        if (!current_user_can('manage_options') || !isset($_GET['cpd_debug'])) {
            return;
        }
        
        echo '<div class="notice notice-info"><p><strong>Settings Debug Info:</strong></p>';
        echo '<ul>';
        echo '<li>Domain option: ' . esc_html(get_option($this->option_name, 'NOT_SET')) . '</li>';
        echo '<li>Types option: ' . esc_html(print_r(get_option($this->option_name . '_types', 'NOT_SET'), true)) . '</li>';
        echo '<li>Relative URLs: ' . esc_html(get_option($this->option_name . '_relative_urls', 'NOT_SET') ? 'Enabled' : 'Disabled') . '</li>';
        echo '<li>Preserve Data: ' . esc_html(get_option($this->option_name . '_preserve_data', 'NOT_SET') ? 'Enabled' : 'Disabled') . '</li>';
        echo '</ul></div>';
    }
    
    /**
     * Admin page HTML
     */
    public function admin_page_html() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Add settings errors
        settings_errors($this->option_name);
        
        $current_domain = get_option($this->option_name, '');
        $site_url = get_site_url();
        ?>
        <div class="wrap custom-permalink-domain">
            <h1><?= esc_html(get_admin_page_title()); ?></h1>
            
            <?php $this->debug_settings(); ?>
            
            <div class="cpd-admin-grid">
                <!-- Main Content Area -->
                <div class="cpd-main-content">
                    <?php if (is_multisite()): ?>
                        <?php 
                        // Use consolidated network settings to reduce database calls
                        $network_settings = $this->get_network_settings();
                        $network_enabled = $network_settings['enabled'];
                        $network_override = $network_settings['override'];
                        $network_domain = $network_settings['domain'];
                        ?>
                        
                        <?php if ($network_enabled && $network_override): ?>
                            <div class="notice notice-warning">
                                <p><strong>🔒 Network Override Active:</strong> This site's permalink domain is controlled by network settings.</p>
                                <p>Network Domain: <strong><?= esc_html($network_domain); ?></strong></p>
                                <p>Contact your network administrator to modify these settings.</p>
                            </div>
                        <?php elseif ($network_enabled): ?>
                            <div class="notice notice-info">
                                <p><strong>🌐 Network Settings Available:</strong> Your network administrator has enabled network-wide settings, but individual site configuration is still allowed.</p>
                                <?php if (!empty($network_domain)): ?>
                                    <p>Network Default: <strong><?= esc_html($network_domain); ?></strong></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php 
                    $form_disabled = false;
                    if (is_multisite()) {
                        // Reuse already fetched network settings to avoid additional database calls
                        $form_disabled = $network_enabled && $network_override;
                    }
                    ?>
                    
                    <form action="options.php" method="post" <?= $form_disabled ? 'style="opacity: 0.5; pointer-events: none;"' : ''; ?>>
                        <?php if ($form_disabled): ?>
                            <div class="notice notice-warning inline">
                                <p><strong>Settings Disabled:</strong> Network administrator has enabled override mode.</p>
                            </div>
                        <?php endif; ?>
                        
                        <?php
                        settings_fields($this->plugin_slug . '_settings');
                        do_settings_sections($this->plugin_slug);
                        
                        if (!$form_disabled): ?>
                            <div class="cpd-action-buttons">
                                <input type="submit" class="button-primary cpd-button-primary" value="💾 Save Settings" />
                                <button type="button" id="test-urls-btn" class="button-secondary cpd-button-secondary">🔍 Test URL Changes</button>
                            </div>
                            <div id="url-test-results" class="url-test-results" style="display: none;"></div>
                        <?php else: ?>
                            <p><em>Settings are managed at the network level.</em></p>
                        <?php endif; ?>
                    </form>
                    
                    <!-- How It Works Section -->
                    <div class="cpd-form-section">
                        <div class="cpd-section-header">
                            <h3>🔧 How It Works</h3>
                            <p class="description">Understanding how this plugin modifies your site URLs</p>
                        </div>
                        <div class="cpd-section-body">
                            <div style="padding: 20px;">
                                <p>This plugin allows you to change the domain used in permalinks without affecting your WordPress admin area or login page.</p>
                                
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
                                    <div style="background: #f0f6fc; padding: 15px; border-radius: 6px; border-left: 4px solid #2271b1;">
                                        <h4 style="margin: 0 0 10px 0; color: #1d2327;">✅ Will Continue Using Original Domain</h4>
                                        <ul style="margin: 0; padding-left: 20px;">
                                            <li>WordPress Admin (/wp-admin/)</li>
                                            <li>Login & Registration pages</li>
                                            <li>AJAX & API endpoints</li>
                                            <li>Cron jobs & scheduled tasks</li>
                                        </ul>
                                    </div>
                                    
                                    <div style="background: #edfaef; padding: 15px; border-radius: 6px; border-left: 4px solid #00a32a;">
                                        <h4 style="margin: 0 0 10px 0; color: #1d2327;">🔄 Will Use Custom Domain</h4>
                                        <ul style="margin: 0; padding-left: 20px;">
                                            <li>Posts & Pages</li>
                                            <li>Category & Tag archives</li>
                                            <li>RSS feeds & sitemaps</li>
                                            <li>Canonical URLs & meta tags</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($current_domain)): ?>
                    <!-- URL Examples Section -->
                    <div class="cpd-form-section">
                        <div class="cpd-section-header">
                            <h3>📋 URL Examples</h3>
                            <p class="description">See how your URLs will change with the current settings</p>
                        </div>
                        <div class="cpd-section-body">
                            <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                                <thead>
                                    <tr>
                                        <th style="width: 25%;">Content Type</th>
                                        <th style="width: 37.5%;">Original URL</th>
                                        <th style="width: 37.5%;">New URL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Sample Post</td>
                                        <td style="font-family: monospace; font-size: 0.9em; word-break: break-all;"><?= esc_html($site_url); ?>/sample-post/</td>
                                        <td style="font-family: monospace; font-size: 0.9em; word-break: break-all;"><?= esc_html($current_domain); ?>/sample-post/</td>
                                    </tr>
                                    <tr>
                                        <td>Sample Page</td>
                                        <td style="font-family: monospace; font-size: 0.9em; word-break: break-all;"><?= esc_html($site_url); ?>/sample-page/</td>
                                        <td style="font-family: monospace; font-size: 0.9em; word-break: break-all;"><?= esc_html($current_domain); ?>/sample-page/</td>
                                    </tr>
                                    <tr>
                                        <td>Category</td>
                                        <td style="font-family: monospace; font-size: 0.9em; word-break: break-all;"><?= esc_html($site_url); ?>/category/sample/</td>
                                        <td style="font-family: monospace; font-size: 0.9em; word-break: break-all;"><?= esc_html($current_domain); ?>/category/sample/</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Sidebar -->
                <div class="cpd-sidebar">
                    <!-- Current Status Card -->
                    <div class="card cpd-status-card">
                        <div class="card-header">
                            <h2>📊 Current Status</h2>
                        </div>
                        <div class="card-body">
                            <div class="cpd-status-info">
                                <div class="cpd-status-item">
                                    <div class="cpd-status-label">Site URL</div>
                                    <div class="cpd-status-value" style="font-size: 0.9em; word-break: break-all;">
                                        <?= esc_html($site_url ?: home_url()); ?>
                                    </div>
                                </div>
                                <?php if (!empty($current_domain)): ?>
                                <div class="cpd-status-item">
                                    <div class="cpd-status-label">Custom Domain</div>
                                    <div class="cpd-status-value" style="font-size: 0.9em; word-break: break-all;">
                                        <?= esc_html($current_domain); ?>
                                    </div>
                                </div>
                                <div class="cpd-status-item">
                                    <div class="cpd-status-label">Status</div>
                                    <div class="cpd-status-value">✅ Active</div>
                                </div>
                                <?php else: ?>
                                <div class="cpd-status-item">
                                    <div class="cpd-status-label">Custom Domain</div>
                                    <div class="cpd-status-value">❌ Not Set</div>
                                </div>
                                <div class="cpd-status-item">
                                    <div class="cpd-status-label">Status</div>
                                    <div class="cpd-status-value">⚠️ Configure Below</div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="cpd-status-item">
                                    <div class="cpd-status-label">Plugin Version</div>
                                    <div class="cpd-status-value"><?= esc_html(defined('CPD_VERSION') ? CPD_VERSION : '1.3.8'); ?></div>
                                </div>
                                
                                <?php 
                                $relative_urls = get_option($this->option_name . '_relative_urls', false);
                                if (is_multisite()) {
                                    $network_relative = get_site_option($this->plugin_slug . '_network_relative_enabled', false);
                                    $network_relative_override = get_site_option($this->plugin_slug . '_network_relative_override', false);
                                    if ($network_relative_override) {
                                        $relative_urls = $network_relative;
                                    }
                                }
                                ?>
                                <div class="cpd-status-item">
                                    <div class="cpd-status-label">Protocol-Relative URLs</div>
                                    <div class="cpd-status-value"><?= $relative_urls ? '✅ Enabled' : '❌ Disabled'; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Documentation Card -->
                    <div class="card card-compact">
                        <div class="card-header">
                            <h3>📚 Need Help?</h3>
                        </div>
                        <div class="card-body">
                            <p>Get the most out of your custom domain setup:</p>
                            <ul style="margin: 10px 0; padding-left: 20px;">
                                <li><a href="#" target="_blank">Setup Guide</a></li>
                                <li><a href="#" target="_blank">Troubleshooting</a></li>
                                <li><a href="#" target="_blank">Best Practices</a></li>
                            </ul>
                            
                            <div style="background: rgba(255,255,255,0.1); padding: 12px; border-radius: 6px; margin-top: 15px;">
                                <strong>💡 Tip:</strong> Always test your URL changes before going live to ensure everything works as expected.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Change permalink domain (optimized version)
     */
    public function change_permalink_domain($url) {
        // Don't rewrite URLs in admin context, AJAX requests, or CRON jobs
        if ($this->is_admin_context()) {
            return $url;
        }
        
        // Don't rewrite wp-admin, wp-json, or login URLs to prevent CORS and admin access issues
        if (strpos($url, '/wp-admin') !== false || 
            strpos($url, '/wp-json/') !== false || 
            strpos($url, 'wp-login') !== false || 
            strpos($url, 'wp-register') !== false) {
            return $url;
        }
        
        $custom_domain = $this->get_custom_domain();
        if (!empty($custom_domain)) {
            $site_url = get_site_url();
            $url = str_replace($site_url, $custom_domain, $url);
        }
        
        // Apply relative URL conversion if enabled
        return $this->make_url_relative($url);
    }
    
    /**
     * Change permalink domain for testing purposes (bypasses admin context check)
     */
    public function change_permalink_domain_for_testing($url) {
        // Don't rewrite wp-admin, wp-json, or login URLs to prevent CORS and admin access issues
        if (strpos($url, '/wp-admin') !== false || 
            strpos($url, '/wp-json/') !== false || 
            strpos($url, 'wp-login') !== false || 
            strpos($url, 'wp-register') !== false) {
            return $url;
        }
        
        $custom_domain = $this->get_custom_domain();
        if (!empty($custom_domain)) {
            $site_url = get_site_url();
            $url = str_replace($site_url, $custom_domain, $url);
        }
        
        // Apply relative URL conversion if enabled
        return $this->make_url_relative($url);
    }
    
    /**
     * Change home_url for frontend only (not admin) - optimized
     */
    public function change_home_url_for_frontend($url, $path, $orig_scheme, $blog_id) {
        // Only change for frontend requests, not admin
        if ($this->is_admin_context()) {
            return $url;
        }
        
        // Don't change login, register, or wp-admin URLs
        if (strpos($path, 'wp-login') !== false || strpos($path, 'wp-admin') !== false || strpos($path, 'wp-register') !== false) {
            return $url;
        }
        
        return $this->change_permalink_domain($url);
    }
    
    /**
     * Change site_url for specific frontend contexts - optimized
     */
    public function change_site_url_for_frontend($url, $path, $scheme, $blog_id) {
        // Only change specific paths for frontend
        if ($this->is_admin_context()) {
            return $url;
        }
        
        // Don't rewrite wp-json URLs to prevent CORS issues with GraphQL and REST API in admin contexts
        if (strpos($path, 'wp-json/') !== false) {
            return $url;
        }
        
        // Change URLs for feeds, RSS, etc. (excluding wp-json for admin compatibility)
        $frontend_paths = array('feed', 'rdf', 'rss', 'rss2', 'atom', 'xmlrpc.php');
        foreach ($frontend_paths as $frontend_path) {
            if (strpos($path, $frontend_path) !== false) {
                return $this->change_permalink_domain($url);
            }
        }
        
        return $url;
    }
    
    /**
     * Change REST URL only for frontend contexts (not admin or AJAX) - optimized
     */
    public function change_rest_url_frontend_only($url) {
        // Don't rewrite REST URLs in admin, AJAX, or CRON contexts to prevent CORS issues
        if ($this->is_admin_context()) {
            return $url;
        }
        
        // Don't rewrite if the request is from wp-admin
        if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], '/wp-admin') !== false) {
            return $url;
        }
        
        return $this->change_permalink_domain($url);
    }

    /**
     * Transform REST API post data to use custom permalinks
     * Handles guid.rendered and link attributes in REST responses
     *
     * @param WP_REST_Response $response The response object
     * @param WP_Post $post Post object
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Modified response
     */
    public function transform_rest_post_data($response, $post, $request) {
        // Don't rewrite in admin contexts to prevent CORS issues
        if ($this->is_admin_context()) {
            return $response;
        }

        // Don't rewrite if the request is from wp-admin
        if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], '/wp-admin') !== false) {
            return $response;
        }

        $data = $response->get_data();

        // Transform the 'link' attribute (post permalink)
        if (isset($data['link'])) {
            $data['link'] = $this->transform_url_for_rest_response($data['link']);
        }

        // Transform the 'guid.rendered' attribute
        if (isset($data['guid']['rendered'])) {
            $data['guid']['rendered'] = $this->transform_url_for_rest_response($data['guid']['rendered']);
        }

        // Transform Yoast SEO head content (canonical URLs, Open Graph, Twitter Cards, etc.)
        if (isset($data['yoast_head'])) {
            $data['yoast_head'] = $this->transform_yoast_head_content($data['yoast_head']);
        }

        // Transform Yoast SEO head JSON content (structured data)
        if (isset($data['yoast_head_json'])) {
            $data['yoast_head_json'] = $this->transform_yoast_head_json($data['yoast_head_json']);
        }

        $response->set_data($data);
        return $response;
    }

    /**
     * Transform URL specifically for REST API responses
     * Bypasses wp-json protection to allow proper URL transformation in response data
     *
     * @param string $url URL to transform
     * @return string Transformed URL
     */
    private function transform_url_for_rest_response($url) {
        // Don't rewrite wp-admin or login URLs
        if (strpos($url, '/wp-admin') !== false ||
            strpos($url, 'wp-login') !== false ||
            strpos($url, 'wp-register') !== false) {
            return $url;
        }

        $custom_domain = $this->get_custom_domain();
        if (!empty($custom_domain)) {
            $site_url = get_site_url();
            if (!empty($site_url)) {
                $url = str_replace($site_url, $custom_domain, $url);
            }
        }

        // Apply relative URL conversion if enabled
        return $this->make_url_relative($url);
    }

    /**
     * Register REST API filters for custom post types
     * Called during rest_api_init to catch all registered post types
     */
    public function register_rest_filters_for_custom_post_types() {
        $post_types = get_post_types(array('public' => true, '_builtin' => false), 'names');

        foreach ($post_types as $post_type) {
            // Only add filter if the post type supports REST API
            if (post_type_supports($post_type, 'rest-api') ||
                (function_exists('rest_get_route_for_post_type_items') && rest_get_route_for_post_type_items($post_type))) {
                add_filter("rest_prepare_{$post_type}", array($this, 'transform_rest_post_data'), 10, 3);
            }
        }
    }

    /**
     * Change permalink domain for indexing contexts (like Algolia)
     * This allows search indexers to get the correct custom permalinks
     * while maintaining CORS protection for interactive admin requests
     */
    public function change_permalink_domain_for_indexing($url) {
        // Allow indexing contexts to get custom permalinks even in admin
        // This is safe because it's not an interactive request that would cause CORS issues
        
        // Check for network override first (multisite)
        if (is_multisite()) {
            $network_enabled = get_site_option($this->plugin_slug . '_network_enabled', false);
            $network_override = get_site_option($this->plugin_slug . '_network_override', false);
            
            if ($network_enabled && $network_override) {
                $custom_domain = get_site_option($this->plugin_slug . '_network_domain', '');
                if (!empty($custom_domain)) {
                    $site_url = get_site_url();
                    return str_replace($site_url, $custom_domain, $url);
                }
            }
        }
        
        // Fall back to individual site setting
        $custom_domain = get_option($this->option_name);
        if (empty($custom_domain)) {
            return $url;
        }
        
        $site_url = get_site_url();
        return str_replace($site_url, $custom_domain, $url);
    }
    
    /**
     * Fix Algolia permalink during indexing
     * Ensures Algolia indexes posts with custom permalink domains
     */
    public function fix_algolia_permalink($shared_attributes, $post) {
        if (isset($shared_attributes['permalink'])) {
            $shared_attributes['permalink'] = $this->change_permalink_domain_for_indexing($shared_attributes['permalink']);
        }
        return $shared_attributes;
    }
    
    /**
     * Fix Algolia term permalink during indexing
     * Ensures Algolia indexes terms with custom permalink domains
     */
    public function fix_algolia_term_permalink($record, $term) {
        if (isset($record['permalink'])) {
            $record['permalink'] = $this->change_permalink_domain_for_indexing($record['permalink']);
        }
        return $record;
    }
    
    /**
     * Change paginate links
     */
    public function change_paginate_links($result) {
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
     * Change sitemap entries
     */
    public function change_sitemap_entry($sitemap_entry, $object_type, $object_subtype) {
        if (isset($sitemap_entry['loc'])) {
            $sitemap_entry['loc'] = $this->change_permalink_domain($sitemap_entry['loc']);
        }
        return $sitemap_entry;
    }
    
    
    /**
     * Output custom canonical URL
     */
    public function output_custom_canonical() {
        $custom_domain = $this->get_custom_domain();
        if (empty($custom_domain)) {
            return;
        }
        
        // Only add canonical if no SEO plugin is handling it
        if (!defined('WPSEO_VERSION') && !defined('THE_SEO_FRAMEWORK_VERSION') && !defined('RANK_MATH_VERSION')) {
            $canonical_url = '';
            
            if (is_singular()) {
                $canonical_url = get_permalink();
            } elseif (is_category()) {
                $canonical_url = get_category_link(get_queried_object_id());
            } elseif (is_tag()) {
                $canonical_url = get_tag_link(get_queried_object_id());
            } elseif (is_author()) {
                $canonical_url = get_author_posts_url(get_queried_object_id());
            } elseif (is_home() || is_front_page()) {
                $canonical_url = home_url('/');
            }
            
            if (!empty($canonical_url)) {
                $canonical_url = $this->change_permalink_domain($canonical_url);
                echo '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
            }
        }
        
        // Add Open Graph and Twitter meta tags if no plugin is handling them
        if (!defined('WPSEO_VERSION') && !defined('THE_SEO_FRAMEWORK_VERSION')) {
            $current_url = '';
            
            if (is_singular()) {
                $current_url = get_permalink();
            } elseif (is_home() || is_front_page()) {
                $current_url = home_url('/');
            }
            
            if (!empty($current_url)) {
                $current_url = $this->change_permalink_domain($current_url);
                echo '<meta property="og:url" content="' . esc_url($current_url) . '" />' . "\n";
                echo '<meta name="twitter:url" content="' . esc_url($current_url) . '" />' . "\n";
            }
        }
    }
    
    /**
     * Replace URLs in content
     */
    public function replace_content_urls($content) {
        $custom_domain = $this->get_custom_domain();
        if (empty($custom_domain) && !$this->get_relative_urls_settings()['enabled']) {
            return $content;
        }
        
        if (empty($content)) {
            return $content;
        }
        
        $site_url = get_site_url();
        $home_url = get_home_url();
        
        // Replace internal links in content that point to the old domain
        if (!empty($custom_domain)) {
            $content = str_replace($site_url, $custom_domain, $content);
            if ($home_url !== $site_url) {
                $content = str_replace($home_url, $custom_domain, $content);
            }
        }
        
        // Apply relative URL conversion to all absolute URLs in content
        $relative_settings = $this->get_relative_urls_settings();
        if ($relative_settings['enabled']) {
            // Convert https:// and http:// URLs to protocol-relative URLs
            $content = preg_replace('/https?:\/\//', '//', $content);
        }
        
        return $content;
    }

    /**
     * Transform URLs in Yoast SEO JSON-LD structured data
     *
     * This method processes the schema graph to replace any URLs that point to the
     * original domain with the custom permalink domain. This ensures that all
     * structured data in REST API responses uses the correct domain.
     *
     * @param array $data The Yoast schema graph data
     * @return array Modified schema graph with transformed URLs
     * @since 1.3.8
     */
    public function transform_yoast_schema_urls($data) {
        $custom_domain = $this->get_custom_domain();
        if (empty($custom_domain) || empty($data)) {
            return $data;
        }

        $site_url = get_site_url();
        $home_url = get_home_url();

        // Recursively transform URLs in the schema data
        return $this->transform_urls_in_array($data, $site_url, $home_url, $custom_domain);
    }

    /**
     * Recursively transform URLs in an array structure
     *
     * @param mixed $data The data to transform (can be array, string, or other types)
     * @param string $site_url Original site URL
     * @param string $home_url Original home URL
     * @param string $custom_domain Custom domain to replace with
     * @return mixed Transformed data
     */
    private function transform_urls_in_array($data, $site_url, $home_url, $custom_domain) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->transform_urls_in_array($value, $site_url, $home_url, $custom_domain);
            }
        } elseif (is_string($data)) {
            // Transform URLs in string values
            $data = str_replace($site_url, $custom_domain, $data);
            if ($home_url !== $site_url) {
                $data = str_replace($home_url, $custom_domain, $data);
            }
        }

        return $data;
    }

    /**
     * Transform URLs in Yoast SEO head content for REST API responses
     *
     * The yoast_head property contains HTML meta tags with URLs that need to be
     * transformed to use the custom domain. This method processes the HTML content
     * to replace any URLs pointing to the original domain.
     *
     * @param string $head_content The Yoast head HTML content
     * @return string Modified head content with transformed URLs
     * @since 1.3.8
     */
    private function transform_yoast_head_content($head_content) {
        $custom_domain = $this->get_custom_domain();
        if (empty($custom_domain) || empty($head_content)) {
            return $head_content;
        }

        $site_url = get_site_url();
        $home_url = get_home_url();

        // Extract domain from site URL (remove protocol and path)
        $site_domain = parse_url($site_url, PHP_URL_HOST);
        $custom_domain_host = parse_url($custom_domain, PHP_URL_HOST);

        if (!$site_domain || !$custom_domain_host) {
            return $head_content;
        }

        // Replace all possible variations of the old domain with the new domain
        // Handle different protocols and formats
        $replacements = array(
            'https://' . $site_domain => 'https://' . $custom_domain_host,
            'http://' . $site_domain => 'http://' . $custom_domain_host,
            '//' . $site_domain => '//' . $custom_domain_host,
            $site_domain => $custom_domain_host,
            // Also handle escaped versions for JSON-LD in HTML
            'https:\\/\\/' . $site_domain => 'https:\\/\\/' . $custom_domain_host,
            'http:\\/\\/' . $site_domain => 'http:\\/\\/' . $custom_domain_host,
            '\\/\\/' . $site_domain => '\\/\\/' . $custom_domain_host,
        );

        $transformed_content = $head_content;
        foreach ($replacements as $old => $new) {
            $transformed_content = str_replace($old, $new, $transformed_content);
        }

        return $transformed_content;
    }

    /**
     * Transform Yoast SEO head JSON content for REST API responses
     *
     * Handles the parsed JSON version of Yoast head content, transforming URLs
     * in structured data (JSON-LD) to use the custom domain.
     *
     * @param array $head_json Parsed Yoast head JSON data
     * @return array Modified JSON data with transformed URLs
     * @since 1.3.8
     */
    private function transform_yoast_head_json($head_json) {
        $custom_domain = $this->get_custom_domain();
        if (empty($custom_domain) || empty($head_json)) {
            return $head_json;
        }

        $site_url = get_site_url();
        $home_url = get_home_url();

        // Extract domain from site URL (remove protocol and path)
        $site_domain = parse_url($site_url, PHP_URL_HOST);
        $custom_domain_host = parse_url($custom_domain, PHP_URL_HOST);

        if (!$site_domain || !$custom_domain_host) {
            return $head_json;
        }

        // Recursively transform URLs in the JSON structure
        return $this->transform_json_urls($head_json, $site_domain, $custom_domain_host);
    }

    /**
     * Recursively transform URLs in JSON data structure
     *
     * @param mixed $data JSON data (array, object, or string)
     * @param string $old_domain Old domain to replace
     * @param string $new_domain New domain to use
     * @return mixed Transformed data
     */
    private function transform_json_urls($data, $old_domain, $new_domain) {
        if (is_string($data)) {
            // Replace all possible variations of the old domain with the new domain
            $replacements = array(
                'https://' . $old_domain => 'https://' . $new_domain,
                'http://' . $old_domain => 'http://' . $new_domain,
                '//' . $old_domain => '//' . $new_domain,
                $old_domain => $new_domain,
                // Also handle escaped versions that might appear in JSON
                'https:\\/\\/' . $old_domain => 'https:\\/\\/' . $new_domain,
                'http:\\/\\/' . $old_domain => 'http:\\/\\/' . $new_domain,
                '\\/\\/' . $old_domain => '\\/\\/' . $new_domain,
            );

            $transformed = $data;
            foreach ($replacements as $old => $new) {
                $transformed = str_replace($old, $new, $transformed);
            }
            return $transformed;
        } elseif (is_array($data)) {
            $result = array();
            foreach ($data as $key => $value) {
                $result[$key] = $this->transform_json_urls($value, $old_domain, $new_domain);
            }
            return $result;
        } elseif (is_object($data)) {
            $result = new stdClass();
            foreach ($data as $key => $value) {
                $result->$key = $this->transform_json_urls($value, $old_domain, $new_domain);
            }
            return $result;
        }

        return $data;
    }

    /**
     * Enqueue admin scripts (optimized for memory usage)
     */
    public function admin_enqueue_scripts($hook) {
        // Only load on our plugin pages
        if (strpos($hook, $this->plugin_slug) === false) {
            return;
        }
        
        // Enqueue CSS styles
        wp_enqueue_style(
            'custom-permalink-domain-admin',
            plugin_dir_url(__FILE__) . 'admin-styles.css',
            array(),
            '1.3.8'
        );
        
        wp_enqueue_script('jquery');
        
        // Use external JS file instead of inline script to reduce memory usage
        wp_add_inline_script('jquery', $this->get_admin_js());
        wp_localize_script('jquery', 'cpd_ajax', array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cpd_test_urls')
        ));
        
        // Add minimal CSS for URL testing
        wp_add_inline_style('wp-admin', $this->get_admin_css());
    }
    
    /**
     * Get admin JavaScript (optimized and compressed)
     */
    private function get_admin_js() {
        return "jQuery(function(\$){
            \$('#test-urls-btn').click(function(e){
                e.preventDefault();
                var btn = \$(this), div = \$('#url-test-results');
                btn.prop('disabled', true).text('Testing...');
                div.html('<p>Loading...</p>').show();
                
                \$.ajax({
                    url: cpd_ajax.url,
                    type: 'POST',
                    data: {action: 'cpd_test_urls', nonce: cpd_ajax.nonce},
                    success: function(r){
                        if(r.success){
                            var h = '<h4>URL Test Results</h4><div class=\"url-test-results\">';
                            \$.each(r.data, function(t, u){
                                h += '<div class=\"url-comparison\"><h5>' + t + '</h5>';
                                h += '<div class=\"url-before\"><strong>Before:</strong> ' + u.original + '</div>';
                                h += '<div class=\"url-after\"><strong>After:</strong> ' + u.modified + '</div></div>';
                            });
                            h += '</div>';
                            div.html(h).show();
                        } else {
                            div.html('<p class=\"error\">Error: ' + (r.data || 'Unknown error') + '</p>').show();
                        }
                    },
                    error: function(xhr, status, error){
                        div.html('<p class=\"error\">AJAX request failed: ' + error + '</p>').show();
                    },
                    complete: function(){
                        btn.prop('disabled', false).text('🔍 Test URL Changes');
                    }
                });
            });
        });";
    }
    
    /**
     * Get admin CSS (compressed)
     */
    private function get_admin_css() {
        return ".url-test-results{margin-top:20px;margin-bottom:20px;border:1px solid #ddd;padding:15px;background:#f9f9f9;border-radius:4px}.url-comparison{margin-bottom:15px;padding-bottom:15px;border-bottom:1px solid #eee}.url-comparison:last-child{border-bottom:none;margin-bottom:0}.url-comparison h5{margin:0 0 10px 0;color:#333;font-weight:600}.url-before,.url-after{font-family:monospace;font-size:12px;margin:5px 0;word-break:break-all;padding:8px;border-radius:3px}.url-before{color:#d54e21;background:#fff;border-left:3px solid #d54e21}.url-after{color:#46b450;background:#fff;border-left:3px solid #46b450}.url-test-results .error{color:#d63638;background:#fff2f2;border:1px solid #d63638;padding:10px;border-radius:3px}";
    }
    
    /**
     * Handle AJAX URL testing
     */
    /**
     * Purge all major caching plugin caches to ensure settings apply immediately
     */
    private function purge_all_caches() {
        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Clear WP Rocket cache
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        } elseif (function_exists('rocket_clean_minify')) {
            rocket_clean_minify();
            rocket_clean_cache_busting();
        }
        
        // Clear W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        } elseif (class_exists('W3_Cache') && method_exists('W3_Cache', 'flush_all')) {
            W3_Cache::flush_all();
        }
        
        // Clear WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        } elseif (function_exists('prune_super_cache')) {
            prune_super_cache(get_current_blog_id(), true);
        }
        
        // Clear LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            LiteSpeed_Cache_API::purge_all();
        } elseif (defined('LSCWP_V') && function_exists('litespeed_purge_all')) {
            litespeed_purge_all();
        }
        
        // Clear WP Fastest Cache
        if (class_exists('WpFastestCache') && method_exists('WpFastestCache', 'deleteCache')) {
            $wpfc = new WpFastestCache();
            $wpfc->deleteCache(true);
        }
        
        // Clear Autoptimize cache
        if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
            autoptimizeCache::clearall();
        }
        
        // Clear WP Optimize cache
        if (class_exists('WP_Optimize') && method_exists('WP_Optimize', 'get_cache')) {
            $wp_optimize = WP_Optimize();
            if (method_exists($wp_optimize, 'get_cache') && $wp_optimize->get_cache()) {
                $wp_optimize->get_cache()->purge();
            }
        }
        
        // Clear Comet Cache
        if (class_exists('comet_cache') && method_exists('comet_cache', 'clear')) {
            comet_cache::clear();
        }
        
        // Clear Cache Enabler
        if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_total_cache')) {
            Cache_Enabler::clear_total_cache();
        }
        
        // Clear Hummingbird cache
        if (class_exists('Hummingbird\\WP_Hummingbird') && function_exists('wphb_clear_module_cache')) {
            wphb_clear_module_cache('page_cache');
            wphb_clear_module_cache('minify');
        }
        
        // Clear SG Optimizer cache
        if (class_exists('SiteGround_Optimizer\\Supercacher\\Supercacher')) {
            $sg_cache = new SiteGround_Optimizer\Supercacher\Supercacher();
            if (method_exists($sg_cache, 'purge_cache')) {
                $sg_cache->purge_cache();
            }
        }
        
        // Clear our own cached values
        $this->clear_internal_cache();
        
        // Allow other plugins to hook into cache clearing
        do_action('custom_permalink_domain_cache_cleared');
    }
    
    /**
     * Clear internal plugin cache
     */
    private function clear_internal_cache() {
        $this->custom_domain_cache = null;
        $this->content_types_cache = null;
        $this->network_settings_cache = null;
        $this->relative_urls_cache = null;
    }

    public function ajax_test_urls() {
        // Verify nonce
        if (!check_ajax_referer('cpd_test_urls', 'nonce', false)) {
            wp_send_json_error(__('Security check failed.', 'custom-permalink-domain'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'custom-permalink-domain'));
        }
        
        // First, purge all caches to ensure we get fresh URLs
        $this->cache_manager->purge_all_caches();
        
        $custom_domain = get_option($this->option_name);
        if (empty($custom_domain)) {
            wp_send_json_error(__('No custom domain configured. Please enter a custom domain in the settings above.', 'custom-permalink-domain'));
        }
        
        $sample_urls = array();
        $site_url = get_site_url();
        
        // Basic URLs that should always exist
        $sample_urls['Home URL'] = array(
            'original' => home_url('/'),
            'modified' => $this->url_transformer->transform_url_for_testing(home_url('/'))
        );
        
        $sample_urls['Site URL'] = array(
            'original' => $site_url,
            'modified' => $this->url_transformer->transform_url_for_testing($site_url)
        );
        
        // Get a recent post
        $recent_post = get_posts(array('numberposts' => 1, 'post_status' => 'publish'));
        if ($recent_post) {
            $post = $recent_post[0];
            $sample_urls['Recent Post'] = array(
                'original' => get_permalink($post->ID),
                'modified' => $this->url_transformer->transform_url_for_testing(get_permalink($post->ID))
            );
            
            $sample_urls['Post Comments Feed'] = array(
                'original' => get_post_comments_feed_link($post->ID),
                'modified' => $this->url_transformer->transform_url_for_testing(get_post_comments_feed_link($post->ID))
            );
        } else {
            $sample_urls['Sample Post'] = array(
                'original' => $site_url . '/sample-post/',
                'modified' => $custom_domain . '/sample-post/'
            );
        }
        
        // RSS Feed
        $sample_urls['RSS Feed'] = array(
            'original' => get_feed_link(),
            'modified' => $this->url_transformer->transform_url_for_testing(get_feed_link())
        );
        
        $sample_urls['Comments Feed'] = array(
            'original' => get_feed_link('comments_rss2'),
            'modified' => $this->url_transformer->transform_url_for_testing(get_feed_link('comments_rss2'))
        );
        
        // REST API
        $sample_urls['REST API'] = array(
            'original' => rest_url(),
            'modified' => $this->url_transformer->transform_url_for_testing(rest_url())
        );
        
        // Category archive (if categories exist)
        $categories = get_categories(array('number' => 1, 'hide_empty' => true));
        if ($categories) {
            $category = $categories[0];
            $sample_urls['Category: ' . $category->name] = array(
                'original' => get_category_link($category->term_id),
                'modified' => $this->url_transformer->transform_url_for_testing(get_category_link($category->term_id))
            );
        } else {
            $sample_urls['Sample Category'] = array(
                'original' => $site_url . '/category/sample/',
                'modified' => $custom_domain . '/category/sample/'
            );
        }
        
        // Author archive (if users exist)
        $users = get_users(array('number' => 1, 'who' => 'authors'));
        if ($users) {
            $user = $users[0];
            $sample_urls['Author: ' . $user->display_name] = array(
                'original' => get_author_posts_url($user->ID),
                'modified' => $this->url_transformer->transform_url_for_testing(get_author_posts_url($user->ID))
            );
        }
        
        wp_send_json_success($sample_urls);
    }
}

// Initialize the plugin
new CustomPermalinkDomain();

// Add activation hook
register_activation_hook(__FILE__, 'custom_permalink_domain_activate');
function custom_permalink_domain_activate($network_wide = false) {
    if (is_multisite() && $network_wide) {
        // Network activation
        $sites = get_sites(array('number' => 0));
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            custom_permalink_domain_activate_single_site();
            restore_current_blog();
        }
        
        // Set default network options
        add_site_option('custom_permalink_domain_network_enabled', false);
        add_site_option('custom_permalink_domain_network_domain', '');
        add_site_option('custom_permalink_domain_network_override', false);
        add_site_option('custom_permalink_domain_network_relative_enabled', false);
        add_site_option('custom_permalink_domain_network_relative_override', false);
        add_site_option('custom_permalink_domain_network_preserve_data', false);
    } else {
        // Single site activation
        custom_permalink_domain_activate_single_site();
    }
}

function custom_permalink_domain_activate_single_site() {
    // Add default options if needed
    if (get_option('custom_permalink_domain') === false) {
        add_option('custom_permalink_domain', '');
    }
    if (get_option('custom_permalink_domain_types') === false) {
        add_option('custom_permalink_domain_types', array(
            'posts' => 1,
            'pages' => 1,
            'categories' => 1,
            'tags' => 1,
            'authors' => 1,
            'attachments' => 1
        ));
    }
    if (get_option('custom_permalink_domain_relative_urls') === false) {
        add_option('custom_permalink_domain_relative_urls', false);
    }
    if (get_option('custom_permalink_domain_preserve_data') === false) {
        add_option('custom_permalink_domain_preserve_data', false);
    }
}

// Handle new site creation in multisite
add_action('wpmu_new_blog', 'custom_permalink_domain_new_site', 10, 6);
function custom_permalink_domain_new_site($blog_id, $user_id, $domain, $path, $site_id, $meta) {
    if (is_plugin_active_for_network(plugin_basename(__FILE__))) {
        switch_to_blog($blog_id);
        custom_permalink_domain_activate_single_site();
        restore_current_blog();
    }
}

// Add deactivation hook
register_deactivation_hook(__FILE__, 'custom_permalink_domain_deactivate');
function custom_permalink_domain_deactivate() {
    // Clear any transients on deactivation
    delete_transient('custom_permalink_domain_cache');
    
    // Clear object cache
    wp_cache_flush();
    
    // Note: We preserve the main settings so users can reactivate without losing configuration
    // Settings are only deleted on full plugin deletion (uninstall)
}

// Add uninstall hook
register_uninstall_hook(__FILE__, 'custom_permalink_domain_uninstall');
function custom_permalink_domain_uninstall() {
    // Remove all plugin options when plugin is deleted
    delete_option('custom_permalink_domain');
    delete_option('custom_permalink_domain_types');
    delete_option('custom_permalink_domain_relative_urls');
    
    // Remove any transients that might have been created
    delete_transient('custom_permalink_domain_cache');
    
    // Remove any user meta that might have been created
    delete_metadata('user', 0, 'custom_permalink_domain_dismissed_notices', '', true);
    
    // For multisite installations, clean up all sites
    if (is_multisite()) {
        global $wpdb;
        
        // Get all sites
        $sites = get_sites(array('number' => 0));
        
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            
            // Delete options for each site
            delete_option('custom_permalink_domain');
            delete_option('custom_permalink_domain_types');
            delete_option('custom_permalink_domain_relative_urls');
            delete_transient('custom_permalink_domain_cache');
            delete_metadata('user', 0, 'custom_permalink_domain_dismissed_notices', '', true);
            
            restore_current_blog();
        }
        
        // Also clean up network-wide options if any were created
        delete_site_option('custom_permalink_domain_network_enabled');
        delete_site_option('custom_permalink_domain_network_domain');
        delete_site_option('custom_permalink_domain_network_override');
        delete_site_option('custom_permalink_domain_network_relative_enabled');
        delete_site_option('custom_permalink_domain_network_relative_override');
        delete_site_option('custom_permalink_domain_network_settings');
    }
    
    // Clear any cached data
    wp_cache_flush();
}
?>
