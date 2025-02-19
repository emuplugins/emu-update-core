<?php
/*
Plugin Name: Emu Update Core
Description: Intercepta as atualizações de qualquer plugin e altera a URL de download com base em um JSON remoto.
Version: 1.0.0
Author: Emu Plugins
*/

if (!defined('ABSPATH')) exit;

add_action('admin_init', function() {
    // Forçar a verificação de atualizações para todos os plugins
    if (function_exists('get_plugin_updates')) {
        get_plugin_updates(); // Verifica se há atualizações para todos os plugins
    }
});
require_once 'update-handler.php';


// Interceptar atualizações de terceiros

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

// Execução principal
$plugins_validos_core = validar_plugins_existentes(PLUGINS_LIST);

foreach ($plugins_validos_core as $plugin_core) {
    $plugin_name_core = dirname($plugin_core);
    $plugin_file_core = basename($plugin_core);
    
    // Verifica se o arquivo principal do plugin existe
    if (file_exists(WP_PLUGIN_DIR . "/$plugin_name_core/$plugin_file_core")) {
        // Cria URL específica para cada plugin
        $api_url_core = 'https://raw.githubusercontent.com/emuplugins/emu-update-list/main/' . $plugin_name_core . '/info.json';

        // Verificar se há atualizações disponíveis para o plugin
        $update_plugins_core = get_site_transient('update_plugins');
        
        // Verifica se o plugin tem uma atualização disponível
        if (isset($update_plugins_core->response[$plugin_core]) && is_object($update_plugins_core->response[$plugin_core])) {
            // Se houver uma atualização disponível, cria o objeto de atualização
            new Emu_Update_Core(
                $plugin_name_core,
                $api_url_core // Passa a URL específica
            );
        } else {
            error_log("[Emu Update Core] O plugin $plugin_core não tem atualização disponível.");
        }
    } else {
        error_log("[Emu Update Core] Arquivo do plugin não encontrado: $plugin_name_core/$plugin_file_core");
    }
}
