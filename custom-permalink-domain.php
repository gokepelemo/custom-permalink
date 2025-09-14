<?php
/*
Plugin Name: Custom Permalink Domain
Plugin URI: https://wordpress.org/plugins/custom-permalink-domain/
Description: Changes permalink domain without affecting site URLs with admin interface. Fully multisite compatible with relative URLs support.
Version: 1.1.0
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

// Include multisite utilities
require_once plugin_dir_path(__FILE__) . 'multisite-utils.php';

/**
 * Custom Permalink Domain Plugin
 * 
 * PERFORMANCE OPTIMIZATIONS (v1.1.0):
 * - Consolidated database calls using caching properties
 * - Enhanced admin context checking with static caching
 * - Reduced redundant get_site_option() calls in admin pages
 * - Optimized network settings retrieval
 * - Improved JavaScript compression and error handling
 * - Better memory usage in admin script loading
 * - Consolidated network settings validation
 */

class CustomPermalinkDomain {
    
    private $option_name = 'custom_permalink_domain';
    private $plugin_slug = 'custom-permalink-domain';
    private $is_network_admin = false;
    
    // Cache frequently accessed options to reduce database queries
    private $custom_domain_cache = null;
    private $content_types_cache = null;
    private $network_settings_cache = null;
    private $relative_urls_cache = null;
    
    public function __construct() {
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
        
        add_action('admin_init', array($this, 'admin_init'));
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
     * Register frontend-only filters (SEO, meta tags, etc.)
     */
    private function register_frontend_filters() {
        // Only add frontend filters if not in admin and we have a custom domain
        if (!is_admin() && $this->get_custom_domain()) {
            add_action('wp_head', array($this, 'output_custom_canonical'), 1);
            add_filter('wpseo_canonical', array($this, 'change_permalink_domain')); // Yoast SEO
            add_filter('wpseo_opengraph_url', array($this, 'change_permalink_domain')); // Yoast OG
            add_filter('wpseo_twitter_card_image', array($this, 'change_permalink_domain')); // Yoast Twitter
            add_filter('the_seo_framework_canonical_url', array($this, 'change_permalink_domain')); // SEO Framework
            add_filter('rank_math/frontend/canonical', array($this, 'change_permalink_domain')); // RankMath
            
            // WordPress core meta and link filters
            add_filter('get_shortlink', array($this, 'change_permalink_domain'));
            add_filter('post_comments_feed_link', array($this, 'change_permalink_domain'));
            
            // Filter URLs in content that might be generated
            add_filter('the_content', array($this, 'replace_content_urls'), 999);
            add_filter('widget_text', array($this, 'replace_content_urls'), 999);
        }
    }
    
    /**
     * Register permalink filters based on configuration
     */
    private function register_permalink_filters() {
        $custom_domain = $this->get_custom_domain();
        if (empty($custom_domain)) {
            return;
        }
        
        $content_types = $this->get_content_types();
        
        // Register content type specific filters
        if (!empty($content_types['posts'])) {
            add_filter('post_link', array($this, 'change_permalink_domain'));
        }
        if (!empty($content_types['pages'])) {
            add_filter('page_link', array($this, 'change_permalink_domain'));
        }
        if (!empty($content_types['categories'])) {
            add_filter('category_link', array($this, 'change_permalink_domain'));
        }
        if (!empty($content_types['tags'])) {
            add_filter('tag_link', array($this, 'change_permalink_domain'));
        }
        if (!empty($content_types['authors'])) {
            add_filter('author_link', array($this, 'change_permalink_domain'));
        }
        if (!empty($content_types['attachments'])) {
            add_filter('attachment_link', array($this, 'change_permalink_domain'));
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
        add_filter('home_url', array($this, 'change_home_url_for_frontend'), 10, 4);
        add_filter('site_url', array($this, 'change_site_url_for_frontend'), 10, 4);
        
        // RSS/Atom feeds
        add_filter('feed_link', array($this, 'change_permalink_domain'));
        add_filter('category_feed_link', array($this, 'change_permalink_domain'));
        add_filter('author_feed_link', array($this, 'change_permalink_domain'));
        add_filter('tag_feed_link', array($this, 'change_permalink_domain'));
        add_filter('search_feed_link', array($this, 'change_permalink_domain'));
        
        // Archives and other special pages
        add_filter('year_link', array($this, 'change_permalink_domain'));
        add_filter('month_link', array($this, 'change_permalink_domain'));
        add_filter('day_link', array($this, 'change_permalink_domain'));
        
        // REST API - special handling to avoid CORS issues with WP GraphQL and admin contexts
        // Uses dedicated function with admin context checks
        add_filter('rest_url', array($this, 'change_rest_url_frontend_only'));
        
        // Comments
        add_filter('get_comments_link', array($this, 'change_permalink_domain'));
        
        // Search
        add_filter('search_link', array($this, 'change_permalink_domain'));
        
        // Canonical and meta URLs (for headers)
        add_filter('get_canonical_url', array($this, 'change_permalink_domain'));
        add_filter('wp_get_canonical_url', array($this, 'change_permalink_domain'));
        
        // Pagination links
        add_filter('paginate_links', array($this, 'change_paginate_links'));
        
        // WordPress.org specific filters for sitemaps
        add_filter('wp_sitemaps_posts_entry', array($this, 'change_sitemap_entry'), 10, 3);
        add_filter('wp_sitemaps_taxonomies_entry', array($this, 'change_sitemap_entry'), 10, 3);
        add_filter('wp_sitemaps_users_entry', array($this, 'change_sitemap_entry'), 10, 3);
    }
    
    /**
     * Register Algolia Search plugin integration filters (fixed duplicates)
     */
    private function register_algolia_filters() {
        // Algolia Search plugin integration - ensure custom permalinks are indexed correctly
        // These filters run during indexing operations to provide correct URLs to search engines
        add_filter('algolia_post_shared_attributes', array($this, 'fix_algolia_permalink'), 10, 2);
        add_filter('algolia_searchable_post_shared_attributes', array($this, 'fix_algolia_permalink'), 10, 2);
        add_filter('algolia_term_record', array($this, 'fix_algolia_term_permalink'), 10, 2);
    }
    
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('custom-permalink-domain', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Get custom domain with caching to reduce database calls
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
     * Get content types with caching
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
     * Get network settings with caching
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
     * Check if we're in an admin context (optimized with static caching)
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
     * Clear internal caches when options are updated
     */
    public function clear_cache() {
        $this->custom_domain_cache = null;
        $this->content_types_cache = null;
        $this->network_settings_cache = null;
        $this->relative_urls_cache = null;
    }
    
    /**
     * Convert absolute URL to protocol-relative URL
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
     * Apply relative URL conversion to a URL after domain change
     */
    private function apply_relative_url_conversion($url) {
        // First apply custom domain change if needed
        $url = $this->change_permalink_domain($url);
        
        // Then make it relative if enabled
        return $this->make_url_relative($url);
    }
    
    
    /**
     * Show admin notices
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
        
        update_site_option($this->plugin_slug . '_network_enabled', $network_enabled);
        update_site_option($this->plugin_slug . '_network_domain', $network_domain);
        update_site_option($this->plugin_slug . '_network_override', $network_override);
        update_site_option($this->plugin_slug . '_network_relative_enabled', $network_relative_enabled);
        update_site_option($this->plugin_slug . '_network_relative_override', $network_relative_override);
        
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
            <h1>Custom Permalink Domain - Network Settings</h1>
            
            <div class="notice notice-info">
                <p><strong>Network Overview:</strong> Managing <?= esc_html($stats['total_sites']); ?> sites (<?= esc_html($stats['sites_with_custom_domains']); ?> with custom domains).</p>
            </div>
            
            <form method="post" action="edit.php?action=<?= esc_attr($this->plugin_slug); ?>">
                <?php wp_nonce_field($this->plugin_slug . '-network-options'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="network_enabled">Enable Network-wide Settings</label>
                        </th>
                        <td>
                            <input type="checkbox" id="network_enabled" name="network_enabled" value="1" <?= checked($network_enabled, 1, false); ?> />
                            <p class="description">Enable network-wide permalink domain settings that apply to all sites.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="network_domain">Network Permalink Domain</label>
                        </th>
                        <td>
                            <input type="url" id="network_domain" name="network_domain" value="<?= esc_attr($network_domain); ?>" class="regular-text" placeholder="https://cdn.network.com" />
                            <p class="description">Domain to use for all sites in the network (only if network-wide settings are enabled).</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="network_override">Override Individual Site Settings</label>
                        </th>
                        <td>
                            <input type="checkbox" id="network_override" name="network_override" value="1" <?= checked($network_override, 1, false); ?> />
                            <p class="description">Force network domain on all sites, overriding individual site settings.</p>
                        </td>
                    </tr>
                </table>
                
                <h2>Relative URLs Settings</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="network_relative_enabled">Enable Network-wide Relative URLs</label>
                        </th>
                        <td>
                            <input type="checkbox" id="network_relative_enabled" name="network_relative_enabled" value="1" <?= checked($network_relative_enabled, 1, false); ?> />
                            <p class="description">Convert all absolute URLs to protocol-relative URLs (//example.com/path) for all sites in the network.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="network_relative_override">Override Individual Site Relative URL Settings</label>
                        </th>
                        <td>
                            <input type="checkbox" id="network_relative_override" name="network_relative_override" value="1" <?= checked($network_relative_override, 1, false); ?> />
                            <p class="description">Force relative URLs on all sites, overriding individual site preferences.</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Network Settings'); ?>
                <button type="button" id="test-urls-btn" class="button" style="margin-left: 10px;">Test URL Changes</button>
                <div id="url-test-results" style="margin-top: 20px;"></div>
            </form>
            
            <div class="card">
                <h2>Bulk Operations</h2>
                <p>Apply actions to all sites in the network at once.</p>
                
                <form method="post" action="edit.php?action=<?= esc_attr($this->plugin_slug); ?>" style="margin-bottom: 20px;">
                    <?php wp_nonce_field($this->plugin_slug . '-network-options'); ?>
                    <input type="hidden" name="network_enabled" value="<?= $network_enabled ? 1 : 0; ?>" />
                    <input type="hidden" name="network_domain" value="<?= esc_attr($network_domain); ?>" />
                    <input type="hidden" name="network_override" value="<?= $network_override ? 1 : 0; ?>" />
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Bulk Apply Domain</th>
                            <td>
                                <button type="submit" name="bulk_action" value="apply_network_domain" class="button" 
                                        <?= empty($network_domain) ? 'disabled title="Set a network domain first"' : ''; ?>>
                                    Apply "<?= esc_html($network_domain ?: 'No domain set'); ?>" to All Sites
                                </button>
                                <p class="description">This will set the network domain on all sites, overriding individual settings.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Clear All Domains</th>
                            <td>
                                <button type="submit" name="bulk_action" value="clear_all_domains" class="button button-secondary" 
                                        onclick="return confirm('Are you sure? This will remove custom domains from all sites.')">
                                    Clear All Custom Domains
                                </button>
                                <p class="description">This will remove custom permalink domains from all sites in the network.</p>
                            </td>
                        </tr>
                    </table>
                </form>
            </div>
            
            <div class="card">
                <h2>Individual Site Management</h2>
                <p>If network-wide settings are disabled, each site can configure its own permalink domain in their individual Settings → Permalink Domain page.</p>
                
                <h3>Recent Site Activity</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>Custom Domain</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sites = get_sites(array('number' => 10));
                        foreach ($sites as $site) {
                            switch_to_blog($site->blog_id);
                            $site_domain = get_option($this->option_name);
                            $site_url = get_site_url();
                            restore_current_blog();
                            ?>
                            <tr>
                                <td>
                                    <strong><?= esc_html($site->domain . $site->path); ?></strong>
                                </td>
                                <td>
                                    <?= empty($site_domain) ? '<em>Not set</em>' : esc_html($site_domain); ?>
                                </td>
                                <td>
                                    <?php if ($network_enabled && $network_override): ?>
                                        <span style="color: blue;">Network Override</span>
                                    <?php elseif (!empty($site_domain)): ?>
                                        <span style="color: green;">Active</span>
                                    <?php else: ?>
                                        <span style="color: gray;">Default</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= esc_url(get_admin_url($site->blog_id, 'options-general.php?page=' . $this->plugin_slug)); ?>" target="_blank">Configure</a>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
                
                <?php if (count($sites) >= 10): ?>
                    <p><em>Showing first 10 sites. Visit Network Admin → Sites for complete list.</em></p>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h2>Multisite Features</h2>
                <ul>
                    <li><strong>Network Override:</strong> Apply the same domain to all sites in the network</li>
                    <li><strong>Individual Control:</strong> Let each site admin configure their own domain</li>
                    <li><strong>Centralized Management:</strong> View and manage all site configurations from here</li>
                    <li><strong>Bulk Operations:</strong> Apply settings across multiple sites at once</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * Add admin menu page
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
     * Add network admin menu page
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
                        <p><strong>Network Override Active:</strong> This site's permalink domain is controlled by network settings.</p>
                        <p>Network Domain: <strong><?= esc_html($network_domain); ?></strong></p>
                        <p>Contact your network administrator to modify these settings.</p>
                    </div>
                <?php elseif ($network_enabled): ?>
                    <div class="notice notice-info">
                        <p><strong>Network Settings Available:</strong> Your network administrator has enabled network-wide settings, but individual site configuration is still allowed.</p>
                        <?php if (!empty($network_domain)): ?>
                            <p>Network Default: <strong><?= esc_html($network_domain); ?></strong></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="notice notice-info">
                <p><strong>Current WordPress Site URL:</strong> <?= esc_html($site_url); ?></p>
                <?php if (!empty($current_domain)): ?>
                    <p><strong>Current Custom Permalink Domain:</strong> <?= esc_html($current_domain); ?></p>
                <?php endif; ?>
            </div>
            
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
                
                if (!$form_disabled) {
                    submit_button('Save Settings');
                    echo '<button type="button" id="test-urls-btn" class="button" style="margin-left: 10px;">Test URL Changes</button>';
                    echo '<div id="url-test-results" style="margin-top: 20px;"></div>';
                } else {
                    echo '<p><em>Settings are managed at the network level.</em></p>';
                }
                ?>
            </form>
            
            <div class="card">
                <h2>How It Works</h2>
                <p>This plugin allows you to change the domain used in permalinks without affecting your WordPress admin area or login page.</p>
                <ul>
                    <li><strong>WordPress Admin:</strong> Will continue to use <?= esc_html($site_url); ?></li>
                    <li><strong>Permalinks:</strong> Will use your custom domain for public-facing URLs</li>
                    <li><strong>SEO Benefits:</strong> Useful for CDN integration or domain migration</li>
                </ul>
                
                <h3>URLs That Will Be Changed</h3>
                <ul>
                    <li><strong>Post & Page Links:</strong> Individual post and page URLs</li>
                    <li><strong>Category & Tag Archives:</strong> Taxonomy archive pages</li>
                    <li><strong>Author Pages:</strong> Author archive pages</li>
                    <li><strong>RSS Feeds:</strong> All feed URLs (RSS, Atom, etc.)</li>
                    <li><strong>REST API:</strong> WordPress REST API endpoints</li>
                    <li><strong>Sitemaps:</strong> WordPress core sitemap URLs</li>
                    <li><strong>Canonical URLs:</strong> Link rel="canonical" in headers</li>
                    <li><strong>Meta Tags:</strong> Open Graph og:url and Twitter Card URLs</li>
                    <li><strong>Pagination:</strong> Next/previous page links</li>
                    <li><strong>Comments:</strong> Comment feed and form URLs</li>
                    <li><strong>Search:</strong> Search result URLs</li>
                    <li><strong>Date Archives:</strong> Year, month, and day archive URLs</li>
                </ul>
                
                <h3>URLs That Will NOT Be Changed</h3>
                <ul>
                    <li><strong>Admin Area:</strong> /wp-admin/ and all admin pages</li>
                    <li><strong>Login/Register:</strong> wp-login.php and registration URLs</li>
                    <li><strong>AJAX Requests:</strong> WordPress AJAX handlers</li>
                    <li><strong>Cron Jobs:</strong> Scheduled task URLs</li>
                    <li><strong>Plugin Assets:</strong> CSS, JS, and image files</li>
                </ul>
            </div>
            
            <?php if (!empty($current_domain)): ?>
            <div class="card">
                <h2>Example URLs</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Content Type</th>
                            <th>Original URL</th>
                            <th>New URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Sample Post</td>
                            <td><?= esc_html($site_url); ?>/sample-post/</td>
                            <td><?= esc_html($current_domain); ?>/sample-post/</td>
                        </tr>
                        <tr>
                            <td>Sample Page</td>
                            <td><?= esc_html($site_url); ?>/sample-page/</td>
                            <td><?= esc_html($current_domain); ?>/sample-page/</td>
                        </tr>
                        <tr>
                            <td>Category</td>
                            <td><?= esc_html($site_url); ?>/category/sample/</td>
                            <td><?= esc_html($current_domain); ?>/category/sample/</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <h2>Plugin Information</h2>
                <table class="form-table">
                    <tr>
                        <th>Plugin Status</th>
                        <td><?php echo !empty($current_domain) ? '<span style="color: green;">Active with custom domain</span>' : '<span style="color: orange;">Active but no domain set</span>'; ?></td>
                    </tr>
                    <tr>
                        <th>Database Options</th>
                        <td>
                            <?php 
                            $main_option = get_option($this->option_name);
                            $types_option = get_option($this->option_name . '_types');
                            echo 'Main setting: ' . (empty($main_option) ? 'Empty' : 'Set') . '<br>';
                            echo 'Content types: ' . (empty($types_option) ? 'Default' : count(array_filter($types_option)) . ' enabled');
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Cleanup on Uninstall</th>
                        <td>All plugin data will be completely removed when the plugin is deleted through WordPress admin.</td>
                    </tr>
                </table>
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
            '1.0.0'
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
                div.html('<p>Loading...</p>');
                
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
                            div.html(h);
                        } else {
                            div.html('<p class=\"error\">Error: ' + r.data + '</p>');
                        }
                    },
                    error: function(){
                        div.html('<p class=\"error\">AJAX request failed.</p>');
                    },
                    complete: function(){
                        btn.prop('disabled', false).text('Test URL Changes');
                    }
                });
            });
        });";
    }
    
    /**
     * Get admin CSS (compressed)
     */
    private function get_admin_css() {
        return ".url-test-results{margin-top:20px;border:1px solid #ddd;padding:15px;background:#f9f9f9}.url-comparison{margin-bottom:15px;padding-bottom:15px;border-bottom:1px solid #eee}.url-comparison:last-child{border-bottom:none}.url-comparison h5{margin:0 0 10px 0;color:#333}.url-before,.url-after{font-family:monospace;font-size:12px;margin:5px 0;word-break:break-all}.url-before{color:#d54e21}.url-after{color:#46b450}#test-urls-btn{margin-top:10px}";
    }
    
    /**
     * Handle AJAX URL testing
     */
    public function ajax_test_urls() {
        check_ajax_referer('cpd_test_urls', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'custom-permalink-domain'));
        }
        
        $custom_domain = get_option($this->option_name);
        if (empty($custom_domain)) {
            wp_send_json_error(__('No custom domain configured.', 'custom-permalink-domain'));
        }
        
        $sample_urls = array();
        
        // Get a recent post
        $recent_post = get_posts(array('numberposts' => 1, 'post_status' => 'publish'));
        if ($recent_post) {
            $post = $recent_post[0];
            $sample_urls['Post URL'] = array(
                'original' => get_permalink($post->ID),
                'modified' => $this->change_permalink_domain(get_permalink($post->ID))
            );
            
            $sample_urls['Post Comments Feed'] = array(
                'original' => get_post_comments_feed_link($post->ID),
                'modified' => $this->change_permalink_domain(get_post_comments_feed_link($post->ID))
            );
        }
        
        // Get home and feed URLs
        $sample_urls['Home URL'] = array(
            'original' => home_url('/'),
            'modified' => $this->change_permalink_domain(home_url('/'))
        );
        
        $sample_urls['RSS Feed'] = array(
            'original' => get_feed_link(),
            'modified' => $this->change_permalink_domain(get_feed_link())
        );
        
        $sample_urls['Comments Feed'] = array(
            'original' => get_feed_link('comments_rss2'),
            'modified' => $this->change_permalink_domain(get_feed_link('comments_rss2'))
        );
        
        // REST API
        $sample_urls['REST API'] = array(
            'original' => rest_url(),
            'modified' => $this->change_permalink_domain(rest_url())
        );
        
        // Category archive (if categories exist)
        $categories = get_categories(array('number' => 1, 'hide_empty' => true));
        if ($categories) {
            $category = $categories[0];
            $sample_urls['Category Archive'] = array(
                'original' => get_category_link($category->term_id),
                'modified' => $this->change_permalink_domain(get_category_link($category->term_id))
            );
        }
        
        // Author archive (if users exist)
        $users = get_users(array('number' => 1, 'who' => 'authors'));
        if ($users) {
            $user = $users[0];
            $sample_urls['Author Archive'] = array(
                'original' => get_author_posts_url($user->ID),
                'modified' => $this->change_permalink_domain(get_author_posts_url($user->ID))
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
