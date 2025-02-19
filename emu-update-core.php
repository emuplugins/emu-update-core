<?php
/*
Plugin Name: Emu Update Core
Description: Intercepta as atualizações de qualquer plugin e altera a URL de download com base em um JSON remoto.
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
    'jet-search/jet-search.php'
]);

// Função para validar plugins existentes
function validar_plugins_existentes($plugins_core) {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $todos_plugins_core = get_plugins();
    $plugins_validos_core = [];

    foreach ($plugins_core as $plugin_core) {
        if (array_key_exists($plugin_core, $todos_plugins_core)) {
            $plugins_validos_core[] = $plugin_core;
        } else {
            error_log("[Emu Update Core] Plugin não encontrado: $plugin_core");
        }
    }

    return $plugins_validos_core;
}

// Função para verificar e forçar a atualização do plugin
function verificar_e_forcar_atualizacao($plugin_core) {
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_core);
    $current_version = $plugin_data['Version'];

    $update_plugins_core = get_site_transient('update_plugins');
    if (isset($update_plugins_core->response[$plugin_core]) && is_object($update_plugins_core->response[$plugin_core])) {
        $new_version = $update_plugins_core->response[$plugin_core]->new_version;

        if (version_compare($current_version, $new_version, '<')) {
            // Se a versão atual for menor que a nova versão, forçar a atualização
            $plugin_name_core = dirname($plugin_core);
            $api_url_core = 'https://raw.githubusercontent.com/emuplugins/emu-update-list/main/' . $plugin_name_core . '/info.json';

            new Emu_Update_Core(
                $plugin_name_core,
                $api_url_core
            );

            // Verificar se a atualização foi bem-sucedida
            $plugin_data_after_update = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_core);
            $updated_version = $plugin_data_after_update['Version'];

            if (version_compare($updated_version, $new_version, '<')) {
                error_log("[Emu Update Core] Falha ao atualizar o plugin $plugin_core. Versão atual: $updated_version, Versão esperada: $new_version");
                
                
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            
                $upgrader = new Plugin_Upgrader();
                $upgrader->upgrade($plugin_core);
                if (!is_plugin_active($plugin_core)) {
                    activate_plugin($plugin_core);
                }
                
            } else {
                error_log("[Emu Update Core] Plugin $plugin_core atualizado com sucesso para a versão $updated_version");
            }
        }
        
    }
}

// Execução principal
add_action('admin_init', function() {
    $plugins_validos_core = validar_plugins_existentes(PLUGINS_LIST);

    foreach ($plugins_validos_core as $plugin_core) {
        verificar_e_forcar_atualizacao($plugin_core);
    }
});