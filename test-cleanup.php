<?php
/**
 * Test script to verify plugin cleanup functionality
 * 
 * This script can be used to test the uninstall process
 * Run this after installing and configuring the plugin to verify cleanup
 */

// This would normally be called by WordPress during plugin deletion
require_once 'uninstall.php';

echo "Plugin cleanup test completed.\n";
echo "Check your database to verify all custom_permalink_domain* options have been removed.\n";

// You can also run these queries manually in your database to verify cleanup:
echo "\nManual verification queries:\n";
echo "SELECT * FROM wp_options WHERE option_name LIKE 'custom_permalink_domain%';\n";
echo "SELECT * FROM wp_sitemeta WHERE meta_key LIKE 'custom_permalink_domain%'; -- For multisite\n";
?>
