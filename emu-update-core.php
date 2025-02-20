<?php
/*
Plugin Name: Emu Update Core
Description: Intercepts updates of any plugin and changes the download URL based on a remote JSON.
Version: 1.0.0
Author: Emu Plugins
*/

if ( ! defined('ABSPATH')) exit;

// ==============================================================================================================
// INITIALIZE THE UPDATE SYSTEM
// ==============================================================================================================

if (is_admin()) {

    require_once plugin_dir_path(__FILE__) . 'update-handler.php';

    // ==============================================================================================================
    // DEFINE THE PLUGIN LIST TO BE CHECKED
    // ==============================================================================================================

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

    // ==============================================================================================================
    // VALIDATE THE INSTALLED PLUGINS
    // ==============================================================================================================

    function validate_existing_plugins($core_plugins)
    {
        // Check if the 'get_plugins' function is available, if not, load it
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_core_plugins = get_plugins(); // Get all installed plugins
        $valid_core_plugins = []; // Initialize an array to store valid plugins

        // Loop through the plugin list to check if they are installed
        foreach ($core_plugins as $core_plugin) {
            if (array_key_exists($core_plugin, $all_core_plugins)) {
                $valid_core_plugins[] = $core_plugin; // Add valid plugins to the list
            } else {
                // If plugin is not found, log the error
                error_log("[Emu Update Core] Plugin not found: $core_plugin");
            }
        }

        return $valid_core_plugins; // Return the list of valid plugins
    }

    // ==============================================================================================================
    // CHECK AND FORCE PLUGIN UPDATE
    // ==============================================================================================================

    function check_and_force_update($core_plugin)
    {
        // Check if the 'get_plugin_data' function is available, if not, load it
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Get the plugin data and current version
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $core_plugin);
        $current_version = $plugin_data['Version'];

        // Get the stored last update date and the current time
        $last_update = get_option('last_update_' . $core_plugin);
        $current_time = time();

        // Check if an update is available for the plugin
        $update_plugins = get_site_transient('update_plugins');
        if (isset($update_plugins->response[$core_plugin]) && is_object($update_plugins->response[$core_plugin])) {
            $new_version = $update_plugins->response[$core_plugin]->new_version;

            // Compare the current version with the new version
            if (version_compare($current_version, $new_version, '<')) {
                // If the current version is less than the new version, force the update
                $plugin_name = dirname($core_plugin);
                $api_url = 'https://raw.githubusercontent.com/emuplugins/emu-update-list/main/' . $plugin_name . '/info.json';

                // Start the plugin update using the provided URL
                new Emu_Update_Core(
                    $plugin_name,
                    $api_url
                );

                // Check if the update was successful
                $plugin_data_after_update = get_plugin_data(WP_PLUGIN_DIR . '/' . $core_plugin);
                $updated_version = $plugin_data_after_update['Version'];

                // If the version after update is still less than the new version, do not update again
                if (version_compare($updated_version, $new_version, '<')) {

                    // If the last update was within 7 days, don't force another update
                    if ($last_update && ($current_time - $last_update) < 7 * DAY_IN_SECONDS) {
                        return; // Exit the function without updating
                    }
                    
                    // Proceed to update the plugin
                    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                    $upgrader = new Plugin_Upgrader();

                    add_action('admin_head', function() {
                        echo '<style>body > div.wrap {display: none;}</style>';
                    }, 1);

                    $upgrader->upgrade($core_plugin);

                    add_action('admin_head', function() {
                        echo '<style>body > div.wrap {display: none;}</style>';
                    }, 1); 

                    // ===================================================================
                    // VERIFICA E CORRIGE O NOME DO DIRETÓRIO DO PLUGIN SE NECESSÁRIO
                    // ===================================================================
                    $plugins_dir = WP_PLUGIN_DIR; // Caminho base da pasta de plugins
                    $expected_dir_name = dirname($core_plugin); // Nome do diretório esperado (vindo da constante PLUGINS_LIST)
                    $expected_main_file = basename($core_plugin); // Nome do arquivo principal do plugin (vindo da constante PLUGINS_LIST)
                    $expected_path = $plugins_dir . '/' . $expected_dir_name . '/' . $expected_main_file; // Caminho completo esperado

                    // Verifica se o caminho esperado já existe
                    if (!file_exists($expected_path)) {
                        // Procura em todos os diretórios de plugins o arquivo principal do plugin
                        foreach (glob($plugins_dir . '/*', GLOB_ONLYDIR) as $dir) {
                            $current_dir_name = basename($dir); // Nome do diretório atual
                            $main_file_path = $dir . '/' . $expected_main_file; // Caminho do arquivo principal no diretório atual

                            // Se o arquivo principal for encontrado em um diretório com nome diferente
                            if (file_exists($main_file_path) && $current_dir_name !== $expected_dir_name) {
                                $new_dir = $plugins_dir . '/' . $expected_dir_name; // Novo caminho do diretório

                                // Remove o diretório de destino se já existir (segurança adicional)
                                if (file_exists($new_dir)) {
                                    require_once ABSPATH . 'wp-admin/includes/file.php';
                                    WP_Filesystem();
                                    global $wp_filesystem;

                                    if ($wp_filesystem->delete($new_dir, true)) { // Deleta recursivamente
                                        error_log("[Emu Update Core] Removed conflicting directory: $new_dir");
                                    } else {
                                        error_log("[Emu Update Core] Failed to remove directory: $new_dir");
                                        break;
                                    }
                                }

                                // Renomeia o diretório atual para o nome esperado
                                if (rename($dir, $new_dir)) {
                                    error_log("[Emu Update Core] Directory renamed from $current_dir_name to $expected_dir_name");
                                    $core_plugin = $expected_dir_name . '/' . $expected_main_file; // Atualiza o caminho do plugin
                                    wp_clean_plugins_cache(); // Atualiza o cache de plugins
                                } else {
                                    error_log("[Emu Update Core] Failed to rename directory from $current_dir_name to $expected_dir_name");
                                }
                                break;
                            }
                        }
                    }

                    // If the plugin is not active, activate it
                    if (!is_plugin_active($core_plugin)) {
                        activate_plugin($core_plugin);
                        // Update the last update timestamp to the current time
                        update_option('last_update_' . $core_plugin, $current_time);
                    }
                
                } else {
                    // If the plugin was updated successfully, log the success
                    error_log("[Emu Update Core] Plugin $core_plugin successfully updated to version $updated_version");
                }
            }
        }
    }

    // ==============================================================================================================
    // MAIN EXECUTION OF THE PLUGIN
    // ==============================================================================================================

    add_action('admin_init', function () {
        // Validate the plugins in the list
        $valid_core_plugins = validate_existing_plugins(PLUGINS_LIST);

        // For each valid plugin, check and force the update
        foreach ($valid_core_plugins as $core_plugin) {
            check_and_force_update($core_plugin);
        }
    });
}

// Auto check updates

// Adds a custom 7-day interval
add_filter('cron_schedules', function($schedules) {
    $schedules['weekly'] = array(
        'interval' => 7 * 24 * 60 * 60, // 7 days in seconds
        'display'  => __('Every 7 days'),
    );
    return $schedules;
});

// Schedules the cron task every 7 days
if (!wp_next_scheduled('check_plugins_update')) {
    wp_schedule_event(time(), 'weekly', 'check_plugins_update');
}

// Function that will be executed every 7 days
add_action('check_plugins_update', function () {
    // Checks if the current user is an administrator (optional, depending on the logic)
    if (current_user_can('manage_options')) {
        // Executes the validation and update logic
        $valid_core_plugins = validate_existing_plugins(PLUGINS_LIST);
        
        foreach ($valid_core_plugins as $core_plugin) {
            check_and_force_update($core_plugin);
        }
    }
});