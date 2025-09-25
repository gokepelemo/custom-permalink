<?php
/**
 * Admin Interface Class for Custom Permalink Domain Plugin
 *
 * Handles all WordPress admin interface functionality including menu creation,
 * page rendering, form processing, AJAX endpoints, and settings management.
 * Uses the service locator pattern for dependency management.
 *
 * Features:
 * - WordPress Settings API integration
 * - Multisite network admin support
 * - AJAX URL testing functionality
 * - Comprehensive form validation
 * - Cache management integration
 * - Responsive admin interface design
 * - Network override functionality
 *
 * @package CustomPermalinkDomain
 * @since   1.3.11
 * @version 1.3.11
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

require_once 'class-cpd-constants.php';
require_once 'class-cpd-service-locator.php';

class CPD_Admin {

    /**
     * Service locator instance
     * @var CPD_Service_Locator
     */
    private $services;

    /**
     * Plugin slug
     * @var string
     */
    private $plugin_slug;

    /**
     * Option name
     * @var string
     */
    private $option_name;

    /**
     * Network admin flag
     * @var bool
     */
    private $is_network_admin;

    /**
     * Constructor
     *
     * @param CPD_Service_Locator $services Service locator instance
     */
    public function __construct(CPD_Service_Locator $services) {
        $this->services = $services;
        $this->plugin_slug = CPD_Constants::PLUGIN_SLUG;
        $this->option_name = CPD_Constants::OPTION_NAME;
        $this->is_network_admin = is_multisite() && is_network_admin();

        $this->register_hooks();
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Admin menu hooks
        if (is_multisite()) {
            add_action('network_admin_menu', [$this, 'add_network_admin_menu']);
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('network_admin_edit_' . $this->plugin_slug, [$this, 'handle_network_admin_save']);
        } else {
            add_action('admin_menu', [$this, 'add_admin_menu']);
        }

        // Admin initialization
        add_action('admin_init', [$this, 'admin_init'], CPD_Constants::PRIORITY_EARLY);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);

        // Admin notices
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('network_admin_notices', [$this, 'network_admin_notices']);

        // AJAX endpoints
        add_action('wp_ajax_cpd_test_urls', [$this, 'ajax_test_urls']);
    }

    /**
     * Add admin menu page for single site
     */
    public function add_admin_menu() {
        add_options_page(
            __('Custom Permalink Domain Settings', CPD_Constants::TEXT_DOMAIN),
            __('Permalink Domain', CPD_Constants::TEXT_DOMAIN),
            CPD_Constants::ADMIN_CAPABILITY,
            $this->plugin_slug,
            [$this, 'admin_page_html']
        );
    }

    /**
     * Add network admin menu page for multisite installations
     */
    public function add_network_admin_menu() {
        add_submenu_page(
            'settings.php',
            __('Custom Permalink Domain Network Settings', CPD_Constants::TEXT_DOMAIN),
            __('Permalink Domain', CPD_Constants::TEXT_DOMAIN),
            CPD_Constants::NETWORK_ADMIN_CAPABILITY,
            $this->plugin_slug . '-network',
            [$this, 'network_admin_page_html']
        );
    }

    /**
     * Initialize admin settings
     */
    public function admin_init() {
        $this->register_settings();
        $this->add_settings_sections();
        $this->add_settings_fields();
    }

    /**
     * Register plugin settings with WordPress Settings API
     */
    private function register_settings() {
        $settings = [
            $this->option_name => 'sanitize_domain',
            $this->option_name . '_types' => 'sanitize_content_types',
            $this->option_name . '_relative_urls' => 'sanitize_relative_urls',
            $this->option_name . '_preserve_data' => 'sanitize_preserve_data'
        ];

        foreach ($settings as $setting => $sanitizer) {
            register_setting(
                $this->plugin_slug . '_settings',
                $setting,
                [$this, $sanitizer]
            );
        }
    }

    /**
     * Add settings sections
     */
    private function add_settings_sections() {
        $sections = [
            $this->plugin_slug . '_section' => [
                'title' => 'Permalink Domain Configuration',
                'callback' => 'settings_section_callback'
            ],
            $this->plugin_slug . '_content_section' => [
                'title' => 'Content Types to Modify',
                'callback' => 'content_section_callback'
            ],
            $this->plugin_slug . '_relative_section' => [
                'title' => 'Relative URLs Settings',
                'callback' => 'relative_section_callback'
            ]
        ];

        foreach ($sections as $section_id => $section) {
            add_settings_section(
                $section_id,
                $section['title'],
                [$this, $section['callback']],
                $this->plugin_slug
            );
        }
    }

    /**
     * Add settings fields
     */
    private function add_settings_fields() {
        // Main domain field
        add_settings_field(
            'permalink_domain',
            'New Permalink Domain',
            [$this, 'domain_field_callback'],
            $this->plugin_slug,
            $this->plugin_slug . '_section'
        );

        // Content type fields
        $content_types = CPD_Constants::DEFAULT_CONTENT_TYPES;
        foreach ($content_types as $type => $default) {
            add_settings_field(
                'content_type_' . $type,
                ucwords(str_replace('_', ' ', $type)),
                [$this, 'content_type_field_callback'],
                $this->plugin_slug,
                $this->plugin_slug . '_content_section',
                ['type' => $type, 'label' => ucwords(str_replace('_', ' ', $type))]
            );
        }

        // Relative URLs fields
        add_settings_field(
            'relative_urls_enabled',
            'Enable Protocol-Relative URLs',
            [$this, 'relative_urls_enabled_callback'],
            $this->plugin_slug,
            $this->plugin_slug . '_relative_section'
        );

        // Data preservation field
        add_settings_field(
            'preserve_data',
            'Preserve Data on Uninstall',
            [$this, 'preserve_data_callback'],
            $this->plugin_slug,
            $this->plugin_slug . '_section'
        );
    }

    /**
     * Main settings section callback
     */
    public function settings_section_callback() {
        echo '<p>Configure the custom domain to use for permalinks on your site.</p>';
    }

    /**
     * Content section callback
     */
    public function content_section_callback() {
        echo '<p>Choose which types of content should use the custom permalink domain.</p>';
    }

    /**
     * Relative URLs section callback
     */
    public function relative_section_callback() {
        echo '<p>Configure protocol-relative URLs (starting with // instead of http/https).</p>';
    }

    /**
     * Domain field callback
     */
    public function domain_field_callback() {
        $options = $this->services->get('options');
        $value = $options->get_custom_domain();

        echo sprintf(
            '<input type="url" id="permalink_domain" name="%s" value="%s" class="regular-text" placeholder="https://cdn.example.com" />',
            esc_attr($this->option_name),
            esc_attr($value)
        );
        echo '<p class="description">Enter the full URL including protocol (http:// or https://)</p>';
    }

    /**
     * Content type field callback
     */
    public function content_type_field_callback($args) {
        $options = $this->services->get('options');
        $content_types = $options->get_content_types();
        $type = $args['type'];
        $checked = isset($content_types[$type]) && $content_types[$type];

        echo sprintf(
            '<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>',
            esc_attr($this->option_name . '_types'),
            esc_attr($type),
            checked($checked, true, false),
            esc_html($args['label'])
        );
    }

    /**
     * Relative URLs enabled callback
     */
    public function relative_urls_enabled_callback() {
        $options = $this->services->get('options');
        $relative_settings = $options->get_relative_urls_settings();
        $checked = $relative_settings['enabled'] ?? false;

        echo sprintf(
            '<label><input type="checkbox" name="%s[enabled]" value="1" %s /> Use protocol-relative URLs (//domain.com instead of https://domain.com)</label>',
            esc_attr($this->option_name . '_relative_urls'),
            checked($checked, true, false)
        );
        echo '<p class="description">This can be useful for sites that serve both HTTP and HTTPS content.</p>';
    }

    /**
     * Preserve data callback
     */
    public function preserve_data_callback() {
        $preserve_data = get_option($this->option_name . '_preserve_data', 0);

        echo sprintf(
            '<label><input type="checkbox" name="%s" value="1" %s /> Keep plugin settings when uninstalled</label>',
            esc_attr($this->option_name . '_preserve_data'),
            checked($preserve_data, 1, false)
        );
        echo '<p class="description">If checked, plugin settings will be preserved when the plugin is deleted.</p>';
    }

    /**
     * Render main admin page
     */
    public function admin_page_html() {
        if (!current_user_can(CPD_Constants::ADMIN_CAPABILITY)) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Add settings errors
        settings_errors($this->option_name);

        $options = $this->services->get('options');
        $current_domain = $options->get_custom_domain();
        $site_url = site_url();

        ?>
        <div class="wrap custom-permalink-domain">
            <h1><?= esc_html(get_admin_page_title()); ?></h1>

            <?php $this->render_debug_info(); ?>

            <div class="cpd-admin-grid">
                <div class="cpd-main-content">
                    <?php $this->render_multisite_notices(); ?>

                    <?php $this->render_settings_form(); ?>

                    <?php $this->render_how_it_works_section(); ?>

                    <?php if (!empty($current_domain)): ?>
                        <?php $this->render_url_examples_section($current_domain, $site_url); ?>
                    <?php endif; ?>
                </div>

                <div class="cpd-sidebar">
                    <?php $this->render_sidebar(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render network admin page
     */
    public function network_admin_page_html() {
        if (!current_user_can(CPD_Constants::NETWORK_ADMIN_CAPABILITY)) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $options = $this->services->get('options');
        $network_settings = $options->get_network_settings();

        ?>
        <div class="wrap">
            <h1><?= esc_html(get_admin_page_title()); ?></h1>

            <form method="post" action="edit.php?action=<?= esc_attr($this->plugin_slug); ?>">
                <?php wp_nonce_field($this->plugin_slug . '_network_save'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Network-Wide Settings</th>
                        <td>
                            <label>
                                <input type="checkbox" name="network_enabled" value="1" <?= checked($network_settings['enabled'], true, false); ?> />
                                Enable centralized permalink domain management
                            </label>
                            <p class="description">When enabled, you can set a default domain for all sites in the network.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Network Permalink Domain</th>
                        <td>
                            <input type="url" name="network_domain" value="<?= esc_attr($network_settings['domain']); ?>" class="regular-text" placeholder="https://cdn.example.com" />
                            <p class="description">Default domain to use across all sites in the network.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Override Individual Sites</th>
                        <td>
                            <label>
                                <input type="checkbox" name="network_override" value="1" <?= checked($network_settings['override'], true, false); ?> />
                                Force all sites to use the network domain
                            </label>
                            <p class="description">When checked, individual sites cannot override the network domain setting.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Network Settings'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handle network admin form save
     */
    public function handle_network_admin_save() {
        if (!current_user_can(CPD_Constants::NETWORK_ADMIN_CAPABILITY)) {
            wp_die(__('You do not have sufficient permissions to perform this action.'));
        }

        check_admin_referer($this->plugin_slug . '_network_save');

        $network_enabled = isset($_POST['network_enabled']) && $_POST['network_enabled'] === '1';
        $network_domain = sanitize_text_field($_POST['network_domain'] ?? '');
        $network_override = isset($_POST['network_override']) && $_POST['network_override'] === '1';

        update_site_option(CPD_Constants::get_network_option_name('enabled'), $network_enabled);
        update_site_option(CPD_Constants::get_network_option_name('domain'), $network_domain);
        update_site_option(CPD_Constants::get_network_option_name('override'), $network_override);

        // Purge caches after network settings change
        $cache_manager = $this->services->get('cache');
        $cache_manager->purge_all_caches();

        // Log the network settings change
        CPD_Logger::info('Network settings updated', [
            'enabled' => $network_enabled,
            'domain' => $network_domain,
            'override' => $network_override,
            'user_id' => get_current_user_id()
        ]);

        wp_redirect(add_query_arg(['page' => $this->plugin_slug . '-network', 'updated' => 'true'], network_admin_url('settings.php')));
        exit;
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, $this->plugin_slug) === false) {
            return;
        }

        $plugin_version = CPD_Constants::PLUGIN_VERSION;

        // Enqueue admin CSS
        wp_enqueue_style(
            CPD_Constants::ADMIN_CSS_HANDLE,
            CPD_Constants::get_plugin_url() . 'admin-styles.css',
            [],
            $plugin_version
        );

        // Enqueue admin JS
        wp_enqueue_script(
            CPD_Constants::ADMIN_JS_HANDLE,
            CPD_Constants::get_plugin_url() . 'admin-script.js',
            ['jquery'],
            $plugin_version,
            true
        );

        // Localize script
        wp_localize_script(CPD_Constants::ADMIN_JS_HANDLE, 'cpdAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cpd_test_urls'),
            'strings' => [
                'testing' => __('Testing URLs...', CPD_Constants::TEXT_DOMAIN),
                'error' => __('Error testing URLs', CPD_Constants::TEXT_DOMAIN)
            ]
        ]);
    }

    /**
     * AJAX URL testing endpoint
     */
    public function ajax_test_urls() {
        check_ajax_referer('cpd_test_urls', 'nonce');

        if (!current_user_can(CPD_Constants::ADMIN_CAPABILITY)) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        try {
            $transformer = $this->services->get('url_transformer');
            $test_urls = CPD_Test_Utils::get_test_urls();

            $results = [];
            foreach ($test_urls as $type => $url) {
                $transformed = $transformer->transform_url($url, true); // Bypass admin check for testing
                $results[] = [
                    'type' => ucfirst($type),
                    'original' => $url,
                    'transformed' => $transformed,
                    'changed' => $url !== $transformed
                ];
            }

            wp_send_json_success($results);

        } catch (Exception $e) {
            CPD_Logger::error('AJAX URL test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            wp_send_json_error('URL testing failed: ' . $e->getMessage());
        }
    }

    /**
     * Display admin notices
     */
    public function admin_notices() {
        if (!$this->is_plugin_admin_page()) {
            return;
        }

        if (isset($_GET['updated']) && $_GET['updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
        }
    }

    /**
     * Display network admin notices
     */
    public function network_admin_notices() {
        if (!$this->is_plugin_admin_page()) {
            return;
        }

        if (isset($_GET['updated']) && $_GET['updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>Network settings saved successfully!</p></div>';
        }
    }

    // Sanitization methods
    public function sanitize_domain($input) {
        if (empty($input)) {
            return '';
        }

        $sanitized = esc_url_raw($input);

        // Remove duplicate protocols
        $sanitized = preg_replace('/^https?:\/\/https?:\/\//', 'https://', $sanitized);

        return $sanitized;
    }

    public function sanitize_content_types($input) {
        if (!is_array($input)) {
            return CPD_Constants::DEFAULT_CONTENT_TYPES;
        }

        $sanitized = [];
        foreach (CPD_Constants::DEFAULT_CONTENT_TYPES as $type => $default) {
            $sanitized[$type] = isset($input[$type]) && $input[$type] === '1';
        }

        return $sanitized;
    }

    public function sanitize_relative_urls($input) {
        if (!is_array($input)) {
            return ['enabled' => false, 'types' => []];
        }

        return [
            'enabled' => isset($input['enabled']) && $input['enabled'] === '1',
            'types' => $input['types'] ?? []
        ];
    }

    public function sanitize_preserve_data($input) {
        return $input === '1' ? 1 : 0;
    }

    // Helper methods for rendering
    private function is_plugin_admin_page() {
        $screen = get_current_screen();
        return $screen && strpos($screen->id, $this->plugin_slug) !== false;
    }

    private function render_debug_info() {
        if (!current_user_can(CPD_Constants::ADMIN_CAPABILITY) || !isset($_GET['cpd_debug'])) {
            return;
        }

        $options = $this->services->get('options');

        echo '<div class="notice notice-info"><p><strong>Settings Debug Info:</strong></p>';
        echo '<pre>' . esc_html(print_r([
            'custom_domain' => $options->get_custom_domain(),
            'content_types' => $options->get_content_types(),
            'relative_urls' => $options->get_relative_urls_settings(),
            'network_settings' => $options->get_network_settings()
        ], true)) . '</pre>';
        echo '</div>';
    }

    private function render_multisite_notices() {
        if (!is_multisite()) {
            return;
        }

        $options = $this->services->get('options');
        $network_settings = $options->get_network_settings();

        if ($network_settings['enabled'] && $network_settings['override']) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>🔒 Network Override Active:</strong> This site\'s permalink domain is controlled by network settings.</p>';
            echo '<p>Network Domain: <strong>' . esc_html($network_settings['domain']) . '</strong></p>';
            echo '</div>';
        } elseif ($network_settings['enabled']) {
            echo '<div class="notice notice-info">';
            echo '<p><strong>🌐 Network Settings Available:</strong> Individual site configuration is allowed.</p>';
            if (!empty($network_settings['domain'])) {
                echo '<p>Network Default: <strong>' . esc_html($network_settings['domain']) . '</strong></p>';
            }
            echo '</div>';
        }
    }

    private function render_settings_form() {
        $options = $this->services->get('options');
        $network_settings = $options->get_network_settings();
        $form_disabled = is_multisite() && $network_settings['enabled'] && $network_settings['override'];

        echo '<form action="options.php" method="post"' . ($form_disabled ? ' style="opacity: 0.5; pointer-events: none;"' : '') . '>';

        if ($form_disabled) {
            echo '<div class="notice notice-warning inline">';
            echo '<p><strong>Settings Disabled:</strong> Network administrator has enabled override mode.</p>';
            echo '</div>';
        }

        settings_fields($this->plugin_slug . '_settings');
        do_settings_sections($this->plugin_slug);

        if (!$form_disabled) {
            echo '<div class="cpd-action-buttons">';
            echo '<input type="submit" class="button-primary cpd-button-primary" value="💾 Save Settings" />';
            echo '<button type="button" id="test-urls-btn" class="button-secondary cpd-button-secondary">🔍 Test URL Changes</button>';
            echo '</div>';
            echo '<div id="url-test-results" class="url-test-results" style="display: none;"></div>';
        } else {
            echo '<p><em>Settings are managed at the network level.</em></p>';
        }

        echo '</form>';
    }

    private function render_how_it_works_section() {
        echo '<div class="cpd-form-section">';
        echo '<div class="cpd-section-header">';
        echo '<h3>🔧 How It Works</h3>';
        echo '<p class="description">Understanding how this plugin modifies your site URLs</p>';
        echo '</div>';
        echo '<div class="cpd-section-body">';
        echo '<p>This plugin allows you to change the domain used in permalinks without affecting your WordPress admin area.</p>';
        echo '</div>';
        echo '</div>';
    }

    private function render_url_examples_section($current_domain, $site_url) {
        echo '<div class="cpd-form-section">';
        echo '<div class="cpd-section-header">';
        echo '<h3>📋 URL Examples</h3>';
        echo '<p class="description">See how your URLs will change with the current settings</p>';
        echo '</div>';
        echo '<div class="cpd-section-body">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Content Type</th><th>Original URL</th><th>New URL</th></tr></thead>';
        echo '<tbody>';

        $examples = [
            'Sample Post' => '/sample-post/',
            'Sample Page' => '/sample-page/',
            'Category' => '/category/sample/'
        ];

        foreach ($examples as $type => $path) {
            echo '<tr>';
            echo '<td>' . esc_html($type) . '</td>';
            echo '<td style="font-family: monospace;">' . esc_html($site_url . $path) . '</td>';
            echo '<td style="font-family: monospace;">' . esc_html($current_domain . $path) . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '</div>';
    }

    private function render_sidebar() {
        echo '<div class="cpd-sidebar-section">';
        echo '<h3>💡 Pro Tips</h3>';
        echo '<ul>';
        echo '<li>Test your URL changes before going live</li>';
        echo '<li>Update your CDN settings to match the new domain</li>';
        echo '<li>Check your SEO tools and analytics</li>';
        echo '</ul>';
        echo '</div>';
    }
}