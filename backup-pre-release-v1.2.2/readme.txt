=== Custom Permalink Domain ===
Contributors: gokepelemo
Tags: permalink, domain, CDN, multisite, migration
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Network: true

Change permalink domains without affecting WordPress admin URLs. Fully multisite compatible with network management.

== Description ==

Custom Permalink Domain allows you to serve your WordPress content from a different domain while keeping your admin area accessible from the original domain. This is perfect for CDN integration, domain migration testing, domain mapping using reverse proxies, or performance optimization.

= Key Features =

* Easy admin interface in Settings menu
* Protocol-relative URLs support (//example.com/path format)
* URL validation and sanitization
* Choose which content types to modify
* Non-destructive - preserves admin functionality
* Real-time URL examples
* Proper WordPress security practices
* **Full multisite support with network management**
* **Bulk operations for network administrators**
* **Individual site control or network-wide override**

= Use Cases =

* **CDN Integration**: Serve content from a CDN domain
* **Domain Migration**: Test new domains safely
* **Performance**: Separate content delivery from admin
* **SEO**: Maintain admin access during domain changes
* **Protocol Flexibility**: Use relative URLs for HTTPS/HTTP compatibility

== Installation ==

1. Upload the plugin file to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to Settings → Permalink Domain
4. Enter your new domain and save settings

== Frequently Asked Questions ==

= Will this affect my WordPress admin area? =

No, your WordPress admin, login, and dashboard will continue to work from your original domain.

= Can I use this with a CDN? =

Yes, this is perfect for CDN integration. Enter your CDN domain and your content will be served from there.

= What happens if I deactivate the plugin? =

All URLs will return to normal. Your settings are preserved if you reactivate.

= Will this work with multisite? =

Yes! The plugin is fully multisite compatible with these features:
- Network admin interface for managing all sites
- Individual site configuration when allowed
- Network-wide domain override capability
- Bulk operations for applying settings to all sites
- Automatic setup for new sites in the network

== Screenshots ==

1. Settings page showing domain configuration
2. URL examples showing before/after changes
3. Content type selection options

== Changelog ==

= 1.1.0 =
* Added comprehensive protocol-relative URLs support
* Network-level control for relative URLs in multisite environments
* Performance optimizations: reduced database queries and improved caching
* Enhanced admin context detection for better compatibility
* Improved JavaScript error handling and user experience
* Consolidated network settings retrieval for better performance
* Added smart relative URL conversion with admin area protection
* Better documentation and code organization

= 1.0.2 =
* Fixed WP GraphQL compatibility issues
* Enhanced admin context checks to prevent CORS errors
* Improved wp-json URL filtering to exclude admin requests
* Added specialized REST API filtering for frontend-only contexts
* Better URL pattern matching for wp-admin and wp-json paths
* Maintained all existing functionality while fixing GraphQL conflicts

= 1.0.1 =
* Added full multisite support
* Network admin interface for managing all sites
* Individual site configuration options
* Network-wide domain override capability
* Bulk operations for network administrators

= 1.0.0 =
* Initial release
* Admin interface for domain configuration
* Support for all major content types
* URL validation and security features

== Upgrade Notice ==

= 1.1.0 =
Major feature update: Added comprehensive protocol-relative URLs support with network-level controls and significant performance optimizations.

= 1.0.2 =
Important compatibility fix for WP GraphQL and REST API. Resolves CORS issues while maintaining full functionality.

= 1.0.1 =
Major update adding full multisite support with network management capabilities.
