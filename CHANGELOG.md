# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.5] - 2025-09-21

### Added

- Comprehensive PHPDoc throughout entire codebase with detailed class descriptions, method signatures, usage examples, and best practices
- Architecture analysis with thorough review of modularity and performance with specific improvement recommendations for future releases
- Enhanced class documentation for all main classes (CustomPermalinkDomain, CPD_Options_Manager, CPD_Cache_Manager, CPD_URL_Transformer) with architecture details and performance notes
- Detailed documentation to multisite utilities and uninstall logic with preservation matrix explanations

### Enhanced

- Code quality significantly improved through comprehensive inline documentation and architectural insights

## [1.3.4] - 2025-09-20

### Fixed

- Dynamic property warnings by adding proper declarations for all cache properties (`$content_types_cache`, `$custom_domain_cache`, `$network_settings_cache`, `$relative_urls_cache`) to resolve PHP 8.2+ deprecation warnings
- Activation safety check to verify no regressions in activation hooks and preservation logic initialization

### Enhanced

- Code quality through enhanced PHPDoc comments for better code documentation

## [1.3.3] - 2025-09-14

### Enhanced

- Network preservation logic: When "Preserve network settings on uninstall" is enabled at the network level, site-level settings are now preserved by default unless explicitly opted out at the site level
- UI descriptions: Site-level preserve option now shows contextual descriptions based on network settings to clarify inheritance behavior

### Fixed

- Button alignment: Fixed visual alignment issues between "Save Network Settings" and "Test URL Changes" buttons

## [1.3.2] - 2025-09-14

### Fixed

- Critical fix: Resolved settings persistence issues where network and site settings weren't being saved properly
- Enhanced activation: Improved plugin activation to ensure all settings are properly initialized

### Added

- Debug functionality: Added troubleshooting capabilities accessible via ?cpd_debug=1 parameter

## [1.3.1] - 2025-09-14

### Fixed

- Package fix: Resolved missing /includes/ directory in release packages
- Release automation: Enhanced automated release script with better cleanup and validation

## [1.3.0] - 2025-09-14

### Changed

- Major refactoring: Decomposed monolithic class into specialized components (URL Transformer, Cache Manager, Options Manager)

### Enhanced

- Performance improvements: Optimized database queries with batch retrieval and caching
- Enhanced cache support: Added support for 12 cache plugins with unified management
- CSS optimization: Reduced !important declarations and improved responsive design

## [1.1.0] - Previous Release

### Added

- Comprehensive protocol-relative URLs support
- Network-level control for relative URLs in multisite environments
- Performance optimizations: reduced database queries and improved caching
- Enhanced admin context detection for better compatibility
- Improved JavaScript error handling and user experience
- Consolidated network settings retrieval for better performance
- Smart relative URL conversion with admin area protection
- Better documentation and code organization

## [1.0.2] - Previous Release

### Fixed

- WP GraphQL compatibility issues
- Enhanced admin context checks to prevent CORS errors
- Improved wp-json URL filtering to exclude admin requests
- Maintained all existing functionality while fixing GraphQL conflicts

## [1.0.1] - Previous Release

### Added

- Full multisite support
- Network admin interface for managing all sites
- Individual site configuration options
- Network-wide domain override capability
- Bulk operations for network administrators

## [1.0.0] - Initial Release

### Added

- Initial release
- Admin interface for domain configuration
- Support for all major content types
- URL validation and security features