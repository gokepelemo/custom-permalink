<?php
/**
 * Cache Manager Class for Custom Permalink Domain Plugin
 * 
 * Implements strategy pattern for cache purging across multiple caching plugins.
 * Consolidates all caching operations for better maintainability.
 * 
 * @package CustomPermalinkDomain
 * @since 1.3.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache Strategy Interface
 */
interface CPD_Cache_Strategy {
    /**
     * Check if this cache strategy is available
     * @return bool
     */
    public function is_available();
    
    /**
     * Purge the cache
     * @return bool Success status
     */
    public function purge();
    
    /**
     * Get the cache strategy name
     * @return string
     */
    public function get_name();
}

/**
 * WordPress Core Cache Strategy
 */
class CPD_WordPress_Cache_Strategy implements CPD_Cache_Strategy {
    public function is_available() {
        return function_exists('wp_cache_flush');
    }
    
    public function purge() {
        if ($this->is_available()) {
            wp_cache_flush();
            return true;
        }
        return false;
    }
    
    public function get_name() {
        return 'WordPress Core Cache';
    }
}

/**
 * WP Rocket Cache Strategy
 */
class CPD_WP_Rocket_Strategy implements CPD_Cache_Strategy {
    public function is_available() {
        return function_exists('rocket_clean_domain') || 
               function_exists('rocket_clean_minify');
    }
    
    public function purge() {
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            return true;
        } elseif (function_exists('rocket_clean_minify')) {
            rocket_clean_minify();
            if (function_exists('rocket_clean_cache_busting')) {
                rocket_clean_cache_busting();
            }
            return true;
        }
        return false;
    }
    
    public function get_name() {
        return 'WP Rocket';
    }
}

/**
 * W3 Total Cache Strategy
 */
class CPD_W3TC_Strategy implements CPD_Cache_Strategy {
    public function is_available() {
        return function_exists('w3tc_flush_all') || 
               (class_exists('W3_Cache') && method_exists('W3_Cache', 'flush_all'));
    }
    
    public function purge() {
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            return true;
        } elseif (class_exists('W3_Cache') && method_exists('W3_Cache', 'flush_all')) {
            W3_Cache::flush_all();
            return true;
        }
        return false;
    }
    
    public function get_name() {
        return 'W3 Total Cache';
    }
}

/**
 * WP Super Cache Strategy
 */
class CPD_WP_Super_Cache_Strategy implements CPD_Cache_Strategy {
    public function is_available() {
        return function_exists('wp_cache_clear_cache') || 
               function_exists('prune_super_cache');
    }
    
    public function purge() {
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            return true;
        } elseif (function_exists('prune_super_cache')) {
            prune_super_cache(get_current_blog_id(), true);
            return true;
        }
        return false;
    }
    
    public function get_name() {
        return 'WP Super Cache';
    }
}

/**
 * LiteSpeed Cache Strategy
 */
class CPD_LiteSpeed_Strategy implements CPD_Cache_Strategy {
    public function is_available() {
        return (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) ||
               (defined('LSCWP_V') && function_exists('litespeed_purge_all'));
    }
    
    public function purge() {
        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            LiteSpeed_Cache_API::purge_all();
            return true;
        } elseif (defined('LSCWP_V') && function_exists('litespeed_purge_all')) {
            litespeed_purge_all();
            return true;
        }
        return false;
    }
    
    public function get_name() {
        return 'LiteSpeed Cache';
    }
}

/**
 * WP Fastest Cache Strategy
 */
class CPD_WP_Fastest_Cache_Strategy implements CPD_Cache_Strategy {
    public function is_available() {
        return class_exists('WpFastestCache') && method_exists('WpFastestCache', 'deleteCache');
    }
    
    public function purge() {
        if ($this->is_available()) {
            $wpfc = new WpFastestCache();
            $wpfc->deleteCache(true);
            return true;
        }
        return false;
    }
    
    public function get_name() {
        return 'WP Fastest Cache';
    }
}

/**
 * Autoptimize Cache Strategy
 */
class CPD_Autoptimize_Strategy implements CPD_Cache_Strategy {
    public function is_available() {
        return class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall');
    }
    
    public function purge() {
        if ($this->is_available()) {
            autoptimizeCache::clearall();
            return true;
        }
        return false;
    }
    
    public function get_name() {
        return 'Autoptimize';
    }
}

/**
 * WP Optimize Cache Strategy
 */
class CPD_WP_Optimize_Strategy implements CPD_Cache_Strategy {
    public function is_available() {
        return class_exists('WP_Optimize') && method_exists('WP_Optimize', 'get_cache');
    }
    
    public function purge() {
        if ($this->is_available()) {
            $wp_optimize = WP_Optimize();
            if (method_exists($wp_optimize, 'get_cache') && $wp_optimize->get_cache()) {
                $wp_optimize->get_cache()->purge();
                return true;
            }
        }
        return false;
    }
    
    public function get_name() {
        return 'WP Optimize';
    }
}

/**
 * Comet Cache Strategy
 */
class CPD_Comet_Cache_Strategy implements CPD_Cache_Strategy {
    public function is_available() {
        return class_exists('comet_cache') && method_exists('comet_cache', 'clear');
    }
    
    public function purge() {
        if ($this->is_available()) {
            comet_cache::clear();
            return true;
        }
        return false;
    }
    
    public function get_name() {
        return 'Comet Cache';
    }
}

/**
 * Cache Enabler Strategy
 */
class CPD_Cache_Enabler_Strategy implements CPD_Cache_Strategy {
    public function is_available() {
        return class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_total_cache');
    }
    
    public function purge() {
        if ($this->is_available()) {
            Cache_Enabler::clear_total_cache();
            return true;
        }
        return false;
    }
    
    public function get_name() {
        return 'Cache Enabler';
    }
}

/**
 * Hummingbird Cache Strategy
 */
class CPD_Hummingbird_Strategy implements CPD_Cache_Strategy {
    public function is_available() {
        return class_exists('Hummingbird\\WP_Hummingbird') && function_exists('wphb_clear_module_cache');
    }
    
    public function purge() {
        if ($this->is_available()) {
            wphb_clear_module_cache('page_cache');
            wphb_clear_module_cache('minify');
            return true;
        }
        return false;
    }
    
    public function get_name() {
        return 'Hummingbird';
    }
}

/**
 * SG Optimizer Cache Strategy
 */
class CPD_SG_Optimizer_Strategy implements CPD_Cache_Strategy {
    public function is_available() {
        return class_exists('SiteGround_Optimizer\\Supercacher\\Supercacher');
    }
    
    public function purge() {
        if ($this->is_available()) {
            $sg_cache = new SiteGround_Optimizer\Supercacher\Supercacher();
            if (method_exists($sg_cache, 'purge_cache')) {
                $sg_cache->purge_cache();
                return true;
            }
        }
        return false;
    }
    
    public function get_name() {
        return 'SG Optimizer';
    }
}

/**
 * Main Cache Manager Class
 */
class CPD_Cache_Manager {
    
    /**
     * Cache strategies
     * @var array
     */
    private $strategies = [];
    
    /**
     * Internal cache storage
     * @var array
     */
    private $internal_cache = [];
    
    /**
     * Constructor - Initialize all cache strategies
     */
    public function __construct() {
        $this->initialize_strategies();
    }
    
    /**
     * Initialize all cache purging strategies
     */
    private function initialize_strategies() {
        $this->strategies = [
            new CPD_WordPress_Cache_Strategy(),
            new CPD_WP_Rocket_Strategy(),
            new CPD_W3TC_Strategy(),
            new CPD_WP_Super_Cache_Strategy(),
            new CPD_LiteSpeed_Strategy(),
            new CPD_WP_Fastest_Cache_Strategy(),
            new CPD_Autoptimize_Strategy(),
            new CPD_WP_Optimize_Strategy(),
            new CPD_Comet_Cache_Strategy(),
            new CPD_Cache_Enabler_Strategy(),
            new CPD_Hummingbird_Strategy(),
            new CPD_SG_Optimizer_Strategy(),
        ];
    }
    
    /**
     * Purge all available caches
     * 
     * @return array Results of purge operations
     */
    public function purge_all_caches() {
        $results = [];
        
        foreach ($this->strategies as $strategy) {
            if ($strategy->is_available()) {
                $success = $strategy->purge();
                $results[$strategy->get_name()] = $success;
            }
        }
        
        // Clear internal plugin cache
        $this->clear_internal_cache();
        
        // Clear plugin transients
        $this->clear_transients();
        
        // Allow other plugins to hook into cache clearing
        do_action('custom_permalink_domain_cache_cleared');
        
        return $results;
    }
    
    /**
     * Clear internal plugin cache
     */
    public function clear_internal_cache() {
        $this->internal_cache = [];
    }
    
    /**
     * Clear plugin-specific transients
     */
    private function clear_transients() {
        delete_transient('custom_permalink_domain_cache');
        delete_transient('custom_permalink_domain_version_check');
    }
    
    /**
     * Get cached value
     * 
     * @param string $key Cache key
     * @return mixed Cached value or null
     */
    public function get($key) {
        return isset($this->internal_cache[$key]) ? $this->internal_cache[$key] : null;
    }
    
    /**
     * Set cached value
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $expiry Expiry time in seconds (optional)
     */
    public function set($key, $value, $expiry = 0) {
        $this->internal_cache[$key] = [
            'value' => $value,
            'expiry' => $expiry > 0 ? time() + $expiry : 0
        ];
    }
    
    /**
     * Check if cache key exists and is not expired
     * 
     * @param string $key Cache key
     * @return bool
     */
    public function has($key) {
        if (!isset($this->internal_cache[$key])) {
            return false;
        }
        
        $cache_item = $this->internal_cache[$key];
        if ($cache_item['expiry'] > 0 && $cache_item['expiry'] < time()) {
            unset($this->internal_cache[$key]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Delete cached value
     * 
     * @param string $key Cache key
     */
    public function delete($key) {
        unset($this->internal_cache[$key]);
    }
    
    /**
     * Get list of available cache plugins
     * 
     * @return array List of available cache plugin names
     */
    public function get_available_cache_plugins() {
        $available = [];
        
        foreach ($this->strategies as $strategy) {
            if ($strategy->is_available()) {
                $available[] = $strategy->get_name();
            }
        }
        
        return $available;
    }
    
    /**
     * Test cache purging for a specific strategy
     * 
     * @param string $strategy_name Strategy name to test
     * @return bool Success status
     */
    public function test_cache_purge($strategy_name) {
        foreach ($this->strategies as $strategy) {
            if ($strategy->get_name() === $strategy_name && $strategy->is_available()) {
                return $strategy->purge();
            }
        }
        
        return false;
    }
}