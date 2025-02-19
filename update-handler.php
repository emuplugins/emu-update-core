<?php

if (!defined('ABSPATH')) exit;

class Emu_Update_Core {
    // Classe vazia, se necessário
}

class Emu_Updater {
    private $api_url;
    private $plugin_slug;

    public function __construct($plugin_slug) {
        $this->plugin_slug = $plugin_slug;
        $this->api_url     = 'https://raw.githubusercontent.com/emuplugins/' . $this->plugin_slug . '/main/info.json';

        add_filter('site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_details'], 10, 3);
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) return $transient;

        $transient_key = 'emu_updater_' . $this->plugin_slug;
        $update_info = get_transient($transient_key);

        if (!$update_info) {
            $response = wp_remote_get($this->api_url);
            if (is_wp_error($response)) return $transient;

            $update_info = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($update_info) || empty($update_info['version'])) return $transient;

            set_transient($transient_key, $update_info, HOUR_IN_SECONDS);
        }

        // Use o caminho correto do plugin para recuperar a versão atual
        $plugin_file = $this->plugin_slug . '/' . $this->plugin_slug . '.php';
        $current_version = isset($transient->checked[$plugin_file]) ? $transient->checked[$plugin_file] : '0.0.0';

        if (version_compare($update_info['version'], $current_version, '>')) {
            $transient->response[$plugin_file] = (object) [
                'slug'        => $update_info['slug'],
                'new_version' => $update_info['version'],
                'package'     => $update_info['download_url'],
                'url'         => $update_info['author_homepage']
            ];
        }

        return $transient;
    }

    public function plugin_details($result, $action, $args) {
        if ($args->slug !== $this->plugin_slug) return $result;

        $transient_key = 'emu_updater_' . $this->plugin_slug;
        $update_info = get_transient($transient_key);

        if (!$update_info) {
            $response = wp_remote_get($this->api_url);
            if (is_wp_error($response)) return $result;

            $update_info = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($update_info) || empty($update_info['version'])) return $result;

            set_transient($transient_key, $update_info, HOUR_IN_SECONDS);
        }

        return (object) [
            'name'          => $update_info['name'],
            'slug'          => $update_info['slug'],
            'version'       => $update_info['version'],
            'author'        => '<a href="' . esc_url($update_info['author_homepage']) . '">' . esc_html($update_info['author']) . '</a>',
            'download_link' => $update_info['download_url'],
            'last_updated'  => $update_info['last_updated'],
            'tested'        => $update_info['tested'],
            'requires'      => $update_info['requires'],
            'sections'      => $update_info['sections']
        ];
    }
}

$plugin_dir_unsanitized = basename(__DIR__);
$plugin_slug = $plugin_dir_unsanitized;
if (substr($plugin_slug, -5) === '-main') {
    $plugin_slug = substr($plugin_slug, 0, -5);
}

$desired_plugin_dir = $plugin_slug;
$self_plugin_dir = $plugin_dir_unsanitized;

new Emu_Updater($plugin_slug);

// Exibe o link de "Verificar Atualizações" na listagem de plugins
add_filter('plugin_action_links_' . $self_plugin_dir . '/' . $plugin_slug . '.php', function($actions) use ($self_plugin_dir) {
    $url = wp_nonce_url(admin_url("plugins.php?force-check-update=$self_plugin_dir"), "force_check_update_$self_plugin_dir");
    $actions['check_update'] = '<a href="' . esc_url($url) . '">Verificar Atualizações</a>';
    return $actions;
});

// Após instalação/atualização, move o plugin para o diretório desejado
add_filter('upgrader_post_install', function($response, $hook_extra, $result) use ($desired_plugin_dir) {
    global $wp_filesystem;
    
    $proper_destination = WP_PLUGIN_DIR . '/' . $desired_plugin_dir;
    $current_destination = $result['destination'];
    
    if ($current_destination !== $proper_destination) {
        $wp_filesystem->move($current_destination, $proper_destination);
        $result['destination'] = $proper_destination;
    }
    
    return $response;
}, 10, 3);

add_action('upgrader_process_complete', function($upgrader_object, $options) use ($self_plugin_dir, $desired_plugin_dir, $plugin_slug) {
    $current_plugin_file = $self_plugin_dir . '/' . $plugin_slug . '.php';
    
    if (isset($options['action'], $options['type'], $options['plugins']) && 
        $options['action'] === 'update' && 
        $options['type'] === 'plugin' && 
        is_array($options['plugins']) && 
        in_array($current_plugin_file, $options['plugins'])) {
        
        $plugin_file = $current_plugin_file;
        
        if ($self_plugin_dir !== $desired_plugin_dir) {
            $old_path = WP_PLUGIN_DIR . '/' . $self_plugin_dir;
            $new_path = WP_PLUGIN_DIR . '/' . $desired_plugin_dir;
            
            if (rename($old_path, $new_path)) {
                $plugin_file = $desired_plugin_dir . '/' . $plugin_slug . '.php';
            } else {
                error_log('Erro ao renomear a pasta do plugin.');
            }
        }
        
        if (!is_plugin_active($plugin_file)) {
            $result = activate_plugin($plugin_file);
            if (is_wp_error($result)) {
                error_log('Erro ao reativar o plugin: ' . $result->get_error_message());
            }
        }
    }
}, 10, 2);