<?php
/**
 * Plugin Name: Emu Update Core
 * Description: Handle the update sistem for GPL Plugins and Themes.
 * Version:     1.0.0
 * Author:      Emu Plugins
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: emu-update-core
 * Domain Path: /languages
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}
define('PLUGIN_DIR', plugin_dir_path(__FILE__));

if (is_admin()) {
    require_once PLUGIN_DIR . 'update-handler.php';
}

delete_site_transient('update_plugins'); 

add_filter('site_transient_update_plugins', function($transient) {
    
    // Definindo a lista de plugins
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

    $all_core_plugins = get_plugins();

    // Percorre a lista de plugins
    foreach (PLUGINS_LIST as $plugin) {

        // Verifica se o plugin está instalado
        if (!array_key_exists($plugin, $all_core_plugins)) continue;

        // Obtém a versão atual do plugin instalado
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
        $current_version = $plugin_data['Version']; // Versão atual instalada

        // Obtém o slug do plugin (sem a extensão .php)
        $plugin_slug = str_replace('.php', '', $plugin);
        $plugin_slug = explode('/', $plugin_slug)[0];

        // Obtém o JSON de informações sobre o plugin
        $response = wp_remote_get('https://raw.githubusercontent.com/emuplugins/emu-update-list/main/'.$plugin_slug.'/info.json?rand=' . rand(), [
            'headers' => [
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            ]
        ]);
        
        if (!is_wp_error($response)) {
            // Decodifica o corpo da resposta JSON
            $json_body = wp_remote_retrieve_body($response);
            $data = json_decode($json_body, true);

            // Se o JSON contiver as informações do plugin
            if (!empty($data) && isset($transient->response)) {
                // Itera sobre as respostas de plugins para encontrar o plugin específico
                foreach ($transient->response as $plugin_slug_in_transient => $plugin_info) {

                    // Verifica se o slug do plugin corresponde
                    if (strpos($plugin_slug_in_transient, basename($plugin)) !== false) {
                        
                        // Se as versões forem iguais, não atualiza
                        if ($data['version'] === $current_version) {
                            unset($transient->response[$plugin_slug_in_transient]); // Remove a atualização do transient
                        } else {
                            // Caso contrário, atualiza os dados com o JSON
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
