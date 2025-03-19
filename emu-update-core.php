<?php
/**
 * Plugin Name: Emu Update Core
 * Description: Handle the update sistem for GPL Plugins and Themes.
 * Version:     1.0.2
 * Author:      Emu Plugins
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: emu-update-core
 * Domain Path: /languages
 */


if (!defined('ABSPATH')) {
    exit;
}

define('PLUGIN_DIR', plugin_dir_path(__FILE__));

function remove_perfmatters_update_message() {
    remove_action('in_plugin_update_message-perfmatters/perfmatters.php', 'perfmatters_plugin_update_message', 10, 2);
}
add_action('init', 'remove_perfmatters_update_message');

if (is_admin()) {
    require_once PLUGIN_DIR . 'update-handler.php';
}

add_filter('site_transient_update_plugins', function($transient) {	

    if (!defined('PLUGINS_LIST')) {
        define('PLUGINS_LIST', [
            'jet-smart-filters/jet-smart-filters.php',
            'jet-engine/jet-engine.php',
            'jet-elements/jet-elements.php',
            'jet-popup/jet-popup.php',
            'jet-tabs/jet-tabs.php',
            'jet-engine-dynamic-tables-module/jet-engine-dynamic-tables-module.php',
            'jet-menu/jet-menu.php',
            'jet-form-builder-login-action/jet-form-builder-login-action.php',
            'jet-theme-core/jet-theme-core.php',
            'perfmatters/perfmatters.php'
        ]);
    }
    if (!function_exists('get_plugins')) return;

    $all_core_plugins = get_plugins();

    foreach (PLUGINS_LIST as $plugin) {

        if (array_key_exists($plugin, $all_core_plugins)) {

            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
            $current_version = $plugin_data['Version'];

            $plugin_slug = dirname($plugin);

            $jsonResponse = get_transient('json_plugin_info');

            if ($jsonResponse === false || !array_key_exists($plugin_slug, $jsonResponse)) {
                $response = wp_remote_get('https://raw.githubusercontent.com/emuplugins/emu-update-list/refs/heads/main/' . $plugin_slug . '/info.json');
            
                if (!is_wp_error($response)) {
                    $json_body = wp_remote_retrieve_body($response);
                    $data = json_decode($json_body, true);
            
                    $existing_jsonResponse = get_transient('json_plugin_info');
            
                    if ($existing_jsonResponse === false) {
                        $existing_jsonResponse = [];
                    }
            
                    $existing_jsonResponse[$plugin_slug] = $data ? $data : 'invalidJson';
            
                    set_transient('json_plugin_info', $existing_jsonResponse, 12 * HOUR_IN_SECONDS);
                }
            }
            
        }
    }

    foreach (PLUGINS_LIST as $plugin) {
        $plugin_slug = dirname($plugin);

        $jsonResponse = get_transient('json_plugin_info');

        if (isset($jsonResponse[$plugin_slug])) {
            $data = $jsonResponse[$plugin_slug];

            if (!empty($data) && isset($transient->response)) {
                foreach ($transient->response as $plugin_slug_in_transient => $plugin_info) {

                    if (strpos($plugin_slug_in_transient, basename($plugin)) !== false) {
                        
                        if ($data['version'] === $current_version) {
                            unset($transient->response[$plugin_slug_in_transient]); 
                        } else {
                            $plugin_info->package = $data['download_url'];
                            $plugin_info->new_version = $data['version'];
                            $plugin_info->url = $data['author_homepage'];
                        }
                    }
                }
            }
        }
    }    

    return $transient;

});