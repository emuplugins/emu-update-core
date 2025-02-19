<?php
/*
Plugin Name: Emu Update Core
Description: Intercepts updates of any plugin and changes the download URL based on a remote JSON.
Version: 1.0.0
Author: Emu Plugins
*/

if (!defined('ABSPATH')) exit;

require_once 'update-handler.php';

define('PLUGINS_LIST', [
    'jet-smart-filters/jet-smart-filters.php',
    'jet-engine/jet-engine.php',
    'jet-elements/jet-elements.php',
    'jet-popup/jet-popup.php',
    'jet-tabs/jet-tabs.php',
    'jet-engine-dynamic-tables-module/jet-engine-dynamic-tables-module.php',
    'jet-menu/jet-menu.php',
    'jet-form-builder-login-action/jet-form-builder-login-action.php',
    'jet-woo-product-gallery/jet-woo-product-gallery.php',
    'perfmatters/perfmatter.php',
    'jet-search/jet-search.php',
    'jet-theme-core/jet-theme-core.php'
]);

// Function to validate existing plugins
function validate_existing_plugins($core_plugins) {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_core_plugins = get_plugins();
    $valid_core_plugins = [];

    foreach ($core_plugins as $core_plugin) {
        if (array_key_exists($core_plugin, $all_core_plugins)) {
            $valid_core_plugins[] = $core_plugin;
        } else {
            error_log("[Emu Update Core] Plugin not found: $core_plugin");
        }
    }

    return $valid_core_plugins;
}

// Function to check and force the plugin update
function check_and_force_update($core_plugin) {
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $core_plugin);
    $current_version = $plugin_data['Version'];

    $update_plugins = get_site_transient('update_plugins');
    if (isset($update_plugins->response[$core_plugin]) && is_object($update_plugins->response[$core_plugin])) {
        $new_version = $update_plugins->response[$core_plugin]->new_version;

        if (version_compare($current_version, $new_version, '<')) {
            // If the current version is less than the new version, force update
            $plugin_name = dirname($core_plugin);
            $api_url = 'https://raw.githubusercontent.com/emuplugins/emu-update-list/main/' . $plugin_name . '/info.json';

            new Emu_Update_Core(
                $plugin_name,
                $api_url
            );

            // Check if the update was successful
            $plugin_data_after_update = get_plugin_data(WP_PLUGIN_DIR . '/' . $core_plugin);
            $updated_version = $plugin_data_after_update['Version'];

            if (version_compare($updated_version, $new_version, '<')) {
                error_log("[Emu Update Core] Failed to update plugin $core_plugin. Current version: $updated_version, Expected version: $new_version");
                
                
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            
                $upgrader = new Plugin_Upgrader();
                $upgrader->upgrade($core_plugin);
                if (!is_plugin_active($core_plugin)) {
                    activate_plugin($core_plugin);
                }
                
            } else {
                error_log("[Emu Update Core] Plugin $core_plugin updated successfully to version $updated_version");
            }
        }
        
    }
}

// Main execution
add_action('admin_init', function() {
    $valid_core_plugins = validate_existing_plugins(PLUGINS_LIST);

    foreach ($valid_core_plugins as $core_plugin) {
        check_and_force_update($core_plugin);
    }
});
