<?php
/*
Plugin Name: Emu Update Core
Description: Intercepta as atualizações de qualquer plugin e altera a URL de download com base em um JSON remoto.
Version: 1.0.0
Author: Emu Plugins
*/

if (!defined('ABSPATH')) exit;

// Cancela qualquer tentativa de traduzir o plugin

add_action('init', function () {
    load_plugin_textdomain('emu-easy-attribute', false, false);
});

// Sistema de atualização do plugin

$plugin_slug = basename(__DIR__);
if (substr($plugin_slug, -5) === '-main') {
    $plugin_slug = substr($plugin_slug, 0, -5);
}
$self_plugin_dir = basename(__DIR__);

require_once plugin_dir_path(__FILE__) . 'update-handler.php';

new Emu_Updater($plugin_slug, $self_plugin_dir);

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
    'perfmatters/perfmatter.php'
]);

// Função para validar plugins existentes

function validar_plugins_existentes($plugins) {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $todos_plugins = get_plugins();
    $plugins_validos = [];

    foreach ($plugins as $plugin) {
        if (array_key_exists($plugin, $todos_plugins)) {
            $plugins_validos[] = $plugin;
        } else {
            error_log("[Emu Update Core] Plugin não encontrado: $plugin");
        }
    }

    return $plugins_validos;
}

// Execução principal
$plugins_validos = validar_plugins_existentes(PLUGINS_LIST);

foreach ($plugins_validos as $plugin) {
    $plugin_name = dirname($plugin);
    $plugin_file = basename($plugin);
    
    // Verifica se o arquivo principal do plugin existe
    if (file_exists(WP_PLUGIN_DIR . "/$plugin_name/$plugin_file")) {
        // Cria URL específica para cada plugin
        $api_url = 'https://raw.githubusercontent.com/emuplugins/emu-update-list/main/' . $plugin_name . '/info.json';

        // Verificar se há atualizações disponíveis para o plugin
        $update_plugins = get_site_transient('update_plugins');
        
        // Verifica se o plugin tem uma atualização disponível
        if (isset($update_plugins->response[$plugin]) && is_object($update_plugins->response[$plugin])) {
            // Se houver uma atualização disponível, cria o objeto de atualização
            new Emu_Update_Core(
                $plugin_name,
                $plugin_name,
                $plugin_file,
                $api_url // Passa a URL específica
            );
        } else {
            error_log("[Emu Update Core] O plugin $plugin não tem atualização disponível.");
        }
    } else {
        error_log("[Emu Update Core] Arquivo do plugin não encontrado: $plugin_name/$plugin_file");
    }
}

// Força verificação de atualizações
add_action('admin_init', function() {
    if (is_admin()) {
        return;
    }
    wp_update_plugins();
});
