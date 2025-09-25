<?php
/**
 * Service Locator Pattern Implementation for Custom Permalink Domain Plugin
 *
 * Centralized service container that manages all plugin dependencies and services.
 * Implements lazy loading, dependency injection, and service lifecycle management.
 * This pattern improves testability, maintainability, and decoupling of components.
 *
 * Features:
 * - Singleton service management
 * - Lazy loading of services
 * - Service factory registration
 * - Dependency injection support
 * - Service lifecycle hooks
 * - Configuration management
 * - Testing support with mock services
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

class CPD_Service_Locator {

    /**
     * Single instance of the service locator
     * @var CPD_Service_Locator
     */
    private static $instance = null;

    /**
     * Service instances cache
     * @var array
     */
    private $services = [];

    /**
     * Service factory callbacks
     * @var array
     */
    private $factories = [];

    /**
     * Service configuration
     * @var array
     */
    private $config = [];

    /**
     * Service aliases
     * @var array
     */
    private $aliases = [];

    /**
     * Service lifecycle hooks
     * @var array
     */
    private $hooks = [];

    /**
     * Testing mode flag
     * @var bool
     */
    private $testing_mode = false;

    /**
     * Get singleton instance
     *
     * @return CPD_Service_Locator
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register_core_services();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        $this->testing_mode = defined('CPD_PLUGIN_TESTING') && CPD_PLUGIN_TESTING;
    }

    /**
     * Register core plugin services
     */
    private function register_core_services() {
        // Register service factories
        $this->register_factory('logger', function() {
            require_once 'class-cpd-logger.php';
            return CPD_Logger::class; // Static class, return class name
        });

        $this->register_factory('options', function($locator) {
            if (!class_exists('CPD_Options_Manager')) {
                require_once 'class-cpd-options-manager.php';
            }
            return new CPD_Options_Manager();
        });

        $this->register_factory('cache', function($locator) {
            if (!class_exists('CPD_Cache_Manager')) {
                require_once 'class-cpd-cache-manager.php';
            }
            return new CPD_Cache_Manager();
        });

        $this->register_factory('url_transformer', function($locator) {
            if (!class_exists('CPD_URL_Transformer')) {
                require_once 'class-cpd-url-transformer.php';
            }
            $options = $locator->get('options');
            return new CPD_URL_Transformer($options);
        });

        $this->register_factory('admin', function($locator) {
            require_once 'class-cpd-admin.php';
            return new CPD_Admin($locator);
        });

        // Register aliases for convenience
        $this->add_alias('log', 'logger');
        $this->add_alias('opts', 'options');
        $this->add_alias('transformer', 'url_transformer');
    }

    /**
     * Register a service factory
     *
     * @param string   $service_name Service identifier
     * @param callable $factory      Factory function that creates the service
     * @param array    $config       Optional service configuration
     */
    public function register_factory($service_name, callable $factory, array $config = []) {
        $this->factories[$service_name] = $factory;
        $this->config[$service_name] = $config;

        CPD_Logger::debug("Service factory registered: {$service_name}", [
            'service' => $service_name,
            'config' => $config
        ]);
    }

    /**
     * Register a singleton service instance
     *
     * @param string $service_name Service identifier
     * @param mixed  $instance     Service instance
     */
    public function register_instance($service_name, $instance) {
        $this->services[$service_name] = $instance;

        CPD_Logger::debug("Service instance registered: {$service_name}", [
            'service' => $service_name,
            'class' => is_object($instance) ? get_class($instance) : gettype($instance)
        ]);
    }

    /**
     * Get a service by name
     *
     * @param string $service_name Service identifier
     * @return mixed Service instance
     * @throws Exception If service not found or creation failed
     */
    public function get($service_name) {
        // Resolve alias
        $actual_name = $this->resolve_alias($service_name);

        // Return cached instance if available
        if (isset($this->services[$actual_name])) {
            return $this->services[$actual_name];
        }

        // Create service if factory exists
        if (isset($this->factories[$actual_name])) {
            return $this->create_service($actual_name);
        }

        throw new Exception("Service '{$service_name}' not found");
    }

    /**
     * Check if service is available
     *
     * @param string $service_name Service identifier
     * @return bool
     */
    public function has($service_name) {
        $actual_name = $this->resolve_alias($service_name);
        return isset($this->services[$actual_name]) || isset($this->factories[$actual_name]);
    }

    /**
     * Create a service instance
     *
     * @param string $service_name Service identifier
     * @return mixed Service instance
     */
    private function create_service($service_name) {
        $this->fire_hook('before_create', $service_name);

        try {
            $factory = $this->factories[$service_name];
            $config = $this->config[$service_name] ?? [];

            // Call factory function
            $service = $factory($this, $config);

            // Cache the instance
            $this->services[$service_name] = $service;

            CPD_Logger::debug("Service created: {$service_name}", [
                'service' => $service_name,
                'class' => is_object($service) ? get_class($service) : gettype($service)
            ]);

            $this->fire_hook('after_create', $service_name, $service);

            return $service;

        } catch (Exception $e) {
            CPD_Logger::error("Failed to create service: {$service_name}", [
                'service' => $service_name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->fire_hook('create_failed', $service_name, $e);
            throw $e;
        }
    }

    /**
     * Add service alias
     *
     * @param string $alias   Alias name
     * @param string $service Target service name
     */
    public function add_alias($alias, $service) {
        $this->aliases[$alias] = $service;
    }

    /**
     * Resolve service alias
     *
     * @param string $name Service name or alias
     * @return string Actual service name
     */
    private function resolve_alias($name) {
        return $this->aliases[$name] ?? $name;
    }

    /**
     * Add lifecycle hook
     *
     * @param string   $event    Hook event (before_create, after_create, create_failed)
     * @param callable $callback Hook callback
     */
    public function add_hook($event, callable $callback) {
        if (!isset($this->hooks[$event])) {
            $this->hooks[$event] = [];
        }
        $this->hooks[$event][] = $callback;
    }

    /**
     * Fire lifecycle hook
     *
     * @param string $event Hook event
     * @param mixed  ...$args Hook arguments
     */
    private function fire_hook($event, ...$args) {
        if (!isset($this->hooks[$event])) {
            return;
        }

        foreach ($this->hooks[$event] as $callback) {
            try {
                call_user_func_array($callback, $args);
            } catch (Exception $e) {
                CPD_Logger::warning("Hook callback failed: {$event}", [
                    'event' => $event,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get all registered service names
     *
     * @return array Service names
     */
    public function get_service_names() {
        return array_unique(array_merge(
            array_keys($this->services),
            array_keys($this->factories)
        ));
    }

    /**
     * Get service configuration
     *
     * @param string $service_name Service identifier
     * @return array Service configuration
     */
    public function get_config($service_name) {
        $actual_name = $this->resolve_alias($service_name);
        return $this->config[$actual_name] ?? [];
    }

    /**
     * Update service configuration
     *
     * @param string $service_name Service identifier
     * @param array  $config       New configuration
     */
    public function update_config($service_name, array $config) {
        $actual_name = $this->resolve_alias($service_name);
        $this->config[$actual_name] = array_merge(
            $this->config[$actual_name] ?? [],
            $config
        );

        // If service is already created and has a configure method, call it
        if (isset($this->services[$actual_name])) {
            $service = $this->services[$actual_name];
            if (is_object($service) && method_exists($service, 'configure')) {
                $service->configure($this->config[$actual_name]);
            }
        }
    }

    /**
     * Reset service (force recreation on next access)
     *
     * @param string $service_name Service identifier
     */
    public function reset($service_name) {
        $actual_name = $this->resolve_alias($service_name);

        if (isset($this->services[$actual_name])) {
            $service = $this->services[$actual_name];

            // Call cleanup method if available
            if (is_object($service) && method_exists($service, 'cleanup')) {
                $service->cleanup();
            }

            unset($this->services[$actual_name]);

            CPD_Logger::debug("Service reset: {$service_name}");
        }
    }

    /**
     * Reset all services
     */
    public function reset_all() {
        foreach (array_keys($this->services) as $service_name) {
            $this->reset($service_name);
        }
    }

    /**
     * Enable testing mode with mock services
     */
    public function enable_testing_mode() {
        $this->testing_mode = true;
        $this->reset_all();
    }

    /**
     * Disable testing mode
     */
    public function disable_testing_mode() {
        $this->testing_mode = false;
        $this->reset_all();
    }

    /**
     * Register mock service for testing
     *
     * @param string $service_name Service identifier
     * @param mixed  $mock_instance Mock instance
     */
    public function register_mock($service_name, $mock_instance) {
        if (!$this->testing_mode) {
            throw new Exception('Mock services can only be registered in testing mode');
        }

        $this->services[$service_name] = $mock_instance;

        CPD_Logger::debug("Mock service registered: {$service_name}", [
            'service' => $service_name,
            'testing_mode' => true
        ]);
    }

    /**
     * Get service statistics
     *
     * @return array Service statistics
     */
    public function get_statistics() {
        return [
            'total_services' => count($this->get_service_names()),
            'instantiated_services' => count($this->services),
            'registered_factories' => count($this->factories),
            'aliases' => count($this->aliases),
            'testing_mode' => $this->testing_mode,
            'memory_usage' => memory_get_usage(true),
            'services' => [
                'instantiated' => array_keys($this->services),
                'factories' => array_keys($this->factories),
                'aliases' => $this->aliases
            ]
        ];
    }

    /**
     * Export configuration for debugging
     *
     * @return array Complete service locator configuration
     */
    public function export_config() {
        return [
            'services' => array_map(function($service) {
                return is_object($service) ? get_class($service) : gettype($service);
            }, $this->services),
            'factories' => array_keys($this->factories),
            'config' => $this->config,
            'aliases' => $this->aliases,
            'hooks' => array_map('count', $this->hooks),
            'testing_mode' => $this->testing_mode
        ];
    }

    /**
     * Create child locator with inherited services
     *
     * @return CPD_Service_Locator Child service locator
     */
    public function create_child() {
        $child = new self();
        $child->factories = $this->factories;
        $child->config = $this->config;
        $child->aliases = $this->aliases;
        $child->testing_mode = $this->testing_mode;

        return $child;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {}

    /**
     * Cleanup on shutdown
     */
    public function __destruct() {
        $this->reset_all();
    }
}

// Convenience functions for global access
if (!function_exists('cpd_service')) {
    /**
     * Get service from global service locator
     *
     * @param string $service_name Service identifier
     * @return mixed Service instance
     */
    function cpd_service($service_name) {
        return CPD_Service_Locator::get_instance()->get($service_name);
    }
}

if (!function_exists('cpd_has_service')) {
    /**
     * Check if service is available in global service locator
     *
     * @param string $service_name Service identifier
     * @return bool
     */
    function cpd_has_service($service_name) {
        return CPD_Service_Locator::get_instance()->has($service_name);
    }
}