<?php
/**
 * Constants and Configuration for Custom Permalink Domain Plugin
 *
 * Centralized configuration management for all plugin constants, magic numbers,
 * and configuration values. This improves maintainability and provides a single
 * source of truth for all plugin settings.
 *
 * @package CustomPermalinkDomain
 * @since   1.3.11
 * @version 1.3.11
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CPD_Constants {

    // Plugin Information
    const PLUGIN_VERSION = '1.4.0';
    const PLUGIN_SLUG = 'custom-permalink-domain';
    const OPTION_NAME = 'custom_permalink_domain';
    const TEXT_DOMAIN = 'custom-permalink-domain';

    // WordPress Requirements
    const MIN_WP_VERSION = '5.0';
    const MIN_PHP_VERSION = '7.4';
    const TESTED_UP_TO = '6.9';

    // Database and Caching
    const CACHE_TTL_SHORT = 300;    // 5 minutes
    const CACHE_TTL_MEDIUM = 3600;  // 1 hour
    const CACHE_TTL_LONG = 86400;   // 24 hours
    const BATCH_SIZE = 100;         // Default batch size for operations

    // Network/Multisite Settings
    const NETWORK_OPTION_PREFIX = 'custom_permalink_domain_network_';
    const MAX_SITES_BULK_OPERATION = 1000;

    // Performance Limits
    const MAX_URL_LENGTH = 2048;
    const MAX_CONTENT_LENGTH = 1048576; // 1MB for content processing
    const MAX_CACHE_ENTRIES = 1000;

    // URL Processing
    const URL_PATTERN_TIMEOUT = 5; // seconds for regex operations
    const MAX_REDIRECTS = 5;

    // Admin Interface
    const ADMIN_CAPABILITY = 'manage_options';
    const NETWORK_ADMIN_CAPABILITY = 'manage_network_options';
    const ADMIN_MENU_POSITION = 80;

    // Cache Keys
    const CACHE_KEY_DOMAIN = 'cpd_custom_domain';
    const CACHE_KEY_CONTENT_TYPES = 'cpd_content_types';
    const CACHE_KEY_NETWORK_SETTINGS = 'cpd_network_settings';
    const CACHE_KEY_RELATIVE_URLS = 'cpd_relative_urls';

    // Hook Priorities
    const PRIORITY_EARLY = 5;
    const PRIORITY_NORMAL = 10;
    const PRIORITY_LATE = 20;
    const PRIORITY_VERY_LATE = 999;

    // Error Codes
    const ERROR_INVALID_DOMAIN = 'invalid_domain';
    const ERROR_NETWORK_ACCESS = 'network_access_denied';
    const ERROR_CACHE_FAILURE = 'cache_operation_failed';
    const ERROR_URL_TRANSFORM = 'url_transformation_failed';

    // Logging Levels
    const LOG_ERROR = 'error';
    const LOG_WARNING = 'warning';
    const LOG_INFO = 'info';
    const LOG_DEBUG = 'debug';

    // Content Types (Default enabled)
    const DEFAULT_CONTENT_TYPES = [
        'posts' => true,
        'pages' => true,
        'categories' => true,
        'tags' => true,
        'authors' => false,
        'feeds' => false,
        'sitemaps' => true
    ];

    // Protected URL Patterns
    const PROTECTED_PATHS = [
        '/wp-admin/',
        '/wp-includes/',
        '/wp-content/plugins/',
        '/wp-login.php',
        '/xmlrpc.php'
    ];

    // SEO Plugin Hooks
    const SEO_HOOKS = [
        'yoast' => [
            'wpseo_canonical',
            'wpseo_opengraph_url',
            'wpseo_twitter_card_image',
            'wpseo_schema_graph'
        ],
        'rankmath' => [
            'rank_math/frontend/canonical',
            'rank_math/opengraph/facebook/og_url'
        ],
        'seo_framework' => [
            'the_seo_framework_canonical_url',
            'the_seo_framework_og_url'
        ]
    ];

    // Cache Plugin Detection
    const CACHE_PLUGINS = [
        'wp_rocket' => 'rocket_clean_domain',
        'w3tc' => 'w3tc_flush_all',
        'wp_super_cache' => 'wp_cache_clear_cache',
        'litespeed' => 'LiteSpeed_Cache_API::purge_all',
        'wp_fastest_cache' => 'WpFastestCache::deleteCache',
        'autoptimize' => 'autoptimizeCache::clearall',
        'wp_optimize' => 'WP_Optimize::get_cache',
        'comet_cache' => 'comet_cache::clear',
        'cache_enabler' => 'Cache_Enabler::clear_total_cache',
        'hummingbird' => 'wphb_clear_module_cache',
        'sg_optimizer' => 'SiteGround_Optimizer\Supercacher\Supercacher'
    ];

    // JavaScript/CSS Cache Busting
    const ASSET_VERSION_QUERY = 'ver';
    const ADMIN_CSS_HANDLE = 'custom-permalink-domain-admin';
    const ADMIN_JS_HANDLE = 'custom-permalink-domain-admin-js';

    /**
     * Get plugin base URL
     *
     * @return string Plugin URL
     */
    public static function get_plugin_url() {
        return plugin_dir_url(dirname(__FILE__));
    }

    /**
     * Get plugin base path
     *
     * @return string Plugin directory path
     */
    public static function get_plugin_path() {
        return plugin_dir_path(dirname(__FILE__));
    }

    /**
     * Check if current environment is development
     *
     * @return bool True if development environment
     */
    public static function is_development() {
        return defined('WP_DEBUG') && WP_DEBUG;
    }

    /**
     * Get cache TTL based on context
     *
     * @param string $context Cache context (short|medium|long)
     * @return int Cache TTL in seconds
     */
    public static function get_cache_ttl($context = 'medium') {
        switch ($context) {
            case 'short':
                return self::CACHE_TTL_SHORT;
            case 'long':
                return self::CACHE_TTL_LONG;
            default:
                return self::CACHE_TTL_MEDIUM;
        }
    }

    /**
     * Get prefixed option name
     *
     * @param string $suffix Option suffix
     * @return string Full option name
     */
    public static function get_option_name($suffix = '') {
        if (empty($suffix)) {
            return self::OPTION_NAME;
        }
        return self::OPTION_NAME . '_' . $suffix;
    }

    /**
     * Get network option name
     *
     * @param string $suffix Option suffix
     * @return string Full network option name
     */
    public static function get_network_option_name($suffix) {
        return self::NETWORK_OPTION_PREFIX . $suffix;
    }
}