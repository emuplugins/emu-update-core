<?php
/*
Plugin Name: Emu Update Core
Description: Intercepta as atualizações de qualquer plugin e altera a URL de download com base em um JSON remoto.
Version: 0.1
Author: Seu Nome
*/

if (!defined('ABSPATH')) exit;


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

// Função para verificar atualizações
function forcar_verificar_atualizacao_plugins($plugins) {
    // Força a verificação de atualizações
    wp_update_plugins();

    // Obtém o transient de atualizações
    $updates = get_site_transient('update_plugins');

    // DEBUG: Verifique o conteúdo do transient
    error_log("[Emu Update Core] Transient update_plugins: " . print_r($updates, true));

    $plugins_com_atualizacao = [];

    if (!empty($updates->response)) {
        foreach ($updates->response as $plugin_file => $update_info) {
            if (in_array($plugin_file, $plugins)) {
                $plugins_com_atualizacao[] = $plugin_file;
            }
        }
    }

    return $plugins_com_atualizacao;
}

// ========== EXECUÇÃO PRINCIPAL ========== //
$plugins_validos = validar_plugins_existentes(PLUGINS_LIST);

$plugins_atualizaveis = forcar_verificar_atualizacao_plugins($plugins_validos);
require_once 'update_handler.php';
// Se houver atualizações, processe-as
if (!empty($plugins_atualizaveis)) {
    

    foreach ($plugins_atualizaveis as $plugin) {
        $plugin_name = dirname($plugin); // Extrai o diretório do plugin
        new Emu_Update_Core(
            $plugin_name,       // Nome do plugin (ex: jet-smart-filters)
            $plugin_name,       // Diretório do plugin
            basename($plugin)   // Arquivo principal (ex: jet-smart-filters.php)
        );
    }
}


