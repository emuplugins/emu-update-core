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
    if (!function_exists('get_plugins')) return;

    $all_core_plugins = get_plugins();

    // Percorre a lista de plugins
    foreach (PLUGINS_LIST as $plugin) {

        // Verifica se o plugin está instalado
        if (array_key_exists($plugin, $all_core_plugins)) {

            // Obtém a versão atual do plugin instalado
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
            $current_version = $plugin_data['Version']; // Versão atual instalada

            // Obtém o slug do plugin (sem a extensão .php)
            $plugin_slug = dirname($plugin);

            // Verifica se a resposta já foi salva para este plugin
            $jsonResponse = get_transient('json_plugin_info');

            if ($jsonResponse === false || !array_key_exists($plugin_slug, $jsonResponse)) {
                // Realiza a requisição HTTP para obter informações sobre o plugin
                $response = wp_remote_get('https://raw.githubusercontent.com/emuplugins/emu-update-list/refs/heads/main/' . $plugin_slug . '/info.json');
            
                if (!is_wp_error($response)) {
                    // Decodifica a resposta JSON
                    $json_body = wp_remote_retrieve_body($response);
                    $data = json_decode($json_body, true);
            
                    // Recupera o valor atual do transient (se existir)
                    $existing_jsonResponse = get_transient('json_plugin_info');
            
                    // Se não houver dados existentes, cria um novo array
                    if ($existing_jsonResponse === false) {
                        $existing_jsonResponse = [];
                    }
            
                    // Adiciona ou atualiza a entrada no array
                    $existing_jsonResponse[$plugin_slug] = $data ? $data : 'invalidJson';
            
                    // Salva novamente no cache (12 horas)
                    set_transient('json_plugin_info', $existing_jsonResponse, 12 * HOUR_IN_SECONDS);
                }
            }
            
        }
    }

    // Agora, manipula o transient de atualização de plugins
    foreach (PLUGINS_LIST as $plugin) {
        $plugin_slug = dirname($plugin);

        $jsonResponse = get_transient('json_plugin_info');

        if (isset($jsonResponse[$plugin_slug])) {
            $data = $jsonResponse[$plugin_slug];

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


delete_transient('json_plugin_info');

delete_site_transient('update_plugins');