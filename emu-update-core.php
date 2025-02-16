<?php
/*
Plugin Name: Emu Update Core
Description: Intercepta as atualizações de qualquer plugin e altera a URL de download com base em um JSON remoto.
Version: 0.1.0
Author: Seu Nome
*/

if (!defined('ABSPATH')) exit;

require_once 'update_handler.php';

//self
$plugin_slug = basename(__DIR__);  // Diretório do plugin
if (substr($plugin_slug, -5) === '-main') {
    $plugin_slug = substr($plugin_slug, 0, -5); // Remove o sufixo '-main'
}
$self_plugin_dir = basename(__DIR__); // Mantemos o diretório original para referência

// Lista de plugins que você deseja verificar (DEVE corresponder EXATAMENTE ao caminho do plugin)
define('PLUGINS_LIST', [
    'jet-smart-filters/jet-smart-filters.php',
    'jet-engine/jet-engine.php',
    'jet-elements/jet-elements.php',
    'jet-popup/jet-popup.php',
    'jet-tabs/jet-tabs.php'
]);

// Função para validar se os plugins da lista existem
function validar_plugins_existentes($plugins) {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $todos_plugins = get_plugins(); // Obtém todos os plugins instalados (ativos ou não)
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

// ========== EXECUÇÃO PRINCIPAL ========== //
$plugins_validos = validar_plugins_existentes(PLUGINS_LIST);

// Cria instâncias da classe Emu_Update_Core para todos os plugins válidos
foreach ($plugins_validos as $plugin) {
    $plugin_name = dirname($plugin); // Extrai o diretório do plugin
    new Emu_Update_Core(
        $plugin_name,       // Nome do plugin (ex: jet-smart-filters)
        $plugin_name,       // Diretório do plugin
        basename($plugin)   // Arquivo principal (ex: jet-smart-filters.php)
    );
}

// Força a verificação de atualizações após registrar todos os hooks
add_action('admin_init', function() {
    wp_update_plugins();
});