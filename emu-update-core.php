<?php
/*
Plugin Name: Emu Update Core
Description: Intercepta as atualizações de qualquer plugin e altera a URL de download com base em um JSON remoto.
Version: 0.1.0
Author: Seu Nome
*/

if (!defined('ABSPATH')) exit;

require_once 'update_handler.php';

// Configuração do auto-update para o próprio plugin
$plugin_slug = basename(__DIR__);
if (substr($plugin_slug, -5) === '-main') {
    $plugin_slug = substr($plugin_slug, 0, -5);
}
$self_plugin_dir = basename(__DIR__);
new Emu_Updater($plugin_slug, $self_plugin_dir);

// Lista de plugins para verificar atualizações
define('PLUGINS_LIST', [
    'jet-smart-filters/jet-smart-filters.php',
    'jet-engine/jet-engine.php',
    'jet-elements/jet-elements.php',
    'jet-popup/jet-popup.php',
    'jet-tabs/jet-tabs.php'
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
        
        new Emu_Update_Core(
            $plugin_name,
            $plugin_name,
            $plugin_file,
            $api_url // Passa a URL específica
        );
    } else {
        error_log("[Emu Update Core] Arquivo do plugin não encontrado: $plugin_name/$plugin_file");
    }
}

// Força verificação de atualizações
add_action('admin_init', function() {
    wp_update_plugins();
});