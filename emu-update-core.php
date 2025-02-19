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
